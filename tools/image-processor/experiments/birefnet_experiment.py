#!/usr/bin/env python3
"""Private, opt-in CLI experiment. No HTTP API, database or production imports."""
import argparse
from contextlib import contextmanager
import json
import math
import os
from pathlib import Path
import selectors
import signal
import stat
import subprocess
import sys
import time
import threading
import urllib.request

sys.dont_write_bytecode = True

from experiment_safety import (check_model, exclusive_private_file, experiment_lock,
                               new_run_directory, private_directory, lexical_path,
                               sha256_file, write_private_json)
from model_manifest import MODELS

DEFAULT_RSS_MIB = 1536
DEFAULT_TIMEOUT = 120
MAX_WORKER_STDOUT = 64 * 1024
GUARD_TOKEN = b"anabelka-supervised-v1\n"
CGROUP_ROOT = Path("/sys/fs/cgroup")
PROC_CGROUP = Path("/proc/self/cgroup")
PROC_MOUNTINFO = Path("/proc/self/mountinfo")


def cgroup_memory_headroom_mib():
    """Include exposed finite container quotas, rather than treating host RAM as ours."""
    root = CGROUP_ROOT
    candidates = [root]
    legacy_memberships = []
    try:
        for line in PROC_CGROUP.read_text().splitlines():
            hierarchy, controllers, group = line.split(":", 2)
            if hierarchy == "0" and not controllers:
                directory = root / group.lstrip("/")
                if directory.exists() and directory.is_relative_to(root):
                    candidates.extend([directory, *(parent for parent in directory.parents if parent.is_relative_to(root))])
            if "memory" in controllers.split(","):
                legacy_memberships.append(group)
    except OSError as error:
        raise ValueError("Cgroup-memory monitoring is unavailable.") from error
    headrooms = []
    for directory in dict.fromkeys(candidates):
        limit_file, current_file = directory / "memory.max", directory / "memory.current"
        if not limit_file.exists():
            continue
        try:
            limit = limit_file.read_text().strip()
            if limit != "max":
                headrooms.append(max(0, int(limit) - int(current_file.read_text().strip())) / 1024 ** 2)
        except (OSError, ValueError) as error:
            raise ValueError("Cgroup-memory monitoring is unavailable.") from error
    # Older Android/Linux deployments may expose cgroup v1 instead.
    legacy_roots = [root / "memory"]
    if legacy_memberships:
        try:
            for line in PROC_MOUNTINFO.read_text().splitlines():
                before, after = line.split(" - ", 1)
                fields = after.split()
                if fields[0] == "cgroup" and "memory" in fields[2].split(","):
                    legacy_roots.append(Path(before.split()[4].replace("\\040", " ")))
        except (OSError, ValueError, IndexError) as error:
            raise ValueError("Cgroup-memory mounting information is unavailable.") from error
    for legacy_root in dict.fromkeys(legacy_roots):
        legacy_candidates = [legacy_root]
        for group in legacy_memberships:
            directory = legacy_root / group.lstrip("/")
            if directory.exists() and directory.is_relative_to(legacy_root):
                legacy_candidates.extend([directory, *(parent for parent in directory.parents if parent.is_relative_to(legacy_root))])
        for legacy in dict.fromkeys(legacy_candidates):
            if not (legacy / "memory.limit_in_bytes").exists():
                continue
            try:
                limit = int((legacy / "memory.limit_in_bytes").read_text().strip())
                if limit < 1 << 60:
                    headrooms.append(max(0, limit - int((legacy / "memory.usage_in_bytes").read_text().strip())) / 1024 ** 2)
            except (OSError, ValueError) as error:
                raise ValueError("Cgroup-memory monitoring is unavailable.") from error
    return min(headrooms) if headrooms else None


def available_memory_mib():
    if not sys.platform.startswith("linux"):
        raise ValueError("Safe experiment supervision supports Linux/Termux only; Windows is unsupported.")
    try:
        for line in Path("/proc/meminfo").read_text().splitlines():
            if line.startswith("MemAvailable:"):
                amount = int(line.split()[1]) / 1024
                if amount > 0:
                    quota_headroom = cgroup_memory_headroom_mib()
                    return min(amount, quota_headroom) if quota_headroom is not None else amount
    except (OSError, ValueError) as error:
        raise ValueError("Available-memory monitoring is unavailable.") from error
    raise ValueError("Available-memory monitoring is unavailable.")


