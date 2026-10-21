#!/usr/bin/env python3
"""Compare background routes without changing source photos or product data."""

from __future__ import annotations

import argparse
from contextlib import ExitStack
import inspect
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


DEBUG_LEGEND = {
    "confident_foreground": {"color": [0, 180, 80], "label": "Source: confident foreground"},
    "confident_background": {"color": [255, 205, 0], "label": "Source: confirmed old background"},
    "ambiguous": {"color": [175, 80, 230], "label": "Source: ambiguous (still protected)"},
    "weak_contour_background": {"color": [230, 160, 0], "label": "Weak zone: confirmed source paths"},
    "weak_contour_protected": {"color": [180, 40, 180], "label": "Weak zone: protected uncertainty"},
    "weak_contours": {"color": [0, 210, 215], "label": "Detected weak source contours"},
    "unresolved_material_protected": {"color": [90, 95, 210], "label": "Protected unresolved material extent"},
    "lost_confident_foreground": {"color": [245, 45, 45], "label": "Candidate removed confident foreground"},
    "lost_ambiguous": {"color": [35, 120, 255], "label": "Candidate removed ambiguous pixels"},
    "lost_unresolved_material": {"color": [255, 95, 30], "label": "Candidate removed protected material"},
}


class AutoDebugCapture:
    """Observe existing AUTO calls, keeping only small analysis masks."""

    def __init__(self, probe, worker):
        self.probe = probe
        self.worker = worker
        self.masks = {}
        self.candidate_alpha = None
        self.error = ""
        self.classification_stage = "contours_only"

    def observe_weak_contours(self, detector, *args, **kwargs):
        result = detector(*args, **kwargs)
        try:
            contours = processor.np.asarray(result["x"] | result["y"], dtype=bool)
            if contours.ndim != 2 or not contours.size or max(contours.shape) > 512:
                raise ValueError("Weak contour maps must have a nonempty <=512 pixel analysis edge.")
            if processor.np.any(contours):
                self.masks["weak_contours"] = contours.copy()
        except Exception as error:
            self.error = type(error).__name__ + ": " + str(error)
        return result

    def observe_source_graph(self, graph, *args, **kwargs):
        result = graph(*args, **kwargs)
        if result is not None:
            try:
                weak = result[2]
                contours = processor.np.asarray(weak["weak_contours"], dtype=bool)
                if contours.ndim != 2 or not contours.size or max(contours.shape) > 512:
                    raise ValueError("Source graph maps must have a nonempty <=512 pixel analysis edge.")
                if (processor.np.any(contours) or
                    processor.np.any(weak.get("unresolved_material_protected", False))):
                    for name in ("weak_contours", "weak_contour_background", "weak_contour_protected",
                                 "unresolved_material_protected"):
                        if name not in weak:
                            continue
                        mask = processor.np.asarray(weak[name], dtype=bool)
                        if mask.shape != contours.shape:
                            raise ValueError("Source graph maps must match the weak contour map.")
                        self.masks[name] = mask.copy()
                    self.classification_stage = "source_paths_pending_material"
            except Exception as error:
                self.error = type(error).__name__ + ": " + str(error)
        return result

    def observe_probe(self, *args, **kwargs):
        # The extra graph traversal only diagnoses --debug-map. Production
        # diagnostics do not request it, and older probes keep their contract.
        if "measure_guard_effect" in inspect.signature(self.probe).parameters:
            kwargs["measure_guard_effect"] = True
        result = self.probe(*args, **kwargs)
        if result is not None:
            try:
                alpha = processor.np.asarray(result["alpha"])
                if alpha.ndim != 2 or not alpha.size or max(alpha.shape) > 512:
                    raise ValueError("Debug evidence must have a nonempty <=512 pixel analysis edge.")
                for name in ("confident_foreground", "confident_background", "ambiguous",
                             "weak_contour_background", "weak_contour_protected", "weak_contours",
                             "unresolved_material_protected"):
                    if name not in result:
                        continue
                    mask = processor.np.asarray(result[name], dtype=bool)
                    if mask.shape != alpha.shape:
                        raise ValueError("Debug classification masks must match analysis alpha.")
                    self.masks[name] = mask.copy()
                self.classification_stage = "final_probe"
            except Exception as error:
                # Observational export must never alter the production decision.
                self.masks.clear()
                self.error = type(error).__name__ + ": " + str(error)
        return result

    def observe_worker(self, *args, **kwargs):
        result = self.worker(*args, **kwargs)
        if result is not None and self.masks:
            try:
                height, width = next(iter(self.masks.values())).shape
                self.candidate_alpha = processor.np.asarray(result[0].resize(
                    (width, height), Image.Resampling.BILINEAR).getchannel("A")).copy()
            except Exception as error:
                self.error = type(error).__name__ + ": " + str(error)
        return result

    def export(self, source: Image.Image, output: Path, selection: dict) -> dict:
        record = {"enabled": True, "legend": DEBUG_LEGEND,
                  "classification_domain": "opaque GrabCut pixels; unclassified source is unchanged",
                  "classification_stage": self.classification_stage,
                  "fallback_reason": selection.get("fallback_reason", "probe_not_run")}
        if self.error:
            return {**record, "status": "error", "error": self.error}
        if not self.masks:
            return {**record, "status": "skipped",
                    "reason": selection.get("fallback_reason", "probe_not_run")}
        try:
            height, width = next(iter(self.masks.values())).shape
            # This source copy is bounded by the production probe resolution.
            pixels = processor.np.asarray(source.resize(
                (width, height), Image.Resampling.BILINEAR).convert("RGB")).copy()
            layers = dict(self.masks)
            if self.candidate_alpha is not None:
                removed = self.candidate_alpha < 128
                for protected, loss in (("confident_foreground", "lost_confident_foreground"),
                                        ("ambiguous", "lost_ambiguous"),
                                        ("unresolved_material_protected", "lost_unresolved_material")):
                    if protected in self.masks:
                        layers[loss] = removed & self.masks[protected]
            for name, mask in layers.items():
                color = processor.np.asarray(DEBUG_LEGEND[name]["color"], dtype=float)
                pixels[mask] = processor.np.rint(pixels[mask] * .35 + color * .65).astype("uint8")
            overlay = Image.fromarray(pixels)
            footer_height = 24 + len(DEBUG_LEGEND) * 16
            canvas = Image.new("RGB", (max(width, 330), height + footer_height), "white")
            canvas.paste(overlay, (0, 0))
            draw = ImageDraw.Draw(canvas)
            title = ("Source paths; material checks pending" if
                     self.classification_stage == "source_paths_pending_material" else
                     "Weak contours; classification unavailable" if
                     self.classification_stage == "contours_only" else
                     "AUTO classes; loss is not background evidence")
            draw.text((6, height + 3), title, fill="black")
            for index, entry in enumerate(DEBUG_LEGEND.values()):
                y = height + 21 + index * 16
                draw.rectangle((6, y, 15, y + 10), fill=tuple(entry["color"]))
                draw.text((21, y - 1), entry["label"], fill="black")
            # The complete debug artifact also has a maximum 512-pixel edge.
            canvas.thumbnail((512, 512), Image.Resampling.LANCZOS)
            path = output / "classification-map.png"
            canvas.save(path, "PNG")
            return {**record, "status": "saved", "path": str(path),
                    "analysis_size": [width, height], "map_size": list(canvas.size),
                    "candidate_available": self.candidate_alpha is not None,
                    "counts": {name: int(processor.np.count_nonzero(mask))
                               for name, mask in layers.items()}}
        except Exception as error:
            return {**record, "status": "error", "error": type(error).__name__ + ": " + str(error)}


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
    *,
    timings: dict | None = None,
) -> tuple[Image.Image, dict]:
    timings = {} if timings is None else timings
    # Each stage has a separate image copy; none changes the source on disk.
    work = processor.measured_call(timings, "source_copy", image.copy)
    if stage == "original":
        return processor.measured_call(timings, "normalization", processor.normalized_master,
            work,
            processor.BACKGROUND_PROFILE_ORIGINAL,
            source_path=None,
        )
    if stage == "grabcut":
        return processor.measured_call(timings, "normalization", processor.normalized_master,
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
            return processor.measured_call(timings, "normalization", processor.normalized_master,
                work,
                background_profile,
                source_path=source,
            )
    return processor.measured_call(timings, "normalization", processor.normalized_master,
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


def compare_source(source: Path, output: Path, background_profile: str, *, debug_map: bool = False) -> bool:
    compare_started = time.perf_counter()
    timings = {}
    before = processor.measured_call(timings, "hash_source_before", processor.sha256_file, source)
    image = processor.measured_call(timings, "decode", processor.normalized_image, source)
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
        "timings_ms": timings,
    }
    previews = {}
    succeeded = True
    print("\nSOURCE: " + source.name)
    for stage, label in STAGES:
        started = time.perf_counter()
        stage_timings = {}
        record = {"timings_ms": stage_timings}
        capture = None
        try:
            with ExitStack() as stack:
                if debug_map and stage == "auto":
                    capture = AutoDebugCapture(processor.grabcut_fallback_evidence,
                                               processor.run_modnet_worker)
                    stack.enter_context(patch.object(processor, "grabcut_fallback_evidence",
                                                     side_effect=capture.observe_probe))
                    weak_detector = processor.weak_source_contour_uncertainty
                    stack.enter_context(patch.object(processor, "weak_source_contour_uncertainty",
                        side_effect=lambda *args, **kwargs: capture.observe_weak_contours(
                            weak_detector, *args, **kwargs)))
                    source_graph = processor.source_connected_background
                    stack.enter_context(patch.object(processor, "source_connected_background",
                        side_effect=lambda *args, **kwargs: capture.observe_source_graph(
                            source_graph, *args, **kwargs)))
                    worker = stack.enter_context(patch.object(processor, "run_modnet_worker",
                                                             side_effect=capture.observe_worker))
                else:
                    worker = stack.enter_context(patch.object(processor, "run_modnet_worker",
                                                             wraps=processor.run_modnet_worker))
                try:
                    master, diagnostics = render_stage(stage, image, source, background_profile,
                                                       timings=stage_timings)
                finally:
                    record["modnet_worker_calls"] = worker.call_count
            record["normalization"] = diagnostics
            target = output / (stage + ".webp")
            processor.measured_call(stage_timings, "save_preview", processor.save_webp, master, target, 92)
            record["preview"] = str(target)
            record["output_size"] = list(master.size)
            # Keep only a small comparison tile between stages. Full-resolution
            # masters are already saved and need not accumulate in Android RAM.
            previews[stage] = processor.measured_call(stage_timings, "thumbnail_resize", master.resize,
                processor.THUMB_SIZE,
                Image.Resampling.LANCZOS,
            )
        except Exception as error:
            record["error"] = type(error).__name__ + ": " + str(error)
            succeeded = False
        elapsed = time.perf_counter() - started
        record["elapsed_seconds"] = round(elapsed, 3)
        stage_timings["total"] = round(elapsed * 1000, 3)
        # Per-image diagnostics must not inherit another HTTP request's legacy
        # health error. The observed call count remains a useful CLI cross-check.
        selection = record.get("normalization", {}).get("mask_selection", {})
        if capture is not None:
            report["debug_map"] = processor.measured_call(timings, "debug_map_export",
                                                          capture.export, image, output, selection)
            print("  DEBUG_MAP: " + report["debug_map"]["status"]
                  + " | " + str(report["debug_map"].get("path", report["debug_map"].get("reason", ""))))
        record["worker_error"] = selection.get("worker_error", "")
        report["stages"][stage] = record
        print(" | ".join(stage_caption(label, record)))
        print("  TIMINGS_MS: " + ", ".join(name + "=" + str(value)
              for name, value in sorted(stage_timings.items())))
        if selection:
            print("  FALLBACK: " + str(selection.get("fallback_status", "unknown"))
                  + " / " + str(selection.get("fallback_reason", "unknown")))
            probe = selection.get("probe", {})
            if probe:
                print("  PROBE: stage=" + str(probe.get("stage", "unknown"))
                      + " | reason=" + str(probe.get("reason", "unknown"))
                      + " | minimum_required=" + str(probe.get("minimum_required", "n/a")))
            if probe.get("counts"):
                print("  PROBE_COUNTS: " + ", ".join(
                    str(key) + "=" + str(value)
                    for key, value in sorted(probe["counts"].items())
                ))
            if "texture_threshold" in probe or "texture_background_p90" in probe:
                print("  TEXTURE: threshold=" + str(probe.get("texture_threshold", "n/a"))
                      + " | backdrop_p90=" + str(probe.get("texture_background_p90", "n/a"))
                      + " | backdrop_max=" + str(probe.get("texture_background_max", "n/a"))
                      + " | density_threshold=" + str(probe.get("texture_density_threshold", "n/a"))
                      + " | measurement_radius=" + str(probe.get("texture_radius", "n/a"))
                      + " | guard_radius=" + str(probe.get("texture_guard_radius", "n/a")))
        if record.get("worker_error"):
            print("  WORKER_ERROR: " + record["worker_error"])
        if record.get("error"):
            print("  ERROR: " + record["error"])
        if record.get("preview"):
            print("  SAVED: " + record["preview"])
    after = processor.measured_call(timings, "hash_source_after", processor.sha256_file, source)
    report["source_sha256_after"] = after
    report["source_unchanged"] = before == after
    report["visual_acceptance"] = "pending manual review of real photographs"
    comparison_path = output / "comparison.jpg"
    with processor.timed_stage(timings, "comparison_save"):
        comparison_image(previews, report["stages"]).save(comparison_path, "JPEG", quality=95)
    # Finish the measured run before serializing its own timing record.
    timings["total"] = round((time.perf_counter() - compare_started) * 1000, 3)
    report_path = output / "diagnostics.json"
    report_path.write_text(
        json.dumps(report, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print("SOURCE_UNCHANGED: " + str(report["source_unchanged"]))
    print("COMPARISON: " + str(comparison_path))
    print("DIAGNOSTICS: " + str(report_path))
    return succeeded and report["source_unchanged"]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--debug-map",
        action="store_true",
        help="Observe AUTO source classifications and export a <=512 pixel debug map; no extra inference.",
    )
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
            passed = compare_source(source, run_root / source.stem, args.background_profile,
                                    debug_map=args.debug_map)
            succeeded = passed and succeeded
        except (ValueError, OSError) as error:
            print("ERROR: " + source.name + ": " + str(error), file=sys.stderr)
            succeeded = False
    return 0 if succeeded else 1


if __name__ == "__main__":
    raise SystemExit(main())
