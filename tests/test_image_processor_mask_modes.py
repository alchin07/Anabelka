"""Explicit mask modes must dispatch faithfully without changing geometry."""

import importlib.util
import io
import json
import subprocess
import sys
import tempfile
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location("anabelka_mask_modes_test", PROCESSOR_DIR / "server.py")
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)

REPLACEMENT_PROFILES = (processor.BACKGROUND_PROFILE_STUDIO, processor.BACKGROUND_PROFILE_BRAND)


def fixture():
    pixels = np.full((160, 120, 3), (145, 107, 78), dtype=np.uint8)
    pixels[10:150, 40:80] = (220, 160, 140)
    image = Image.fromarray(pixels)
    alpha = np.zeros((160, 120), dtype=np.uint8)
    alpha[10:150, 40:80] = 255
    alpha[30:140, 40] = 180  # A real soft edge must survive forced MODNet.
    subject = image.convert("RGBA")
    subject.putalpha(Image.fromarray(alpha))
    return image, subject, (40, 10, 40, 140)


def detection_patches(stack, bbox):
    stack.enter_context(patch.object(processor, "detect_mediapipe_person_bbox", return_value=None))
    stack.enter_context(patch.object(processor, "detect_face_subject_bbox", return_value=bbox))
    stack.enter_context(patch.object(processor, "detect_person_bbox", return_value=None))


