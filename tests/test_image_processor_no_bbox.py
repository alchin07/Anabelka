"""No detector rectangle must not prevent segmentation or change framing."""
import importlib.util
import sys
import tempfile
import unittest
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np
from PIL import Image

DIR = Path(__file__).resolve().parents[1] / 'tools' / 'image-processor'
sys.path.insert(0, str(DIR))
spec = importlib.util.spec_from_file_location('no_bbox_processor', DIR / 'server.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


def fixture(garment=False):
    pixels = np.full((240, 180, 3), (145, 107, 78), dtype=np.uint8)
    alpha = np.zeros((240, 180), dtype=np.uint8)
    if garment:
        pixels[70:160, 40:140] = (240, 235, 220)
        pixels[105:115, 40:140] = (95, 65, 70)
        alpha[70:160, 40:140] = 255
    else:
        pixels[:, 55:125] = (220, 160, 140)
        alpha[:, 55:125] = 255
        pixels[85:120, 55:125] = (240, 235, 220)  # pale underwear
        pixels[90:150, 20:35] = (220, 160, 140)  # separate hand
        alpha[90:150, 20:35] = 255
    image = Image.fromarray(pixels)
    subject = image.convert('RGBA')
    alpha[:, 55] = np.where(alpha[:, 55] > 0, 180, 0)
    subject.putalpha(Image.fromarray(alpha))
    return image, subject


def no_detector(stack):
    for name in ('detect_mediapipe_person_bbox', 'detect_face_subject_bbox', 'detect_person_bbox'):
        stack.enter_context(patch.object(p, name, return_value=None))
    for name in ('subject_crop_box', 'mediapipe_torso_crop_box', 'mediapipe_torso_zoom_out_canvas'):
        stack.enter_context(patch.object(p, name, side_effect=AssertionError('unexpected geometry')))


class NoBboxTests(unittest.TestCase):
    def test_modnet_receives_whole_frame_and_preserves_soft_alpha(self):
        image, subject = fixture()
        for profile in ('studio-light', 'anabelka-brand'):
            with self.subTest(profile=profile), ExitStack() as stack:
                no_detector(stack)
                worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(subject, .41, {})))
                for name in ('build_subject_rgba', 'cosmetic_cleanup_subject_fringes', 'refine_upper_enclosed_background_gaps'):
                    stack.enter_context(patch.object(p, name, side_effect=AssertionError(name)))
                master, diag = p.normalized_master(image, profile, Path('/tmp/source.png'), mask_mode='modnet')
                expected = p.compose_subject_on_background(p.transparent_standard_canvas(subject, p.MASTER_SIZE), profile)
                np.testing.assert_array_equal(np.asarray(master), np.asarray(expected))
                self.assertEqual(worker.call_args.args, (Path('/tmp/source.png'), image))
                self.assertEqual(diag['mask_method'], 'modnet')
                self.assertEqual(diag['background_profile'], profile)
                self.assertFalse(diag['crop_applied'])
                self.assertFalse(diag['subject_detected'])
                self.assertEqual(diag['detection_reason'], 'subject-not-detected')
                self.assertEqual(diag['crop_strategy'], 'preserve-source-frame')
                self.assertEqual(diag['zoom_scale'], 1.0)
                self.assertIn('total', diag['timings_ms'])

    def test_real_grabcut_and_auto_keep_torso_hand_and_pale_garment(self):
        image, _ = fixture()
        for mode in ('grabcut', 'auto'):
            with self.subTest(mode=mode), ExitStack() as stack:
                no_detector(stack)
                stack.enter_context(patch.object(p, 'run_modnet_worker', side_effect=AssertionError('unnecessary worker')))
                master, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode=mode)
                self.assertEqual(diag['mask_method'], 'opencv-grabcut')
                self.assertEqual(diag['background_profile'], 'studio-light')
                self.assertFalse(diag['background_fallback'])
                a = np.asarray(master)
                # Whole 180x240 source fits at scale 1200/180, vertically centered.
                for x, y, color in ((85, 50, (220,160,140)), (85,100,(240,235,220)), (27,120,(220,160,140))):
                    np.testing.assert_allclose(a[100 + round(y*1200/180), round(x*1200/180)], color, atol=2)
                self.assertFalse(np.allclose(a[250, 50], (145,107,78), atol=5))

    def test_garment_without_person_uses_safe_grabcut(self):
        image, _ = fixture(garment=True)
        with ExitStack() as stack:
            no_detector(stack)
            master, diag = p.normalized_master(image, 'anabelka-brand', mask_mode='grabcut')
        self.assertEqual(master.size, p.MASTER_SIZE)
        self.assertEqual(diag['mask_method'], 'opencv-grabcut')
        self.assertEqual(diag['background_profile'], 'anabelka-brand')

    def test_skin_dominated_frame_does_not_invert_mask(self):
        pixels = np.full((160, 120, 3), (145, 107, 78), dtype=np.uint8)
        pixels[:, 20:100] = (220, 160, 140)
        pixels[40:120, :] = (220, 160, 140)
        pixels[65:85, 45:75] = (240, 235, 220)
        image = Image.fromarray(pixels)
        result = p.build_subject_rgba(image, None)
        self.assertIsNotNone(result)
        alpha = np.asarray(result[0].getchannel('A'))
        for y,x in ((0,60),(159,60),(80,0),(80,119),(75,60)):
            self.assertGreaterEqual(int(alpha[y,x]), 192)
        self.assertEqual(int(alpha[0,0]), 0)

    def test_enclosed_matching_material_loss_is_rejected_locally(self):
        image, _ = fixture()
        pixels = np.asarray(image).copy()
        pixels[90:110, 75:100] = (145,107,78)
        with ExitStack() as stack:
            no_detector(stack)
            with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                p.normalized_master(Image.fromarray(pixels), 'studio-light', mask_mode='grabcut')

    def test_unreliable_corners_fail_without_using_central_rectangle(self):
        image = Image.fromarray(np.random.default_rng(19).integers(0,256,(120,80,3),dtype=np.uint8))
        with patch.object(p.cv2, 'grabCut', side_effect=AssertionError('no sample evidence')):
            self.assertIsNone(p.build_subject_rgba(image, None))

    def test_homogeneous_inverted_corner_seeds_are_ambiguous(self):
        for kind in ('rectangle', 'vertical_slit', 'irregular_pocket'):
            pixels = np.full((160,120,3), (220,160,140), dtype=np.uint8)
            if kind == 'rectangle': pixels[20:150,40:80] = (145,107,78)
            elif kind == 'vertical_slit': pixels[:,40:80] = (145,107,78)
            else:
                for y in range(20,150): pixels[y,35+(y%20)//2:80] = (145,107,78)
            with self.subTest(kind=kind), ExitStack() as stack:
                no_detector(stack)
                with self.assertRaisesRegex(p.MaskProcessingError, 'grabcut_mask_unavailable'):
                    p.normalized_master(Image.fromarray(pixels), 'studio-light', mask_mode='grabcut')

    def test_unsupported_source_retention_is_not_claimed_as_quality(self):
        pixels = np.random.default_rng(29).integers(0,256,(160,120,3),dtype=np.uint8)
        pixels[:,50:100] = (220,160,140); pixels[50:95,10:25] = (220,160,140)
        image = Image.fromarray(pixels); bad = image.convert('RGBA')
        alpha=np.zeros((160,120),np.uint8); alpha[:,50:100]=255; bad.putalpha(Image.fromarray(alpha))
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(bad,.4,{})))
            with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')

    def test_thin_supported_hair_or_strap_loss_is_rejected(self):
        image, good = fixture(); pixels=np.asarray(image).copy(); pixels[20:140,10:12]=(60,40,30)
        image=Image.fromarray(pixels); bad=image.convert('RGBA'); bad.putalpha(good.getchannel('A'))
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(bad,.41,{})))
            with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')

    def test_different_uniform_lower_corners_are_ambiguous(self):
        pixels=np.full((160,120,3),(145,107,78),dtype=np.uint8)
        pixels[80:,:]=(90,60,40); pixels[:,40:80]=(220,160,140)
        with ExitStack() as stack:
            no_detector(stack)
            with self.assertRaisesRegex(p.MaskProcessingError, 'grabcut_mask_unavailable'):
                p.normalized_master(Image.fromarray(pixels), 'studio-light', mask_mode='grabcut')

    def test_complex_primary_modnet_success_does_not_run_grabcut(self):
        image, subject = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'complex_background_prefers_modnet', return_value=True))
            stack.enter_context(patch.object(p, 'build_subject_rgba', side_effect=AssertionError('unnecessary GrabCut')))
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(subject, .41, {})))
            _, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'))
        self.assertEqual(worker.call_count, 1)
        self.assertEqual(diag['mask_method'], 'modnet')

    def test_complex_worker_failure_does_not_retry_after_grabcut_failure(self):
        image, _ = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'complex_background_prefers_modnet', return_value=True))
            stack.enter_context(patch.object(p, 'build_subject_rgba', return_value=None))
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=None))
            _, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'))
        self.assertEqual(worker.call_count, 1)
        self.assertTrue(diag['background_fallback'])

    def test_real_missing_worker_without_bbox_does_not_publish(self):
        image, _ = fixture()
        with tempfile.TemporaryDirectory() as directory, ExitStack() as stack:
            root = Path(directory); source = root/'source.png'; image.save(source)
            no_detector(stack)
            stack.enter_context(patch.object(p, 'MODNET_MODEL_PATH', root/'missing.onnx'))
            with self.assertRaisesRegex(p.MaskProcessingError, 'modnet_worker_failed') as caught:
                p.normalized_master(image, 'studio-light', source, mask_mode='modnet')
            self.assertEqual(caught.exception.normalization['worker_error'], 'worker-not-ready')

    def test_invalid_mask_never_composes_background(self):
        image, _ = fixture(); bad = image.convert('RGBA'); bad.putalpha(0)
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(bad,.4,{})))
            stack.enter_context(patch.object(p, 'compose_subject_on_background', side_effect=AssertionError('invalid mask composed')))
            with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')

    def test_no_evidence_never_initializes_rectangle(self):
        image = Image.new('RGB', (180, 240), (145, 107, 78))
        with ExitStack() as stack:
            no_detector(stack)
            grabcut = stack.enter_context(patch.object(p.cv2, 'grabCut', side_effect=AssertionError('no reliable seeds')))
            with self.assertRaisesRegex(p.MaskProcessingError, 'grabcut_mask_unavailable'):
                p.normalized_master(image, 'studio-light', mask_mode='grabcut')
            self.assertEqual(grabcut.call_count, 0)

    def test_invalid_modnet_alpha_and_missing_hand_are_rejected(self):
        image, good = fixture()
        candidates = []
        for alpha in (0, 255, 90):
            bad = image.convert('RGBA'); bad.putalpha(alpha); candidates.append((bad, .4, {}))
        bad = good.copy(); alpha = np.asarray(bad.getchannel('A')).copy(); alpha[90:150, 20:35] = 0
        bad.putalpha(Image.fromarray(alpha)); candidates.append((bad, .4, {}))
        candidates.extend(((good, float('nan'), {}), (Image.new('RGBA', (20,20)), .4, {})))
        for result in candidates:
            with self.subTest(ratio=result[1]), ExitStack() as stack:
                no_detector(stack)
                stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=result))
                stack.enter_context(patch.object(p, 'build_subject_rgba', side_effect=AssertionError('manual fallback')))
                with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                    p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')

    def test_worker_failure_is_distinct_and_manual_never_falls_back(self):
        image, _ = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=None))
            stack.enter_context(patch.object(p, 'build_subject_rgba', side_effect=AssertionError('manual fallback')))
            with self.assertRaisesRegex(p.MaskProcessingError, 'modnet_worker_failed') as caught:
                p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')
            self.assertEqual(caught.exception.normalization['reason_code'], 'modnet_worker_failed')
            self.assertEqual(worker.call_count, 1)

    def test_worker_alpha_validation_error_is_mask_quality_rejection(self):
        image, _ = fixture()
        def invalid_worker(source, image, *, diagnostics):
            diagnostics.update(status='failed', worker_error='worker-alpha-size-mismatch')
            return None
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', side_effect=invalid_worker))
            with self.assertRaisesRegex(p.MaskProcessingError, 'mask_quality_rejected'):
                p.normalized_master(image, 'studio-light', Path('/tmp/source.png'), mask_mode='modnet')

    def test_auto_tries_worker_once_only_after_unavailable_grabcut(self):
        image, subject = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'complex_background_prefers_modnet', return_value=False))
            stack.enter_context(patch.object(p, 'build_subject_rgba', return_value=None))
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=(subject, .41, {})))
            _, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'))
            self.assertEqual(worker.call_count, 1)
            self.assertEqual(diag['mask_method'], 'modnet')
            self.assertFalse(diag['background_fallback'])

    def test_auto_rejection_reports_actual_original_background(self):
        image, _ = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'build_subject_rgba', return_value=None))
            worker = stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=None))
            master, diag = p.normalized_master(image, 'studio-light', Path('/tmp/source.png'))
            self.assertEqual(worker.call_count, 1)
            self.assertEqual(diag['background_profile_requested'], 'studio-light')
            self.assertEqual(diag['background_profile'], 'original-canvas')
            self.assertEqual(diag['fallback_reason'], 'background_fallback')
            self.assertEqual(diag['reason_code'], 'background_fallback')
            self.assertEqual(diag['mask_method'], 'none')
            np.testing.assert_array_equal(np.asarray(master), np.asarray(p.standard_canvas(image, p.MASTER_SIZE)))

    def test_original_profile_does_not_segment(self):
        image, _ = fixture()
        with ExitStack() as stack:
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', side_effect=AssertionError('original')))
            stack.enter_context(patch.object(p, 'build_subject_rgba', side_effect=AssertionError('original')))
            master, diag = p.normalized_master(image, 'original-canvas', mask_mode='modnet')
            self.assertEqual(diag['background_profile'], 'original-canvas')
            self.assertFalse(diag['background_fallback'])

    def test_error_cleans_job_directories_without_touching_source(self):
        image, _ = fixture()
        with tempfile.TemporaryDirectory() as directory, ExitStack() as stack:
            root = Path(directory); source_root = root/'uploads'/'products'; source_root.mkdir(parents=True)
            source = source_root/'source.png'; image.save(source); before = source.read_bytes()
            for name, value in (('PROJECT_ROOT',root), ('SOURCE_ROOT',source_root), ('ORIGINAL_ROOT',root/'originals'), ('PROCESSED_ROOT',source_root/'processed')):
                stack.enter_context(patch.object(p,name,value))
            no_detector(stack)
            stack.enter_context(patch.object(p, 'run_modnet_worker', return_value=None))
            with self.assertRaisesRegex(p.MaskProcessingError, 'modnet_worker_failed'):
                p.process_image('uploads/products/source.png', 'studio-light', 'modnet')
            self.assertEqual(source.read_bytes(), before)
            self.assertEqual(list(p.ORIGINAL_ROOT.iterdir()), [])
            self.assertEqual(list(p.PROCESSED_ROOT.iterdir()), [])


if __name__ == '__main__': unittest.main()