def proc_path_for_pid(pid):
    """Match the real direct child even with a host-mounted /proc in a PID namespace."""
    try:
        parent = dict(line.split(":", 1) for line in Path("/proc/self/status").read_text().splitlines() if ":" in line)
        parent_pid = int(parent["Pid"].strip())
        try:
            children = (Path("/proc") / str(parent_pid) / "task" / str(parent_pid) / "children").read_text().split()
        except FileNotFoundError:
            # Kernels without CONFIG_CHECKPOINT_RESTORE omit task/children.
            children = [entry.name for entry in Path("/proc").iterdir() if entry.name.isdigit()]
        for candidate in dict.fromkeys([str(pid), *children]):
            directory = Path("/proc") / candidate
            try:
                fields = dict(line.split(":", 1) for line in (directory / "status").read_text().splitlines() if ":" in line)
                namespace_pid = int(fields.get("NSpid", fields["Pid"]).split()[-1])
                if namespace_pid == pid and int(fields["PPid"].strip()) == parent_pid:
                    return directory
            except (OSError, ValueError, KeyError):
                continue
    except (OSError, ValueError, KeyError) as error:
        raise ValueError("Worker PID-namespace monitoring is unavailable.") from error
    raise ValueError("Worker PID-namespace monitoring is unavailable.")


def process_rss_mib(pid, proc_directory=None):
    try:
        fields = {}
        for line in ((proc_directory or proc_path_for_pid(pid)) / "status").read_text().splitlines():
            if line.startswith(("VmRSS:", "VmHWM:")):
                key, value = line.split(":", 1)
                fields[key] = int(value.split()[0]) / 1024
        if "VmRSS" not in fields or "VmHWM" not in fields:
            raise ValueError("Worker RSS monitoring is unavailable.")
        return max(fields.values())
    except OSError as error:
        raise ValueError("Worker RSS monitoring is unavailable.") from error


def terminate_group(process):
    """Always kill the group even if its leader exits before a descendant does."""
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    try:
        process.wait(timeout=.25)
    except subprocess.TimeoutExpired:
        pass
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    process.wait()


@contextmanager
def interruption_signals():
    previous = {}
    if threading.current_thread() is threading.main_thread():
        def interrupt(signum, frame):
            raise KeyboardInterrupt
        for signum in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            previous[signum] = signal.signal(signum, interrupt)
    try:
        yield previous
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)


