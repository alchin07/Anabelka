#!/usr/bin/env python3
"""Compare background routes without changing source photos or product data."""

from __future__ import annotations

import argparse
import json
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

from PIL import Image, ImageDraw

import server as processor


DEFAULT_SOURCES = (
    "20260904-111401-94806794d9042bb1.jpg",
    "20260904-082048-f82e27bc5a56b2b1.jpg",
)

STAGES = (
    ("original", "Original+Canvas"),
    ("grabcut", "Forced GrabCut"),
    ("modnet", "Forced MODNet"),
    ("auto", "Automatic"),
)


def checked_source(value: str) -> Path:
    """Allow one upload filename or its normal project-relative source path."""
    path = Path(value)
    if path.is_absolute():
        raise ValueError("Use an upload filename or uploads/products/<filename>.")
    if len(path.parts) == 1:
        value = "uploads/products/" + value
    try:
        return processor.safe_source_path(value)
    except (ValueError, OSError) as error:
        raise ValueError(value + ": " + str(error)) from error


def render_stage(
    stage: str,
    image: Image.Image,
    source: Path,
    background_profile: str,
) -> tuple[Image.Image, dict]:
    # Each stage has a separate image copy; none changes the source on disk.
    work = image.copy()
    if stage == "original":
        return processor.normalized_master(
            work,
            processor.BACKGROUND_PROFILE_ORIGINAL,
            source_path=None,
        )
    if stage == "grabcut":
        return processor.normalized_master(
            work,
            background_profile,
            source_path=None,
        )
    if stage == "modnet":
        # Force the existing primary route only in this diagnostic process.
        # A failed worker still uses the production-safe GrabCut fallback.
        with patch.object(
            processor,
            "complex_background_prefers_modnet",
            return_value=True,
        ):
            return processor.normalized_master(
                work,
                background_profile,
                source_path=source,
            )
    return processor.normalized_master(
        work,
        background_profile,
        source_path=source,
    )


def stage_caption(label: str, record: dict) -> list[str]:
    diagnostics = record.get("normalization", {})
    method = diagnostics.get("mask_method", "none")
    ratio = diagnostics.get("mask_foreground_ratio", "n/a")
    crop = diagnostics.get("crop_strategy", "standard-canvas")
    return [
        label + " | " + str(method),
        "mask=" + str(ratio) + " | " + str(crop),
        "elapsed=" + str(record["elapsed_seconds"]) + "s | workers="
        + str(record.get("modnet_worker_calls", 0)),
    ]


def comparison_image(previews: dict[str, Image.Image], stages: dict) -> Image.Image:
    width, height = processor.THUMB_SIZE
    caption_height = 64
    canvas = Image.new(
        "RGB",
        (width * len(STAGES), height + caption_height),
        "white",
    )
    draw = ImageDraw.Draw(canvas)
    for index, (stage, label) in enumerate(STAGES):
        left = index * width
        if stage in previews:
            preview = previews[stage].resize(
                (width, height),
                Image.Resampling.LANCZOS,
            )
            canvas.paste(preview, (left, caption_height))
        else:
            draw.text((left + 8, caption_height + 8), "Stage failed", fill="red")
        for line, text in enumerate(stage_caption(label, stages[stage])):
            draw.text((left + 8, 6 + line * 17), text, fill="black")
        if index:
            draw.line((left, 0, left, canvas.height), fill=(210, 210, 210))
    return canvas


