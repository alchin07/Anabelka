"""Residual accounting must explain losses without changing source decisions."""

import json
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

import test_image_processor_local_uncertainty as local

processor = local.processor


class ResidualDiagnosticsTests(unittest.TestCase):
    def test_local_confirmation_accounting_includes_soft_alpha(self):
        fixture = local.LocalUncertaintyTests(methodName="runTest")
        fixture.setUp()
        image, grabcut, _, bbox = fixture.lighting_fixture()
        alpha = np.asarray(grabcut.getchannel("A")).copy()
        # The same resolved source lighting zone includes soft GrabCut alpha.
        # Continuity residuals use >=128; accounting must cover that domain,
        # even though final opaque-background evidence uses >=192.
        alpha[175:435, 305:309] = 160
        grabcut.putalpha(Image.fromarray(alpha))
        report = {}
        evidence = processor.grabcut_fallback_evidence(
            image, grabcut, bbox, diagnostics=report)
        self.assertIsNotNone(evidence, report)
        counts = report["counts"]
        self.assertEqual(counts["after_source_graph"]
                         + counts["restored_by_local_confirmation"]
                         - counts["removed_by_local_protection"],
                         counts["after_continuity"])

    def test_graph_guards_local_protection_and_filters_balance(self):
        fixture = local.LocalUncertaintyTests(methodName="runTest")
        fixture.setUp()
        image, grabcut, _, bbox = fixture.lighting_fixture()
        report = {}
        evidence = processor.grabcut_fallback_evidence(
            image, grabcut, bbox, diagnostics=report, measure_guard_effect=True)
        self.assertIsNotNone(evidence, report)
        counts = report["counts"]
        self.assertIn("removed_by_source_graph", counts)
        self.assertEqual(counts["before_continuity"] - counts["removed_by_source_graph"],
                         counts["after_source_graph"])
        self.assertEqual(counts["removed_by_source_graph"],
                         counts["removed_by_graph_guard_pixels"]
                         + counts["removed_by_graph_paths"])
        self.assertEqual(counts["removed_by_source_graph"],
                         counts["removed_by_source_contours_without_guards"]
                         + counts["removed_by_uncertainty_guard_pixels"]
                         + counts["removed_by_uncertainty_guard_disconnection"])
        self.assertEqual(counts["after_source_graph"] + counts["restored_by_local_confirmation"]
                         - counts["removed_by_local_protection"], counts["after_continuity"])
        self.assertEqual(counts["after_continuity"] - counts["removed_by_texture"],
                         counts["after_texture"])
        self.assertEqual(counts["after_texture"] - counts["removed_by_detail_filter"],
                         counts["after_detail_filter"])
        self.assertGreaterEqual(counts["source_graph_guard_induced_disconnected"], 0)
        self.assertGreater(report["timings_ms"]["total"], 0)
        for name in ("weak_contours", "source_contours", "source_graph", "local_regions"):
            self.assertGreaterEqual(report["source_timings_ms"][name], 0)
        json.dumps(report, allow_nan=False)

    def test_diagnostic_graph_comparison_is_observational(self):
        fixture = local.LocalUncertaintyTests(methodName="runTest")
        fixture.setUp()
        image, grabcut, _, bbox = fixture.lighting_fixture(jpeg=True)
        before = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        report = {}
        after = processor.grabcut_fallback_evidence(
            image, grabcut, bbox, diagnostics=report, measure_guard_effect=True)
        self.assertIsNotNone(before)
        self.assertIsNotNone(after)
        self.assertEqual(before.keys(), after.keys())
        for name, mask in before.items():
            if isinstance(mask, np.ndarray):
                np.testing.assert_array_equal(mask, after[name], err_msg=name)

    def test_production_timings_do_not_repeat_observational_graph(self):
        fixture = local.LocalUncertaintyTests(methodName="runTest")
        fixture.setUp()
        image, grabcut, _, bbox = fixture.lighting_fixture()
        report = {}
        with patch.object(processor, "source_clear_edge_paths",
                          wraps=processor.source_clear_edge_paths) as paths:
            evidence = processor.grabcut_fallback_evidence(
                image, grabcut, bbox, diagnostics=report)
        self.assertIsNotNone(evidence)
        self.assertEqual(paths.call_count, 1)
        self.assertNotIn("guard_comparison", report)

    def test_no_independent_residual_skips_later_material_analysis(self):
        fixture = local.LocalUncertaintyTests(methodName="runTest")
        fixture.setUp()
        image, grabcut, _, bbox = fixture.lighting_fixture()
        pixels = np.asarray(image).copy()
        pixels[208:400, 256:320] += 1
        image = local.Image.fromarray(pixels)
        probe = {}
        with patch.object(processor, "source_paired_lines",
                          wraps=processor.source_paired_lines) as texture:
            evidence = processor.grabcut_fallback_evidence(
                image, grabcut, bbox, diagnostics=probe)
        self.assertIsNone(evidence)
        self.assertEqual(probe["reason"], "unresolved_weak_material_extent")
        self.assertLess(probe["counts"]["after_continuity"], probe["minimum_required"])
        self.assertEqual(texture.call_count, 0)


if __name__ == "__main__":
    unittest.main()
