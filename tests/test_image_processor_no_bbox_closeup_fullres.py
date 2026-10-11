"""Exact JPEG evidence and adversarial upper corners for close-up validation."""
import hashlib
import unittest
from pathlib import Path

import cv2
import numpy as np
from PIL import Image

from test_image_processor_no_bbox_closeup import closeup, p


FIXTURES = Path(__file__).parent / 'fixtures' / 'closeup'
ORIGINAL = FIXTURES / '20260904-111401-44eb8f1e1cbac540.jpg'
REDUCED = FIXTURES / '44eb8f1e1cbac540-1182x1536.jpg'
ALPHA = FIXTURES / '44eb8f1e1cbac540-modnet-alpha.png'


def probe(image):
    result = image.convert('RGB').copy()
    result.thumbnail((512, 512), Image.Resampling.BILINEAR)
    return result


def top_right_spread(image):
    pixels = np.asarray(image, dtype=np.float32)
    height, width = pixels.shape[:2]
    patch = pixels[:round(height * .05), -round(width * .05):].reshape(-1, 3)
    return float(np.percentile(np.linalg.norm(patch - np.median(patch, axis=0), axis=1), 90))


def changed_upper_corner(kind):
    image, _ = closeup(shaded=True)
    pixels = np.asarray(image).copy()
    height, width = pixels.shape[:2]
    dy, dx = round(height * .05), round(width * .05)
    yy, xx = np.mgrid[:dy, :dx]
    base = np.array((161, 111, 78))
    if kind == 'noise':
        values = np.random.default_rng(17).choice((-3, 3), (dy, dx))
    elif kind == 'vertical_step':
        values = np.where(xx < dx // 2, 0, 5)
    elif kind == 'small_vertical_step':
        values = np.where(xx < dx // 2, 0, 3)
    elif kind == 'horizontal_step':
        values = np.where(yy < dy // 2, 0, 5)
    elif kind == 'diagonal_step':
        values = np.where(yy < .5 * xx + 4, 0, 5)
    elif kind == 'small_diagonal_step':
        values = np.where(yy < .5 * xx + 4, 0, 3)
    elif kind == 'striped':
        values = np.where(xx % 2, 0, 5)
    elif kind == 'excessive_gradient':
        values = np.rint(np.linspace(-10, 10, dx))[None, :] + np.zeros((dy, 1))
    elif kind == 'smooth_gradient':
        values = np.rint(np.linspace(-3, 3, dx))[None, :] + np.zeros((dy, 1))
    elif kind == 'chromatic':
        values = np.zeros((dy, dx, 3), dtype=int)
        values[:, dx // 2:, :] = (8, -4, 0)
        pixels[:dy, -dx:] = base + values
        return Image.fromarray(pixels)
    else:
        raise AssertionError(kind)
    pixels[:dy, -dx:] = base + values[:, :, None]
    return Image.fromarray(pixels)


class FullresCloseupEvidenceTests(unittest.TestCase):
    def test_small_smooth_illumination_gradient_is_corroborated(self):
        image = changed_upper_corner('smooth_gradient')
        self.assertGreater(top_right_spread(image), 4)
        self.assertIsNotNone(p.build_closeup_validation_seed_mask(image))

    def test_exact_original_and_prior_reduced_copy_recover_same_source_evidence(self):
        self.assertEqual(hashlib.sha256(ORIGINAL.read_bytes()).hexdigest(),
                         'c45e4d4444b26701005fda5dc3332e2a418936ae4a251d761a3130da59ce9d7b')
        self.assertEqual(hashlib.sha256(REDUCED.read_bytes()).hexdigest(),
                         '1bfd68d7dc5122a135ce0eede95ec3e93856eaacafcf9258ae40eee41d64957b')
        with Image.open(ORIGINAL) as opened:
            original = opened.convert('RGB')
        with Image.open(REDUCED) as opened:
            reduced = opened.convert('RGB')
        self.assertEqual(original.size, (2500, 3250))
        self.assertEqual(reduced.size, (1182, 1536))
        full_probe, reduced_probe = probe(original), probe(reduced)
        self.assertAlmostEqual(top_right_spread(full_probe), 4.1231055, places=5)
        self.assertAlmostEqual(top_right_spread(reduced_probe), 3.7749171, places=5)
        seeds = []
        for label, image in (('original', full_probe), ('prior_reduced', reduced_probe),
                             ('bilinear_resize', probe(original.resize(reduced.size, Image.Resampling.BILINEAR)))):
            with self.subTest(label=label):
                self.assertIsNone(p.build_undetected_seed_mask(image))
                seed = p.build_closeup_validation_seed_mask(image)
                self.assertIsNotNone(seed, label)
                if seed is not None:
                    self.assertEqual(seed[0, 0], cv2.GC_BGD)
                    self.assertEqual(seed[0, -1], cv2.GC_BGD)
                    self.assertEqual(seed[-1, 0], cv2.GC_PR_FGD)  # cropped skin
                    self.assertEqual(seed[280, 180], cv2.GC_PR_FGD)  # garment
                    seeds.append(seed == cv2.GC_PR_FGD)
        self.assertEqual(len(seeds), 3)
        for seed in seeds[1:]:
            self.assertGreater(float(np.mean(seed == seeds[0])), .98)

    def test_near_limit_texture_steps_and_wrong_colour_do_not_become_background(self):
        for kind in ('noise', 'vertical_step', 'small_vertical_step', 'horizontal_step',
                     'diagonal_step', 'small_diagonal_step',
                     'striped', 'chromatic', 'excessive_gradient'):
            with self.subTest(kind=kind):
                image = changed_upper_corner(kind)
                self.assertGreater(top_right_spread(image), 4)
                self.assertIsNone(p.build_closeup_validation_seed_mask(image))

    def test_validation_evidence_is_read_only(self):
        with Image.open(ORIGINAL) as opened:
            image = probe(opened)
        before = np.asarray(image).copy()
        self.assertIsNotNone(p.build_closeup_validation_seed_mask(image))
        np.testing.assert_array_equal(np.asarray(image), before)

    def test_exact_original_real_worker_alpha_passes_without_any_pixel_repair(self):
        self.assertEqual(hashlib.sha256(ALPHA.read_bytes()).hexdigest(),
                         'e054515eb20064d995c8e8d5cd969521d4e1525ff77285b4437a902638a7349a')
        with Image.open(ORIGINAL) as opened:
            image = opened.convert('RGB')
        with Image.open(ALPHA) as opened:
            alpha = opened.convert('L')
        self.assertEqual(alpha.size, image.size)
        subject = image.convert('RGBA')
        subject.putalpha(alpha)
        before = np.asarray(subject).copy()
        ratio = float(np.mean(np.asarray(alpha) >= 128))
        self.assertAlmostEqual(ratio, .8631379692307692, places=10)
        diagnostics = {}
        self.assertFalse(p.validate_undetected_subject(image, subject, ratio, diagnostics))
        self.assertEqual(diagnostics['reason'], 'source_evidence_unavailable')
        self.assertTrue(p.validate_undetected_subject(image, subject, ratio, diagnostics,
                                                    allow_closeup_evidence=True), diagnostics)
        self.assertEqual(diagnostics['reason'], 'structural_checks_passed')
        self.assertEqual(diagnostics['source_evidence'], 'closeup_corner_samples')
        np.testing.assert_array_equal(np.asarray(subject), before)


if __name__ == '__main__':
    unittest.main()