def supervise(command, *, timeout=DEFAULT_TIMEOUT, rss_limit_mib=DEFAULT_RSS_MIB, reserve_mib=256, worker_guard=False):
    for value in (timeout, rss_limit_mib):
        if not math.isfinite(value) or value <= 0:
            raise ValueError("Timeout and RSS budget must be positive finite numbers.")
    if reserve_mib < 0 or not math.isfinite(reserve_mib):
        raise ValueError("Memory reserve must be nonnegative and finite.")
    available = available_memory_mib()
    if available < rss_limit_mib + reserve_mib:
        raise ValueError("Available memory is below RSS budget plus reserve.")
    started = time.monotonic()
    process = None
    selector = None
    guard_read = guard_write = None
    output = bytearray()
    peak = 0.0
    failure = None
    proc_directory = None
    with interruption_signals() as handled:
        try:
            environment = os.environ.copy()
            environment.update({"PYTHONDONTWRITEBYTECODE": "1", "OMP_NUM_THREADS": "1", "OPENBLAS_NUM_THREADS": "1", "MKL_NUM_THREADS": "1"})
            command = list(command)
            if worker_guard:
                guard_read, guard_write = os.pipe()
                os.write(guard_write, GUARD_TOKEN)
                command.extend(["--supervisor-fd", str(guard_read), "--supervisor-pid", str(os.getpid())])
            process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                       start_new_session=True, env=environment,
                                       pass_fds=(guard_read,) if worker_guard else ())
            if guard_read is not None:
                os.close(guard_read)
                guard_read = None
            selector = selectors.DefaultSelector()
            selector.register(process.stdout, selectors.EVENT_READ)
            os.set_blocking(process.stdout.fileno(), False)
            while True:
                alive = process.poll() is None
                if alive:
                    try:
                        if proc_directory is None:
                            proc_directory = proc_path_for_pid(process.pid)
                        peak = max(peak, process_rss_mib(process.pid, proc_directory))
                        remaining = available_memory_mib()
                    except ValueError:
                        if process.poll() is None:
                            failure = "memory_monitor_unavailable"
                            break
                        remaining = reserve_mib
                    if peak > rss_limit_mib:
                        failure = "rss_budget_exceeded"
                        break
                    if remaining < reserve_mib:
                        failure = "available_memory_reserve_exhausted"
                        break
                    if time.monotonic() - started > timeout:
                        failure = "timeout"
                        break
                events = selector.select(.025 if alive else 0)
                for key, _ in events:
                    data = os.read(key.fd, 8192)
                    if data:
                        output.extend(data)
                        if len(output) > MAX_WORKER_STDOUT:
                            failure = "worker_output_limit"
                            break
                    else:
                        selector.unregister(key.fileobj)
                if failure:
                    break
                if not alive:
                    # Never wait indefinitely for descendants holding stdout open.
                    while True:
                        try:
                            data = os.read(process.stdout.fileno(), 8192)
                        except BlockingIOError:
                            break
                        if not data:
                            break
                        output.extend(data)
                        if len(output) > MAX_WORKER_STDOUT:
                            failure = "worker_output_limit"
                            break
                    break
        except KeyboardInterrupt:
            failure = "interrupted"
        finally:
            # Repeated signals must not interrupt termination/reaping.
            for signum in handled:
                signal.signal(signum, signal.SIG_IGN)
            if process is not None:
                terminate_group(process)
                if process.stdout is not None:
                    process.stdout.close()
            if selector is not None:
                selector.close()
            for fd in (guard_read, guard_write):
                if fd is not None:
                    os.close(fd)
    if process is None:
        raise KeyboardInterrupt
    result = {"ok": False, "worker_pid": process.pid, "exit_code": process.returncode,
              "wall_ms": round((time.monotonic() - started) * 1000, 1),
              "peak_rss_mib": round(peak, 2), "rss_limit_mib": rss_limit_mib,
              "rss_enforcement": "sampled_proc_rss_high_water_not_a_hard_cap",
              "sample_interval_ms": 25, "available_memory_start_mib": round(available, 2),
              "available_memory_measurement": "minimum_proc_memavailable_and_exposed_finite_cgroup_headroom"}
    if failure:
        result["failure"] = failure
        return result
    try:
        worker = json.loads(output.decode("utf-8"))
        if not isinstance(worker, dict) or type(worker.get("ok")) is not bool:
            raise ValueError("Invalid worker result.")
    except (ValueError, UnicodeError):
        result["failure"] = "invalid_worker_result"
        return result
    result["worker"] = worker
    result["ok"] = process.returncode == 0 and worker["ok"]
    if not result["ok"]:
        result["failure"] = "worker_error"
    return result


def source_signature(path):
    path = Path(path).expanduser().absolute()
    if path.is_symlink() or not path.is_file():
        raise ValueError("Source must be an existing regular file, without a symlink alias.")
    info = path.stat()
    if not stat.S_ISREG(info.st_mode):
        raise ValueError("Source must be a regular file.")
    if info.st_size > 128 * 1024 * 1024:
        raise ValueError("Source exceeds experiment byte budget.")
    return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, sha256_file(path))


