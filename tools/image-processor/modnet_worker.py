#!/usr/bin/env python3

from __future__ import annotations

import argparse
import gc
import hashlib
import json
import time
import warnings
from pathlib import Path

import numpy as np
from PIL import Image, ImageOps


MODEL_BYTES = 25969398

MODEL_SHA256 = (
    "5069a5e306b9f5e9f4f2b0360264c9f8"
    "ea13b257c7c39943c7cf6a2ec3a102ae"
)

INPUT_SIZE = 512


def sha256_file(
    path: Path,
) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as handle:
        for chunk in iter(
            lambda: handle.read(
                1024 * 1024
            ),
            b"",
        ):
            digest.update(chunk)

    return digest.hexdigest()


def modnet_input_size(
    width: int,
    height: int,
) -> tuple[int, int]:
    if width <= 0 or height <= 0:
        raise ValueError(
            "Invalid image size."
        )

    if (
        max(width, height) < INPUT_SIZE
        or min(width, height) > INPUT_SIZE
    ):
        if width >= height:
            work_height = INPUT_SIZE
            work_width = int(
                width
                / height
                * INPUT_SIZE
            )
        else:
            work_width = INPUT_SIZE
            work_height = int(
                height
                / width
                * INPUT_SIZE
            )
    else:
        work_width = width
        work_height = height

    work_width -= work_width % 32
    work_height -= work_height % 32

    return (
        max(32, work_width),
        max(32, work_height),
    )


def normalize_rgb(
    pixels: np.ndarray,
) -> np.ndarray:
    tensor = (
        pixels.astype(
            np.float32
        )
        / 255.0
    )

    return (
        tensor - 0.5
    ) / 0.5


def run_worker(
    source: Path,
    model: Path,
    alpha_output: Path,
) -> dict:
    if not source.is_file():
        raise RuntimeError(
            "Source image is missing."
        )

    if not model.is_file():
        raise RuntimeError(
            "MODNet model is missing."
        )

    if model.stat().st_size != MODEL_BYTES:
        raise RuntimeError(
            "MODNet model size mismatch."
        )

    if sha256_file(model) != MODEL_SHA256:
        raise RuntimeError(
            "MODNet model SHA-256 mismatch."
        )

    with Image.open(source) as opened:
        image = (
            ImageOps
            .exif_transpose(opened)
            .convert("RGB")
        )

    source_width, source_height = (
        image.size
    )

    work_width, work_height = (
        modnet_input_size(
            source_width,
            source_height,
        )
    )

    resized = image.resize(
        (
            work_width,
            work_height,
        ),
        Image.Resampling.BILINEAR,
    )

    pixels = np.asarray(
        resized,
        dtype=np.uint8,
    )

    tensor = normalize_rgb(
        pixels
    )

    tensor = np.transpose(
        tensor,
        (2, 0, 1),
    )

    tensor = np.expand_dims(
        tensor,
        axis=0,
    )

    # Do not import ORT until all cheap validation
    # and preprocessing has finished.
    warnings.filterwarnings(
        "ignore",
        message="Unsupported platform",
    )

    import onnxruntime as ort

    providers = (
        ort.get_available_providers()
    )

    if (
        "CPUExecutionProvider"
        not in providers
    ):
        raise RuntimeError(
            "CPUExecutionProvider "
            "is unavailable."
        )

    options = ort.SessionOptions()

    # Keep the short-lived worker conservative on Android.
    options.execution_mode = (
        ort.ExecutionMode.ORT_SEQUENTIAL
    )
    options.intra_op_num_threads = 2
    options.inter_op_num_threads = 1
    options.enable_mem_pattern = False
    options.enable_cpu_mem_arena = False

    started = time.perf_counter()

    session = ort.InferenceSession(
        str(model),
        sess_options=options,
        providers=[
            "CPUExecutionProvider",
        ],
    )

    load_ms = (
        time.perf_counter()
        - started
    ) * 1000.0

    inputs = session.get_inputs()

    if len(inputs) != 1:
        raise RuntimeError(
            "Unexpected MODNet input count."
        )

    started = time.perf_counter()

    outputs = session.run(
        None,
        {
            inputs[0].name: tensor,
        },
    )

    inference_ms = (
        time.perf_counter()
        - started
    ) * 1000.0

    if not outputs:
        raise RuntimeError(
            "MODNet returned no output."
        )

    matte = np.asarray(
        outputs[0],
        dtype=np.float32,
    ).squeeze()

    if (
        matte.ndim != 2
        or not np.all(
            np.isfinite(matte)
        )
    ):
        raise RuntimeError(
            "Invalid MODNet matte."
        )

    matte = np.clip(
        matte,
        0.0,
        1.0,
    )

    foreground_ratio = float(
        np.mean(
            matte >= 0.5
        )
    )

    soft_ratio = float(
        np.mean(
            (matte > 0.05)
            & (matte < 0.95)
        )
    )

    small_alpha = Image.fromarray(
        np.rint(
            matte * 255.0
        ).astype(np.uint8),
        mode="L",
    )

    full_alpha = small_alpha.resize(
        (
            source_width,
            source_height,
        ),
        Image.Resampling.BILINEAR,
    )

    alpha_output.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    full_alpha.save(
        alpha_output,
        "PNG",
    )

    result = {
        "ok": True,
        "source_width": source_width,
        "source_height": source_height,
        "work_width": work_width,
        "work_height": work_height,
        "load_ms": round(
            load_ms,
            1,
        ),
        "inference_ms": round(
            inference_ms,
            1,
        ),
        "foreground_ratio": round(
            foreground_ratio,
            4,
        ),
        "soft_edge_ratio": round(
            soft_ratio,
            4,
        ),
        "onnxruntime": str(
            ort.__version__
        ),
        "provider": (
            "CPUExecutionProvider"
        ),
        "alpha": str(
            alpha_output.resolve()
        ),
    }

    # Explicit cleanup is mostly documentary:
    # the operating system reclaims everything
    # when this worker process exits.
    del session
    del outputs
    del matte
    del tensor

    gc.collect()

    return result


def main() -> None:
    parser = argparse.ArgumentParser()

    parser.add_argument(
        "--source",
        required=True,
    )

    parser.add_argument(
        "--model",
        required=True,
    )

    parser.add_argument(
        "--alpha",
        required=True,
    )

    args = parser.parse_args()

    try:
        result = run_worker(
            Path(args.source),
            Path(args.model),
            Path(args.alpha),
        )
    except Exception as error:
        print(
            json.dumps(
                {
                    "ok": False,
                    "error_type":
                        type(error).__name__,
                    "error": str(error),
                },
                ensure_ascii=False,
            )
        )
        raise SystemExit(1)

    print(
        json.dumps(
            result,
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