class MaskModeDispatchTests(unittest.TestCase):
    def render(self, image, bbox, profile, **kwargs):
        return processor.custom_background_master(
            image, bbox, (10, 5, 110, 155), "aspect-fill", None, None, None,
            profile, Path("/tmp/immutable-source.png"), **kwargs)

    def test_forced_grabcut_skips_all_route_and_fallback_analysis(self):
        image, subject, bbox = fixture()
        for profile in REPLACEMENT_PROFILES:
            with self.subTest(profile=profile), ExitStack() as stack:
                for name in ("complex_background_prefers_modnet", "grabcut_fallback_evidence",
                             "modnet_fallback_is_better", "run_modnet_worker"):
                    stack.enter_context(patch.object(processor, name, side_effect=AssertionError(name)))
                stack.enter_context(patch.object(processor, "build_subject_rgba", return_value=(subject, .29)))
                cosmetic = stack.enter_context(patch.object(processor, "cosmetic_cleanup_subject_fringes",
                                                            side_effect=lambda i, s, b: s))
                enclosed = stack.enter_context(patch.object(processor, "refine_upper_enclosed_background_gaps",
                                                            side_effect=lambda i, s, b: s))
                diagnostics = {}
                result = self.render(image, bbox, profile, mask_mode="grabcut", diagnostics=diagnostics)
                self.assertEqual(result[2], "opencv-grabcut")
                expected = processor.compose_subject_on_background(
                    processor.transparent_standard_canvas(subject.crop((10, 5, 110, 155)), processor.MASTER_SIZE),
                    profile)
                np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(expected))
                self.assertEqual(diagnostics["modnet_worker_calls"], 0)
                self.assertEqual(diagnostics["worker_error"], "")
                self.assertFalse(set(diagnostics["timings_ms"]) & {"primary_route_analysis", "probe", "comparison", "worker"})
                self.assertEqual(cosmetic.call_count, 1)
                self.assertEqual(enclosed.call_count, 1)

    def test_forced_modnet_runs_once_and_preserves_soft_alpha_without_cleanup(self):
        image, subject, bbox = fixture()
        before = np.asarray(subject).copy()
        for profile in REPLACEMENT_PROFILES:
            with self.subTest(profile=profile), ExitStack() as stack:
                for name in ("complex_background_prefers_modnet", "grabcut_fallback_evidence",
                             "modnet_fallback_is_better", "build_subject_rgba",
                             "cosmetic_cleanup_subject_fringes", "refine_upper_enclosed_background_gaps"):
                    stack.enter_context(patch.object(processor, name, side_effect=AssertionError(name)))
                worker = stack.enter_context(patch.object(processor, "run_modnet_worker",
                                                         return_value=(subject, .29, {})))
                diagnostics = {}
                result = self.render(image, bbox, profile, mask_mode="modnet", diagnostics=diagnostics)
                self.assertEqual(result[2], "modnet")
                expected = processor.compose_subject_on_background(
                    processor.transparent_standard_canvas(subject.crop((10, 5, 110, 155)), processor.MASTER_SIZE),
                    profile)
                np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(expected))
                np.testing.assert_array_equal(np.asarray(subject), before)
                self.assertEqual(worker.call_count, 1)
                self.assertEqual(worker.call_args.args, (Path("/tmp/immutable-source.png"), image))
                self.assertEqual(diagnostics["modnet_worker_calls"], 1)

    def test_omitted_auto_and_explicit_auto_have_identical_routing_and_pixels(self):
        image, subject, bbox = fixture()
        for profile in REPLACEMENT_PROFILES:
            for prefer_modnet in (False, True):
                with self.subTest(profile=profile, prefer_modnet=prefer_modnet), ExitStack() as stack:
                    stack.enter_context(patch.object(processor, "complex_background_prefers_modnet",
                                                     return_value=prefer_modnet))
                    stack.enter_context(patch.object(processor, "grabcut_fallback_evidence", return_value=None))
                    stack.enter_context(patch.object(processor, "build_subject_rgba", return_value=(subject, .29)))
                    stack.enter_context(patch.object(processor, "run_modnet_worker", return_value=(subject, .29, {})))
                    stack.enter_context(patch.object(processor, "cosmetic_cleanup_subject_fringes",
                                                     side_effect=lambda i, s, b: s))
                    stack.enter_context(patch.object(processor, "refine_upper_enclosed_background_gaps",
                                                     side_effect=lambda i, s, b: s))
                    omitted, explicit = {}, {}
                    first = self.render(image, bbox, profile, diagnostics=omitted)
                    second = self.render(image, bbox, profile, mask_mode="auto", diagnostics=explicit)
                    self.assertEqual(first[1:], second[1:])
                    np.testing.assert_array_equal(np.asarray(first[0]), np.asarray(second[0]))
                    for key in ("primary_route", "fallback_status", "fallback_reason", "modnet_worker_calls",
                                "worker_error", "mask_mode_requested"):
                        self.assertEqual(omitted[key], explicit[key])

    def test_normalization_records_actual_method_for_both_profiles_and_all_modes(self):
        image, subject, bbox = fixture()
        for profile in REPLACEMENT_PROFILES:
            for mode, expected in (("auto", "opencv-grabcut"), ("grabcut", "opencv-grabcut"), ("modnet", "modnet")):
                with self.subTest(profile=profile, mode=mode), ExitStack() as stack:
                    detection_patches(stack, bbox)
                    stack.enter_context(patch.object(processor, "complex_background_prefers_modnet", return_value=False))
                    stack.enter_context(patch.object(processor, "grabcut_fallback_evidence", return_value=None))
                    stack.enter_context(patch.object(processor, "build_subject_rgba", return_value=(subject, .29)))
                    stack.enter_context(patch.object(processor, "run_modnet_worker", return_value=(subject, .29, {})))
                    master, diagnostics = processor.normalized_master(
                        image, profile, source_path=Path("/tmp/source.png"), mask_mode=mode)
                    self.assertEqual(master.size, processor.MASTER_SIZE)
                    self.assertEqual(diagnostics["background_profile"], profile)
                    self.assertEqual(diagnostics["mask_mode_requested"], mode)
                    self.assertEqual(diagnostics["mask_method"], expected)
                    self.assertEqual(diagnostics["processor_version"], processor.VERSION)
                    self.assertEqual(diagnostics["worker_error"], "")

    def test_explicit_methods_preserve_all_four_existing_crop_strategies(self):
        for profile in REPLACEMENT_PROFILES:
            for length, strategy in ((20, "torso-normalize"), (60, "aspect-fill"),
                                     (110, "torso-zoom-out"), (110, "preserve-closeup")):
                with self.subTest(profile=profile, strategy=strategy), ExitStack() as stack:
                    image, subject, bbox = fixture()
                    if strategy == "preserve-closeup":
                        pixels = np.asarray(image).copy()
                        pixels[:, 40:80] = (220, 160, 140)
                        image = Image.fromarray(pixels)
                        alpha = np.asarray(subject.getchannel("A")).copy()
                        alpha[:, 40:80] = 255
                        subject = image.convert("RGBA")
                        subject.putalpha(Image.fromarray(alpha))
                    detection = (bbox, .95, (60., 80.), (60., 35.),
                                 (60., 35. + length), float(length), 20.)
                    stack.enter_context(patch.object(processor, "detect_mediapipe_person_bbox", return_value=detection))
                    stack.enter_context(patch.object(processor, "complex_background_prefers_modnet", return_value=False))
                    stack.enter_context(patch.object(processor, "grabcut_fallback_evidence", return_value=None))
                    stack.enter_context(patch.object(processor, "build_subject_rgba", return_value=(subject, .29)))
                    stack.enter_context(patch.object(processor, "run_modnet_worker", return_value=(subject, .29, {})))
                    stack.enter_context(patch.object(processor, "cosmetic_cleanup_subject_fringes",
                                                     side_effect=lambda i, s, b: s))
                    stack.enter_context(patch.object(processor, "refine_upper_enclosed_background_gaps",
                                                     side_effect=lambda i, s, b: s))
                    baseline, before = processor.normalized_master(
                        image, profile, source_path=Path("/tmp/source.png"))
                    self.assertEqual(before["crop_strategy"], strategy)
                    for mode in ("grabcut", "modnet"):
                        observed, after = processor.normalized_master(
                            image, profile, source_path=Path("/tmp/source.png"), mask_mode=mode)
                        np.testing.assert_array_equal(np.asarray(observed), np.asarray(baseline))
                        for key in ("crop_strategy", "crop_applied", "crop_box", "person_bbox", "method",
                                    "torso_ratio_before", "torso_ratio_after", "zoom_scale", "zoom_out_applied"):
                            self.assertEqual(after.get(key), before.get(key), key)

    def test_forced_modnet_missing_source_and_unusable_canvas_fail_explicitly(self):
        image, subject, bbox = fixture()
        with self.assertRaisesRegex(RuntimeError, "source-missing") as caught:
            processor.custom_background_master(image, bbox, None, "subject-bbox", None, None, None,
                                               "studio-light", mask_mode="modnet")
        self.assertEqual(caught.exception.normalization["worker_error"], "source-missing")
        for mode in ("grabcut", "modnet"):
            with self.subTest(mode=mode), ExitStack() as stack:
                stack.enter_context(patch.object(processor, "run_modnet_worker", return_value=(subject, .29, {})))
                stack.enter_context(patch.object(processor, "build_subject_rgba", return_value=(subject, .29)))
                stack.enter_context(patch.object(processor, "transparent_zoom_out_canvas", return_value=None))
                with self.assertRaisesRegex(RuntimeError, "subject_canvas_unavailable"):
                    processor.custom_background_master(
                        image, bbox, None, "torso-zoom-out", .8, (60., 80.), (60., 35.),
                        "studio-light", Path("/tmp/source.png"), mask_mode=mode)

    def test_original_canvas_ignores_every_removal_mode(self):
        image, _, bbox = fixture()
        with ExitStack() as stack:
            detection_patches(stack, bbox)
            for name in ("build_subject_rgba", "run_modnet_worker", "complex_background_prefers_modnet"):
                stack.enter_context(patch.object(processor, name, side_effect=AssertionError(name)))
            baseline, _ = processor.normalized_master(image)
            for mode in ("auto", "grabcut", "modnet"):
                with self.subTest(mode=mode):
                    observed, diagnostics = processor.normalized_master(image, mask_mode=mode)
                    np.testing.assert_array_equal(np.asarray(observed), np.asarray(baseline))
                    self.assertEqual(diagnostics["mask_mode_requested"], mode)
                    self.assertEqual(diagnostics["mask_method"], "none")
                    self.assertFalse(diagnostics["subject_mask_applied"])
                    self.assertEqual(diagnostics["worker_error"], "")

    def test_no_bbox_preserves_auto_canvas_but_fails_manual_replacement(self):
        image, _, _ = fixture()
        with ExitStack() as stack:
            detection_patches(stack, None)
            canvas, diagnostics = processor.normalized_master(image, "studio-light")
            self.assertEqual(diagnostics.get("mask_method"), "none")
            self.assertEqual(canvas.size, processor.MASTER_SIZE)
            for mode in ("grabcut", "modnet"):
                with self.subTest(mode=mode), self.assertRaises(RuntimeError) as caught:
                    processor.normalized_master(image, "studio-light", mask_mode=mode)
                self.assertEqual(caught.exception.normalization["mask_method"], "none")
                self.assertEqual(caught.exception.normalization["mask_mode_requested"], mode)

    def test_missing_grabcut_mask_is_manual_failure(self):
        image, _, bbox = fixture()
        with patch.object(processor, "build_subject_rgba", return_value=None):
            with self.assertRaises(RuntimeError) as caught:
                self.render(image, bbox, "studio-light", mask_mode="grabcut")
        self.assertEqual(caught.exception.normalization["mask_method"], "none")

    def test_forced_worker_exception_never_uses_grabcut(self):
        image, _, bbox = fixture()
        with (
            patch.object(processor, "run_modnet_worker", side_effect=RuntimeError("inference-crashed")),
            patch.object(processor, "build_subject_rgba", side_effect=AssertionError("fallback")),
        ):
            with self.assertRaisesRegex(RuntimeError, "inference-crashed") as caught:
                self.render(image, bbox, "studio-light", mask_mode="modnet")
        self.assertIn("inference-crashed", caught.exception.normalization["worker_error"])

    def test_invalid_mode_is_rejected_before_detection(self):
        image, _, _ = fixture()
        with patch.object(processor, "detect_mediapipe_person_bbox", side_effect=AssertionError("detection")):
            with self.assertRaises(ValueError):
                processor.normalized_master(image, "studio-light", mask_mode="unknown")


