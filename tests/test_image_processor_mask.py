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
    def test_head_above_detector_box_is_not_seeded_as_background(self):
        for hair_top in (0, 15):
            with self.subTest(hair_top=hair_top):
                pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
                pixels[40:150, 35:86] = (205, 150, 120)
                pixels[hair_top:55, 44:76] = (45, 28, 15)
                image = Image.fromarray(pixels)
                seen = []
                real_grabcut = cv2.grabCut

                def capture(*args, **kwargs):
                    seen.append(args[1].copy())
                    return real_grabcut(*args, **kwargs)

                cv2.setRNGSeed(0)
                with patch.object(cv2, "grabCut", side_effect=capture):
                    result = processor.build_subject_rgba(image, (15, 48, 90, 110))

                self.assertIsNotNone(result)
                self.assertEqual(len(seen), 1)
                self.assertEqual(int(seen[0][20, 60]), cv2.GC_PR_FGD)
                rgba, _ = result
                alpha = np.asarray(rgba.getchannel("A"))
                self.assertEqual(int(alpha[20, 60]), 255)
                self.assertEqual(int(alpha[85, 60]), 255)
                background = np.all(pixels == (214, 191, 160), axis=2)
                np.testing.assert_array_equal(alpha[background], 0)
                np.testing.assert_array_equal(np.asarray(rgba)[:, :, :3], pixels)
                np.testing.assert_array_equal(np.asarray(image), pixels)

    def test_top_seed_extension_ignores_separate_object(self):
        pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[40:150, 35:86] = (205, 150, 120)
        pixels[15:55, 44:76] = (45, 28, 15)
        pixels[8:20, 8:20] = (60, 90, 160)
        image = Image.fromarray(pixels)
        seen = []
        real_grabcut = cv2.grabCut

        def capture(*args, **kwargs):
            seen.append(args[1].copy())
            return real_grabcut(*args, **kwargs)

        cv2.setRNGSeed(0)
        with patch.object(cv2, "grabCut", side_effect=capture):
            result = processor.build_subject_rgba(image, (15, 48, 90, 110))

        self.assertIsNotNone(result)
        self.assertEqual(len(seen), 1)
        self.assertEqual(int(seen[0][20, 60]), cv2.GC_PR_FGD)
        self.assertEqual(int(seen[0][12, 12]), cv2.GC_BGD)
        self.assertEqual(int(seen[0][5, 60]), cv2.GC_BGD)
        rgba, _ = result
        self.assertEqual(rgba.getpixel((12, 12))[3], 0)
        self.assertEqual(rgba.getpixel((60, 20))[3], 255)

    def test_external_background_seeds_keep_enclosed_matches_undecided(self):
        pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[20:150, 35:85] = (205, 150, 120)
        pixels[70:95, 45:55] = (214, 191, 160)
        image = Image.fromarray(pixels)
        seen = []
        real_grabcut = cv2.grabCut

        def capture(*args, **kwargs):
            seen.append(args[1].copy())
            return real_grabcut(*args, **kwargs)

        cv2.setRNGSeed(0)
        with patch.object(cv2, "grabCut", side_effect=capture):
            result = processor.build_subject_rgba(image, (10, 10, 100, 145))

        self.assertIsNotNone(result)
        self.assertEqual(len(seen), 1)
        initial = seen[0]
        self.assertEqual(int(initial[80, 20]), cv2.GC_PR_BGD)
        self.assertEqual(int(initial[0, 0]), cv2.GC_BGD)
        self.assertEqual(int(initial[40, 60]), cv2.GC_PR_FGD)
        # Check initial labels, not a guarantee of the final segmentation.
        self.assertEqual(int(initial[80, 50]), cv2.GC_PR_FGD)
        rgba, _ = result
        self.assertEqual(rgba.getpixel((60, 40)), (205, 150, 120, 255))
        np.testing.assert_array_equal(np.asarray(rgba)[:, :, :3], pixels)
        np.testing.assert_array_equal(np.asarray(image), pixels)

    def test_subject_touching_all_four_frame_edges_is_preserved(self):
        pixels = np.full((120, 100, 3), (214, 191, 160), dtype=np.uint8)
        pixels[:, 40:60] = (205, 150, 120)
        pixels[45:75, :] = (205, 150, 120)
        image = Image.fromarray(pixels)
        cv2.setRNGSeed(0)

        result = processor.build_subject_rgba(image, (0, 0, 100, 120))
        self.assertIsNotNone(result)
        rgba, _ = result
        alpha = np.asarray(rgba.getchannel("A"))

        for x, y in ((50, 0), (50, 119), (0, 60), (99, 60)):
            with self.subTest(point=(x, y)):
                self.assertEqual(int(alpha[y, x]), 255)

        background = np.all(pixels == (214, 191, 160), axis=2)
        np.testing.assert_array_equal(alpha[background], 0)
        np.testing.assert_array_equal(np.asarray(rgba)[:, :, :3], pixels)
        np.testing.assert_array_equal(np.asarray(image), pixels)

    def test_aspect_fill_does_not_enlarge_torso_past_limit(self):
        image = Image.new("RGB", (2093, 2721), (120, 85, 70))
        detection = (
            (0, 80, 2093, 2641), 0.85, (1071.0, 1376.5),
            (1071.0, 750.0), (1071.0, 2003.0), 1253.0, 500.0,
        )
        with patch.object(processor, "detect_mediapipe_person_bbox",
                          return_value=detection):
            master, diag = processor.normalized_master(image, "original-canvas")
        self.assertFalse(diag["crop_applied"])
        self.assertIsNone(diag.get("crop_box"))
        self.assertEqual(diag["method"], "mediapipe-persondet-no-crop")
        expected = processor.standard_canvas(image, processor.MASTER_SIZE)
        np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))

    def test_aspect_fill_keeps_crop_when_torso_size_is_safe(self):
        image = Image.new("RGB", (2093, 2721), (120, 85, 70))
        detection = (
            (0, 80, 2093, 2641), 0.85, (1071.0, 1200.0),
            (1071.0, 750.0), (1071.0, 1650.0), 900.0, 500.0,
        )
        with patch.object(processor, "detect_mediapipe_person_bbox",
                          return_value=detection):
            master, diag = processor.normalized_master(image, "original-canvas")
        self.assertEqual(diag["crop_strategy"], "aspect-fill")
        self.assertEqual(diag["crop_box"], [164, 0, 1978, 2721])
        expected = processor.standard_canvas(
            image.crop((164, 0, 1978, 2721)), processor.MASTER_SIZE
        )
        np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))

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

    def test_uniform_border_cleanup_preserves_enclosed_light_detail(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 20:90] = (205, 150, 120)
        pixels[25:75, 60:70] = (214, 191, 160)

        foreground = np.zeros((100, 120), dtype=np.uint8)
        foreground[10:90, 20:90] = 255

        cleaned = processor.suppress_uniform_border_background(
            Image.fromarray(pixels),
            foreground,
        )

        # An opaque enclosed colour match is not proof of background.
        np.testing.assert_array_equal(cleaned, foreground)
        self.assertEqual(int(cleaned[50, 40]), 255)

    def test_uniform_border_cleanup_keeps_narrow_detail_and_removes_wide_spill(self):
        pixels = np.full((64, 80, 3), 180, dtype=np.uint8)
        foreground = np.zeros((64, 80), dtype=np.uint8)

        # Real subject core.
        pixels[8:56, 24:52] = (210, 150, 120)
        foreground[8:56, 24:52] = 255

        # Narrow blonde-hair-like detail whose colour matches the background.
        foreground[10:40, 19:24] = 255

        # Wide false GrabCut foreground containing supplier background.
        foreground[28:52, 52:72] = 255

        cleaned = processor.suppress_uniform_border_background(
            Image.fromarray(pixels), foreground
        )

        # Outer fringe may be cleaned, but the interior of the narrow
        # subject detail must survive.
        self.assertEqual(int(cleaned[20, 19]), 0)
        self.assertEqual(int(cleaned[20, 22]), 255)

        # Wide background spill must be removed.
        self.assertEqual(int(cleaned[40, 60]), 0)

        # Genuine subject stays intact.
        self.assertEqual(int(cleaned[20, 30]), 255)

    def test_cosmetic_cleanup_removes_side_fringe_but_keeps_subject(self):
        pixels = np.full(
            (100, 120, 3),
            (214, 191, 160),
            dtype=np.uint8,
        )
        pixels[10:90, 30:80] = (205, 150, 120)

        # A narrow background-coloured hanging fragment beside the torso.
        pixels[38:60, 24:30] = (214, 191, 160)

        alpha = np.zeros(
            (100, 120),
            dtype=np.uint8,
        )
        alpha[10:90, 30:80] = 255
        alpha[38:60, 24:30] = 255

        rgba = np.dstack(
            (
                pixels,
                alpha,
            )
        )
        subject = Image.fromarray(
            rgba,
            mode="RGBA",
        )

        cleaned = processor.cosmetic_cleanup_subject_fringes(
            Image.fromarray(pixels),
            subject,
            (20, 10, 70, 80),
        )
        cleaned_alpha = np.asarray(
            cleaned.getchannel("A")
        )

        self.assertEqual(
            int(cleaned_alpha[48, 26]),
            0,
        )
        self.assertEqual(
            int(cleaned_alpha[48, 50]),
            255,
        )
        self.assertEqual(
            int(cleaned_alpha[20, 50]),
            255,
        )

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