def compare_source(source: Path, output: Path, background_profile: str) -> bool:
    before = processor.sha256_file(source)
    image = processor.normalized_image(source)
    output.mkdir(parents=True, exist_ok=False)
    report = {
        "source": processor.project_relative(source),
        "source_size": list(image.size),
        "source_sha256_before": before,
        "background_profile": background_profile,
        "processor_version": processor.VERSION,
        "profile": processor.PROFILE,
        "python_version": sys.version.split()[0],
        "pillow_version": processor.PIL.__version__,
        "opencv_version": processor.cv2.__version__,
        "numpy_version": processor.np.__version__,
        "modnet_worker_ready": processor.modnet_worker_ready(),
        "modnet_execution": "separate-process",
        "modnet_timeout_seconds": processor.MODNET_WORKER_TIMEOUT,
        "stages": {},
    }
    previews = {}
    succeeded = True
    print("\nSOURCE: " + source.name)
    for stage, label in STAGES:
        processor._modnet_worker_error = ""
        started = time.perf_counter()
        record = {}
        try:
            with patch.object(processor, "run_modnet_worker", wraps=processor.run_modnet_worker) as worker:
                try:
                    master, diagnostics = render_stage(stage, image, source, background_profile)
                finally:
                    record["modnet_worker_calls"] = worker.call_count
            record["normalization"] = diagnostics
            target = output / (stage + ".webp")
            processor.save_webp(master, target, 92)
            record["preview"] = str(target)
            record["output_size"] = list(master.size)
            # Keep only a small comparison tile between stages. Full-resolution
            # masters are already saved and need not accumulate in Android RAM.
            previews[stage] = master.resize(
                processor.THUMB_SIZE,
                Image.Resampling.LANCZOS,
            )
        except Exception as error:
            record["error"] = type(error).__name__ + ": " + str(error)
            succeeded = False
        record["elapsed_seconds"] = round(time.perf_counter() - started, 3)
        record["worker_error"] = processor._modnet_worker_error
        report["stages"][stage] = record
        print(" | ".join(stage_caption(label, record)))
        if record.get("worker_error"):
            print("  WORKER_ERROR: " + record["worker_error"])
        if record.get("error"):
            print("  ERROR: " + record["error"])
        if record.get("preview"):
            print("  SAVED: " + record["preview"])
    after = processor.sha256_file(source)
    report["source_sha256_after"] = after
    report["source_unchanged"] = before == after
    report["visual_acceptance"] = "pending manual review of real photographs"
    report_path = output / "diagnostics.json"
    report_path.write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    comparison_path = output / "comparison.jpg"
    comparison_image(previews, report["stages"]).save(
        comparison_path,
        "JPEG",
        quality=95,
    )
    print("SOURCE_UNCHANGED: " + str(report["source_unchanged"]))
    print("COMPARISON: " + str(comparison_path))
    print("DIAGNOSTICS: " + str(report_path))
    return succeeded and report["source_unchanged"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "sources",
        nargs="*",
        help="Upload filenames or uploads/products/<filename>; defaults to both controls.",
    )
    parser.add_argument(
        "--background-profile",
        choices=(processor.BACKGROUND_PROFILE_STUDIO, processor.BACKGROUND_PROFILE_BRAND),
        default=processor.BACKGROUND_PROFILE_STUDIO,
    )
    args = parser.parse_args(argv)
    try:
        sources = [checked_source(value) for value in args.sources or DEFAULT_SOURCES]
        if len({source.stem for source in sources}) != len(sources):
            raise ValueError("Choose source filenames with distinct stems only once.")
    except (ValueError, OSError) as error:
        parser.error(str(error))
    if not processor.modnet_worker_ready():
        print(
            "WARNING: MODNet worker/model are unavailable. Forced MODNet may fall back "
            "to GrabCut; inspect actual mask_method and worker_error in diagnostics.",
            file=sys.stderr,
        )
    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S-%f")
    run_root = processor.WORK_ROOT / "modnet-fallback-check" / timestamp
    run_root.mkdir(parents=True, exist_ok=False)
    succeeded = True
    for source in sources:
        try:
            passed = compare_source(source, run_root / source.stem, args.background_profile)
            succeeded = passed and succeeded
        except (ValueError, OSError) as error:
            print("ERROR: " + source.name + ": " + str(error), file=sys.stderr)
            succeeded = False
    return 0 if succeeded else 1


if __name__ == "__main__":
    raise SystemExit(main())
