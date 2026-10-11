"""One CPU inference, then exit. Imported only by the supervised subprocess.

Reference rembg BiRefNet session: square Lanczos RGB/global-max/ImageNet input;
sigmoid/global-minmax/Lanczos mask returned in EXIF-oriented original geometry.
The square model domain distorts aspect ratio; exported originals never do.
"""
import argparse
import importlib.util
import json
from pathlib import Path
import sys
import time

sys.dont_write_bytecode = True

import numpy as np
from PIL import Image, ImageOps

from experiment_safety import check_model, exclusive_private_file, private_directory
from model_manifest import MODELS

SHAPE = (1, 3, 1024, 1024)
OUTPUT_SHAPE = (1, 1, 1024, 1024)
MAX_SOURCE_PIXELS = 36_000_000
GUARD_TOKEN = b"anabelka-supervised-v1\n"


class WorkerFailure(RuntimeError):
    def __init__(self, code, diagnostic=None):
        self.code = code
        self.diagnostic = " ".join(str(diagnostic).split())[:1024] if diagnostic else None
        super().__init__(code)


def guard_supervisor(fd, parent_pid):
    """An inherited private pipe both authorizes launch and tracks parent liveness."""
    import os
    import signal
    import stat
    import threading
    if os.getppid() != parent_pid or os.getpgrp() != os.getpid() or not stat.S_ISFIFO(os.fstat(fd).st_mode):
        raise WorkerFailure("supervisor_capability_invalid")
    if os.read(fd, len(GUARD_TOKEN)) != GUARD_TOKEN:
        raise WorkerFailure("supervisor_capability_invalid")
    def watch():
        try:
            os.read(fd, 1)
        finally:
            # A closed parent pipe (including parent SIGKILL) ends this session.
            os.killpg(os.getpgrp(), signal.SIGKILL)
    threading.Thread(target=watch, daemon=True, name="experiment-parent-watch").start()


def preflight_source(source):
    with Image.open(source) as opened:
        if opened.width * opened.height > MAX_SOURCE_PIXELS:
            raise ValueError("Source exceeds experiment pixel budget.")


def load_rgb(source):
    with Image.open(source) as opened:
        if opened.width * opened.height > MAX_SOURCE_PIXELS:
            raise ValueError("Source exceeds experiment pixel budget.")
        return ImageOps.exif_transpose(opened).convert("RGB")


def prepare_tensor(image):
    pixels = np.asarray(image.convert("RGB").resize((1024, 1024), Image.Resampling.LANCZOS), dtype=np.float32)
    pixels = pixels / max(float(pixels.max()), 1e-6)
    pixels = (pixels - np.asarray([.485, .456, .406], dtype=np.float32)) / np.asarray([.229, .224, .225], dtype=np.float32)
    tensor = np.ascontiguousarray(pixels.transpose(2, 0, 1)[None, ...], dtype=np.float32)
    if tensor.shape != SHAPE or not np.isfinite(tensor).all():
        raise ValueError("Invalid float32 NCHW input tensor.")
    return tensor


def validate_session(session):
    inputs, outputs = session.get_inputs(), session.get_outputs()
    if len(inputs) != 1 or len(outputs) != 1:
        raise ValueError("Unexpected BiRefNet input/output count.")
    if inputs[0].name != "input_image" or outputs[0].name != "output_image":
        raise ValueError("Unexpected BiRefNet input/output name.")
    for value, shape in ((inputs[0], SHAPE), (outputs[0], OUTPUT_SHAPE)):
        if value.type != "tensor(float)" or tuple(value.shape) != shape:
            raise ValueError("Unexpected BiRefNet float32 NCHW schema.")
    return inputs[0].name