def run_experiment(args):
    source = args.source.expanduser().absolute()
    model = lexical_path(args.model)
    if source == model:
        raise ValueError("Source and model must be different files.")
    with experiment_lock():
        checked = check_model(model, args.method)
        before = source_signature(source)
        root = private_directory(args.output_root)
        directory = new_run_directory(root)
        command = [sys.executable, "-B", str(Path(__file__).with_name("birefnet_worker.py")),
                   "--source", str(source), "--model", str(model), "--method", args.method,
                   "--run-directory", str(directory)]
        if args.previews:
            command.append("--previews")
        result = supervise(command, timeout=args.timeout, rss_limit_mib=args.rss_limit_mib, worker_guard=True)
        try:
            after = source_signature(source)
            unchanged = before == after
        except (OSError, ValueError):
            after = None
            unchanged = False
        result.update({"method": args.method, "model": checked,
                       "profile": MODELS[args.method]["profile"],
                       "source_sha256_before": before[-1],
                       "source_sha256_after": after[-1] if after else None,
                       "source_unchanged": unchanged, "run_directory": str(directory),
                       "intra_op_threads": MODELS[args.method]["intra_op_threads"], "inter_op_threads": 1})
        if not unchanged:
            result.update({"ok": False, "failure": "source_changed"})
        write_private_json(directory / "metrics.json", result)
        return result


def download_model(destination):
    """Fetch only the pinned upstream artifact; fail on bytes/hash/no overwrite."""
    destination = lexical_path(destination)
    manifest = MODELS["birefnet"]
    written = 0
    created = False
    try:
        with exclusive_private_file(destination) as output:
            created = True
            # Redirects may go to GitHub release assets; accepted bytes remain hash-pinned.
            with urllib.request.urlopen(manifest["url"], timeout=30) as response:
                while True:
                    chunk = response.read(min(1024 * 1024, manifest["bytes"] + 1 - written))
                    if not chunk:
                        break
                    written += len(chunk)
                    if written > manifest["bytes"]:
                        raise ValueError("Downloaded model exceeds pinned size.")
                    output.write(chunk)
        return check_model(destination, "birefnet")
    except BaseException:
        if created:
            destination.unlink()
        raise


def parser():
    top = argparse.ArgumentParser(description=__doc__)
    commands = top.add_subparsers(dest="command", required=True)
    download = commands.add_parser("download", help="Download checksum-pinned official BiRefNet Lite")
    download.add_argument("--model", type=Path, required=True)
    check = commands.add_parser("check", help="Validate private model size and pinned SHA-256")
    check.add_argument("--method", choices=MODELS, default="birefnet")
    check.add_argument("--model", type=Path, required=True)
    check.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT)
    check.add_argument("--rss-limit-mib", type=float, default=DEFAULT_RSS_MIB)
    run = commands.add_parser("run", help="Run one image in a supervised CPU worker")
    run.add_argument("--method", choices=MODELS, default="birefnet")
    run.add_argument("--source", required=True, type=Path)
    run.add_argument("--model", required=True, type=Path)
    run.add_argument("--output-root", required=True, type=Path, help="Existing private mode-0700 directory outside any repository")
    run.add_argument("--previews", action="store_true", help="Also save RGBA/checker/white PNGs at original oriented size")
    run.add_argument("--timeout", type=float, default=DEFAULT_TIMEOUT)
    run.add_argument("--rss-limit-mib", type=float, default=DEFAULT_RSS_MIB)
    return top


def main():
    args = parser().parse_args()
    os.umask(0o077)
    try:
        if args.command == "download":
            with experiment_lock():
                result = {"ok": True, "model": download_model(args.model)}
        elif args.command == "check":
            with experiment_lock():
                checked = check_model(args.model, args.method)
                command = [sys.executable, "-B", str(Path(__file__).with_name("birefnet_worker.py")), "--model", str(lexical_path(args.model)), "--method", args.method, "--check-only"]
                result = supervise(command, timeout=args.timeout, rss_limit_mib=args.rss_limit_mib, worker_guard=True)
                result["model"] = checked
        else:
            result = run_experiment(args)
    except KeyboardInterrupt:
        result = {"ok": False, "failure": "interrupted"}
    except (OSError, ValueError) as error:
        result = {"ok": False, "error_type": type(error).__name__, "error": str(error)}
    print(json.dumps(result, allow_nan=False))
    return 0 if result["ok"] else (130 if result.get("failure") == "interrupted" else 1)


if __name__ == "__main__":
    raise SystemExit(main())
