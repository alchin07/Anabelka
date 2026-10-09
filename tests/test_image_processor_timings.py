"""Full-path measurements must remain observational and request-local."""

import importlib.util
import io
import json
import sys
import tempfile
import unittest
from concurrent.futures import ThreadPoolExecutor
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np
from PIL import Image


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location("anabelka_timing_test", PROCESSOR_DIR / "server.py")
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


def fixture():
    pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
    pixels[10:150, 20:100] = (205, 150, 120)
    pixels[20:80, 72:88] = (45, 28, 15)
    pixels[30:70, 62:68] = (214, 191, 160)
    return Image.fromarray(pixels)


class ImageProcessorTimingTests(unittest.TestCase):
    def assert_timings(self, values, required):
        for name in required:
            self.assertIn(name, values)
            self.assertGreaterEqual(values[name], 0)
        self.assertGreater(values["total"], 0)

    def test_full_process_reports_io_detection_geometry_and_save(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / "source.jpg"
            fixture().save(source)
            with (
                patch.object(processor, "safe_source_path", return_value=source),
                patch.object(processor, "ORIGINAL_ROOT", root / "originals"),
                patch.object(processor, "PROCESSED_ROOT", root / "processed"),
                patch.object(processor, "project_relative", side_effect=lambda path: str(path)),
                patch.object(processor, "detect_mediapipe_person_bbox", return_value=None),
                patch.object(processor, "detect_face_subject_bbox", return_value=(10, 5, 100, 150)),
            ):
                result = processor.process_image("source", processor.BACKGROUND_PROFILE_ORIGINAL)
            self.assert_timings(result.get("timings_ms", {}),
                ("source_validation", "copy_original", "hash_original", "decode", "normalization",
                 "thumbnail_resize", "save_master", "save_thumb", "total"))
            self.assert_timings(result["normalization"].get("timings_ms", {}),
                ("detect_mediapipe", "detect_face", "geometry", "original_canvas", "total"))
            self.assert_timings(result.get("decode_timings_ms", {}),
                ("opencv_probe", "pillow_decode", "total"))
            self.assertTrue(Path(result["master"]["path"]).is_file())
            self.assertEqual(result["input"], {"width": 120, "height": 160})

    def test_grabcut_substages_do_not_change_rgba_or_ratio(self):
        image = fixture().resize((300, 400))
        with patch.object(processor, "SUBJECT_MASK_MAX_EDGE", 160):
            cv2.setRNGSeed(0)
            expected = processor.build_subject_rgba(image, (25, 10, 250, 375))
            timings = {}
            cv2.setRNGSeed(0)
            observed = processor.build_subject_rgba(image, (25, 10, 250, 375), timings=timings)
        self.assertIsNotNone(expected)
        self.assertIsNotNone(observed)
        self.assertEqual(expected[1], observed[1])
        np.testing.assert_array_equal(np.asarray(expected[0]), np.asarray(observed[0]))
        self.assert_timings(timings, ("seed_prepare", "work_resize", "border_seed", "inference", "border_suppression_work",
            "primary_component", "edge_refinement", "alpha_resize", "border_suppression_source", "total"))
        self.assertEqual(timings["inference_calls"], 1)

    def test_grabcut_reuses_identical_work_border_analysis(self):
        image = fixture().resize((300, 400))
        suppress = processor.suppress_uniform_border_background
        with patch.object(processor, "SUBJECT_MASK_MAX_EDGE", 160):
            # An old two-argument replacement exercises the uncached path.
            with patch.object(processor, "suppress_uniform_border_background",
                              side_effect=lambda i, f: suppress(i, f)):
                cv2.setRNGSeed(0)
                expected = processor.build_subject_rgba(image, (25, 10, 250, 375))
            with patch.object(processor, "uniform_border_background_mask",
                              wraps=processor.uniform_border_background_mask) as border:
                cv2.setRNGSeed(0)
                observed = processor.build_subject_rgba(image, (25, 10, 250, 375))
            self.assertEqual(border.call_count, 2, "one work seed and one source-size suppression")
        self.assertEqual(expected[1], observed[1])
        np.testing.assert_array_equal(np.asarray(expected[0]), np.asarray(observed[0]))

    def test_known_absent_border_mask_does_not_repeat_analysis(self):
        image = fixture()
        foreground = np.full((160, 120), 255, dtype=np.uint8)
        with patch.object(processor, "uniform_border_background_mask",
                          side_effect=AssertionError("repeated border analysis")):
            observed = processor.suppress_uniform_border_background(image, foreground, background_mask=None)
        self.assertIs(observed, foreground)

    def test_enclosed_gap_reports_each_local_grabcut_without_changing_alpha(self):
        image = fixture()
        alpha = np.zeros((160, 120), dtype=np.uint8)
        alpha[10:150, 20:100] = 255
        subject = image.convert("RGBA")
        subject.putalpha(Image.fromarray(alpha))
        cv2.setRNGSeed(0)
        expected = processor.refine_upper_enclosed_background_gaps(image, subject, (0, 0, 120, 160))
        timings = {}
        cv2.setRNGSeed(0)
        observed = processor.refine_upper_enclosed_background_gaps(
            image, subject, (0, 0, 120, 160), timings=timings)
        np.testing.assert_array_equal(np.asarray(expected), np.asarray(observed))
        self.assert_timings(timings, ("colour_candidates", "local_inference", "total"))
        self.assertGreaterEqual(timings["candidate_components"], 1)
        self.assertGreaterEqual(timings["local_inference_calls"], 1)
        self.assertEqual(len(timings["local_inference_ms"]), timings["local_inference_calls"])

    def test_custom_route_keeps_legacy_patched_callable_signatures(self):
        image = fixture()
        subject = image.convert("RGBA")
        subject.putalpha(Image.new("L", image.size, 255))
        diagnostics = {}
        with (
            patch.object(processor, "build_subject_rgba", side_effect=lambda i, b: (subject, .5)),
            patch.object(processor, "cosmetic_cleanup_subject_fringes", side_effect=lambda i, s, b: s),
            patch.object(processor, "refine_upper_enclosed_background_gaps", side_effect=lambda i, s, b: s),
        ):
            result = processor.custom_background_master(image, (0, 0, 120, 160), None,
                "subject-bbox", None, None, None, processor.BACKGROUND_PROFILE_STUDIO,
                diagnostics=diagnostics)
        self.assertIsNotNone(result)
        self.assert_timings(diagnostics.get("timings_ms", {}),
            ("grabcut", "cosmetic_cleanup", "enclosed_gap_cleanup", "subject_canvas", "compose", "total"))

    def test_request_timing_dictionaries_are_independent(self):
        image = fixture()
        with (
            patch.object(processor, "detect_mediapipe_person_bbox", return_value=None),
            patch.object(processor, "detect_face_subject_bbox", return_value=None),
            patch.object(processor, "detect_person_bbox", return_value=None),
        ):
            with ThreadPoolExecutor(max_workers=2) as pool:
                records = list(pool.map(lambda _: processor.normalized_master(image)[1], range(2)))
        first = records[0].get("timings_ms", {})
        second = records[1].get("timings_ms", {})
        self.assert_timings(first, ("detect_mediapipe", "detect_face", "detect_hog", "original_canvas", "total"))
        self.assert_timings(second, ("detect_mediapipe", "detect_face", "detect_hog", "original_canvas", "total"))
        first["request_marker"] = 1
        self.assertNotIn("request_marker", second)

    def test_cli_times_source_io_and_each_full_render_stage(self):
        cli_spec = importlib.util.spec_from_file_location("anabelka_cli_timing_test",
            PROCESSOR_DIR / "check_modnet_fallback.py")
        cli = importlib.util.module_from_spec(cli_spec)
        cli_spec.loader.exec_module(cli)
        cli.processor = processor
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / "source.jpg"
            fixture().save(source)
            output = root / "comparison"
            with (
                patch.object(processor, "project_relative", side_effect=lambda path: str(path)),
                patch.object(processor, "detect_mediapipe_person_bbox", return_value=None),
                patch.object(processor, "detect_face_subject_bbox", return_value=None),
                patch.object(processor, "detect_person_bbox", return_value=None),
                redirect_stdout(io.StringIO()),
            ):
                self.assertTrue(cli.compare_source(source, output, processor.BACKGROUND_PROFILE_STUDIO))
            report = json.loads((output / "diagnostics.json").read_text())
        self.assert_timings(report.get("timings_ms", {}),
            ("hash_source_before", "decode", "hash_source_after", "comparison_save", "total"))
        for stage in report["stages"].values():
            self.assert_timings(stage.get("timings_ms", {}),
                ("source_copy", "normalization", "save_preview", "thumbnail_resize", "total"))
            self.assertAlmostEqual(stage["elapsed_seconds"], stage["timings_ms"]["total"] / 1000, places=3)

    def test_debug_map_preserves_full_material_extent_and_candidate_loss(self):
        cli_spec = importlib.util.spec_from_file_location("anabelka_material_debug_timing_test",
            PROCESSOR_DIR / "check_modnet_fallback.py")
        cli = importlib.util.module_from_spec(cli_spec)
        cli_spec.loader.exec_module(cli)
        cli.processor = processor
        material = np.zeros((16, 12), dtype=bool)
        material[3:13, 4:8] = True
        empty = np.zeros_like(material)
        weak = {"weak_contours": empty, "weak_contour_background": empty,
                "weak_contour_protected": empty, "unresolved_material_protected": material}
        capture = cli.AutoDebugCapture(lambda *args: None, lambda *args: None)
        capture.observe_source_graph(lambda *args: (empty, empty, weak), None)
        np.testing.assert_array_equal(capture.masks.get("unresolved_material_protected"), material)
        evidence = {"alpha": np.full(material.shape, 255, dtype=np.uint8),
                    "confident_foreground": empty, "confident_background": empty,
                    "ambiguous": material, "unresolved_material_protected": material}
        capture.probe = lambda *args: evidence
        capture.observe_probe(None)
        capture.candidate_alpha = np.zeros(material.shape, dtype=np.uint8)
        with tempfile.TemporaryDirectory() as temp:
            record = capture.export(fixture(), Path(temp), {"fallback_reason": "unresolved_weak_material_extent"})
        self.assertEqual(record["status"], "saved")
        self.assertEqual(record["counts"]["unresolved_material_protected"], 40)
        self.assertEqual(record["counts"]["lost_unresolved_material"], 40)


if __name__ == "__main__":
    unittest.main()
