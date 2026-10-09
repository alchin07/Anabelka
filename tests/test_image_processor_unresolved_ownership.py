"""Unknown source material owns its interior while other spill stays comparable."""

import json
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

import test_image_processor_local_uncertainty as local
import test_image_processor_modnet_routing as routing

processor = routing.processor


class UnresolvedOwnershipTests(unittest.TestCase):
    def setUp(self):
        self.routes = routing.GrabcutFallbackTests(methodName="runTest")

    @staticmethod
    def rgba(image, alpha):
        subject = image.convert("RGBA")
        subject.putalpha(Image.fromarray(alpha.astype(np.uint8)))
        return subject

    def two_lobes(self, transpose=False):
        """Two opaque domains share clear backdrop, with uncertainty in only one.

        Detector geometry is controlled to isolate ownership from detection.
        All source graph, colour, texture and comparison decisions run normally.
        A transparent source strip separates the two possible material extents.
        """
        shape = (512, 384)
        pixels = np.full((*shape, 3), (145, 107, 78), dtype=np.uint8)
        core = np.zeros(shape, dtype=bool)
        core[32:144, 128:256] = True
        pixels[core] = (220, 160, 140)
        uncertain = np.zeros(shape, dtype=bool)
        uncertain[184:400, 48:144] = True
        independent = np.zeros(shape, dtype=bool)
        independent[184:400, 232:352] = True
        edge = np.zeros(shape, dtype=bool)
        edge[250:290, 100] = True
        alpha = np.where(core | uncertain | independent, 255, 0).astype(np.uint8)
        candidate_alpha = np.where(core | uncertain, 255, 0).astype(np.uint8)
        bbox = (128, 32, 128, 368)
        axis = "x"
        if transpose:
            pixels = pixels.transpose(1, 0, 2)
            alpha, candidate_alpha = alpha.T, candidate_alpha.T
            core, uncertain, independent, edge = core.T, uncertain.T, independent.T, edge.T
            bbox = (bbox[1], bbox[0], bbox[3], bbox[2])
            axis = "y"
        image = Image.fromarray(pixels)
        empty = np.zeros(alpha.shape, dtype=bool)

        def detected(_lab, _alpha, _candidates, _background, report):
            report["weak_source_contour_calibration"] = {
                name: {"tangent_width": 31} for name in ("x", "y")}
            return {"x": edge if axis == "x" else empty,
                    "y": edge if axis == "y" else empty,
                    "exposed_x": empty, "exposed_y": empty, "calibrated": True}

        return (image, self.rgba(image, alpha), self.rgba(image, candidate_alpha),
                bbox, uncertain, independent, detected)

    def test_unresolved_lobe_keeps_full_interior_and_independent_spill_is_accepted(self):
        for transpose in (False, True):
            with self.subTest(transpose=transpose):
                image, grabcut, useful, bbox, uncertain, independent, detected = self.two_lobes(transpose)
                image_before = np.asarray(image).copy()
                grabcut_before = np.asarray(grabcut).copy()
                probe = {}
                with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
                self.assertIsNotNone(evidence, probe.get("reason"))
                self.assertGreater(probe["counts"]["unresolved_weak_material_regions"], 0)
                self.assertTrue(np.all(evidence["protected"][uncertain]))
                self.assertFalse(np.any(evidence["confident_background"][uncertain]))
                self.assertFalse(np.any(evidence["residual"][uncertain]))
                self.assertGreater(np.count_nonzero(evidence["residual"] & independent), 20000)
                before = {name: mask.copy() for name, mask in evidence.items()
                          if isinstance(mask, np.ndarray)}
                selection = {}
                self.assertTrue(processor.modnet_fallback_is_better(
                    useful, self.routes.candidate(useful)[1], evidence, diagnostics=selection), selection)
                self.assertEqual(selection["reason"], "accepted")
                for name, original in before.items():
                    np.testing.assert_array_equal(evidence[name], original)
                np.testing.assert_array_equal(np.asarray(image), image_before)
                np.testing.assert_array_equal(np.asarray(grabcut), grabcut_before)
                json.dumps(probe, allow_nan=False)
                json.dumps(selection, allow_nan=False)

    def test_real_source_tone_changes_do_not_bound_unknown_material(self):
        """A lighting band or contrasting fabric stripe can cross one garment.

        The real detector sees a weak outline only in the upper material. Its
        unknown extent must also retain the lower, background-matching fabric.
        Neither an exposed source step nor a nonmatching fabric stripe proves
        that the original opaque material has ended.
        """
        for change in ("exposed_lighting", "contrasting_stripe"):
            for transpose in (False, True):
                with self.subTest(change=change, transpose=transpose):
                    image, grabcut, _, bbox, uncertain, independent, _ = self.two_lobes()
                    original = np.asarray(grabcut.getchannel("A")).copy()
                    pixels = np.asarray(image).astype(float)
                    rows, columns = np.indices(original.shape)
                    backdrop_tones = (original == 0) | uncertain | independent
                    variation = (np.random.default_rng(1).normal(0, 1, original.shape)
                                 + 4 * rows / 512 + 2 * columns / 384)
                    pixels[backdrop_tones] += variation[backdrop_tones, None]
                    visible_outline = uncertain & (rows < 290)
                    pixels[visible_outline] += 1
                    if change == "exposed_lighting":
                        pixels[310:] += 4
                    else:
                        pixels[310:320, 48:144] = (60, 45, 55)
                    lower_center = uncertain & (rows >= 340) & (rows < 392)
                    lower_center &= (columns >= 56) & (columns < 136)
                    pixels = np.clip(np.rint(pixels), 0, 255).astype(np.uint8)
                    if transpose:
                        pixels = pixels.transpose(1, 0, 2)
                        original, uncertain = original.T, uncertain.T
                        independent, lower_center = independent.T, lower_center.T
                        bbox = (bbox[1], bbox[0], bbox[3], bbox[2])
                    image = Image.fromarray(pixels)
                    grabcut = self.rgba(image, original)
                    probe = {}
                    evidence = processor.grabcut_fallback_evidence(
                        image, grabcut, bbox, diagnostics=probe)
                    self.assertIsNotNone(evidence, probe.get("reason"))
                    self.assertGreater(probe["counts"]["unresolved_weak_material_regions"], 0)
                    self.assertTrue(np.all(evidence["unresolved_material_protected"][uncertain]))
                    self.assertFalse(np.any(evidence["confident_background"][uncertain]))
                    self.assertFalse(np.any(evidence["residual"][uncertain]))
                    self.assertGreater(np.count_nonzero(evidence["residual"] & independent), 20000)
                    useful_alpha = original.copy()
                    useful_alpha[evidence["confident_background"]] = 0
                    useful = self.rgba(image, useful_alpha)
                    self.assertTrue(processor.modnet_fallback_is_better(
                        useful, self.routes.candidate(useful)[1], evidence))
                    damaged_alpha = useful_alpha.copy()
                    damaged_alpha[lower_center] = 0
                    selection = {}
                    self.assertFalse(processor.modnet_fallback_is_better(
                        self.rgba(image, damaged_alpha),
                        float(np.mean(damaged_alpha >= 128)), evidence,
                        diagnostics=selection))
                    self.assertIn(selection["reason"], (
                        "protected_foreground_loss", "local_foreground_loss",
                        "material_foreground_loss", "unresolved_material_foreground_loss"))

    def test_diagonal_soft_alpha_bridge_keeps_one_unknown_material_component(self):
        image, grabcut, _, bbox, uncertain, independent, detected = self.two_lobes()
        original = np.asarray(grabcut.getchannel("A")).copy()
        # An above-clear alpha path joins the two islands diagonally. Keeping
        # only horizontal/vertical links or only opaque/palette pixels would
        # falsely make the second half independently removable.
        for offset in range(90):
            row, column = 205 + offset, 143 + offset
            original[row, column] = max(original[row, column], 32)
        probe = {}
        with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
            evidence = processor.grabcut_fallback_evidence(
                image, self.rgba(image, original), bbox, diagnostics=probe)
        self.assertIsNone(evidence)
        self.assertEqual(probe["reason"], "unresolved_weak_material_extent")
        self.assertGreaterEqual(probe["counts"]["unresolved_material_protected"],
                                np.count_nonzero(uncertain | independent))

    def test_native_soft_alpha_bridge_survives_bounded_ownership_analysis(self):
        image, grabcut, _, bbox, uncertain, independent, detected = self.two_lobes()
        scale = 4
        image = image.resize((image.width * scale, image.height * scale),
                             Image.Resampling.NEAREST)
        original = np.asarray(grabcut.getchannel("A").resize(
            image.size, Image.Resampling.NEAREST)).copy()
        # This native above-clear attachment disappears from an averaged
        # analysis alpha. That must not license removal of the whole second
        # material island, even though inference still uses the bounded grid.
        for offset in range(357):
            row, column = 820 + offset, 575 + offset
            original[row, column] = max(original[row, column], 32)
        component_count, _ = processor.cv2.connectedComponents(
            (original > 16).astype(np.uint8), connectivity=8)
        self.assertEqual(component_count - 1, 2)
        probe = {}
        with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
            evidence = processor.grabcut_fallback_evidence(
                image, self.rgba(image, original),
                tuple(value * scale for value in bbox), diagnostics=probe)
        self.assertEqual(probe["analysis_size"], [384, 512])
        self.assertIsNone(evidence)
        self.assertEqual(probe["reason"], "unresolved_weak_material_extent")
        self.assertGreaterEqual(probe["counts"]["unresolved_material_protected"],
                                np.count_nonzero(uncertain | independent))

    def test_unresolved_lobe_cannot_keep_its_outline_and_lose_its_center(self):
        for transpose in (False, True):
            with self.subTest(transpose=transpose):
                image, grabcut, useful, bbox, uncertain, _, detected = self.two_lobes(transpose)
                probe = {}
                with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
                self.assertIsNotNone(evidence, probe.get("reason"))
                center = processor.cv2.erode(
                    uncertain.astype(np.uint8), np.ones((17, 17), dtype=np.uint8),
                ).astype(bool)
                for loss in (uncertain, center):
                    with self.subTest(center_only=loss is center):
                        alpha = np.asarray(useful.getchannel("A")).copy()
                        alpha[loss] = 0
                        damaged = self.rgba(image, alpha)
                        selection = {}
                        self.assertFalse(processor.modnet_fallback_is_better(
                            damaged, self.routes.candidate(damaged)[1], evidence,
                            diagnostics=selection))
                        self.assertIn(selection["reason"], (
                            "protected_foreground_loss", "local_foreground_loss",
                            "material_foreground_loss", "weak_contour_foreground_loss",
                            "unresolved_material_foreground_loss"))

    def test_unresolved_material_rejects_tiny_interior_hole_and_uniform_opacity_loss(self):
        image, grabcut, useful, bbox, uncertain, _, detected = self.two_lobes()
        probe = {}
        with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
            evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertIsNotNone(evidence, probe.get("reason"))
        original = np.asarray(useful.getchannel("A"))
        tiny_hole = original.copy()
        tiny_hole[320:324, 80:84] = 0
        translucent = original.copy()
        translucent[uncertain] = 224
        for kind, alpha in (("tiny_hole", tiny_hole), ("translucent", translucent)):
            with self.subTest(kind=kind):
                damaged = self.rgba(image, alpha)
                selection = {}
                self.assertFalse(processor.modnet_fallback_is_better(
                    damaged, self.routes.candidate(damaged)[1], evidence,
                    diagnostics=selection))
                self.assertEqual(selection["reason"], "unresolved_material_foreground_loss")

    def test_real_wide_and_curved_material_center_never_becomes_removal_evidence(self):
        fixtures = local.LocalUncertaintyTests(methodName="runTest")
        fixtures.setUp()
        for kind in ("wide", "curved"):
            for transpose in (False, True):
                with self.subTest(kind=kind, transpose=transpose):
                    image, grabcut, _, bbox = fixtures.lighting_fixture(jpeg=False)
                    pixels = np.asarray(image).copy()
                    rows, columns = np.indices(pixels.shape[:2])
                    material = np.zeros(rows.shape, dtype=bool)
                    if kind == "wide":
                        material[208:400, 256:320] = True
                    else:
                        material = ((((rows - 300) / 80) ** 2
                                     + ((columns - 256) / 48) ** 2 <= 1)
                                    & (columns >= 256))
                    pixels[material] += 1
                    original = np.asarray(grabcut.getchannel("A")).copy()
                    if transpose:
                        pixels, original, material = pixels.transpose(1, 0, 2), original.T, material.T
                        bbox = (bbox[1], bbox[0], bbox[3], bbox[2])
                    image = Image.fromarray(pixels)
                    grabcut = self.rgba(image, original)
                    probe = {}
                    evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
                    center = processor.cv2.erode(
                        material.astype(np.uint8), np.ones((11, 11), dtype=np.uint8),
                    ).astype(bool)
                    self.assertGreater(np.count_nonzero(center), 4000)
                    # If all useful residual shares the unresolved extent,
                    # withholding a second opinion is the conservative result.
                    if evidence is None:
                        self.assertIn(probe["reason"], (
                            "unresolved_weak_material_extent", "insufficient_residual"))
                        alpha = original.copy()
                        alpha[center] = 0
                        damaged = self.rgba(image, alpha)
                        with (
                            patch.object(processor, "complex_background_prefers_modnet", return_value=False),
                            patch.object(processor, "cosmetic_cleanup_subject_fringes", side_effect=lambda _image, subject, _bbox: subject),
                            patch.object(processor, "refine_upper_enclosed_background_gaps", side_effect=lambda _image, subject, _bbox: subject),
                        ):
                            baseline, _ = self.routes.render(image, grabcut, None, bbox, source=None)
                            actual, calls = self.routes.render(image, grabcut, self.routes.candidate(damaged), bbox)
                        self.assertEqual(calls, 0)
                        self.assertEqual(actual[2], "opencv-grabcut")
                        np.testing.assert_array_equal(np.asarray(actual[0]), np.asarray(baseline[0]))
                        continue
                    self.assertTrue(np.all(evidence["protected"][center]))
                    self.assertFalse(np.any(evidence["confident_background"][center]))
                    self.assertFalse(np.any(evidence["residual"][center]))
                    alpha = original.copy()
                    alpha[evidence["confident_background"]] = 0
                    alpha[material] = 255
                    alpha[center] = 0
                    damaged = self.rgba(image, alpha)
                    self.assertFalse(processor.modnet_fallback_is_better(
                        damaged, self.routes.candidate(damaged)[1], evidence))

    def test_routing_uses_independent_improvement_and_preserves_worker_failure_output(self):
        image, grabcut, useful, bbox, _, _, detected = self.two_lobes()
        with (
            patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected),
            patch.object(processor, "complex_background_prefers_modnet", return_value=False),
            patch.object(processor, "cosmetic_cleanup_subject_fringes", side_effect=lambda _image, subject, _bbox: subject),
            patch.object(processor, "refine_upper_enclosed_background_gaps", side_effect=lambda _image, subject, _bbox: subject),
        ):
            selection = {}
            result, calls = self.routes.render(image, grabcut, self.routes.candidate(useful), bbox,
                                              diagnostics=selection)
            self.assertEqual(calls, 1)
            self.assertEqual(result[2], "modnet", selection)
            baseline, _ = self.routes.render(image, grabcut, None, bbox, source=None)
            result, calls = self.routes.render(image, grabcut, RuntimeError("ownership-worker-failure"), bbox)
            self.assertEqual(calls, 1)
            self.assertEqual(result[2], "opencv-grabcut")
            np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_invalid_or_corrupted_candidates_do_not_bypass_ownership(self):
        image, grabcut, useful, bbox, _, _, detected = self.two_lobes()
        probe = {}
        with patch.object(processor, "weak_source_contour_uncertainty", side_effect=detected):
            evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox, diagnostics=probe)
        self.assertIsNotNone(evidence, probe.get("reason"))
        random_alpha = np.random.default_rng(818).integers(0, 256, image.size[::-1], dtype=np.uint8)
        random_candidate = self.rgba(image, random_alpha)
        empty, full = useful.copy(), useful.copy()
        empty.putalpha(0)
        full.putalpha(255)
        candidates = (
            (useful.convert("RGB"), .2), (useful.resize((96, 128)), .2),
            (useful, float("nan")), (empty, .2), (full, .2),
            (random_candidate, .2), (grabcut, .2),
        )
        for candidate, ratio in candidates:
            with self.subTest(mode=candidate.mode, size=candidate.size, ratio=ratio):
                self.assertFalse(processor.modnet_fallback_is_better(candidate, ratio, evidence))

    def test_small_protected_source_component_has_an_independent_loss_gate(self):
        shape = (100, 100)
        core = np.zeros(shape, dtype=bool)
        core[15:85, 30:70] = True
        residual = np.zeros(shape, dtype=bool)
        residual[30:85, 70:90] = True
        small = np.zeros(shape, dtype=bool)
        small[40:44, 42:46] = True
        original = np.where(core | residual, 255, 0).astype(np.uint8)
        alpha = np.where(core & ~small, 255, 0).astype(np.uint8)
        image = Image.new("RGB", (100, 100), (220, 160, 140))
        evidence = {"source_size": image.size, "alpha": original, "residual": residual,
                    "background_like": ~core, "protected": core,
                    "weak_contour_protected": small}
        selection = {}
        self.assertFalse(processor.modnet_fallback_is_better(
            self.rgba(image, alpha), float(np.mean(alpha >= 128)), evidence,
            diagnostics=selection))
        self.assertGreaterEqual(selection["protected_retention"], .98)
        self.assertEqual(selection["reason"], "weak_contour_foreground_loss")

    def test_good_grabcut_still_does_not_invoke_worker(self):
        image, grabcut, good, bbox = self.routes.fixture(spill=False)
        result, calls = self.routes.render(image, grabcut, self.routes.candidate(good), bbox)
        self.assertEqual(calls, 0)
        self.assertEqual(result[2], "opencv-grabcut")


if __name__ == "__main__":
    unittest.main()