class CleanupSafetyTests(unittest.TestCase):
    def test_cosmetic_removes_fringe_without_eroding_adjacent_skin(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 30:80] = (205, 150, 120)
        alpha = np.zeros((100, 120), dtype=np.uint8)
        alpha[10:90, 30:80] = 255
        alpha[38:60, 24:30] = 255
        image = Image.fromarray(pixels)
        subject = Image.fromarray(np.dstack((pixels, alpha)))
        original = np.asarray(subject).copy()
        cleaned = processor.cosmetic_cleanup_subject_fringes(image, subject, (20, 10, 70, 80))
        expected = alpha.copy()
        expected[38:60, 24:30] = 0
        np.testing.assert_array_equal(np.asarray(cleaned.getchannel('A')), expected)
        np.testing.assert_array_equal(np.asarray(cleaned)[:, :, :3], pixels)
        np.testing.assert_array_equal(np.asarray(subject), original)
        np.testing.assert_array_equal(np.asarray(image), pixels)

    def test_uniform_cleanup_preserves_opaque_and_soft_enclosed_detail(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 20:90] = (205, 150, 120)
        pixels[25:75, 60:70] = (214, 191, 160)
        for opacity in (60, 255):
            with self.subTest(opacity=opacity):
                alpha = np.zeros((100, 120), dtype=np.uint8)
                alpha[10:90, 20:90] = 255
                alpha[25:75, 60:70] = opacity
                original = alpha.copy()
                image = Image.fromarray(pixels)
                cleaned = processor.suppress_uniform_border_background(image, alpha)
                np.testing.assert_array_equal(cleaned, original)
                np.testing.assert_array_equal(alpha, original)
                hint = processor.uniform_border_background_mask(image)
                np.testing.assert_array_equal(hint[25:75, 60:70], False)

    def test_cosmetic_keeps_whole_strand_crossing_zone(self):
        for first, last in ((10, 90), (37, 60), (38, 61)):
            with self.subTest(rows=(first, last)):
                pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
                pixels[10:90, 30:80] = (205, 150, 120)
                alpha = np.zeros((100, 120), dtype=np.uint8)
                alpha[10:90, 30:80] = 255
                alpha[first:last, 24:30] = 255
                subject = Image.fromarray(np.dstack((pixels, alpha)))
                cleaned = processor.cosmetic_cleanup_subject_fringes(
                    Image.fromarray(pixels), subject, (20, 10, 70, 80)
                )
                np.testing.assert_array_equal(np.asarray(cleaned), np.asarray(subject))

    def test_cosmetic_keeps_enclosed_light_garment(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 30:80] = (205, 150, 120)
        pixels[38:60, 64:70] = (214, 191, 160)
        alpha = np.zeros((100, 120), dtype=np.uint8)
        alpha[10:90, 30:80] = 255
        subject = Image.fromarray(np.dstack((pixels, alpha)))
        cleaned = processor.cosmetic_cleanup_subject_fringes(
            Image.fromarray(pixels), subject, (20, 10, 70, 80)
        )
        np.testing.assert_array_equal(np.asarray(cleaned), np.asarray(subject))

    def test_cleanup_does_not_restore_already_transparent_gap(self):
        pixels = np.full((100, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[10:90, 20:90] = (205, 150, 120)
        pixels[25:75, 60:70] = (214, 191, 160)
        alpha = np.zeros((100, 120), dtype=np.uint8)
        alpha[10:90, 20:90] = 255
        alpha[25:75, 60:70] = 0
        cleaned = processor.suppress_uniform_border_background(Image.fromarray(pixels), alpha)
        np.testing.assert_array_equal(cleaned, alpha)
        subject = Image.fromarray(np.dstack((pixels, cleaned)))
        cosmetic = processor.cosmetic_cleanup_subject_fringes(
            Image.fromarray(pixels), subject, (20, 10, 70, 80)
        )
        np.testing.assert_array_equal(np.asarray(cosmetic), np.asarray(subject))




def corner_full_frame_fixture():
    pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
    for y in range(50, 160):
        shade = (y - 50) // 2
        pixels[y, :] = (180 + shade, 110 + shade, 80 + shade)
    pixels[:60, 40:80] = (45, 28, 15)
    return Image.fromarray(pixels)


class CornerFallbackTests(unittest.TestCase):
    def test_corner_fallback_rescues_subject_at_frame(self):
        image = corner_full_frame_fixture()
        original = np.asarray(image).copy()
        self.assertIsNone(processor.uniform_border_background_mask(image))
        seen = []
        real = cv2.grabCut

        def capture(*args, **kwargs):
            seen.append((args[1].copy(), args[6]))
            return real(*args, **kwargs)

        cv2.setRNGSeed(0)
        with patch.object(cv2, 'grabCut', side_effect=capture):
            result = processor.build_subject_rgba(image, (0, 0, 120, 160))
        self.assertIsNotNone(result)
        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0][1], cv2.GC_INIT_WITH_MASK)
        self.assertEqual(int(seen[0][0][0, 60]), cv2.GC_PR_FGD)
        self.assertEqual(int(seen[0][0][4, 4]), cv2.GC_BGD)
        rgba, _ = result
        self.assertEqual(rgba.getpixel((60, 0))[3], 255)
        self.assertEqual(rgba.getpixel((60, 20))[3], 255)
        self.assertEqual(rgba.getpixel((60, 120))[3], 255)
        self.assertEqual(rgba.getpixel((60, 159))[3], 255)
        self.assertEqual(rgba.getpixel((4, 4))[3], 0)
        np.testing.assert_array_equal(np.asarray(rgba)[:, :, :3], original)
        np.testing.assert_array_equal(np.asarray(image), original)

    def test_corner_seed_rejects_unreliable_samples(self):
        bad = []
        bad.append(Image.new('RGB', (30, 30), (214, 191, 160)))
        bad.append(Image.new('RGB', (120, 160), (214, 191, 160)))
        pixels = np.asarray(corner_full_frame_fixture()).copy()
        pixels[:8, -6:] = (80, 100, 120)
        bad.append(Image.fromarray(pixels))
        pixels = np.asarray(corner_full_frame_fixture()).copy()
        pixels[:8:2, :6] = (80, 100, 120)
        bad.append(Image.fromarray(pixels))
        for index, image in enumerate(bad):
            with self.subTest(case=index):
                self.assertIsNone(processor.build_corner_background_seed_mask(image))

    def test_corner_fallback_leaves_existing_hint_path_unchanged(self):
        pixels = np.full((160, 120, 3), (214, 191, 160), dtype=np.uint8)
        pixels[20:150, 35:85] = (205, 150, 120)
        image = Image.fromarray(pixels)
        self.assertIsNotNone(processor.uniform_border_background_mask(image))
        cv2.setRNGSeed(0)
        with patch.object(processor, 'build_corner_background_seed_mask',
                          side_effect=AssertionError('Fallback must not run')):
            result = processor.build_subject_rgba(image, (0, 0, 120, 160))
        self.assertIsNotNone(result)
        self.assertEqual(result[0].getpixel((60, 80))[3], 255)
        self.assertEqual(result[0].getpixel((4, 4))[3], 0)

    def test_corner_fallback_is_limited_to_full_frame(self):
        image = corner_full_frame_fixture()
        self.assertIsNone(processor.uniform_border_background_mask(image))
        seen = []
        real = cv2.grabCut

        def capture(*args, **kwargs):
            seen.append(args[6])
            return real(*args, **kwargs)

        cv2.setRNGSeed(0)
        with patch.object(processor, 'build_corner_background_seed_mask',
                          side_effect=AssertionError('Fallback must not run')):
            with patch.object(cv2, 'grabCut', side_effect=capture):
                processor.build_subject_rgba(image, (20, 20, 80, 130))
        self.assertEqual(seen, [cv2.GC_INIT_WITH_RECT])



if __name__ == "__main__":
    unittest.main()
