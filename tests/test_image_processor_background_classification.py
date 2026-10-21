"""Source-only foreground/background classification safety regressions."""

import importlib.util
import io
import unittest
from pathlib import Path

import numpy as np
from PIL import Image


# Keep the fixture's TestCase inside its module: importing its class directly
# would make unittest discover and execute the routing suite a second time.
spec = importlib.util.spec_from_file_location(
    "anabelka_background_classification_fixtures",
    Path(__file__).with_name("test_image_processor_modnet_routing.py"),
)
routing = importlib.util.module_from_spec(spec)
spec.loader.exec_module(routing)
processor = routing.processor


class BackgroundClassificationTests(unittest.TestCase):
    def setUp(self):
        self.fixtures = routing.GrabcutFallbackTests(methodName="runTest")

    def require_categories(self, evidence):
        for key in ("confident_foreground", "confident_background", "ambiguous"):
            self.assertIn(key, evidence.keys())

    def matching_part_fixture(self, region, colour, jpeg=False):
        image, grabcut, good, bbox = self.fixtures.fixture()
        pixels = np.asarray(image).copy()
        top, bottom, left, right = region
        pixels[top:bottom, left:right] = colour
        image = Image.fromarray(pixels)
        if jpeg:
            encoded = io.BytesIO()
            image.save(encoded, "JPEG", quality=95, subsampling=0)
            encoded.seek(0)
            with Image.open(encoded) as opened:
                image = opened.convert("RGB")
        for subject in (grabcut, good):
            alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(alpha)
        return image, grabcut, good, bbox

    def without_region(self, subject, region):
        top, bottom, left, right = region
        alpha = np.asarray(subject.getchannel("A")).copy()
        alpha[top:bottom, left:right] = 0
        damaged = subject.copy()
        damaged.putalpha(Image.fromarray(alpha))
        return damaged

    def comparison(self, candidate, evidence):
        diagnostics = {}
        accepted = processor.modnet_fallback_is_better(
            candidate, self.fixtures.candidate(candidate)[1], evidence,
            diagnostics=diagnostics,
        )
        return accepted, diagnostics

    def test_source_categories_partition_opaque_grabcut_pixels(self):
        image, grabcut, good, bbox = self.fixtures.noisy_fixture(jpeg=True)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        self.require_categories(evidence)
        masks = [evidence[key] for key in (
            "confident_foreground", "confident_background", "ambiguous")]
        opaque = evidence["alpha"] >= 192
        for mask in masks:
            self.assertEqual(mask.dtype, np.dtype(bool))
            self.assertEqual(mask.shape, opaque.shape)
            self.assertFalse(np.any(mask & ~opaque))
        membership = np.sum(np.stack(masks), axis=0)
        np.testing.assert_array_equal(membership, opaque.astype(np.int64))
        np.testing.assert_array_equal(
            evidence["protected"], masks[0] | masks[2])
        self.assertFalse(np.any(evidence["protected"] & masks[1]))
        self.assertGreater(np.count_nonzero(masks[0]), 0)
        self.assertGreater(np.count_nonzero(masks[1]), 0)
        self.assertTrue(self.comparison(good, evidence)[0])

    def test_categories_do_not_depend_on_candidate_or_mutate_source(self):
        image, grabcut, good, bbox = self.fixtures.noisy_fixture(jpeg=True)
        source_before = np.asarray(image).copy()
        grabcut_before = np.asarray(grabcut).copy()
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        self.require_categories(evidence)
        keys = ("alpha", "residual", "background_like", "protected",
                "confident_foreground", "confident_background", "ambiguous")
        before = {key: evidence[key].copy() for key in keys}
        self.assertTrue(self.comparison(good, evidence)[0])
        damaged = self.without_region(good, (32, 96, 144, 240))
        self.assertFalse(self.comparison(damaged, evidence)[0])
        after = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        for key in keys:
            np.testing.assert_array_equal(evidence[key], before[key])
            np.testing.assert_array_equal(after[key], before[key])
        np.testing.assert_array_equal(np.asarray(image), source_before)
        np.testing.assert_array_equal(np.asarray(grabcut), grabcut_before)

    def check_low_contrast_part(self, region, point, jpeg):
        # This is only ONE quantized Lab luminance unit from the exposed
        # backdrop. Its long coherent source contour must not be confused with
        # JPEG noise, even when it joins the matching-colour background region.
        image, grabcut, good, bbox = self.matching_part_fixture(
            region, (147, 109, 80), jpeg=jpeg)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        self.assertTrue(evidence["background_like"][point])
        self.assertTrue(evidence["protected"][point])
        self.require_categories(evidence)
        self.assertFalse(evidence["confident_background"][point])
        self.assertTrue(self.comparison(good, evidence)[0])
        damaged = self.without_region(good, region)
        self.assertFalse(self.comparison(damaged, evidence)[0])
        result, calls = self.fixtures.render(
            image, grabcut, self.fixtures.candidate(damaged), bbox)
        self.assertEqual(calls, 1)
        self.assertEqual(result[2], "opencv-grabcut")

    def test_one_lab_unit_arm_contour_remains_protected(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                self.check_low_contrast_part(
                    (50, 110, 30, 40), (80, 35), jpeg)

    def test_one_lab_unit_hair_contour_remains_protected(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                self.check_low_contrast_part(
                    (10, 30, 45, 75), (20, 60), jpeg)

    def test_weak_model_contours_inside_opaque_spill_remain_protected(self):
        for jpeg in (False, True):
            for name, region in (
                ("arm extension", (70, 120, 80, 90)),
                ("hair fringe", (50, 100, 80, 86)),
                ("narrow hair strand", (65, 125, 80, 83)),
            ):
                with self.subTest(part=name, jpeg=jpeg):
                    # The source detail meets the real body's right edge.
                    # GrabCut also captured the surrounding shadowed backdrop,
                    # so its silhouette does NOT trace this source contour.
                    # RGB116,84,61 differs from that backdrop by only ONE Lab
                    # luminance unit before JPEG compression.
                    image, grabcut, good, bbox = self.matching_part_fixture(
                        region, (116, 84, 61), jpeg=jpeg)
                    top, bottom, left, right = region
                    alpha = np.asarray(good.getchannel("A")).copy()
                    alpha[top:bottom, left:right] = 255
                    good.putalpha(Image.fromarray(alpha))
                    point = ((top + bottom) // 2, (left + right) // 2)
                    grabcut_alpha = np.asarray(grabcut.getchannel("A"))
                    self.assertEqual(grabcut_alpha[point[0], right - 1], 255)
                    self.assertEqual(grabcut_alpha[point[0], right + 1], 255)
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                    self.assertIsNotNone(evidence)
                    self.assertTrue(evidence["background_like"][point])
                    self.assertTrue(evidence["protected"][point])
                    self.require_categories(evidence)
                    self.assertFalse(evidence["confident_background"][point])
                    self.assertTrue(self.comparison(good, evidence)[0])
                    damaged = self.without_region(good, region)
                    self.assertFalse(self.comparison(damaged, evidence)[0])
                    result, calls = self.fixtures.render(
                        image, grabcut, self.fixtures.candidate(damaged), bbox)
                    self.assertEqual(calls, 1)
                    self.assertEqual(result[2], "opencv-grabcut")

    def test_background_noise_cannot_confirm_a_weak_connected_arm_as_background(self):
        scenarios = [
            (noise_level, delta, seed)
            for noise_level in (1, 2)
            for delta in (1, 2, 4)
            for seed in (1, 91, 911)
        ]
        for noise_level, delta, seed in scenarios:
            for jpeg in (False, True):
                with self.subTest(noise_level=noise_level, source_delta=delta,
                                  noise_seed=seed, jpeg=jpeg):
                    image, grabcut, good, bbox = self.fixtures.fixture()
                    size = (384, 512)
                    image = image.resize(size, Image.Resampling.NEAREST)
                    grabcut = grabcut.resize(size, Image.Resampling.NEAREST)
                    good = good.resize(size, Image.Resampling.NEAREST)
                    bbox = tuple(round(value * 3.2) for value in bbox)
                    region = (208, 400, 256, 266)
                    top, bottom, left, right = region
                    pixels = np.asarray(image).astype(np.float64)
                    backdrop = np.asarray(good.getchannel("A")) == 0
                    rows, columns = np.indices(backdrop.shape)
                    # The real source detail and nearby backdrop receive the
                    # SAME noise field. A coherent weak arm contour remains;
                    # higher background noise does not independently prove it
                    # is backdrop. This must not weaken preservation gates.
                    variation = np.random.default_rng(seed).normal(
                        0, noise_level, backdrop.shape)
                    variation += 2 * np.sin(columns / 35) + 4 * rows / 512
                    pixels[backdrop] += variation[backdrop, None]
                    pixels[top:bottom, left:right] += delta
                    image = Image.fromarray(
                        np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
                    if jpeg:
                        encoded = io.BytesIO()
                        image.save(encoded, "JPEG", quality=95, subsampling=0)
                        encoded.seek(0)
                        with Image.open(encoded) as opened:
                            image = opened.convert("RGB")
                    for subject in (grabcut, good):
                        alpha = subject.getchannel("A")
                        subject.paste(image, (0, 0))
                        subject.putalpha(alpha)
                    alpha = np.asarray(good.getchannel("A")).copy()
                    alpha[top:bottom, left:right] = 255
                    good.putalpha(Image.fromarray(alpha))
                    damaged = self.without_region(good, region)
                    self.assertFalse(processor.complex_background_prefers_modnet(image))
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                    if evidence is not None:
                        # Conservative refusal to compare is also safe. Once
                        # classification claims confidence, however, this
                        # independently visible source detail needs protection.
                        point = ((top + bottom) // 2, (left + right) // 2)
                        self.assertTrue(evidence["protected"][point])
                        self.assertFalse(self.comparison(damaged, evidence)[0])
                    result, calls = self.fixtures.render(
                        image, grabcut, self.fixtures.candidate(damaged), bbox)
                    self.assertEqual(result[2], "opencv-grabcut")
                    self.assertEqual(calls, 0 if evidence is None else 1)

    def test_missing_independent_contour_reference_keeps_grabcut(self):
        pixels = np.full((64, 128, 3), (145, 107, 78), dtype=np.uint8)
        pixels[10:58, 40:88] = (210, 150, 112)
        image = Image.fromarray(pixels)
        grabcut = image.convert("RGBA")
        alpha = np.zeros((64, 128), dtype=np.uint8)
        alpha[2:62, 12:116] = 255
        grabcut.putalpha(Image.fromarray(alpha))
        good = image.convert("RGBA")
        alpha = np.zeros((64, 128), dtype=np.uint8)
        alpha[10:58, 40:88] = 255
        good.putalpha(Image.fromarray(alpha))
        bbox = (40, 10, 48, 48)
        probe = {}
        evidence = processor.grabcut_fallback_evidence(
            image, grabcut, bbox, diagnostics=probe)
        # Large matching areas do not make uncalibrated directions reliable.
        # The narrow exposed margins cannot independently train every contour
        # direction, so no worker comparison may remove opaque source pixels.
        self.assertFalse(evidence is not None, "Uncalibrated source probe must decline")
        self.assertEqual(probe["reason"], "insufficient_weak_source_contour_reference")
        reports = probe["weak_source_contour_calibration"]
        self.assertTrue(any(
            report.get("reason") == "insufficient_reference"
            and report["reference_pixels"] < 8
            for report in reports.values()))
        selection = {}
        result, calls = self.fixtures.render(
            image, grabcut, self.fixtures.candidate(good), bbox,
            diagnostics=selection)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)
        self.assertEqual(selection["fallback_reason"],
                         "insufficient_weak_source_contour_reference")

    def test_enclosed_matching_colour_remains_ambiguous_and_protected(self):
        region = (55, 95, 50, 70)
        point = (75, 60)
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                image, grabcut, good, bbox = self.matching_part_fixture(
                    region, (145, 107, 78), jpeg=jpeg)
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                self.assertIsNotNone(evidence)
                # The colour matches clear background, but this source island
                # has no independent path to that background through its skin
                # enclosure. Colour alone cannot remove its protection.
                self.assertFalse(evidence["background_like"][point])
                self.require_categories(evidence)
                self.assertTrue(evidence["ambiguous"][point])
                self.assertFalse(evidence["confident_background"][point])
                self.assertTrue(evidence["protected"][point])
                self.assertTrue(self.comparison(good, evidence)[0])
                damaged = self.without_region(good, region)
                self.assertFalse(self.comparison(damaged, evidence)[0])

    def test_skin_lace_and_exterior_weak_material_loss_is_rejected(self):
        for jpeg in (False, True):
            for name, region in (
                ("skin", (110, 140, 40, 80)),
                ("lace", (70, 100, 45, 75)),
            ):
                with self.subTest(part=name, jpeg=jpeg):
                    image, grabcut, good, bbox = self.matching_part_fixture(
                        (0, 1, 0, 1), (145, 107, 78), jpeg=jpeg)
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                    self.assertIsNotNone(evidence)
                    self.assertTrue(self.comparison(good, evidence)[0])
                    damaged = self.without_region(good, region)
                    self.assertFalse(self.comparison(damaged, evidence)[0])
            for seam in (None, (119, 85, 62)):
                with self.subTest(part="exterior material", jpeg=jpeg, seam=seam):
                    image, grabcut, good, bbox = self.fixtures.exterior_fabric_fixture(
                        jpeg=jpeg, seam_colour=seam)
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                    self.assertIsNotNone(evidence)
                    self.assertTrue(self.comparison(good, evidence)[0])
                    damaged = self.without_region(good, (230, 290, 256, 288))
                    self.assertFalse(self.comparison(damaged, evidence)[0])

    def test_good_noisy_grabcut_does_not_launch_worker_or_change_result(self):
        image, grabcut, good, bbox = self.fixtures.noisy_fixture(
            jpeg=True, spill=False)
        for profile in ("studio-light", "anabelka-brand"):
            with self.subTest(profile=profile):
                baseline, _ = self.fixtures.render(
                    image, grabcut, None, bbox, profile=profile, source=None)
                result, calls = self.fixtures.render(
                    image, grabcut, self.fixtures.candidate(good), bbox,
                    profile=profile)
                self.assertEqual(calls, 0)
                self.assertEqual(result[2], "opencv-grabcut")
                np.testing.assert_array_equal(
                    np.asarray(result[0]), np.asarray(baseline[0]))


if __name__ == "__main__":
    unittest.main()
