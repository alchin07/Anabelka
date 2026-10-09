"""Uncertain source edges must stay local without erasing enclosed material."""

import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

import test_image_processor_local_uncertainty as local
import test_image_processor_modnet_routing as routing

processor = routing.processor


class LocalSourceBoundsTests(unittest.TestCase):
    def smooth_spill(self):
        shape = (512, 384)
        pixels = np.full((*shape, 3), (145, 107, 78), dtype=np.uint8)
        alpha = np.zeros(shape, dtype=np.uint8)
        alpha[48:464, 48:352] = 255
        return pixels, alpha, np.ones(shape, dtype=bool)

    def source_with_detected_edges(self, pixels, alpha, background, edge, axis):
        """Control detector geometry while exercising the actual source graph."""
        transpose = axis == "y"
        work_pixels = pixels.transpose(1, 0, 2) if transpose else pixels
        work_alpha = alpha.T if transpose else alpha
        work_background = background.T if transpose else background
        work_edge = edge.T if transpose else edge
        empty = np.zeros(work_alpha.shape, dtype=bool)

        def detected(_lab, _alpha, _candidates, _background, report):
            report["weak_source_contour_calibration"] = {
                name: {"tangent_width": 31} for name in ("x", "y")}
            return {"x": work_edge if axis == "x" else empty,
                    "y": work_edge if axis == "y" else empty,
                    "exposed_x": empty, "exposed_y": empty, "calibrated": True}

        report = {"counts": {}}
        with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
            result = processor.source_connected_background(
                Image.fromarray(work_pixels), work_alpha,
                work_background.astype(np.uint8), work_background, report)
        self.assertIsNotNone(result, report.get("reason"))
        confirmed, _, masks = result
        if transpose:
            confirmed = confirmed.T
            masks = {name: value.T for name, value in masks.items()}
        return confirmed, masks, report

    def test_several_unresolved_edges_without_core_have_bounded_protection(self):
        pixels, alpha, background = self.smooth_spill()
        edge = np.zeros(alpha.shape, dtype=bool)
        seeds = ((210, 236, 82), (280, 310, 196), (354, 380, 314))
        for top, bottom, column in seeds:
            edge[top:bottom, column] = True
        protections = []
        for axis in ("x", "y"):
            with self.subTest(axis=axis):
                _, masks, _ = self.source_with_detected_edges(
                    pixels, alpha, background, edge, axis)
                protection = masks["weak_contour_protected"]
                protections.append(protection)
                uncertainty = masks["weak_contour_zones"] & (alpha >= 192)
                self.assertTrue(np.all(protection[uncertainty]))
                self.assertLess(np.count_nonzero(protection), alpha.size * 0.06)
                # A short local uncertainty cannot reclaim an opaque normal
                # line merely because that line has no contrasting body core.
                for top, bottom, column in seeds:
                    row = (top + bottom) // 2
                    self.assertTrue(protection[row, column])
                    self.assertFalse(np.all(protection[row, 48:352]))
                self.assertFalse(np.any(protection[200:250, 220:260]))
        np.testing.assert_array_equal(protections[0], protections[1])

    def test_distant_contrasting_core_does_not_create_a_protected_corridor(self):
        pixels, alpha, background = self.smooth_spill()
        pixels[190:250, 48:90] = (220, 160, 140)
        background[190:250, 48:90] = False
        edge = np.zeros(alpha.shape, dtype=bool)
        edge[210:236, 314] = True
        protections = []
        for axis in ("x", "y"):
            with self.subTest(axis=axis):
                _, masks, _ = self.source_with_detected_edges(
                    pixels, alpha, background, edge, axis)
                protection = masks["weak_contour_protected"]
                protections.append(protection)
                self.assertTrue(np.all(protection[edge]))
                self.assertFalse(np.any(protection[212:234, 112:280]))
                self.assertLess(np.count_nonzero(protection), alpha.size * 0.03)
        np.testing.assert_array_equal(protections[0], protections[1])

    def test_unpatched_noisy_backdrop_edges_do_not_protect_most_of_the_picture(self):
        pixels, alpha, background = self.smooth_spill()
        noise = np.random.default_rng(1).normal(0, 1, alpha.shape)
        pixels = pixels.astype(float) + noise[:, :, None]
        # This subtle tonal patch is part of the supplier backdrop. Its
        # unresolved extrema may stay conservative, but must stay local.
        pixels[230:290, 176:221] += 1
        image = Image.fromarray(np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
        report = {"counts": {}}
        result = processor.source_connected_background(
            image, alpha, background.astype(np.uint8), background, report)
        self.assertIsNotNone(result, report.get("reason"))
        masks = result[2]
        self.assertGreater(np.count_nonzero(masks["weak_contours"]), 0)
        protection = masks["weak_contour_protected"]
        self.assertTrue(np.all(protection[masks["weak_contour_zones"] & (alpha >= 192)]))
        self.assertLess(np.count_nonzero(protection), alpha.size * 0.20)
        self.assertFalse(protection[430, 200])
        self.assertFalse(protection[260, 60])

    def test_independently_closed_matching_material_remains_protected(self):
        pixels, alpha, background = self.smooth_spill()
        pixels[48:128, 140:240] = (220, 160, 140)
        background[48:128, 140:240] = False
        # A distinct, palette-matching material is bounded on all four source
        # sides. It does not need a same-row contrasting foreground core.
        pixels[340:420, 150:235] = (149, 109, 80)
        edge = np.zeros(alpha.shape, dtype=bool)
        edge[200:226, 314] = True
        confirmed, _, _ = self.source_with_detected_edges(
            pixels, alpha, background, edge, "x")
        interior = np.s_[348:412, 158:227]
        self.assertFalse(np.any(confirmed[interior]))
        image = Image.fromarray(pixels)
        subject = image.convert("RGBA")
        subject.putalpha(Image.fromarray(alpha))
        evidence = processor.grabcut_fallback_evidence(image, subject, (140, 48, 100, 372))
        if evidence is not None:
            self.assertTrue(np.all(evidence["protected"][interior]))
            self.assertFalse(np.any(evidence["confident_background"][interior]))
            self.assertFalse(np.any(evidence["residual"][interior]))

    def test_wide_low_contrast_material_cannot_lose_its_center_behind_a_kept_outline(self):
        fixtures = local.LocalUncertaintyTests(methodName="runTest")
        fixtures.setUp()
        image, grabcut, good, bbox = fixtures.lighting_fixture(jpeg=False)
        pixels = np.asarray(image).copy()
        pixels[208:400, 256:320] += 1
        image = Image.fromarray(pixels)
        for subject in (grabcut, good):
            original_alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(original_alpha)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        # Refusing unresolved source material before candidate comparison is
        # also safe. If comparison proceeds, the whole material must survive.
        if evidence is None:
            return
        interior = np.s_[230:378, 270:306]
        self.assertTrue(np.all(evidence["protected"][interior]))
        self.assertFalse(np.any(evidence["confident_background"][interior]))
        candidate_alpha = np.asarray(grabcut.getchannel("A")).copy()
        candidate_alpha[evidence["confident_background"]] = 0
        candidate_alpha[208:400, 256:320] = 255
        candidate_alpha[interior] = 0
        candidate = image.convert("RGBA")
        candidate.putalpha(Image.fromarray(candidate_alpha))
        ratio = float(np.mean(candidate_alpha >= 128))
        self.assertFalse(processor.modnet_fallback_is_better(candidate, ratio, evidence))

    def test_curved_matching_extension_cannot_keep_outline_and_lose_center(self):
        fixtures = local.LocalUncertaintyTests(methodName="runTest")
        fixtures.setUp()
        image, grabcut, _, bbox = fixtures.lighting_fixture(jpeg=False)
        pixels = np.asarray(image).copy()
        rows, columns = np.indices(pixels.shape[:2])
        # A physical, palette-matching sleeve joins the body's right edge.
        # Its curved outline has a short detected normal segment far from the
        # contrasting body; local contour attachment alone cannot bound it.
        material = (((rows - 300) / 80) ** 2
                    + ((columns - 256) / 48) ** 2 <= 1) & (columns >= 256)
        pixels[material] += 1
        image = Image.fromarray(pixels)
        original_alpha = np.asarray(grabcut.getchannel("A"))
        grabcut = image.convert("RGBA")
        grabcut.putalpha(Image.fromarray(original_alpha))
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        if evidence is None:
            return
        # Remove five pixels around the physical outline to test a candidate
        # that preserves every contour sample while deleting the real centre.
        center = processor.cv2.erode(
            material.astype(np.uint8), np.ones((11, 11), dtype=np.uint8),
        ).astype(bool)
        candidate_alpha = original_alpha.copy()
        candidate_alpha[evidence["confident_background"]] = 0
        candidate_alpha[material] = 255
        candidate_alpha[center] = 0
        candidate = image.convert("RGBA")
        candidate.putalpha(Image.fromarray(candidate_alpha))
        self.assertFalse(processor.modnet_fallback_is_better(
            candidate, float(np.mean(candidate_alpha >= 128)), evidence))
        self.assertTrue(np.all(evidence["protected"][center]))
        self.assertFalse(np.any(evidence["confident_background"][center]))


if __name__ == "__main__":
    unittest.main()