def load_session(model, method="birefnet"):
    try:
        import onnxruntime as ort
    except ImportError as error:
        raise WorkerFailure("runtime_import_failed") from error
    if "CPUExecutionProvider" not in ort.get_available_providers():
        raise WorkerFailure("cpu_provider_unavailable")
    options = ort.SessionOptions()
    options.execution_mode = ort.ExecutionMode.ORT_SEQUENTIAL
    options.intra_op_num_threads = MODELS[method]["intra_op_threads"]
    options.inter_op_num_threads = 1
    options.enable_mem_pattern = False
    options.enable_cpu_mem_arena = False
    started = time.perf_counter()
    try:
        session = ort.InferenceSession(str(model), sess_options=options, providers=["CPUExecutionProvider"])
    except MemoryError:
        raise
    except Exception as error:
        raise WorkerFailure("onnx_load_failed", error) from error
    load_ms = (time.perf_counter() - started) * 1000
    if session.get_providers() != ["CPUExecutionProvider"]:
        raise WorkerFailure("cpu_provider_unavailable")
    if method == "birefnet":
        try:
            validate_session(session)
        except ValueError as error:
            raise WorkerFailure("schema_mismatch") from error
    else:
        inputs, outputs = session.get_inputs(), session.get_outputs()
        if len(inputs) != 1 or not outputs or inputs[0].type != "tensor(float)" or len(inputs[0].shape) != 4 or inputs[0].shape[1] != 3:
            raise WorkerFailure("schema_mismatch")
    return session, ort, load_ms


def check_runtime(model, method="birefnet"):
    check_model(model, method)
    session, ort, load_ms = load_session(model, method)
    schema = lambda values: [{"name": value.name, "type": value.type, "shape": list(value.shape)} for value in values]
    return {"ok": True, "method": method, "onnxruntime": str(ort.__version__), "provider": "CPUExecutionProvider", "available_providers": ort.get_available_providers(), "session_providers": session.get_providers(), "load_ms": round(load_ms, 1), "input_schema": schema(session.get_inputs()), "output_schema": schema(session.get_outputs()), "intra_op_threads": MODELS[method]["intra_op_threads"], "inter_op_threads": 1}


def alpha_from_logits(logits, size):
    logits = np.asarray(logits)
    if logits.dtype != np.float32 or logits.shape != OUTPUT_SHAPE or not np.isfinite(logits).all():
        raise ValueError("Invalid BiRefNet finite float32 NCHW output.")
    logits = logits[0, 0]
    sigmoid = np.empty_like(logits)
    positive = logits >= 0
    sigmoid[positive] = 1 / (1 + np.exp(-logits[positive]))
    negative_exp = np.exp(logits[~positive])
    sigmoid[~positive] = negative_exp / (1 + negative_exp)
    minimum, maximum = float(sigmoid.min()), float(sigmoid.max())
    if maximum <= minimum:
        raise ValueError("BiRefNet returned a constant prediction; min-max is undefined.")
    mask = ((sigmoid - minimum) / (maximum - minimum) * 255).astype(np.uint8)
    return Image.fromarray(mask, mode="L").resize(size, Image.Resampling.LANCZOS)


def save_png(image, path):
    with exclusive_private_file(path) as handle:
        image.save(handle, "PNG")


def previews(image, alpha, directory):
    rgba = image.copy().convert("RGBA")
    rgba.putalpha(alpha)
    save_png(rgba, directory / "rgba.png")
    white = Image.new("RGB", image.size, "white")
    white.paste(image, (0, 0), alpha)
    save_png(white, directory / "white.png")
    # Build checkerboard in strips to avoid two full-size NumPy coordinate arrays.
    tile = Image.new("RGB", (48, 48), (210, 210, 210))
    light = Image.new("RGB", (24, 24), (245, 245, 245))
    tile.paste(light, (0, 0))
    tile.paste(light, (24, 24))
    checker = Image.new("RGB", image.size)
    for y in range(0, image.height, 48):
        for x in range(0, image.width, 48):
            checker.paste(tile, (x, y))
    checker.paste(image, (0, 0), alpha)
    save_png(checker, directory / "checker.png")


