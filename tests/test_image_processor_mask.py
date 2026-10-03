import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np
from PIL import Image, ImageDraw


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location(
    "anabelka_image_processor", PROCESSOR_DIR / "server.py"
)
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class SubjectMaskTests(unittest.TestCase):
    def test_feather_does_not_restore_removed_background(self):
        foreground = np.zeros((48, 48), dtype=np.uint8)
        foreground[10:38, 14:34] = 255

        alpha = processor.refine_subject_edge(foreground)

        self.assertEqual(np.count_nonzero(alpha[foreground == 0]), 0)

    def test_narrow_background_gap_stays_transparent(self):
        foreground = np.zeros((48, 48), dtype=np.uint8)
        foreground[10:38, 14:34] = 255
        foreground[10:25, 34] = 255
        foreground[10:25, 36] = 255
        foreground[25:28, 34:37] = 255

        alpha = processor.refine_subject_edge(foreground)

        np.testing.assert_array_equal(alpha[12:23, 35], 0)

    def test_feather_keeps_soft_edges_and_opaque_interior(self):
        foreground = np.zeros((48, 48), dtype=np.uint8)
        foreground[10:38, 14:34] = 255
        foreground[5:11, 23:26] = 255  # A connected thin hair tuft.
        original = foreground.copy()

        alpha = processor.refine_subject_edge(foreground)

        np.testing.assert_array_equal(foreground, original)
        self.assertEqual(alpha.shape, foreground.shape)
        self.assertEqual(alpha.dtype, np.uint8)
        self.assertGreater(int(alpha[20, 14]), 128)
        self.assertLess(int(alpha[20, 14]), 255)
        self.assertEqual(int(alpha[20, 24]), 255)
        self.assertGreater(int(alpha[7, 24]), 128)

    def test_uniform_border_cleanup_removes_soft_background_only_when_connected(self):
        pixels = np.full((48, 64, 3), 180, dtype=np.uint8)
        pixels[8:40, 16:48] = (210, 150, 120)
        pixels[20:28, 24:40] = 180  # Enclosed light garment, same colour as background.
        foreground = np.zeros((48, 64), dtype=np.uint8)
        foreground[8:40, 16:48] = 255
        foreground[12:36, 15] = 60  # A soft fringe on the removed supplier background.
        foreground[20:28, 24:40] = 60
        original = foreground.copy()

        cleaned = processor.suppress_uniform_border_background(
            Image.fromarray(pixels), foreground
        )

        np.testing.assert_array_equal(cleaned[12:36, 15], 0)
        np.testing.assert_array_equal(cleaned[20:28, 24:40], 60)
        self.assertEqual(int(cleaned[16, 30]), 255)
        np.testing.assert_array_equal(foreground, original)

    def test_textured_background_does_not_trigger_colour_cleanup(self):
        rows, columns = np.indices((48, 64))
        pixels = np.empty((48, 64, 3), dtype=np.uint8)
        pixels[(rows + columns) % 2 == 0] = (30, 70, 110)
        pixels[(rows + columns) % 2 == 1] = (220, 190, 160)
        foreground = np.zeros((48, 64), dtype=np.uint8)
        foreground[8:40, 16:48] = 255
        foreground[12:36, 15] = 60

        cleaned = processor.suppress_uniform_border_background(
            Image.fromarray(pixels), foreground
        )

        np.testing.assert_array_equal(cleaned, foreground)

    def test_cleanup_visits_all_borders_and_keeps_enclosed_light_island(self):
        pixels = np.full((48, 64, 3), 180, dtype=np.uint8)
        pixels[:, 30:34] = (210, 150, 120)
        pixels[22:26, :] = (210, 150, 120)
        pixels[23, 31] = 180
        foreground = np.full((48, 64), 60, dtype=np.uint8)
        foreground[:, 30:34] = 255
        foreground[22:26, :] = 255
        foreground[23, 31] = 60
        expected = np.zeros((48, 64), dtype=np.uint8)
        expected[:, 30:34] = 255
        expected[22:26, :] = 255
        expected[23, 31] = 60

        cleaned = processor.suppress_uniform_border_background(
            Image.fromarray(pixels), foreground
        )

        np.testing.assert_array_equal(cleaned, expected)

    def test_empty_and_full_masks_remain_stable(self):
        for value in (0, 255):
            with self.subTest(value=value):
                foreground = np.full((16, 16), value, dtype=np.uint8)
                np.testing.assert_array_equal(
                    processor.refine_subject_edge(foreground), foreground
                )

    def test_grabcut_does_not_restore_supplier_background(self):
        cases = (
            ((80, 120), (25, 20, 54, 99), (25, 20, 30, 80)),
            ((800, 1400), (250, 200, 549, 1199), (250, 200, 300, 1000)),
        )
        for size, rectangle, bbox in cases:
            with self.subTest(size=size):
                image = Image.new("RGB", size, (90, 105, 120))
                ImageDraw.Draw(image).rectangle(rectangle, fill=(210, 150, 120))
                original_pixels = np.asarray(image).copy()
                cv2.setRNGSeed(0)

                result = processor.build_subject_rgba(image, bbox)

                self.assertIsNotNone(result)
                rgba, ratio = result
                self.assertEqual(rgba.size, size)
                pixels = np.asarray(rgba)
                old_background = np.all(original_pixels == (90, 105, 120), axis=2)
                self.assertEqual(np.count_nonzero(pixels[:, :, 3][old_background]), 0)
                self.assertEqual(rgba.getpixel((size[0] // 2, size[1] // 2)),
                                 (210, 150, 120, 255))
                self.assertGreater(ratio, 0.24)
                self.assertLess(ratio, 0.28)
                np.testing.assert_array_equal(pixels[:, :, :3], original_pixels)
                np.testing.assert_array_equal(np.asarray(image), original_pixels)

    def test_background_profiles_preserve_torso_crop_and_zoom_geometry(self):
        image = Image.new("RGB", (80, 120), (90, 105, 120))
        ImageDraw.Draw(image).rectangle((25, 20, 54, 99), fill=(210, 150, 120))
        cases = (
            (
                (40.0, 52.0), (40.0, 40.0), (40.0, 64.0), 24.0,
                {
                    "crop_strategy": "torso-normalize",
                    "crop_applied": True,
                    "crop_box": [12, 16, 69, 102],
                    "torso_ratio_before": 0.2,
                    "torso_target_ratio": 0.31,
                },
                (250, 250, 250),
            ),
            (
                (40.0, 60.0), (40.0, 25.0), (40.0, 95.0), 70.0,
                {
                    "crop_strategy": "torso-zoom-out",
                    "crop_applied": False,
                    "torso_ratio_before": 0.5833,
                    "torso_ratio_after": 0.4375,
                    "torso_target_ratio": 0.4,
                    "zoom_scale": 0.75,
                    "zoom_out_applied": True,
                },
                (90, 105, 120),
            ),
        )
        for torso, shoulder, hip, length, expected, original_corner in cases:
            for profile, corner in (
                ("original-canvas", original_corner),
                ("studio-light", (252, 251, 249)),
                ("anabelka-brand", (252, 249, 255)),
            ):
                with self.subTest(strategy=expected["crop_strategy"], profile=profile):
                    # Fix only the external detector output; run the real
                    # geometry, GrabCut, edge cleanup and composition.
                    detection = ((25, 20, 30, 80), 0.95, torso, shoulder, hip, length, 12.0)
                    cv2.setRNGSeed(0)
                    with patch.object(processor, "detect_mediapipe_person_bbox",
                                      return_value=detection):
                        master, diagnostics = processor.normalized_master(image, profile)

                    self.assertEqual(master.size, (1200, 1800))
                    self.assertEqual(master.getpixel((0, 0)), corner)
                    self.assertEqual(master.getpixel((600, 900)), (210, 150, 120))
                    self.assertEqual(diagnostics["background_profile"], profile)
                    self.assertFalse(diagnostics["background_fallback"])
                    self.assertEqual(diagnostics["subject_mask_applied"],
                                     profile != "original-canvas")
                    for key, value in expected.items():
                        self.assertEqual(diagnostics[key], value)


if __name__ == "__main__":
    unittest.main()
