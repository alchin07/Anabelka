"""Weak source contours need local, candidate-independent protection."""

import io
import json
import subprocess
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

import test_image_processor_modnet_routing as routing

processor = routing.processor


class LocalUncertaintyTests(unittest.TestCase):
    def setUp(self):
        self.routes = routing.GrabcutFallbackTests(methodName="runTest")

    def lighting_fixture(self, jpeg=False, uniform=False, spill=True):
        image, grabcut, good, bbox = self.routes.noisy_fixture(spill=spill)
        pixels = np.asarray(image).astype(float)
        backdrop = np.asarray(good.getchannel("A")) == 0
        rows, columns = np.indices(backdrop.shape)
        pixels[backdrop] = (145, 107, 78)
        variation = np.random.default_rng(1).normal(0, 1, backdrop.shape)
        if not uniform:
            variation += 4 * rows / 512 + 2 * columns / 384
        pixels[backdrop] += variation[backdrop, None]
        # The SAME physical transition is directly exposed at the frame,
        # independently of the opaque GrabCut spill in the middle of the image.
        lighting = (columns >= 310) & backdrop
        pixels[lighting] += 1
        image = Image.fromarray(np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
        if jpeg:
            stream = io.BytesIO()
            image.save(stream, "JPEG", quality=95, subsampling=0)
            stream.seek(0)
            with Image.open(stream) as opened:
                image = opened.convert("RGB")
        for subject in (grabcut, good):
            alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(alpha)
        return image, grabcut, good, bbox

    def test_independent_backdrop_transition_does_not_globally_veto_modnet(self):
        for jpeg in (False, True):
            for uniform in (False, True):
                with self.subTest(jpeg=jpeg, uniform=uniform):
                    image, grabcut, good, bbox = self.lighting_fixture(jpeg, uniform)
                    probe = {}
                    evidence = processor.grabcut_fallback_evidence(
                        image, grabcut, bbox, diagnostics=probe)
                    self.assertGreater(probe["counts"]["weak_source_contour_uncertain_edges"], 0)
                    self.assertIsNotNone(evidence, probe.get("reason"))
                    self.assertGreater(np.count_nonzero(evidence["weak_contour_background"]), 0)
                    self.assertTrue(any(
                        "exposed_source_continuation" in region["reasons"]
                        for region in probe["weak_source_contour_local"]["regions"]))
                    self.assertFalse(np.any(evidence["weak_contour_background"] & evidence["protected"]))
                    selection = {}
                    result, calls = self.routes.render(
                        image, grabcut, self.routes.candidate(good), bbox,
                        diagnostics=selection)
                    self.assertEqual(calls, 1)
                    if jpeg and not uniform and result[2] == "opencv-grabcut":
                        # JPEG may leave separate, genuinely unconfirmed edge
                        # pixels. Their existing preservation gates still win;
                        # the resolved lighting zone is not the refusal reason.
                        self.assertEqual(selection["fallback_status"], "rejected")
                        self.assertIn(selection["fallback_reason"],
                                      ("local_foreground_loss", "material_foreground_loss"))
                        self.assertEqual(selection["comparison"]["weak_contour_protected_lost_pixels"], 0)
                    else:
                        self.assertEqual(result[2], "modnet", selection)
                        self.assertEqual(selection["fallback_reason"], "accepted")

    def test_weak_regions_have_bounded_source_reasons_and_local_decisions(self):
        image, grabcut, good, bbox = self.lighting_fixture(jpeg=False)
        probe = {}
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertIsNotNone(evidence, probe.get("reason"))
        local = probe["weak_source_contour_local"]
        self.assertEqual(local["policy"], "source_only_local_protection")
        self.assertGreater(local["region_count"], 0)
        self.assertLessEqual(len(local["regions"]), 64)
        for region in local["regions"]:
            self.assertIn(region["classification"], ("protected", "confirmed_background"))
            self.assertTrue(region["reasons"])
            self.assertEqual(len(region["box"]), 4)
            self.assertGreater(region["pixels"], 0)
            self.assertEqual(region["background_pixels"] + region["protected_pixels"], region["pixels"])
        self.assertEqual(local["auto_effect"], "compare_with_local_protection")
        json.dumps(probe, allow_nan=False)  # No ndarray or nonfinite JSON leaks.
        before = {key: value.copy() for key, value in evidence.items() if isinstance(value, np.ndarray)}
        self.assertTrue(processor.modnet_fallback_is_better(
            good, self.routes.candidate(good)[1], evidence))
        for key, value in before.items():
            np.testing.assert_array_equal(evidence[key], value)

    def test_intersecting_weak_axes_keep_region_counts_separate(self):
        image, grabcut, good, bbox = self.lighting_fixture()
        pixels = np.asarray(image).copy()
        pixels[230:290, 256:310] += 1
        image = Image.fromarray(pixels)
        probe = {}
        processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        regions = probe["weak_source_contour_local"]["regions"]
        self.assertGreater(len(regions), 1)
        for region in regions:
            if "background_pixels" in region:
                self.assertEqual(region["background_pixels"] + region["protected_pixels"],
                                 region["pixels"], region)

    def test_noisy_weak_skin_hair_and_garment_extensions_remain_protected(self):
        for region in ((208, 400, 256, 266), (208, 400, 256, 262),
                       (230, 290, 256, 288)):
            with self.subTest(region=region):
                image, grabcut, missing, bbox = self.lighting_fixture(jpeg=False)
                top, bottom, left, right = region
                pixels = np.asarray(image).copy()
                # Matching source material attaches to the real body's edge;
                # its weak contour cannot be licensed by background noise.
                pixels[top:bottom, left:right] += 1
                image = Image.fromarray(pixels)
                for subject in (grabcut, missing):
                    alpha = subject.getchannel("A")
                    subject.paste(image, (0, 0))
                    subject.putalpha(alpha)
                probe = {}
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
                if evidence is not None:
                    point = ((top + bottom) // 2, (left + right) // 2)
                    self.assertTrue(evidence["protected"][point])
                    self.assertFalse(processor.modnet_fallback_is_better(
                        missing, self.routes.candidate(missing)[1], evidence))
                selection = {}
                result, calls = self.routes.render(
                    image, grabcut, self.routes.candidate(missing), bbox,
                    diagnostics=selection)
                self.assertEqual(result[2], "opencv-grabcut", selection)

    def test_good_grabcut_keeps_worker_unused_with_weak_backdrop_transitions(self):
        image, grabcut, good, bbox = self.lighting_fixture(jpeg=True, spill=False)
        baseline, _ = self.routes.render(image, grabcut, None, bbox, source=None)
        selection = {}
        result, calls = self.routes.render(image, grabcut, self.routes.candidate(good), bbox,
                                          diagnostics=selection)
        self.assertEqual(calls, 0)
        self.assertEqual(result[2], "opencv-grabcut")
        np.testing.assert_array_equal(np.asarray(baseline[0]), np.asarray(result[0]))

    def test_slanted_uncertain_outline_protects_its_whole_attached_interior(self):
        image, grabcut, good, _ = self.lighting_fixture(uniform=True)
        alpha = np.asarray(grabcut.getchannel("A"))
        backdrop = np.asarray(good.getchannel("A")) == 0
        edge = np.zeros(alpha.shape, dtype=bool)
        rows = np.arange(220, 380)
        edge[rows, 280 + (rows - 220) // 2] = True
        # Isolate the downstream geometry of an already-detected contour.
        # The source graph still runs normally and never sees a candidate.
        for axis in ("x", "y"):
            with self.subTest(axis=axis):
                transpose = axis == "y"
                work_alpha = alpha.T if transpose else alpha
                work_backdrop = backdrop.T if transpose else backdrop
                work_edge = edge.T if transpose else edge
                work_image = Image.fromarray(np.asarray(image).transpose(1, 0, 2)) if transpose else image
                empty = np.zeros(work_alpha.shape, dtype=bool)

                def detected(_lab, _alpha, _candidates, _background, report):
                    report["weak_source_contour_calibration"] = {
                        name: {"tangent_width": 31} for name in ("x", "y")}
                    return {"x": work_edge if axis == "x" else empty,
                            "y": work_edge if axis == "y" else empty,
                            "exposed_x": empty, "exposed_y": empty, "calibrated": True}

                with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
                    result = processor.source_connected_background(
                        work_image, work_alpha,
                        ((work_alpha >= 192) & work_backdrop).astype(np.uint8),
                        work_backdrop, {"counts": {}})
                self.assertIsNotNone(result)
                protection = result[2]["weak_contour_protected"]
                if transpose:
                    protection = protection.T
                for row in range(240, 380):
                    outer_edge = 280 + (row - 220) // 2
                    self.assertTrue(np.all(protection[row, 256:min(outer_edge + 1, 352)]), row)

    def test_non_exposed_lighting_is_not_automatically_declared_background(self):
        image, grabcut, good, bbox = self.routes.noisy_fixture()
        pixels = np.asarray(image).astype(float)
        backdrop = np.asarray(good.getchannel("A")) == 0
        rows, columns = np.indices(backdrop.shape)
        pixels[backdrop] += np.random.default_rng(1).normal(0, 1, backdrop.shape)[backdrop, None]
        pixels[(columns >= 310) & (rows >= 180) & (rows < 400) & backdrop] += 1
        image = Image.fromarray(np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
        probe = {}
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertGreater(probe["counts"]["weak_source_contour_uncertain_edges"], 0)
        regions = probe["weak_source_contour_local"]["regions"]
        self.assertTrue(any("no_exposed_source_continuation" in r["reasons"] for r in regions))
        if evidence is not None:
            self.assertTrue(np.any(evidence["weak_contour_protected"]))
            self.assertFalse(processor.modnet_fallback_is_better(
                good, self.routes.candidate(good)[1], evidence))

    def test_matching_garment_without_same_row_source_core_stays_protected(self):
        shape = (512, 384)
        pixels = np.full((*shape, 3), (145, 107, 78), dtype=np.uint8)
        pixels[32:160, 128:256] = (220, 160, 140)
        pixels[160:480, 128:359] += 1  # Matching coat below visible skin.
        alpha = np.zeros(shape, dtype=np.uint8)
        alpha[32:480, 128:368] = 255
        background_like = np.ones(shape, dtype=bool)
        background_like[32:160, 128:256] = False
        edge = np.zeros(shape, dtype=bool)
        edge[160:480, 359] = True
        for axis in ("x", "y"):
            with self.subTest(axis=axis):
                transpose = axis == "y"
                work_alpha = alpha.T if transpose else alpha
                work_background = background_like.T if transpose else background_like
                work_edge = edge.T if transpose else edge
                work_pixels = pixels.transpose(1, 0, 2) if transpose else pixels
                empty = np.zeros(work_alpha.shape, dtype=bool)

                def detected(_lab, _alpha, _candidates, _background, report):
                    report["weak_source_contour_calibration"] = {
                        name: {"tangent_width": 191} for name in ("x", "y")}
                    return {"x": work_edge if axis == "x" else empty,
                            "y": work_edge if axis == "y" else empty,
                            "exposed_x": empty, "exposed_y": empty, "calibrated": True}

                report = {"counts": {}}
                with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
                    result = processor.source_connected_background(
                        Image.fromarray(work_pixels), work_alpha,
                        work_background.astype(np.uint8), work_background, report)
                self.assertIsNotNone(result)
                protection = result[2]["weak_contour_protected"]
                confirmed = result[0]
                if transpose:
                    protection = protection.T
                    confirmed = confirmed.T
                self.assertTrue(np.all(protection[440, 128:359]))
                self.assertFalse(np.any(confirmed[440, 128:359]))
                region = report["weak_source_contour_local"]["regions"][0]
                self.assertIn("unresolved_interior_direction", region["reasons"])
                self.assertGreater(region["unresolved_interior_rows"], 0)

    def test_real_foreground_damage_still_rejects_modnet_after_local_confirmation(self):
        image, grabcut, good, bbox = self.lighting_fixture(jpeg=True)
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[32:96, 144:240] = 0
        damaged = good.copy()
        damaged.putalpha(Image.fromarray(alpha))
        selection = {}
        result, calls = self.routes.render(image, grabcut, self.routes.candidate(damaged), bbox,
                                          diagnostics=selection)
        self.assertEqual(calls, 1)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertFalse(selection["comparison"]["accepted"])

    def test_worker_errors_and_timeouts_keep_grabcut_after_local_confirmation(self):
        image, grabcut, good, bbox = self.lighting_fixture(uniform=True)
        baseline, _ = self.routes.render(image, grabcut, None, bbox, source=None)
        for failure in (RuntimeError("local-worker-failure"),
                        subprocess.TimeoutExpired("local-worker", processor.MODNET_WORKER_TIMEOUT)):
            with self.subTest(failure=type(failure).__name__):
                selection = {}
                result, calls = self.routes.render(image, grabcut, failure, bbox,
                                                  diagnostics=selection)
                self.assertEqual(calls, 1)
                self.assertEqual(result[2], "opencv-grabcut")
                np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))
                self.assertEqual(selection["fallback_status"], "worker_failed")
                self.assertEqual(selection["fallback_reason"], "fallback_worker_failed")

    def test_resolved_weak_zones_preserve_geometry_sizes_and_profile_rendering(self):
        image, grabcut, good, bbox = self.lighting_fixture(uniform=True)
        for profile in ("studio-light", "anabelka-brand"):
            for geometry in ({"crop_box": (10, 10, 370, 500), "crop_strategy": "aspect-fill"},
                             {"crop_strategy": "torso-zoom-out", "zoom_scale": .75,
                              "torso_center": (192., 256.), "shoulder_center": (192., 100.)}):
                with self.subTest(profile=profile, geometry=geometry):
                    # Feed the same accepted matte as a primary MODNet result;
                    # only selection differs, so rendering must be identical.
                    with patch.object(processor, "complex_background_prefers_modnet", return_value=True):
                        expected, expected_calls = self.routes.render(
                            image, grabcut, self.routes.candidate(good), bbox, profile=profile, **geometry)
                    actual, actual_calls = self.routes.render(
                        image, grabcut, self.routes.candidate(good), bbox, profile=profile, **geometry)
                    self.assertEqual(expected_calls, 1)
                    self.assertEqual(actual_calls, 1)
                    self.assertEqual(actual[2], "modnet")
                    self.assertEqual(actual[0].size, (1200, 1800))
                    self.assertEqual(processor.THUMB_SIZE, (320, 480))
                    np.testing.assert_array_equal(np.asarray(actual[0]), np.asarray(expected[0]))
        result, calls = self.routes.render(
            image, grabcut, self.routes.candidate(good), bbox, profile="original-canvas")
        self.assertEqual(calls, 0)


if __name__ == "__main__":
    unittest.main()