def run_worker(source, model, directory, *, method="birefnet", include_previews=False):
    directory = private_directory(directory)
    check_model(model, method)
    preflight_source(source)
    if method == "modnet":
        # Preserve the production algorithm, checksum, resize and 2/1 ORT threads.
        spec = importlib.util.spec_from_file_location("experiment_existing_modnet", Path(__file__).resolve().parents[1] / "modnet_worker.py")
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        # Only the supervisor's fresh private run directory is passed to this worker.
        alpha_path = directory / "alpha.png"
        if alpha_path.exists() or alpha_path.is_symlink():
            raise ValueError("Alpha output already exists.")
        result = module.run_worker(source, model, alpha_path)
        alpha_path.chmod(0o600)
        image = load_rgb(source)
        with Image.open(alpha_path) as opened:
            alpha = opened.convert("L")
    else:
        image = load_rgb(source)
        started = time.perf_counter()
        tensor = prepare_tensor(image)
        preprocess_ms = (time.perf_counter() - started) * 1000
        session, ort, load_ms = load_session(model, method)
        input_name = validate_session(session)
        started = time.perf_counter()
        try:
            output = session.run(None, {input_name: tensor})
        except MemoryError:
            raise
        except Exception as error:
            raise WorkerFailure("onnx_inference_failed", error) from error
        inference_ms = (time.perf_counter() - started) * 1000
        if len(output) != 1:
            raise ValueError("Unexpected BiRefNet runtime output count.")
        alpha = alpha_from_logits(output[0], image.size)
        save_png(alpha, directory / "alpha.png")
        result = {"ok": True, "source_width": image.width, "source_height": image.height, "work_width": 1024, "work_height": 1024, "load_ms": round(load_ms, 1), "preprocess_ms": round(preprocess_ms, 1), "inference_ms": round(inference_ms, 1), "onnxruntime": str(ort.__version__), "provider": "CPUExecutionProvider"}
    pixels = np.asarray(alpha, dtype=np.uint8)
    result.update({"method": method, "profile": MODELS[method]["profile"], "intra_op_threads": MODELS[method]["intra_op_threads"], "inter_op_threads": 1, "foreground_ratio": round(float(np.mean(pixels >= 128)), 6), "soft_edge_ratio": round(float(np.mean((pixels > 12) & (pixels < 243))), 6), "alpha_min": int(pixels.min()), "alpha_max": int(pixels.max()), "alpha": "alpha.png"})
    if include_previews:
        previews(image, alpha, directory)
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path)
    parser.add_argument("--model", required=True, type=Path)
    parser.add_argument("--run-directory", type=Path)
    parser.add_argument("--method", choices=MODELS, required=True)
    parser.add_argument("--previews", action="store_true")
    parser.add_argument("--check-only", action="store_true")
    parser.add_argument("--supervisor-fd", type=int, required=True)
    parser.add_argument("--supervisor-pid", type=int, required=True)
    args = parser.parse_args()
    # Restrict every descendant file, including the unchanged MODNet worker.
    import os
    os.umask(0o077)
    try:
        guard_supervisor(args.supervisor_fd, args.supervisor_pid)
        if args.check_only:
            result = check_runtime(args.model, args.method)
        else:
            if args.source is None or args.run_directory is None:
                raise WorkerFailure("worker_arguments_invalid")
            result = run_worker(args.source, args.model, args.run_directory, method=args.method, include_previews=args.previews)
    except (Exception, MemoryError) as error:
        # No traceback or arbitrary exception message enters persistent metrics.
        code = error.code if isinstance(error, WorkerFailure) else ("memory_error" if isinstance(error, MemoryError) else "worker_operation_failed")
        result = {"ok": False, "error_type": type(error).__name__, "failure": code}
        if isinstance(error, WorkerFailure) and error.diagnostic:
            result["runtime_diagnostic"] = error.diagnostic
        import resource
        result["worker_peak_rss_mib"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024, 2)
        print(json.dumps(result))
        return 1
    import resource
    result["worker_peak_rss_mib"] = round(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024, 2)
    print(json.dumps(result, allow_nan=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
