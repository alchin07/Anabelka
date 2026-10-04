"""Mask regressions. Run from the project root; optional real-photo fixture via
ANABELKA_MASK_REFERENCE=/absolute/path/to/slz28201002_byust_akvamarin.jpg.
"""
import importlib.util
import os
import sys
import unittest
from pathlib import Path

import cv2
import numpy as np
from PIL import Image, ImageDraw

PROCESSOR_DIR = Path(__file__).resolve().parents[1] / 'tools' / 'image-processor'
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location('anabelka_edge_regression', PROCESSOR_DIR / 'server.py')
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)
cv2.setNumThreads(1)


class ImageProcessorEdgeRegressionTests(unittest.TestCase):
    def test_subject_cut_by_frame_keeps_its_visible_edge(self):
        cases = (
            ('bottom', (25, 20, 54, 119), (25, 20, 30, 100), (40, 119)),
            ('top', (25, 0, 54, 99), (25, 0, 30, 100), (40, 0)),
            ('left', (0, 20, 54, 99), (0, 20, 55, 80), (0, 60)),
            ('right', (25, 20, 79, 99), (25, 20, 55, 80), (79, 60)),
        )
        for name, rectangle, bbox, point in cases:
            with self.subTest(edge=name):
                image = Image.new('RGB', (80, 120), (90, 105, 120))
                ImageDraw.Draw(image).rectangle(rectangle, fill=(210, 150, 120))
                cv2.setRNGSeed(0)
                result = processor.build_subject_rgba(image, bbox)
                self.assertIsNotNone(result)
                rgba, _ = result
                self.assertEqual(rgba.size, image.size)
                self.assertEqual(rgba.getpixel(point), (210, 150, 120, 255))
                background = np.all(np.asarray(image) == (90, 105, 120), axis=2)
                self.assertEqual(np.count_nonzero(np.asarray(rgba)[:, :, 3][background]), 0)

    def test_pale_skin_close_in_rgb_but_different_in_chroma_is_preserved(self):
        pixels = np.full((80, 100, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:70, 30:70] = (205, 150, 120)
        pixels[10:70, 20:30] = (218, 184, 157)  # Pale skin at the outer contour.
        foreground = np.zeros((80, 100), dtype=np.uint8)
        foreground[10:70, 20:70] = 255
        original = foreground.copy()
        cleaned = processor.suppress_uniform_border_background(Image.fromarray(pixels), foreground)
        np.testing.assert_array_equal(cleaned[15:65, 20:30], 255)
        np.testing.assert_array_equal(foreground, original)

    def test_narrow_matching_hair_keeps_its_core(self):
        pixels = np.full((100, 100, 3), (214, 191, 160), dtype=np.uint8)
        pixels[20:80, 30:70] = (190, 135, 105)
        pixels[25:75, 25:30] = (215, 192, 161)
        foreground = np.zeros((100, 100), dtype=np.uint8)
        foreground[20:80, 30:70] = 255
        foreground[25:75, 25:30] = 255
        cleaned = processor.suppress_uniform_border_background(Image.fromarray(pixels), foreground)
        self.assertEqual(int(cleaned[50, 28]), 255)
        self.assertGreater(np.count_nonzero(cleaned[25:75, 25:30]), 150)

    def test_wide_matching_background_spill_is_removed(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[15:90, 20:60] = (205, 150, 120)
        foreground = np.zeros((100, 120), dtype=np.uint8)
        foreground[15:90, 20:60] = 255
        foreground[55:90, 60:100] = 255  # Broad supplier-background lobe.
        cleaned = processor.suppress_uniform_border_background(Image.fromarray(pixels), foreground)
        np.testing.assert_array_equal(cleaned[60:85, 65:95], 0)
        np.testing.assert_array_equal(cleaned[20:85, 25:55], 255)

    def test_wide_background_spill_does_not_leave_its_narrow_tail(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 20:60] = (205, 150, 120)
        foreground = np.zeros((100, 120), dtype=np.uint8)
        foreground[10:90, 20:60] = 255
        foreground[20:85, 60:65] = 255  # Narrow spill following the arm.
        foreground[55:85, 65:100] = 255  # It joins a broad background lobe.
        cleaned = processor.suppress_uniform_border_background(Image.fromarray(pixels), foreground)
        np.testing.assert_array_equal(cleaned[20:55, 60:65], 0)
        np.testing.assert_array_equal(cleaned[55:85, 60:100], 0)
        np.testing.assert_array_equal(cleaned[15:85, 25:55], 255)

    def test_nonopaque_upsampling_halo_is_removed(self):
        image = Image.new('RGB', (801, 1401), (90, 105, 120))
        ImageDraw.Draw(image).rectangle((251, 203, 553, 1203), fill=(210, 150, 120))
        cv2.setRNGSeed(0)
        result = processor.build_subject_rgba(image, (251, 203, 303, 1001))
        self.assertIsNotNone(result)
        rgba, _ = result
        pixels = np.asarray(rgba)
        background = np.all(np.asarray(image) == (90, 105, 120), axis=2)
        self.assertEqual(np.count_nonzero(pixels[:, :, 3][background]), 0)
        self.assertEqual(rgba.getpixel((400, 700)), (210, 150, 120, 255))
        np.testing.assert_array_equal(pixels[:, :, :3], np.asarray(image))

    @unittest.skipUnless(os.environ.get('ANABELKA_MASK_REFERENCE'), 'Set ANABELKA_MASK_REFERENCE for the supplied photo')
    def test_supplied_photo_preserves_hair_shoulder_and_clear_background(self):
        image = Image.open(os.environ['ANABELKA_MASK_REFERENCE']).convert('RGB')
        self.assertEqual(image.size, (1181, 1536), 'Use the supplied full reference photo')
        # Inspect exactly the working image, with manually checked subject pixels.
        work = image.resize((923, 1200), Image.Resampling.BILINEAR)
        cv2.setRNGSeed(0)
        result = processor.build_subject_rgba(work, (0, 32, 923, 1168))
        self.assertIsNotNone(result)
        rgba, _ = result
        alpha = np.asarray(rgba)[:, :, 3]
        for name, x, y in (('pale hair', 384, 190), ('shoulder', 289, 424), ('forearm', 270, 686)):
            with self.subTest(region=name):
                self.assertGreaterEqual(int(alpha[y, x]), 128)
        for x, y in ((180, 400), (220, 700), (800, 980), (650, 1020)):
            with self.subTest(background=(x, y)):
                self.assertEqual(int(alpha[y, x]), 0)
        np.testing.assert_array_equal(np.asarray(rgba)[:, :, :3], np.asarray(work))


if __name__ == '__main__':
    unittest.main()
