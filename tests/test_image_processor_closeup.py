
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image

DIR = Path(__file__).resolve().parents[1] / 'tools' / 'image-processor'
sys.path.insert(0, str(DIR))
spec = importlib.util.spec_from_file_location('closeup_processor', DIR / 'server.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class CloseupFramingTests(unittest.TestCase):
    def fixture(self, size=(80, 120), margin=0):
        w, h = size
        a = np.full((h, w, 3), (90, 105, 120), dtype=np.uint8)
        a[margin:h-margin, w//3:2*w//3] = (210, 150, 120)
        image = Image.fromarray(a)
        subject = image.convert('RGBA')
        subject.putalpha(Image.fromarray(np.where(a[:, :, 0] == 210, 255, 0).astype(np.uint8)))
        detection = ((0, 0, w, h), .95, (w/2, h/2), (w/2, h/5),
                     (w/2, h*4/5), h*7/12, 12.)
        return image, subject, detection

    def render(self, image, subject, detection, profile):
        # Isolate placement from detector and segmentation; test real canvas code.
        result = None if subject is None else (subject, .35)
        with patch.object(p, 'detect_mediapipe_person_bbox', return_value=detection), \
             patch.object(p, 'build_subject_rgba', return_value=result), \
             patch.object(p, 'cosmetic_cleanup_subject_fringes', side_effect=lambda i, s, b: s):
            return p.normalized_master(image, profile)

    def assert_preserved(self, diag):
        self.assertEqual(diag['crop_strategy'], 'preserve-closeup')
        self.assertFalse(diag['crop_applied'])
        self.assertFalse(diag['zoom_out_applied'])
        self.assertEqual(diag['zoom_scale'], 1.0)
        self.assertNotIn('crop_box', diag)
        self.assertNotIn('torso_target_ratio', diag)

    def test_closeup_original_keeps_source_framing(self):
        image, subject, detection = self.fixture()
        original = np.asarray(image).copy()
        master, diag = self.render(image, subject, detection, 'original-canvas')
        self.assert_preserved(diag)
        np.testing.assert_array_equal(np.asarray(master), np.asarray(p.standard_canvas(image, p.MASTER_SIZE)))
        np.testing.assert_array_equal(np.asarray(image), original)

    def test_closeup_custom_profiles_keep_standard_canvas(self):
        image, subject, detection = self.fixture()
        original = np.asarray(subject).copy()
        for profile in ('studio-light', 'anabelka-brand'):
            with self.subTest(profile=profile):
                master, diag = self.render(image, subject, detection, profile)
                self.assert_preserved(diag)
                expected = p.compose_subject_on_background(p.transparent_standard_canvas(subject, p.MASTER_SIZE), profile)
                np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))
                np.testing.assert_array_equal(np.asarray(subject), original)

    def test_ordinary_zoom_out_is_not_disabled(self):
        image, subject, detection = self.fixture(margin=10)
        master, diag = self.render(image, subject, detection, 'original-canvas')
        self.assertEqual(diag['crop_strategy'], 'torso-zoom-out')
        self.assertEqual(diag['zoom_scale'], .75)
        self.assertEqual(diag['torso_ratio_after'], .4375)
        self.assertTrue(diag['zoom_out_applied'])
        expected = p.mediapipe_torso_zoom_out_canvas(image, detection[2], detection[3], detection[5])[0]
        np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))

    def test_uncertain_or_disconnected_edges_do_not_trigger(self):
        image, _, _ = self.fixture()
        base = np.asarray(image)
        cases = [Image.new('RGB', (80, 120), (90, 105, 120))]
        for region in ((0, 1), (119, 120), (55, 65)):
            a = base.copy(); a[region[0]:region[1], :] = (90, 105, 120)
            cases.append(Image.fromarray(a))
        for im in cases:
            self.assertFalse(p.source_has_cropped_closeup_edges(im))

    def test_wide_closeup_is_not_cropped_to_fill_canvas(self):
        image, subject, detection = self.fixture(size=(120, 160))
        master, diag = self.render(image, subject, detection, 'original-canvas')
        self.assert_preserved(diag)
        np.testing.assert_array_equal(np.asarray(master), np.asarray(p.standard_canvas(image, p.MASTER_SIZE)))

    def test_segmentation_fallback_keeps_closeup_geometry(self):
        image, _, detection = self.fixture()
        master, diag = self.render(image, None, detection, 'studio-light')
        self.assert_preserved(diag)
        self.assertTrue(diag['background_fallback'])
        self.assertEqual(diag['background_profile'], 'original-canvas')
        np.testing.assert_array_equal(np.asarray(master), np.asarray(p.standard_canvas(image, p.MASTER_SIZE)))


if __name__ == '__main__':
    unittest.main()
