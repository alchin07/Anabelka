"""Isolated experiment contracts; real weights are intentionally optional."""
import contextlib
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import types
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

sys.dont_write_bytecode = True


ROOT = Path(__file__).resolve().parents[1]
EXPERIMENTS = ROOT / "tools" / "image-processor" / "experiments"


def load(name):
    target = EXPERIMENTS / (name + ".py")
    if not target.is_file():
        return None
    sys.path.insert(0, str(EXPERIMENTS))
    try:
        spec = importlib.util.spec_from_file_location(name, target)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module
    finally:
        sys.path.pop(0)


class PrivateExperimentTests(unittest.TestCase):
    def setUp(self):
        self.safety = load("experiment_safety")
        self.assertIsNotNone(self.safety, "Private experiment safety implementation is missing")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.root.chmod(0o700)

    def test_rejects_repository_output(self):
        project = self.root / "project"
        project.mkdir(mode=0o700)
        (project / ".git").mkdir()
        with self.assertRaisesRegex(ValueError, "repository"):
            self.safety.private_directory(project / "results", create=True)
        self.assertFalse((project / "results").exists())

    def test_rejects_bare_repository_output(self):
        project = self.root / "bare.git"
        project.mkdir(mode=0o700)
        (project / "HEAD").write_text("ref: refs/heads/main\n")
        (project / "config").write_text("[core]\n bare = true\n")
        (project / "objects").mkdir()
        (project / "refs").mkdir()
        with self.assertRaisesRegex(ValueError, "repository"):
            self.safety.private_directory(project / "results", create=True)
        self.assertFalse((project / "results").exists())

    def test_rejects_symlink_parent_before_writing(self):
        (self.root / "alias").symlink_to(self.root, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.safety.private_directory(self.root / "alias" / "results", create=True)
        self.assertFalse((self.root / "results").exists())

    def test_rejects_dotdot_alias(self):
        with self.assertRaises(ValueError):
            self.safety.private_directory(self.root / "unused" / ".." / "results", create=True)

    def test_rejects_android_shared_storage(self):
        for value in ("/storage/emulated/0/results", "/sdcard/results", "/mnt/media_rw/sd/results"):
            with self.subTest(path=value), self.assertRaisesRegex(ValueError, "shared"):
                self.safety.private_directory(Path(value), create=True)

    def test_rejects_public_directory_without_chmod_side_effect(self):
        self.root.chmod(0o755)
        with self.assertRaisesRegex(ValueError, "private"):
            self.safety.private_directory(self.root)
        self.assertEqual(self.root.stat().st_mode & 0o777, 0o755)

    def test_run_directory_is_exclusive_private_and_random(self):
        one = self.safety.new_run_directory(self.root)
        two = self.safety.new_run_directory(self.root)
        self.assertNotEqual(one, two)
        self.assertEqual(one.stat().st_mode & 0o777, 0o700)
        self.assertEqual(one.parent, self.root)

    def test_output_file_is_exclusive_private_and_not_hardlinked(self):
        path = self.root / "metrics.json"
        self.safety.write_private_json(path, {"ok": True})
        self.assertEqual(json.loads(path.read_text()), {"ok": True})
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        with self.assertRaises(FileExistsError):
            self.safety.write_private_json(path, {"ok": False})
        linked = self.root / "linked"
        os.link(path, linked)
        with self.assertRaisesRegex(ValueError, "link"):
            self.safety.private_file(path)

    def test_lock_excludes_concurrent_method_and_releases_after_exception(self):
        with patch.dict(os.environ, {"HOME": str(self.root)}):
            with self.safety.experiment_lock():
                code = "import sys;sys.path.insert(0,sys.argv[1]);from experiment_safety import experiment_lock;\nwith experiment_lock(): pass"
                other = subprocess.run([sys.executable, "-c", code, str(EXPERIMENTS)], capture_output=True)
                self.assertNotEqual(other.returncode, 0)
                self.assertIn(b"already running", other.stderr)
            with self.assertRaises(RuntimeError):
                with self.safety.experiment_lock():
                    raise RuntimeError("release")
            with self.safety.experiment_lock():
                pass

    def test_missing_and_corrupt_model_fail_before_worker(self):
        with self.assertRaisesRegex(ValueError, "missing"):
            self.safety.check_model(self.root / "missing.onnx", "birefnet")
        model = self.root / "wrong.onnx"
        model.write_bytes(b"wrong")
        model.chmod(0o600)
        with self.assertRaisesRegex(ValueError, "size"):
            self.safety.check_model(model, "birefnet")

    def test_wrong_hash_is_rejected_even_when_size_matches(self):
        model = self.root / "wrong.onnx"
        model.write_bytes(b"model")
        model.chmod(0o600)
        manifest = {"bytes": 5, "sha256": "0" * 64}
        with patch.dict(self.safety.MODELS, {"birefnet": manifest}):
            with self.assertRaisesRegex(ValueError, "SHA-256"):
                self.safety.check_model(model, "birefnet")

    def test_lock_rejects_symlink_or_public_home_namespace(self):
        with patch.dict(os.environ, {"HOME": str(self.root)}):
            lock_root = self.root / ".anabelka-image-experiments"
            lock_root.mkdir(mode=0o755)
            with self.assertRaisesRegex(ValueError, "private"):
                with self.safety.experiment_lock():
                    pass


class BiRefNetTensorTests(unittest.TestCase):
    def setUp(self):
        self.worker = load("birefnet_worker")
        self.assertIsNotNone(self.worker, "BiRefNet worker implementation is missing")
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)

    def test_rgb_global_max_normalization_matches_rembg_and_float32_nchw(self):
        image = Image.new("RGB", (21, 35), (25, 50, 100))
        tensor = self.worker.prepare_tensor(image)
        self.assertEqual(tensor.shape, (1, 3, 1024, 1024))
        self.assertEqual(tensor.dtype, np.float32)
        np.testing.assert_allclose(tensor[0, :, 0, 0], (np.array([.25, .5, 1], dtype=np.float32) - [.485, .456, .406]) / [.229, .224, .225], atol=1e-6)
        self.assertTrue(tensor.flags.c_contiguous)

    def test_black_image_is_finite(self):
        tensor = self.worker.prepare_tensor(Image.new("RGB", (8, 8), 0))
        self.assertTrue(np.isfinite(tensor).all())

    def test_exif_orientation_is_applied_before_size_and_tensor(self):
        source = self.root / "oriented.jpg"
        exif = Image.Exif()
        exif[274] = 6
        Image.new("RGB", (24, 40), (20, 30, 40)).save(source, exif=exif)
        image = self.worker.load_rgb(source)
        self.assertEqual(image.size, (40, 24))

    def test_portrait_and_landscape_alpha_match_oriented_original_geometry(self):
        logits = np.linspace(-3, 3, 1024 * 1024, dtype=np.float32).reshape(1, 1, 1024, 1024)
        for size in ((75, 120), (120, 75)):
            with self.subTest(size=size):
                alpha = self.worker.alpha_from_logits(logits, size)
                self.assertEqual(alpha.mode, "L")
                self.assertEqual(alpha.size, size)
                prediction = 1 / (1 + np.exp(-logits[0, 0]))
                expected = Image.fromarray(((prediction - prediction.min()) / (prediction.max() - prediction.min()) * 255).astype(np.uint8), mode="L").resize(size, Image.Resampling.LANCZOS)
                np.testing.assert_array_equal(np.asarray(alpha), np.asarray(expected))

    def test_stable_sigmoid_handles_extreme_logits(self):
        logits = np.zeros((1, 1, 1024, 1024), dtype=np.float32)
        logits[:, :, :512, :] = -1000
        logits[:, :, 512:, :] = 1000
        with np.errstate(over="raise", invalid="raise"):
            alpha = self.worker.alpha_from_logits(logits, (1024, 1024))
        self.assertEqual(alpha.getpixel((0, 0)), 0)
        self.assertEqual(alpha.getpixel((0, 1023)), 255)

    def test_rejects_nonfinite_wrong_dtype_and_wrong_output_shape(self):
        bad = [np.zeros((1, 1, 1024, 1024), dtype=np.float64), np.zeros((1024, 1024), dtype=np.float32), np.zeros((1, 3, 1024, 1024), dtype=np.float32), np.full((1, 1, 1024, 1024), np.nan, dtype=np.float32)]
        for logits in bad:
            with self.subTest(shape=logits.shape, dtype=logits.dtype), self.assertRaises(ValueError):
                self.worker.alpha_from_logits(logits, (24, 40))

    def test_constant_predictions_are_explicit_error(self):
        with self.assertRaisesRegex(ValueError, "constant"):
            self.worker.alpha_from_logits(np.zeros((1, 1, 1024, 1024), dtype=np.float32), (24, 40))

    def test_onnx_input_output_metadata_are_validated(self):
        valid_in = types.SimpleNamespace(name="input_image", type="tensor(float)", shape=[1, 3, 1024, 1024])
        valid_out = types.SimpleNamespace(name="output_image", type="tensor(float)", shape=[1, 1, 1024, 1024])
        session = types.SimpleNamespace(get_inputs=lambda: [valid_in], get_outputs=lambda: [valid_out])
        self.assertEqual(self.worker.validate_session(session), "input_image")
        for wrong in (types.SimpleNamespace(name="input", type="tensor(double)", shape=[1, 3, 1024, 1024]), types.SimpleNamespace(name="input", type="tensor(float)", shape=[1, 3, 512, 512])):
            with self.assertRaises(ValueError):
                self.worker.validate_session(types.SimpleNamespace(get_inputs=lambda: [wrong], get_outputs=lambda: [valid_out]))

    def test_rejects_renamed_onnx_schema(self):
        valid_out = types.SimpleNamespace(name="output_image", type="tensor(float)", shape=[1, 1, 1024, 1024])
        wrong_in = types.SimpleNamespace(name="other", type="tensor(float)", shape=[1, 3, 1024, 1024])
        with self.assertRaises(ValueError):
            self.worker.validate_session(types.SimpleNamespace(get_inputs=lambda: [wrong_in], get_outputs=lambda: [valid_out]))

    def fake_ort(self, *, error=None, providers=None):
        valid_in = types.SimpleNamespace(name="input_image", type="tensor(float)", shape=[1, 3, 1024, 1024])
        valid_out = types.SimpleNamespace(name="output_image", type="tensor(float)", shape=[1, 1, 1024, 1024])
        session = types.SimpleNamespace(get_inputs=lambda: [valid_in], get_outputs=lambda: [valid_out], get_providers=lambda: ["CPUExecutionProvider"])
        def factory(*args, **kwargs):
            if error:
                raise error
            self.options = kwargs["sess_options"]
            self.session_providers = kwargs["providers"]
            return session
        return types.SimpleNamespace(__version__="test", get_available_providers=lambda: providers if providers is not None else ["CPUExecutionProvider"], SessionOptions=types.SimpleNamespace, ExecutionMode=types.SimpleNamespace(ORT_SEQUENTIAL="sequential"), InferenceSession=factory)

    def test_runtime_check_reports_cpu_version_schema_and_conservative_options(self):
        self.assertTrue(callable(getattr(self.worker, "check_runtime", None)))
        with patch.object(self.worker, "check_model", return_value={}), patch.dict(sys.modules, {"onnxruntime": self.fake_ort()}):
            result = self.worker.check_runtime(self.root / "model.onnx", "birefnet")
        self.assertEqual(result["onnxruntime"], "test")
        self.assertEqual(result["provider"], "CPUExecutionProvider")
        self.assertEqual(result["input_schema"][0]["shape"], [1, 3, 1024, 1024])
        self.assertEqual(self.options.intra_op_num_threads, 1)
        self.assertEqual(self.options.inter_op_num_threads, 1)
        self.assertEqual(self.options.execution_mode, "sequential")
        self.assertFalse(self.options.enable_mem_pattern)
        self.assertFalse(self.options.enable_cpu_mem_arena)
        self.assertEqual(self.session_providers, ["CPUExecutionProvider"])

    def test_runtime_load_failure_is_predefined_not_sensitive_exception_text(self):
        self.assertTrue(callable(getattr(self.worker, "check_runtime", None)))
        with patch.object(self.worker, "check_model", return_value={}), patch.dict(sys.modules, {"onnxruntime": self.fake_ort(error=RuntimeError("private secret path"))}):
            with self.assertRaises(RuntimeError) as caught:
                self.worker.check_runtime(self.root / "model.onnx", "birefnet")
        self.assertEqual(getattr(caught.exception, "code", None), "onnx_load_failed")
        self.assertNotIn("secret", str(caught.exception))

    def test_runtime_memory_error_remains_identifiable(self):
        self.assertTrue(callable(getattr(self.worker, "check_runtime", None)))
        with patch.object(self.worker, "check_model", return_value={}), patch.dict(sys.modules, {"onnxruntime": self.fake_ort(error=MemoryError())}):
            with self.assertRaises(MemoryError):
                self.worker.check_runtime(self.root / "model.onnx", "birefnet")

    def test_worker_cli_requires_inherited_supervisor_capability(self):
        run = subprocess.run([sys.executable, str(EXPERIMENTS / "birefnet_worker.py"), "--source", str(self.root / "source.png"), "--model", str(self.root / "model.onnx"), "--run-directory", str(self.root), "--method", "birefnet"], capture_output=True)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn(b"--supervisor-fd", run.stderr)

    def test_worker_output_save_never_follows_symlink_or_overwrites_source(self):
        source = self.root / "source.png"
        Image.new("RGB", (24, 40), "red").save(source)
        before = source.read_bytes()
        alpha_path = self.root / "alpha.png"
        alpha_path.symlink_to(source)
        with self.assertRaises((ValueError, FileExistsError)):
            self.worker.save_png(Image.new("L", (24, 40), 0), alpha_path)
        self.assertEqual(source.read_bytes(), before)


