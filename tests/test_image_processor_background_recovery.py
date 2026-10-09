"""Source noise must not turn captured backdrop into protected model pixels."""

import io
import unittest

import numpy as np
from PIL import Image

import test_image_processor_modnet_routing as routing

processor = routing.processor


class BackgroundRecoveryTests(unittest.TestCase):
    def setUp(self):
        self.routes = routing.GrabcutFallbackTests(methodName="runTest")

    def fixture(self, jpeg=True, spill=True):
        image, grabcut, good, bbox = self.routes.noisy_fixture(spill=spill)
        pixels = np.asarray(image).astype(np.float64)
        backdrop = np.asarray(good.getchannel("A")) == 0
        rows, columns = np.indices(backdrop.shape)
        noise = np.random.default_rng(91).normal(size=backdrop.shape)
        variation = noise + 2 * np.sin(columns / 35) + 4 * rows / 512
        pixels[backdrop] += variation[backdrop, None]
        image = Image.fromarray(np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
        if jpeg:
            stream = io.BytesIO()
            image.save(stream, "JPEG", quality=90, subsampling=0)
            stream.seek(0)
            with Image.open(stream) as opened:
                image = opened.convert("RGB")
        for subject in (grabcut, good):
            alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(alpha)
        return image, grabcut, good, bbox

    def test_noisy_captured_backdrop_does_not_veto_good_modnet(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                image, grabcut, good, bbox = self.fixture(jpeg)
                self.assertFalse(processor.complex_background_prefers_modnet(image))
                selection = {}
                result, calls = self.routes.render(
                    image, grabcut, self.routes.candidate(good), bbox,
                    diagnostics=selection)
                self.assertEqual(calls, 1)
                self.assertEqual(result[2], "modnet")
                self.assertGreaterEqual(selection["comparison"]["protected_retention"], .98)

    def test_classification_precedes_candidate_and_keeps_sources_unchanged(self):
        image, grabcut, good, bbox = self.fixture()
        image_before, grabcut_before = np.asarray(image).copy(), np.asarray(grabcut).copy()
        probe = {}
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertIsNotNone(evidence)
        self.assertIn("confident_background", evidence)
        before = {key: value.copy() for key, value in evidence.items() if isinstance(value, np.ndarray)}
        processor.modnet_fallback_is_better(good, self.routes.candidate(good)[1], evidence)
        processor.modnet_fallback_is_better(grabcut, self.routes.candidate(grabcut)[1], evidence)
        for key, original in before.items():
            np.testing.assert_array_equal(evidence[key], original)
        np.testing.assert_array_equal(image_before, np.asarray(image))
        np.testing.assert_array_equal(grabcut_before, np.asarray(grabcut))

    def test_good_noisy_grabcut_still_skips_worker(self):
        image, grabcut, good, bbox = self.fixture(spill=False)
        baseline, _ = self.routes.render(image, grabcut, None, bbox, source=None)
        result, calls = self.routes.render(image, grabcut, self.routes.candidate(good), bbox)
        self.assertEqual(calls, 0)
        self.assertEqual(result[2], "opencv-grabcut")
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_diagnostics_separate_source_classes_and_candidate_losses(self):
        image, grabcut, good, bbox = self.routes.fixture()
        probe = {}
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertIsNotNone(evidence)
        for key in ("confident_foreground", "confident_background", "ambiguous"):
            self.assertIn(key, evidence)
            self.assertEqual(probe["counts"][key], np.count_nonzero(evidence[key]))
        damaged = good.copy()
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[110:140, 40:80] = 0
        damaged.putalpha(Image.fromarray(alpha))
        comparison = {}
        self.assertFalse(processor.modnet_fallback_is_better(
            damaged, self.routes.candidate(damaged)[1], evidence,
            diagnostics=comparison))
        self.assertEqual(comparison["reason"], "protected_foreground_loss")
        self.assertGreater(comparison["confident_foreground_lost_pixels"], 0)
        self.assertGreater(comparison["confident_background_lost_pixels"], 0)
        self.assertGreater(comparison["confident_foreground_opacity_loss"], 0)
        for key in ("confident_foreground", "confident_background", "ambiguous"):
            self.assertEqual(comparison[key + "_pixels"], probe["counts"][key])

    def test_curved_and_diagonal_matching_model_contours_are_not_background(self):
        for shape, jpeg in (("ellipse-15", False), ("ellipse-60", False),
                            ("diagonal", False), ("diagonal", True)):
            with self.subTest(shape=shape, jpeg=jpeg):
                image, grabcut, missing_part, bbox = self.routes.fixture()
                part = np.zeros(np.asarray(grabcut.getchannel("A")).shape, dtype=np.uint8)
                if shape.startswith("ellipse"):
                    processor.cv2.ellipse(part, (85, 100), (15, 28),
                                          int(shape.split("-")[1]), 0, 360, 1, -1)
                else:
                    processor.cv2.line(part, (79, 65), (94, 105), 1, 10)
                part = ((part > 0) & (np.asarray(missing_part.getchannel("A")) == 0)
                        & (np.asarray(grabcut.getchannel("A")) >= 192))
                pixels = np.asarray(image).copy()
                pixels[part] = (116, 84, 61)  # One Lab unit from the shadow backdrop.
                image = Image.fromarray(pixels)
                if jpeg:
                    encoded = io.BytesIO()
                    image.save(encoded, "JPEG", quality=95, subsampling=0)
                    encoded.seek(0)
                    with Image.open(encoded) as opened:
                        image = opened.convert("RGB")
                good = missing_part.copy()
                alpha = np.asarray(good.getchannel("A")).copy()
                alpha[part] = 255
                good.putalpha(Image.fromarray(alpha))
                for subject in (grabcut, good, missing_part):
                    alpha = subject.getchannel("A")
                    subject.paste(image, (0, 0))
                    subject.putalpha(alpha)
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                self.assertIsNotNone(evidence)
                interior = processor.cv2.erode(part.astype(np.uint8),
                                                np.ones((3, 3), dtype=np.uint8)).astype(bool)
                self.assertGreaterEqual(np.mean(evidence["protected"][interior]), .98)
                self.assertFalse(processor.modnet_fallback_is_better(
                    missing_part, self.routes.candidate(missing_part)[1], evidence))
                if not jpeg:
                    self.assertTrue(processor.modnet_fallback_is_better(
                        good, self.routes.candidate(good)[1], evidence))


if __name__ == "__main__":
    unittest.main()
