"""Validation-only evidence for cropped torso masks; GrabCut seeds stay strict."""
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

from test_image_processor_no_bbox import p, no_detector


def closeup(shaded=False):
    pixels = np.full((240, 180, 3), (145, 107, 78), dtype=np.uint8)
    alpha = np.zeros((240, 180), dtype=np.uint8)
    if shaded:
        # Independent, uniform shades of one beige backdrop.
        pixels[:, :90] = (179, 131, 93)
        pixels[:, 90:] = (161, 111, 78)
        pixels[160:, 145:] = (145, 95, 63)
    # Cropped torso/shoulders, occupying both bottom corners in the plain case.
    pixels[:, 60:120] = (220, 160, 140)
    alpha[:, 60:120] = 255
    pixels[65:, :] = (220, 160, 140)
    alpha[65:, :] = 255
    if shaded:
        pixels[160:, 145:] = (145, 95, 63)
        alpha[160:, 145:] = 0
    pixels[90:145, 30:145] = (240, 235, 220)  # white garment
    pixels[25:90, 48:50] = (245, 240, 225)  # separate narrow strap
    alpha[25:90, 48:50] = 255
    alpha[:65, 60] = 180  # preserve supplied soft opacity as well
    subject = Image.fromarray(pixels).convert('RGBA')
    subject.putalpha(Image.fromarray(alpha))
    return Image.fromarray(pixels), subject


def validate(image, subject):
    diag = {}
    ratio = float(np.mean(np.asarray(subject.getchannel('A')) >= 128))
    with ExitStack() as stack:
        no_detector(stack)
        stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(subject, ratio, {})))
        try:
            master, result = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')
            diag = result['mask_selection']['mask_quality']['modnet']
            return True, diag, master
        except p.MaskProcessingError as error:
            return False, error.normalization['mask_selection']['mask_quality']['modnet'], None


class CloseupEvidenceTests(unittest.TestCase):
    def test_body_bottom_corners_accept_valid_modnet_and_preserve_pixels(self):
        image, subject = closeup()
        self.assertIsNone(p.build_undetected_seed_mask(image))  # still unsafe for training
        original = np.asarray(subject).copy()
        ok, diag, master = validate(image, subject)
        self.assertTrue(ok, diag)
        self.assertEqual(diag['reason'], 'structural_checks_passed')
        np.testing.assert_array_equal(np.asarray(subject), original)
        expected = p.compose_subject_on_background(p.transparent_standard_canvas(subject, p.MASTER_SIZE), 'studio-light')
        np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))

    def test_uniform_shaded_top_and_matching_lower_background_accept_modnet(self):
        image, subject = closeup(shaded=True)
        self.assertIsNone(p.build_undetected_seed_mask(image))
        ok, diag, _ = validate(image, subject)
        self.assertTrue(ok, diag)

    def test_auto_uses_valid_modnet_after_strict_grabcut_refusal(self):
        image, subject = closeup()
        ratio = float(np.mean(np.asarray(subject.getchannel('A')) >= 128))
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'complex_background_prefers_modnet', return_value=False))
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(subject, ratio, {})))
            _, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='auto')
        self.assertEqual(worker.call_count, 1)
        self.assertEqual(diag['mask_method'], 'modnet')
        self.assertFalse(diag['background_fallback'])

    def test_forced_grabcut_remains_controlled_refusal(self):
        image, _ = closeup()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', side_effect=AssertionError('forced GrabCut invoked worker')))
            with self.assertRaisesRegex(p.MaskProcessingError, 'grabcut_mask_unavailable'):
                p.normalized_master(image, 'studio-light', mask_mode='grabcut')

    def test_preserves_all_source_loss_reasons(self):
        for kind, reason in (('component', 'source_component_loss'),
                             ('local', 'source_local_material_loss'),
                             ('thin', 'source_thin_material_loss'),
                             ('background', 'source_background_retained'),
                             ('shaded_background', 'source_background_retained')):
            image, subject = closeup(shaded=kind == 'shaded_background')
            alpha = np.asarray(subject.getchannel('A')).copy()
            if kind == 'component': alpha[150:205, :] = 0
            elif kind == 'local': alpha[100:120, 80:100] = 0
            elif kind == 'thin': alpha[25:60, 48:50] = 0
            elif kind == 'background': alpha[:12, :12] = 255
            else: alpha[175:220, 152:175] = 255  # detached dark supplier backdrop
            subject.putalpha(Image.fromarray(alpha))
            with self.subTest(kind=kind):
                ok, diag, _ = validate(image, subject)
                self.assertFalse(ok)
                self.assertEqual(diag['reason'], reason, diag)

    def test_different_chroma_top_corners_still_refused(self):
        image, subject = closeup(shaded=True)
        pixels = np.asarray(image).copy(); pixels[:65, 120:] = (80, 140, 185)
        image = Image.fromarray(pixels)
        ok, diag, _ = validate(image, subject)
        self.assertFalse(ok)
        self.assertEqual(diag['reason'], 'source_evidence_unavailable')

    def test_low_coverage_candidate_cannot_use_closeup_fallback(self):
        image, subject = closeup()
        alpha = np.zeros((240,180), dtype=np.uint8); alpha[90:145,30:145] = 255
        subject.putalpha(Image.fromarray(alpha))
        ok, diag, _ = validate(image, subject)
        self.assertFalse(ok)
        self.assertEqual(diag['reason'], 'source_evidence_unavailable')

    def test_uniform_second_supplier_tone_is_not_claimed_as_cropped_body(self):
        image, subject = closeup()
        pixels = np.asarray(image).copy()
        pixels[120:, :60] = (90, 60, 40)
        pixels[120:, 120:] = (90, 60, 40)
        image = Image.fromarray(pixels)
        ok, diag, _ = validate(image, subject)
        self.assertFalse(ok)
        self.assertEqual(diag['reason'], 'source_evidence_unavailable')

    def test_homogeneous_foreground_and_textured_top_samples_still_refused(self):
        for kind in ('homogeneous', 'textured'):
            image, subject = closeup()
            pixels = np.asarray(image).copy()
            if kind == 'homogeneous':
                pixels[np.asarray(subject.getchannel('A')) > 0] = (220, 160, 140)
            else:
                pixels[:12, :9] = np.random.default_rng(7).integers(0, 256, (12, 9, 3), dtype=np.uint8)
            with self.subTest(kind=kind):
                ok, diag, _ = validate(Image.fromarray(pixels), subject)
                self.assertFalse(ok)
                self.assertEqual(diag['reason'], 'source_evidence_unavailable')

    def test_matching_enclosed_material_prevents_lower_body_reclassification(self):
        image, subject = closeup(shaded=True)
        pixels = np.asarray(image).copy()
        pixels[100:125, 80:105] = (189, 139, 106)  # independently enclosed material
        pixels[160:, 145:] = (189, 139, 106)  # same material at cropped lower edge
        image = Image.fromarray(pixels)
        ok, diag, _ = validate(image, subject)  # candidate wrongly removed lower material
        self.assertFalse(ok)
        self.assertIn(diag['reason'], ('source_component_loss', 'source_local_material_loss'))
        alpha = np.asarray(subject.getchannel('A')).copy()
        alpha[160:, 145:] = 255
        subject.putalpha(Image.fromarray(alpha))
        ok, diag, _ = validate(image, subject)
        self.assertTrue(ok, diag)


if __name__ == '__main__':
    unittest.main()
