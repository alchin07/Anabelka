import contextlib
import importlib.util
import io
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image, ImageDraw


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location(
    "anabelka_modnet_fallback_cli_test",
    PROCESSOR_DIR / "check_modnet_fallback.py",
)
cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cli)
processor = cli.processor


class ModnetFallbackCliTests(unittest.TestCase):
    def compare_fixture(self, timeout=False, foreign_error=False, debug_map=False,
                        classified_probe=False, map_export_error=False):
        # Exercise the real CLI, GrabCut, bridge, geometry and file writes.
        # Only detector/inference dependencies are replaced with fixed inputs.
        image = Image.new("RGB", (120, 160), (145, 107, 78))
        draw = ImageDraw.Draw(image)
        draw.rectangle((40, 30, 79, 149), fill=(225, 170, 140))
        draw.rectangle((45, 10, 74, 30), fill=(50, 25, 20))
        draw.rectangle((30, 50, 39, 109), fill=(225, 170, 140))
        draw.rectangle((40, 70, 79, 99), fill=(245, 240, 230))
        for x in range(42, 78, 3):
            for y in range(72, 98, 3):
                draw.point((x, y), fill=(70, 45, 55))
        alpha = Image.new("L", image.size, 0)
        draw_alpha = ImageDraw.Draw(alpha)
        for rectangle in ((40, 30, 79, 149), (45, 10, 74, 30), (30, 50, 39, 109)):
            draw_alpha.rectangle(rectangle, fill=255)
        detection = ((30, 10, 50, 140), .95, (60., 80.), (60., 40.),
                     (60., 120.), 80., 25.)
        operations = {"grabcut": 0, "probe": 0}
        original_grabcut = processor.build_subject_rgba
        original_probe = processor.grabcut_fallback_evidence

        def observed_grabcut(*args, **kwargs):
            operations["grabcut"] += 1
            return original_grabcut(*args, **kwargs)

        def observed_probe(*args, **kwargs):
            operations["probe"] += 1
            target = classified_evidence if classified_probe else original_probe
            return target(*args, **kwargs)

        def classified_evidence(_image, _subject, _bbox, *, diagnostics=None):
            # The CLI observes production classifications; their inference is
            # covered separately by routing tests. This independent fixed probe
            # exercises capture/export with the real comparison and renderer.
            foreground = processor.np.asarray(alpha) >= 192
            residual = processor.np.zeros(foreground.shape, dtype=bool)
            residual[40:110, 8:25] = True
            opaque = processor.np.asarray(alpha).copy()
            opaque[residual] = 255
            ambiguous = processor.np.zeros_like(residual)
            ambiguous[15:25, 45:50] = True
            confident = foreground & ~ambiguous
            if diagnostics is not None:
                diagnostics.update(stage="ready", reason="residual_confirmed",
                                   counts={"confident_foreground": int(confident.sum()),
                                           "confident_background": int(residual.sum()),
                                           "ambiguous": int(ambiguous.sum())})
            return {"source_size": _image.size, "alpha": opaque, "residual": residual,
                    "protected": foreground, "background_like": residual,
                    "confident_foreground": confident,
                    "confident_background": residual, "ambiguous": ambiguous}

        def detector(_image):
            if foreign_error:
                # Simulate an unrelated request changing legacy health state.
                processor._modnet_worker_error = "another-request-error"
            return detection

        def inference(command, **kwargs):
            if timeout:
                raise subprocess.TimeoutExpired(command, processor.MODNET_WORKER_TIMEOUT)
            output = Path(command[command.index("--alpha") + 1])
            alpha.save(output, "PNG")
            return subprocess.CompletedProcess(
                command,
                0,
                stdout=json.dumps({"ok": True, "foreground_ratio": float(
                    processor.np.mean(processor.np.asarray(alpha) >= 128)),
                                   "inference_ms": 5., "provider": "CPUExecutionProvider",
                                   "onnxruntime": "test-inference"}),
                stderr="",
            )

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            uploads = root / "uploads" / "products"
            uploads.mkdir(parents=True)
            source = uploads / "control.png"
            image.save(source, "PNG")
            original = source.read_bytes()
            captured = io.StringIO()
            with (
                contextlib.ExitStack() as stack,
                patch.object(processor, "PROJECT_ROOT", root),
                patch.object(processor, "SOURCE_ROOT", uploads),
                patch.object(processor, "WORK_ROOT", root / "storage" / "image-processor"),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor, "_modnet_worker_error", "stale-health-error"),
                patch.object(processor, "detect_mediapipe_person_bbox", side_effect=detector),
                patch.object(processor, "build_subject_rgba", side_effect=observed_grabcut),
                patch.object(processor, "grabcut_fallback_evidence", side_effect=observed_probe),
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor.subprocess, "run", side_effect=inference),
                contextlib.redirect_stdout(captured),
            ):
                if map_export_error:
                    stack.enter_context(patch.object(Image.Image, "save",
                        autospec=True, side_effect=self.map_save_failure))
                processor.cv2.setRNGSeed(0)
                result = cli.main([source.name] + (["--debug-map"] if debug_map else [])) == 0
            reports = list((root / "storage" / "image-processor" / "modnet-fallback-check").glob(
                "*/control/diagnostics.json"))
            self.assertEqual(len(reports), 1)
            output = reports[0].parent
            report = json.loads((output / "diagnostics.json").read_text(encoding="utf-8"))
            sizes = {}
            for preview in output.glob("*.webp"):
                with Image.open(preview) as opened:
                    sizes[preview.name] = opened.size
            with Image.open(output / "comparison.jpg") as opened:
                comparison_size = opened.size
            artifacts = {path.name: path.read_bytes() for path in output.iterdir()
                         if path.suffix in (".webp", ".png")}
            artifacts["observed_operations"] = operations
            return (result, report, captured.getvalue(), original, source.read_bytes(),
                    sizes, comparison_size, artifacts)

    def test_cli_keeps_probe_reasons_counts_and_real_worker_calls(self):
        result, report, output, *_ = self.compare_fixture()
        self.assertTrue(result)
        automatic = report["stages"]["auto"]
        self.assertIn("mask_selection", automatic["normalization"])
        selection = automatic["normalization"]["mask_selection"]
        self.assertEqual(automatic["normalization"]["mask_method"], "opencv-grabcut")
        self.assertEqual(selection["fallback_status"], "skipped")
        self.assertTrue(selection["fallback_reason"])
        self.assertIn("counts", selection["probe"])
        self.assertTrue(selection["probe"]["counts"])
        for stage, expected_count in (("original", 0), ("grabcut", 0), ("modnet", 1), ("auto", 0)):
            record = report["stages"][stage]
            self.assertEqual(record["modnet_worker_calls"], expected_count)
            if "mask_selection" in record["normalization"]:
                self.assertEqual(record["normalization"]["mask_selection"]["modnet_worker_calls"], expected_count)
        self.assertEqual(report["stages"]["modnet"]["normalization"]["mask_method"], "modnet")
        self.assertIn("FALLBACK: ", output)
        self.assertIn("PROBE: stage=", output)
        self.assertIn(" | minimum_required=", output)
        self.assertIn("PROBE_COUNTS: ", output)

    def test_cli_timeout_diagnostic_is_local_to_its_stage(self):
        result, report, output, *_ = self.compare_fixture(timeout=True, foreign_error=True)
        self.assertTrue(result)  # A usable GrabCut result is still a success.
        forced = report["stages"]["modnet"]
        self.assertEqual(forced["normalization"]["mask_method"], "opencv-grabcut")
        self.assertEqual(forced["worker_error"], "worker-timeout")
        for stage in ("original", "grabcut", "auto"):
            self.assertEqual(report["stages"][stage]["worker_error"], "", stage)
        self.assertNotIn("another-request-error", json.dumps(report))
        self.assertIn("WORKER_ERROR: worker-timeout", output)

    def test_cli_writes_all_previews_without_changing_source(self):
        result, report, _, before, after, sizes, comparison_size, _ = self.compare_fixture()
        self.assertTrue(result)
        self.assertEqual(before, after)
        self.assertTrue(report["source_unchanged"])
        self.assertEqual(report["source_sha256_before"], report["source_sha256_after"])
        self.assertEqual(set(sizes), {"original.webp", "grabcut.webp", "modnet.webp", "auto.webp"})
        self.assertTrue(all(size == (1200, 1800) for size in sizes.values()))
        self.assertEqual(comparison_size, (1280, 544))

    def test_debug_map_observes_auto_without_changing_processing_or_worker_calls(self):
        plain = self.compare_fixture(classified_probe=True)
        mapped = self.compare_fixture(classified_probe=True, debug_map=True)
        self.assertTrue(plain[0])
        self.assertTrue(mapped[0])
        plain_report, mapped_report = plain[1], mapped[1]
        self.assertNotIn("debug_map", plain_report)
        exported = mapped_report["debug_map"]
        self.assertEqual(exported["status"], "saved")
        self.assertEqual(Path(exported["path"]).name, "classification-map.png")
        self.assertEqual(exported["analysis_size"], [120, 160])
        self.assertTrue(exported["candidate_available"])
        self.assertIn("lost_confident_foreground", exported["legend"])
        self.assertIn("lost_ambiguous", exported["legend"])
        self.assertLessEqual(max(exported["map_size"]), 512)
        self.assertIn("classification-map.png", mapped[7])
        for stage, _ in cli.STAGES:
            first, second = plain_report["stages"][stage], mapped_report["stages"][stage]
            self.assertEqual(first["normalization"], second["normalization"], stage)
            self.assertEqual(first["modnet_worker_calls"], second["modnet_worker_calls"], stage)
            self.assertEqual(plain[7][stage + ".webp"], mapped[7][stage + ".webp"], stage)
        self.assertEqual(mapped[3], mapped[4])
        self.assertEqual(plain_report["source_sha256_before"], mapped_report["source_sha256_after"])
        self.assertEqual(plain[7]["observed_operations"], mapped[7]["observed_operations"])
        self.assertEqual(mapped[7]["observed_operations"], {"grabcut": 2, "probe": 1})

    def test_debug_map_skips_when_auto_probe_has_no_residual_evidence(self):
        result, report, _, before, after, *rest = self.compare_fixture(debug_map=True)
        self.assertTrue(result)
        self.assertEqual(before, after)
        self.assertEqual(report["debug_map"]["status"], "skipped")
        reason = report["stages"]["auto"]["normalization"]["mask_selection"]["fallback_reason"]
        self.assertEqual(report["debug_map"]["reason"], reason)
        self.assertNotIn("classification-map.png", rest[-1])

    def test_debug_map_records_export_failure_without_discarding_previews(self):
        plain = self.compare_fixture(classified_probe=True)
        mapped = self.compare_fixture(classified_probe=True, debug_map=True, map_export_error=True)
        self.assertTrue(mapped[0])
        self.assertEqual(mapped[1]["debug_map"]["status"], "error")
        self.assertIn("synthetic-map-export-error", mapped[1]["debug_map"]["error"])
        self.assertEqual(mapped[3], mapped[4])
        for stage, _ in cli.STAGES:
            self.assertEqual(plain[7][stage + ".webp"], mapped[7][stage + ".webp"], stage)

    def test_debug_map_bounds_source_conversion_before_building_overlay(self):
        shape = (128, 256)
        confident = processor.np.ones(shape, dtype=bool)
        empty = processor.np.zeros(shape, dtype=bool)
        evidence = {"alpha": processor.np.full(shape, 255, dtype="uint8"),
                    "confident_foreground": confident,
                    "confident_background": empty, "ambiguous": empty}
        capture = cli.AutoDebugCapture(lambda *args: evidence, lambda *args: None)
        self.assertIs(capture.observe_probe(None), evidence)
        large_source = Image.new("RGB", (2048, 1024), "gray")
        original_convert = Image.Image.convert

        def bounded_convert(image, *args, **kwargs):
            if max(image.size) > 512:
                raise RuntimeError("full-size debug source conversion")
            return original_convert(image, *args, **kwargs)

        with tempfile.TemporaryDirectory() as temporary, patch.object(
            Image.Image, "convert", autospec=True, side_effect=bounded_convert,
        ):
            exported = capture.export(large_source, Path(temporary), {})
        self.assertEqual(exported["status"], "saved")
        self.assertEqual(exported["analysis_size"], [256, 128])

    def test_debug_map_shows_weak_contours_even_when_source_probe_declines(self):
        shape = (64, 48)
        weak_x = processor.np.zeros(shape, dtype=bool)
        weak_x[12:44, 26] = True
        weak_y = processor.np.zeros(shape, dtype=bool)
        weak_y[46, 4:36] = True
        result = {"x": weak_x, "y": weak_y, "calibrated": True}
        capture = cli.AutoDebugCapture(lambda *args: None, lambda *args: None)
        calls = []

        def observed(*args):
            calls.append(1)
            return result

        self.assertIs(capture.observe_weak_contours(observed, None), result)
        self.assertIsNone(capture.observe_probe(None))
        with tempfile.TemporaryDirectory() as temporary:
            exported = capture.export(Image.new("RGB", (48, 64), "gray"),
                                      Path(temporary), {"fallback_reason": "insufficient_residual"})
        self.assertEqual(calls, [1])
        self.assertEqual(exported["status"], "saved")
        self.assertFalse(exported["candidate_available"])
        self.assertEqual(exported["counts"]["weak_contours"], 64)
        self.assertIn("weak_contour_protected", exported["legend"])
        self.assertIn("weak_contour_background", exported["legend"])
        self.assertEqual(exported["fallback_reason"], "insufficient_residual")

    def test_debug_map_exports_local_weak_classifications_without_another_worker(self):
        shape = (64, 48)
        empty = processor.np.zeros(shape, dtype=bool)
        edge = empty.copy()
        edge[12:44, 26] = True
        protected = empty.copy()
        protected[12:28, 25:28] = True
        background = empty.copy()
        background[28:44, 25:28] = True
        evidence = {"alpha": processor.np.full(shape, 255, dtype="uint8"),
                    "confident_foreground": empty, "confident_background": background,
                    "ambiguous": protected, "weak_contours": edge,
                    "weak_contour_protected": protected,
                    "weak_contour_background": background}
        candidate = Image.new("RGBA", (48, 64), (100, 100, 100, 255))
        calls = []

        def worker(*args):
            calls.append(1)
            return candidate, .2, {}

        capture = cli.AutoDebugCapture(lambda *args: evidence, worker)
        self.assertIs(capture.observe_probe(None), evidence)
        self.assertIs(capture.observe_worker(None)[0], candidate)
        with tempfile.TemporaryDirectory() as temporary:
            exported = capture.export(Image.new("RGB", (48, 64), "gray"), Path(temporary), {})
        self.assertEqual(calls, [1])
        self.assertEqual(exported["counts"]["weak_contour_protected"], 48)
        self.assertEqual(exported["counts"]["weak_contour_background"], 48)

    def test_debug_map_retains_source_protection_when_later_probe_declines(self):
        shape = (64, 48)
        edge = processor.np.zeros(shape, dtype=bool)
        edge[12:44, 26] = True
        protected = processor.np.zeros(shape, dtype=bool)
        protected[4:60, 16:32] = True
        weak = {"weak_contours": edge, "weak_contour_protected": protected,
                "weak_contour_background": processor.np.zeros(shape, dtype=bool)}
        graph_result = (processor.np.zeros(shape, dtype=bool),
                        processor.np.zeros((*shape, 3), dtype=float), weak)
        capture = cli.AutoDebugCapture(lambda *args: None, lambda *args: None)
        calls = []

        def graph(*args):
            calls.append(1)
            return graph_result

        self.assertIs(capture.observe_source_graph(graph, None), graph_result)
        self.assertIsNone(capture.observe_probe(None))
        with tempfile.TemporaryDirectory() as temporary:
            exported = capture.export(Image.new("RGB", (48, 64), "gray"),
                                      Path(temporary), {"fallback_reason": "insufficient_residual"})
        self.assertEqual(calls, [1])
        self.assertEqual(exported["status"], "saved")
        self.assertEqual(exported["classification_stage"], "source_paths_pending_material")
        self.assertEqual(exported["counts"]["weak_contour_protected"], 896)
        self.assertEqual(exported["fallback_reason"], "insufficient_residual")
        self.assertFalse(exported["candidate_available"])

    def test_debug_map_marks_candidate_losses_without_changing_source_classes(self):
        shape = (32, 24)
        confident = processor.np.zeros(shape, dtype=bool)
        confident[2:10, 2:10] = True
        ambiguous = processor.np.zeros(shape, dtype=bool)
        ambiguous[14:22, 2:10] = True
        background = processor.np.zeros(shape, dtype=bool)
        background[2:22, 14:22] = True
        evidence = {"alpha": processor.np.full(shape, 255, dtype="uint8"),
                    "confident_foreground": confident,
                    "confident_background": background, "ambiguous": ambiguous}
        candidate = Image.new("RGBA", (24, 32), (100, 100, 100, 0))
        capture = cli.AutoDebugCapture(lambda *args: evidence,
                                      lambda *args: (candidate, .2, {}))
        self.assertIs(capture.observe_probe(None), evidence)
        self.assertIs(capture.observe_worker(None)[0], candidate)
        with tempfile.TemporaryDirectory() as temporary:
            exported = capture.export(Image.new("RGB", (24, 32), (100, 100, 100)),
                                      Path(temporary), {})
            with Image.open(exported["path"]) as opened:
                foreground_pixel = opened.getpixel((4, 4))
                ambiguous_pixel = opened.getpixel((4, 16))
                background_pixel = opened.getpixel((16, 4))
        self.assertEqual(exported["counts"]["confident_foreground"], 64)
        self.assertEqual(exported["counts"]["confident_background"], 160)
        self.assertEqual(exported["counts"]["lost_confident_foreground"], 64)
        self.assertEqual(exported["counts"]["lost_ambiguous"], 64)
        self.assertGreater(foreground_pixel[0], foreground_pixel[2])
        self.assertGreater(ambiguous_pixel[2], ambiguous_pixel[0])
        self.assertGreater(background_pixel[0], background_pixel[2])
        self.assertTrue(processor.np.array_equal(evidence["confident_foreground"], confident))

    def test_debug_candidate_alpha_extraction_is_bounded(self):
        shape = (128, 256)
        empty = processor.np.zeros(shape, dtype=bool)
        evidence = {"alpha": processor.np.zeros(shape, dtype="uint8"),
                    "confident_foreground": empty, "confident_background": empty,
                    "ambiguous": empty}
        candidate = Image.new("RGBA", (2048, 1024), (100, 100, 100, 128))
        capture = cli.AutoDebugCapture(lambda *args: evidence,
                                      lambda *args: (candidate, .2, {}))
        capture.observe_probe(None)
        original_getchannel = Image.Image.getchannel

        def bounded_channel(image, *args, **kwargs):
            self.assertLessEqual(max(image.size), 512)
            return original_getchannel(image, *args, **kwargs)

        with patch.object(Image.Image, "getchannel", autospec=True,
                          side_effect=bounded_channel):
            self.assertIs(capture.observe_worker(None)[0], candidate)
        self.assertEqual(capture.error, "")
        self.assertEqual(capture.candidate_alpha.shape, shape)

    @staticmethod
    def map_save_failure(image, path, *args, **kwargs):
        if Path(path).name == "classification-map.png":
            raise OSError("synthetic-map-export-error")
        return ModnetFallbackCliTests.original_image_save(image, path, *args, **kwargs)

    original_image_save = Image.Image.save


if __name__ == "__main__":
    unittest.main()