class SupervisorTests(unittest.TestCase):
    def setUp(self):
        self.cli = load("birefnet_experiment")
        self.assertIsNotNone(self.cli, "Experiment supervisor implementation is missing")

    def test_parent_imports_no_heavy_libraries(self):
        code = "import sys;sys.path.insert(0,sys.argv[1]);import birefnet_experiment;assert not ({'numpy','PIL','onnxruntime','cv2'} & set(sys.modules))"
        run = subprocess.run([sys.executable, "-c", code, str(EXPERIMENTS)], capture_output=True)
        self.assertEqual(run.returncode, 0, run.stderr.decode())

    def test_proc_mapping_monitors_actual_child_in_nested_pid_namespace(self):
        self.assertTrue(callable(getattr(self.cli, "proc_path_for_pid", None)))
        child = subprocess.Popen([sys.executable, "-c", "import time;time.sleep(5)"])
        try:
            status = self.cli.proc_path_for_pid(child.pid).joinpath("status").read_text()
            ns_pid = next(line for line in status.splitlines() if line.startswith("NSpid:")).split()[-1]
            self.assertEqual(int(ns_pid), child.pid)
            self.assertGreater(self.cli.process_rss_mib(child.pid), 0)
        finally:
            child.terminate()
            child.wait()

    def test_supervisor_timeout_reaps_worker(self):
        result = self.cli.supervise([sys.executable, "-c", "import time;time.sleep(30)"], timeout=.12, rss_limit_mib=1536, reserve_mib=0)
        self.assertFalse(result["ok"])
        self.assertEqual(result["failure"], "timeout")
        with self.assertRaises(ProcessLookupError):
            os.kill(result["worker_pid"], 0)

    def test_supervisor_rss_limit_reaps_worker(self):
        result = self.cli.supervise([sys.executable, "-c", "import time;x=bytearray(80*1024*1024);time.sleep(30)"], timeout=5, rss_limit_mib=24, reserve_mib=0)
        self.assertEqual(result["failure"], "rss_budget_exceeded")
        self.assertGreater(result["peak_rss_mib"], 24)
        with self.assertRaises(ProcessLookupError):
            os.kill(result["worker_pid"], 0)

    def test_supervisor_fails_closed_without_memory_monitor(self):
        with patch.object(self.cli, "available_memory_mib", side_effect=ValueError("unavailable")):
            with self.assertRaisesRegex(ValueError, "unavailable"):
                self.cli.supervise([sys.executable, "-c", "pass"], timeout=5, rss_limit_mib=1536)

    def test_supervisor_success_and_failure_preserve_bounded_json(self):
        success = self.cli.supervise([sys.executable, "-c", 'print(\'{"ok":true,"inference_ms":2}\')'], timeout=5, rss_limit_mib=1536, reserve_mib=0)
        self.assertTrue(success["ok"])
        self.assertEqual(success["worker"]["inference_ms"], 2)
        failure = self.cli.supervise([sys.executable, "-c", 'import sys;print(\'{"ok":false,"error_type":"MemoryError"}\');sys.exit(1)'], timeout=5, rss_limit_mib=1536, reserve_mib=0)
        self.assertEqual(failure["failure"], "worker_error")
        self.assertEqual(failure["worker"]["error_type"], "MemoryError")

    def test_invalid_worker_output_is_failure(self):
        result = self.cli.supervise([sys.executable, "-c", "print('not-json')"], timeout=5, rss_limit_mib=1536, reserve_mib=0)
        self.assertEqual(result["failure"], "invalid_worker_result")

    def test_cli_has_no_checksum_override_or_http_command(self):
        result = subprocess.run([sys.executable, str(EXPERIMENTS / "birefnet_experiment.py"), "run", "--help"], capture_output=True)
        self.assertEqual(result.returncode, 0)
        for option in (b"--sha256", b"--skip-checksum", b"--port", b"--host"):
            self.assertNotIn(option, result.stdout)

    def test_download_never_overwrites_existing_empty_file(self):
        with tempfile.TemporaryDirectory() as root:
            target = Path(root) / "model.onnx"
            target.touch(mode=0o600)
            with self.assertRaises(FileExistsError):
                self.cli.download_model(target)
            self.assertTrue(target.exists())

    def test_source_changed_is_failure_and_metrics_are_private(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            source = root / "source.png"
            source.write_bytes(b"source-before")
            args = types.SimpleNamespace(source=source, model=root / "model.onnx", output_root=root, method="birefnet", previews=False, timeout=2, rss_limit_mib=1536)
            def fake_supervise(*args, **kwargs):
                source.write_bytes(b"source-after")
                return {"ok": True}
            with patch.object(self.cli, "experiment_lock", contextlib.nullcontext), patch.object(self.cli, "check_model", return_value={}), patch.object(self.cli, "supervise", side_effect=fake_supervise):
                result = self.cli.run_experiment(args)
            self.assertFalse(result["ok"])
            self.assertEqual(result["failure"], "source_changed")
            metrics = Path(result["run_directory"]) / "metrics.json"
            self.assertEqual(metrics.stat().st_mode & 0o777, 0o600)
            self.assertNotEqual(result["source_sha256_before"], result["source_sha256_after"])

    def test_source_unchanged_is_recorded_and_original_never_written(self):
        with tempfile.TemporaryDirectory() as root:
            root = Path(root)
            source = root / "source.png"
            source.write_bytes(b"source-read-only")
            source.chmod(0o400)
            args = types.SimpleNamespace(source=source, model=root / "model.onnx", output_root=root, method="modnet", previews=False, timeout=2, rss_limit_mib=1536)
            with patch.object(self.cli, "experiment_lock", contextlib.nullcontext), patch.object(self.cli, "check_model", return_value={}), patch.object(self.cli, "supervise", return_value={"ok": True}) as invoke:
                result = self.cli.run_experiment(args)
            self.assertTrue(result["source_unchanged"])
            self.assertEqual(source.read_bytes(), b"source-read-only")
            self.assertEqual(result["intra_op_threads"], 2)
            command = invoke.call_args[0][0]
            self.assertEqual(command[command.index("--method") + 1], "modnet")

    def test_selector_setup_failure_reaps_already_spawned_worker(self):
        children = []
        real_popen = subprocess.Popen
        def record(*args, **kwargs):
            process = real_popen(*args, **kwargs)
            children.append(process)
            return process
        try:
            with patch.object(self.cli.subprocess, "Popen", side_effect=record), patch.object(self.cli.selectors, "DefaultSelector", side_effect=RuntimeError("selector unavailable")):
                with self.assertRaises(RuntimeError):
                    self.cli.supervise([sys.executable, "-c", "import time;time.sleep(5)"], timeout=2, rss_limit_mib=1536, reserve_mib=0)
            self.assertIsNotNone(children[0].poll(), "Worker survived supervisor setup failure")
        finally:
            for child in children:
                if child.poll() is None:
                    child.kill()
                    child.wait()

    def test_sigterm_parent_cleans_up_worker_and_returns_interrupted(self):
        with tempfile.TemporaryDirectory() as root:
            pid_file = Path(root) / "pid"
            worker_code = "import os,pathlib,time;pathlib.Path(" + repr(str(pid_file)) + ").write_text(str(os.getpid()));time.sleep(10)"
            parent_code = "import sys,json;sys.path.insert(0,sys.argv[1]);from birefnet_experiment import supervise;print(json.dumps(supervise([sys.executable,'-c',sys.argv[2]],timeout=8,rss_limit_mib=1536,reserve_mib=0)),flush=True)"
            parent = subprocess.Popen([sys.executable, "-c", parent_code, str(EXPERIMENTS), worker_code], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            child_pid = None
            try:
                deadline = time.monotonic() + 3
                while not pid_file.exists() and time.monotonic() < deadline:
                    time.sleep(.02)
                self.assertTrue(pid_file.exists(), "Test worker did not start")
                child_pid = int(pid_file.read_text())
                parent.send_signal(signal.SIGTERM)
                output, error = parent.communicate(timeout=3)
                self.assertEqual(parent.returncode, 0, error.decode())
                self.assertEqual(json.loads(output)["failure"], "interrupted")
                with self.assertRaises(ProcessLookupError):
                    os.kill(child_pid, 0)
            finally:
                if parent.poll() is None:
                    parent.kill()
                    parent.wait()
                if child_pid:
                    with contextlib.suppress(ProcessLookupError):
                        os.kill(child_pid, signal.SIGKILL)

    def test_effective_available_memory_respects_finite_cgroup_headroom(self):
        self.assertTrue(callable(getattr(self.cli, "cgroup_memory_headroom_mib", None)))
        with patch.object(self.cli, "cgroup_memory_headroom_mib", return_value=32):
            self.assertLessEqual(self.cli.available_memory_mib(), 32)

    def test_cgroup_v1_finite_subgroup_headroom_is_not_hidden_by_unlimited_root(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            cgroup = root / "cgroup"
            limited = cgroup / "memory" / "limited"
            limited.mkdir(parents=True)
            (limited.parent / "memory.limit_in_bytes").write_text(str((1 << 63) - 4096))
            (limited.parent / "memory.usage_in_bytes").write_text("0")
            (limited / "memory.limit_in_bytes").write_text(str(64 * 1024 ** 2))
            (limited / "memory.usage_in_bytes").write_text(str(60 * 1024 ** 2))
            membership = root / "membership"
            membership.write_text("5:memory:/limited\n")
            mounts = root / "mounts"
            mounts.write_text("")
            with patch.object(self.cli, "CGROUP_ROOT", cgroup, create=True), patch.object(self.cli, "PROC_CGROUP", membership, create=True), patch.object(self.cli, "PROC_MOUNTINFO", mounts, create=True):
                self.assertEqual(self.cli.cgroup_memory_headroom_mib(), 4)

    def test_default_cli_does_not_create_repository_bytecode_or_outputs(self):
        before = {str(path.relative_to(EXPERIMENTS)) for path in EXPERIMENTS.rglob("*")}
        with tempfile.TemporaryDirectory() as folder:
            run = subprocess.run([sys.executable, str(EXPERIMENTS / "birefnet_experiment.py"), "check", "--model", str(Path(folder) / "missing.onnx")], capture_output=True)
            self.assertNotEqual(run.returncode, 0)
        after = {str(path.relative_to(EXPERIMENTS)) for path in EXPERIMENTS.rglob("*")}
        self.assertEqual(before, after)

    def test_worker_stdout_limit_and_reserve_depletion_are_failures(self):
        output = self.cli.supervise([sys.executable, "-c", "import sys,time;sys.stdout.write('x'*100000);sys.stdout.flush();time.sleep(5)"], timeout=2, rss_limit_mib=1536, reserve_mib=0)
        self.assertEqual(output["failure"], "worker_output_limit")
        with patch.object(self.cli, "available_memory_mib", side_effect=[10000, 100]):
            reserve = self.cli.supervise([sys.executable, "-c", "import time;time.sleep(5)"], timeout=2, rss_limit_mib=1536, reserve_mib=256)
        self.assertEqual(reserve["failure"], "available_memory_reserve_exhausted")

    def test_worker_reports_its_kernel_peak_rss(self):
        code = "import sys;sys.path.insert(0," + repr(str(EXPERIMENTS)) + ");import birefnet_worker as worker;worker.run_worker=lambda *a,**kw:{'ok':True};raise SystemExit(worker.main())"
        command = [sys.executable, "-c", code, "--source", "/unused-source", "--model", "/unused-model", "--run-directory", "/unused-run-directory", "--method", "birefnet"]
        result = self.cli.supervise(command, timeout=3, rss_limit_mib=1536, reserve_mib=0, worker_guard=True)
        self.assertTrue(result["ok"])
        self.assertGreater(result["worker"].get("worker_peak_rss_mib", 0), 0)

    def test_parent_sigkill_closes_guard_pipe_and_worker_stops(self):
        with tempfile.TemporaryDirectory() as root:
            pid_file = Path(root) / "pid.json"
            child_code = "import argparse,json,os,pathlib,sys,time;sys.path.insert(0," + repr(str(EXPERIMENTS)) + ");from birefnet_worker import guard_supervisor;p=argparse.ArgumentParser();p.add_argument('--supervisor-fd',type=int);p.add_argument('--supervisor-pid',type=int);a=p.parse_args();guard_supervisor(a.supervisor_fd,a.supervisor_pid);fields=dict(l.split(':',1) for l in pathlib.Path('/proc/self/status').read_text().splitlines() if ':' in l);pathlib.Path(" + repr(str(pid_file)) + ").write_text(json.dumps({'pid':os.getpid(),'proc_pid':int(fields['Pid'])}));time.sleep(10)"
            parent_code = "import sys;sys.path.insert(0,sys.argv[1]);from birefnet_experiment import supervise;supervise([sys.executable,'-c',sys.argv[2]],timeout=8,rss_limit_mib=1536,reserve_mib=0,worker_guard=True)"
            parent = subprocess.Popen([sys.executable, "-c", parent_code, str(EXPERIMENTS), child_code], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            info = None
            try:
                deadline = time.monotonic() + 3
                while not pid_file.exists() and time.monotonic() < deadline:
                    time.sleep(.02)
                self.assertTrue(pid_file.exists(), "Guarded worker did not start")
                info = json.loads(pid_file.read_text())
                parent.kill()
                parent.wait(timeout=2)
                stopped = False
                deadline = time.monotonic() + 3
                while time.monotonic() < deadline:
                    status = Path("/proc") / str(info["proc_pid"]) / "status"
                    if not status.exists() or "State:\tZ" in status.read_text():
                        stopped = True
                        break
                    time.sleep(.02)
                self.assertTrue(stopped, "Worker survived parent SIGKILL")
            finally:
                if parent.poll() is None:
                    parent.kill()
                    parent.wait()
                if info:
                    with contextlib.suppress(ProcessLookupError):
                        os.kill(info["pid"], signal.SIGKILL)

    @unittest.skipUnless(os.environ.get("ANABELKA_EXPERIMENT_MODEL") and os.environ.get("ANABELKA_EXPERIMENT_SOURCE"), "Optional real weights/source fixture is absent")
    def test_optional_real_weights_load_and_oriented_source_remains_unchanged(self):
        model = Path(os.environ["ANABELKA_EXPERIMENT_MODEL"])
        source = Path(os.environ["ANABELKA_EXPERIMENT_SOURCE"])
        before = hashlib.sha256(source.read_bytes()).hexdigest()
        run = subprocess.run([sys.executable, str(EXPERIMENTS / "birefnet_experiment.py"), "check", "--model", str(model)], capture_output=True, timeout=125)
        result = json.loads(run.stdout)
        self.assertEqual(run.returncode, 0, result)
        self.assertEqual(result["worker"]["input_schema"][0]["shape"], [1, 3, 1024, 1024])
        image = load("birefnet_worker").load_rgb(source)
        self.assertGreater(min(image.size), 0)
        self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(), before)


if __name__ == "__main__":
    unittest.main()