class MaskModeProcessTests(unittest.TestCase):
    def process_patches(self, stack, root, source, bbox):
        stack.enter_context(patch.object(processor, "safe_source_path", return_value=source))
        stack.enter_context(patch.object(processor, "ORIGINAL_ROOT", root / "originals"))
        stack.enter_context(patch.object(processor, "PROCESSED_ROOT", root / "processed"))
        stack.enter_context(patch.object(processor, "project_relative", side_effect=lambda path: str(path)))
        detection_patches(stack, bbox)

    def test_process_passes_mode_and_immutable_original_to_worker(self):
        image, subject, bbox = fixture()
        with tempfile.TemporaryDirectory() as temporary, ExitStack() as stack:
            root = Path(temporary)
            source = root / "source.png"
            image.save(source)
            original_bytes = source.read_bytes()
            self.process_patches(stack, root, source, bbox)
            worker = stack.enter_context(patch.object(processor, "run_modnet_worker", return_value=(subject, .29, {})))
            result = processor.process_image("source", "studio-light", mask_mode="modnet")
            immutable = worker.call_args.args[0]
            self.assertNotEqual(immutable, source)
            self.assertTrue(immutable.is_file())
            self.assertEqual(immutable.read_bytes(), original_bytes)
            self.assertEqual(source.read_bytes(), original_bytes)
            self.assertEqual(worker.call_count, 1)
            self.assertEqual(result["normalization"]["mask_mode_requested"], "modnet")
            self.assertEqual(result["normalization"]["mask_method"], "modnet")
            self.assertEqual(result["master"]["width"], 1200)
            self.assertEqual(result["master"]["height"], 1800)
            self.assertEqual(result["thumb"]["width"], 320)
            self.assertEqual(result["thumb"]["height"], 480)

    def test_worker_unavailable_or_timeout_fails_without_published_outputs(self):
        image, _, bbox = fixture()
        for profile in REPLACEMENT_PROFILES:
            for ready, expected in ((False, "worker-not-ready"), (True, "worker-timeout")):
                with self.subTest(profile=profile, error=expected), tempfile.TemporaryDirectory() as temporary, ExitStack() as stack:
                    root = Path(temporary)
                    source = root / "source.png"
                    image.save(source)
                    before = source.read_bytes()
                    self.process_patches(stack, root, source, bbox)
                    stack.enter_context(patch.object(processor, "modnet_worker_ready", return_value=ready))
                    stack.enter_context(patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"))
                    stack.enter_context(patch.object(processor.subprocess, "run",
                                                     side_effect=subprocess.TimeoutExpired("worker", 45)))
                    stack.enter_context(patch.object(processor, "build_subject_rgba", side_effect=AssertionError("fallback")))
                    saved = stack.enter_context(patch.object(processor, "save_webp", side_effect=AssertionError("published")))
                    worker = stack.enter_context(patch.object(processor, "run_modnet_worker", wraps=processor.run_modnet_worker))
                    with self.assertRaises(RuntimeError) as caught:
                        processor.process_image("source", profile, mask_mode="modnet")
                    self.assertEqual(caught.exception.normalization["worker_error"], expected)
                    self.assertEqual(caught.exception.normalization["mask_mode_requested"], "modnet")
                    self.assertEqual(caught.exception.normalization["mask_method"], "none")
                    self.assertEqual(caught.exception.normalization["processor_version"], processor.VERSION)
                    self.assertEqual(worker.call_count, 1)
                    self.assertEqual(saved.call_count, 0)
                    self.assertEqual(list((root / "processed").iterdir()), [])
                    self.assertEqual(list((root / "originals").iterdir()), [])
                    self.assertEqual(source.read_bytes(), before)
                    if (root / "worker").exists():
                        self.assertEqual(list((root / "worker").iterdir()), [])

    def post(self, payload):
        handler = object.__new__(processor.Handler)
        body = json.dumps(payload).encode()
        handler.path = "/process"
        handler.headers = {"Content-Length": str(len(body))}
        handler.rfile = io.BytesIO(body)
        responses = []
        handler.send_json = lambda status, data: responses.append((status, data))
        handler.do_POST()
        return responses[0]

    def test_http_forwards_requested_mode(self):
        with patch.object(processor, "process_image", return_value={"ok": True}) as process:
            status, _ = self.post({"source": "source", "background_profile": "studio-light", "mask_mode": "grabcut"})
        self.assertEqual(status, 200)
        self.assertEqual(process.call_args.kwargs.get("mask_mode"), "grabcut")

    def test_http_manual_failure_includes_request_local_worker_diagnostics(self):
        image, _, bbox = fixture()
        with tempfile.TemporaryDirectory() as temporary, ExitStack() as stack:
            root = Path(temporary)
            source = root / "source.png"
            image.save(source)
            self.process_patches(stack, root, source, bbox)
            stack.enter_context(patch.object(processor, "modnet_worker_ready", return_value=False))
            status, response = self.post({"source": "source", "background_profile": "studio-light", "mask_mode": "modnet"})
        self.assertGreaterEqual(status, 400)
        self.assertFalse(response["ok"])
        self.assertEqual(response["worker_error"], "worker-not-ready")
        self.assertEqual(response["normalization"]["worker_error"], "worker-not-ready")
        self.assertEqual(response["normalization"]["mask_mode_requested"], "modnet")
        self.assertEqual(response["normalization"]["mask_method"], "none")


if __name__ == "__main__":
    unittest.main()
