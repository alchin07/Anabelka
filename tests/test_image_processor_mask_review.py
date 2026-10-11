import importlib.util
import http.client
import json
import sys
import tempfile
import threading
import time
from types import SimpleNamespace
import unittest
from pathlib import Path
from unittest.mock import patch

import cv2
import numpy as np
from PIL import Image


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / 'tools' / 'image-processor'
sys.path.insert(0, str(PROCESSOR_DIR))


class MaskReviewTests(unittest.TestCase):
    def module(self):
        path = PROCESSOR_DIR / 'mask_review.py'
        self.assertTrue(path.is_file(), 'mask review implementation is required')
        spec = importlib.util.spec_from_file_location('mask_review_tests_module', path)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def fixture(self):
        seed = np.full((120, 100), cv2.GC_PR_BGD, np.uint8)
        seed[:8] = cv2.GC_BGD
        seed[10:110, 25:75] = cv2.GC_PR_FGD
        raw = np.where(seed == cv2.GC_PR_FGD, 255, 0).astype(np.uint8)
        return seed, raw

    def server(self):
        spec = importlib.util.spec_from_file_location('mask_review_server_tests', PROCESSOR_DIR / 'server.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_private_review_replay_revisions_hashes_and_raw_are_immutable(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'create_mask_review'), 'private review storage is required')
        seed, raw = self.fixture()
        raw[50:54, 48:52] = 64
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            job = 'a' * 32
            capture = {'alpha': Image.fromarray(raw), 'seed': seed,
                       'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'},
                       'mask_method': 'modnet'}
            with patch.object(server, 'REVIEW_ROOT', root / 'reviews'):
                review = server.create_mask_review(job, source, image, capture, {}, 'uploads/products/photo.png')
                request = {'job_id': job, 'source_sha256': server.sha256_file(source), 'revision': 0,
                           'actions': [{'kind': 'foreground', 'tool': 'point', 'radius': 4, 'points': [[50, 52]]}]}
                result = server.revise_mask_review(request)
                self.assertEqual(result['revision'], 1)
                self.assertEqual(review['raw_alpha_sha256'], result['review']['raw_alpha_sha256'])
                np.testing.assert_array_equal(np.asarray(Image.open(root / 'reviews' / job / 'raw-alpha.png')), raw)
                with self.assertRaises(ValueError):
                    server.revise_mask_review(request)
                request.update(revision=1, actions=[])
                result = server.revise_mask_review(request)
                self.assertEqual(result['revision'], 2)
                np.testing.assert_array_equal(np.asarray(Image.open(root / 'reviews' / job / 'alpha-2.png')), raw)
                request.update(revision=2, source_sha256='f' * 64)
                with self.assertRaises(ValueError):
                    server.revise_mask_review(request)
                (root / 'reviews' / job / 'raw-alpha.png').write_bytes(b'tampered')
                request['source_sha256'] = server.sha256_file(source)
                with self.assertRaises(ValueError):
                    server.revise_mask_review(request)

    def test_process_optional_review_retains_rejected_alpha_without_public_output(self):
        server = self.server()
        import inspect
        self.assertIn('allow_review', inspect.signature(server.process_image).parameters)
        seed, raw = self.fixture()
        raw[30:60, 30:60] = 0
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)

            def rejected(image, background_profile, source_path=None, mask_mode='auto', review_capture=None):
                if review_capture is not None:
                    review_capture.update(alpha=Image.fromarray(raw), seed=seed,
                                          quality_reason='source_local_material_loss',
                                          geometry={'crop_box': None, 'crop_strategy': 'preserve-source-frame'})
                raise server.MaskProcessingError('mask_quality_rejected', {'reason_code': 'mask_quality_rejected'})

            with (patch.object(server, 'ORIGINAL_ROOT', root / 'originals'),
                  patch.object(server, 'PROCESSED_ROOT', root / 'processed'),
                  patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'safe_source_path', return_value=source),
                  patch.object(server, 'project_relative', side_effect=lambda path: path.relative_to(root).as_posix()),
                  patch.object(server, 'normalized_master', side_effect=rejected)):
                result = server.process_image('uploads/products/photo.png', 'studio-light', 'modnet', allow_review=True)
                self.assertEqual(result['state'], 'needs_review')
                self.assertNotIn('master', result)
                self.assertTrue((root / 'reviews' / result['job_id'] / 'raw-alpha.png').is_file())
                self.assertEqual(list((root / 'processed').iterdir()), [])
                with self.assertRaises(server.MaskProcessingError):
                    server.process_image('uploads/products/photo.png', 'studio-light', 'modnet')

    def test_result_revalidates_and_uses_existing_composition_without_inference(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'render_mask_review'), 'review render endpoint is required')
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            job = 'b' * 32
            with (patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'ORIGINAL_ROOT', root / 'originals'),
                  patch.object(server, 'PROCESSED_ROOT', root / 'processed'),
                  patch.object(server, 'project_relative', side_effect=lambda path: path.relative_to(root).as_posix()),
                  patch.object(server, 'run_modnet_worker', side_effect=AssertionError('must not rerun inference'))):
                capture = {'alpha': Image.fromarray(raw), 'seed': seed,
                           'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'},
                           'mask_method': 'modnet'}
                server.create_mask_review(job, source, image, capture, {}, 'uploads/products/photo.png')
                request = {'job_id': job, 'source_sha256': server.sha256_file(source),
                           'revision': 0, 'background_profile': 'anabelka-brand'}
                result = server.render_mask_review(request)
                self.assertEqual(result['state'], 'accepted')
                self.assertEqual(result['master']['width'], 1200)
                self.assertEqual(result['master']['height'], 1800)
                self.assertEqual(result['review']['revision'], 0)
                rgba = image.convert('RGBA')
                rgba.putalpha(Image.fromarray(raw))
                expected = server.compose_subject_on_background(
                    server.transparent_standard_canvas(rgba, server.MASTER_SIZE), 'anabelka-brand')
                expected_path = root / 'expected.webp'
                server.save_webp(expected, expected_path, 92)
                self.assertEqual((root / result['master']['path']).read_bytes(), expected_path.read_bytes())

    def test_http_review_dispatch_uses_revision_and_returns_no_public_paths(self):
        server = self.server()
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            job = 'c' * 32
            with patch.object(server, 'REVIEW_ROOT', root / 'reviews'):
                server.create_mask_review(job, source, image,
                    {'alpha': Image.fromarray(raw), 'seed': seed,
                     'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}},
                    {}, 'uploads/products/photo.png')
                http_server = server.ThreadingHTTPServer(('127.0.0.1', 0), server.Handler)
                thread = threading.Thread(target=http_server.serve_forever, daemon=True)
                thread.start()
                try:
                    request = {'job_id': job, 'source_sha256': server.sha256_file(source),
                               'revision': 0, 'actions': []}
                    connection = http.client.HTTPConnection('127.0.0.1', http_server.server_port)
                    connection.request('POST', '/mask-review', body=json.dumps(request),
                                       headers={'Content-Type': 'application/json'})
                    response = connection.getresponse()
                    result = json.loads(response.read())
                    self.assertEqual(response.status, 200)
                    self.assertEqual(result['revision'], 1)
                    self.assertNotIn('path', json.dumps(result))
                    connection.close()
                    connection = http.client.HTTPConnection('127.0.0.1', http_server.server_port)
                    connection.request('POST', '/mask-review', body=json.dumps(request),
                                       headers={'Content-Type': 'application/json'})
                    response = connection.getresponse()
                    self.assertEqual(response.status, 400)
                    response.read()
                    connection.close()
                finally:
                    http_server.shutdown()
                    http_server.server_close()
                    thread.join()

    def test_failed_output_save_removes_only_new_unclaimed_review(self):
        server = self.server()
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            other = root / 'reviews' / ('d' * 32)
            other.mkdir(parents=True)
            (other / 'sentinel').write_text('other owner')

            def accepted(image, background_profile, source_path=None, mask_mode='auto', review_capture=None):
                review_capture.update(alpha=Image.fromarray(raw), seed=seed,
                    geometry={'crop_box': None, 'crop_strategy': 'preserve-source-frame'})
                return Image.new('RGB', server.MASTER_SIZE), {'background_profile': background_profile}

            with (patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'ORIGINAL_ROOT', root / 'originals'),
                  patch.object(server, 'PROCESSED_ROOT', root / 'processed'),
                  patch.object(server, 'safe_source_path', return_value=source),
                  patch.object(server, 'project_relative', side_effect=lambda path: path.relative_to(root).as_posix()),
                  patch.object(server, 'normalized_master', side_effect=accepted),
                  patch.object(server, 'save_webp', side_effect=OSError('disk full'))):
                with self.assertRaises(OSError):
                    server.process_image('uploads/products/photo.png', 'studio-light', allow_review=True)
                self.assertEqual([path for path in (root / 'reviews').iterdir() if path.is_dir()], [other])
                self.assertEqual((other / 'sentinel').read_text(), 'other owner')

    def test_expired_orphan_sweep_preserves_claimed_active_and_symlink_directories(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'cleanup_expired_mask_reviews'), 'bounded orphan cleanup is required')
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            with patch.object(server, 'REVIEW_ROOT', root / 'reviews'):
                capture = {'alpha': Image.fromarray(raw), 'seed': seed,
                           'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}}
                for digit in ('a', 'b', 'c'):
                    server.create_mask_review(digit * 32, source, image, capture, {}, 'uploads/products/photo.png')
                for digit in ('a', 'b', 'c'):
                    directory = root / 'reviews' / (digit * 32)
                    metadata = json.loads((directory / 'metadata.json').read_text())
                    metadata['created_at'] = time.time() - 1900
                    server.review_metadata_write(directory, metadata)
                claimed = root / 'reviews' / ('b' * 32)
                (claimed / 'php-owner.json').write_text(json.dumps({'preview_id': 'e' * 64, 'expires_at': int(time.time()) + 300}))
                outside = root / 'outside'
                outside.mkdir()
                (outside / 'sentinel').write_text('keep')
                (root / 'reviews' / ('d' * 32)).symlink_to(outside, target_is_directory=True)
                active = root / 'reviews' / ('c' * 32)
                with (active / 'review.lock').open('a+b') as handle:
                    with server.private_file_lock(handle):
                        server.cleanup_expired_mask_reviews()
                        self.assertTrue(active.is_dir())
                self.assertFalse((root / 'reviews' / ('a' * 32)).exists())
                self.assertTrue(claimed.is_dir())
                self.assertEqual((outside / 'sentinel').read_text(), 'keep')
                (claimed / 'php-owner.json').write_text(json.dumps({'preview_id': 'e' * 64, 'expires_at': int(time.time()) - 1}))
                server.cleanup_expired_mask_reviews()
                self.assertFalse(claimed.exists())
                self.assertFalse(active.exists())
                self.assertEqual((outside / 'sentinel').read_text(), 'keep')

    def test_private_quota_counts_unclaimed_jobs(self):
        server = self.server()
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            source = root / 'photo.png'
            image.save(source)
            capture = {'alpha': Image.fromarray(raw), 'seed': seed,
                       'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}}
            with patch.object(server, 'REVIEW_ROOT', root / 'reviews'), patch.object(server, 'MAX_REVIEW_JOBS', 1):
                server.create_mask_review('a' * 32, source, image, capture, {}, 'uploads/products/photo.png')
                with self.assertRaises(ValueError):
                    server.create_mask_review('b' * 32, source, image, capture, {}, 'uploads/products/photo.png')
                self.assertFalse((root / 'reviews' / ('b' * 32)).exists())

    def test_windows_lock_adapter_uses_one_byte_and_unlocks(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'private_file_lock'), 'portable file lock is required')
        calls = []
        windows = SimpleNamespace(LK_LOCK=1, LK_NBLCK=2, LK_UNLCK=3,
                                  locking=lambda fd, mode, count: calls.append((mode, count)))
        with tempfile.TemporaryFile('a+b') as handle:
            with patch.object(server, '_fcntl', None), patch.object(server, '_msvcrt', windows):
                with server.private_file_lock(handle) as acquired:
                    self.assertTrue(acquired)
                self.assertEqual(calls, [(1, 1), (3, 1)])
                handle.seek(0)
                self.assertEqual(handle.read(), b'0')

    def test_local_soft_foreground_repairs_only_circle_and_preserves_raw(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[50:54, 48:52] = 64
        raw[52, 46] = 64
        baseline = raw.copy()
        rgb = np.full((*raw.shape, 3), 240, np.uint8)
        rgb[seed == cv2.GC_PR_FGD] = (180, 110, 90)
        result = module.revise_alpha(raw, seed, [{
            'kind': 'foreground', 'tool': 'point', 'radius': 4,
            'hardness': .5, 'points': [[50, 52]],
        }], source_rgb=rgb)
        self.assertEqual(result['alpha'][52, 50], 255)
        self.assertGreater(result['alpha'][50, 49], 0)
        self.assertLess(result['alpha'][50, 49], 255)
        self.assertEqual(result['alpha'][52, 46], 64)
        np.testing.assert_array_equal(raw, baseline)
        self.assertTrue(np.all(result['alpha'][raw == 255] == 255))
        self.assertEqual(set(result['validation']['gates']), {'component', 'local', 'thin', 'background'})

    def test_background_mark_on_opaque_skin_or_arm_is_rejected_without_mutation(self):
        module = self.module()
        seed, raw = self.fixture()
        # Even a mistaken source colour label cannot erase immutable opaque material.
        seed[50:60, 40:50] = cv2.GC_PR_BGD
        for kind in ('background',):
            result = module.revise_alpha(raw, seed, [{
                'kind': kind, 'tool': 'point', 'radius': 3,
                'hardness': 1, 'points': [[45, 55]],
            }])
            self.assertEqual(result['validation']['status'], 'needs_review')
            self.assertIn('unsafe_background_annotation', result['validation']['reasons'])
            np.testing.assert_array_equal(result['alpha'], raw)

    def test_foreground_annotation_cannot_add_known_background(self):
        module = self.module()
        seed, raw = self.fixture()
        result = module.revise_alpha(raw, seed, [{
            'kind': 'foreground', 'tool': 'point', 'radius': 3,
            'points': [[5, 4]],
        }])
        self.assertIn('unsupported_foreground_annotation', result['validation']['reasons'])
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_foreground_annotation_cannot_restore_large_ambiguous_backdrop_gap(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[30:90, 35:65] = 0
        result = module.revise_alpha(raw, seed, [{
            'kind': 'foreground', 'tool': 'point', 'radius': 3,
            'points': [[50, 60]],
        }])
        self.assertIn('unsupported_foreground_annotation', result['validation']['reasons'])
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_actual_source_enclosed_backdrop_is_never_promoted_by_foreground_label(self):
        server = self.server()
        module = self.module()
        rgb = np.full((120, 100, 3), 240, np.uint8)
        rgb[10:110, 25:75] = (170, 110, 90)
        rgb[70:105, 25:75] = (60, 50, 45)
        rgb[50:54, 48:52] = 240
        seed = server.review_source_seed(Image.fromarray(rgb))
        self.assertIsNotNone(seed)
        self.assertEqual(seed[52, 50], cv2.GC_PR_FGD)
        for baseline in (0, 64, 128):
            raw = np.zeros((120, 100), np.uint8)
            raw[10:110, 25:75] = 255
            raw[50:54, 48:52] = baseline
            result = module.revise_alpha(raw, seed, [{
                'kind': 'foreground', 'tool': 'point', 'radius': 4,
                'points': [[50, 52]],
            }], source_rgb=rgb)
            self.assertEqual(result['validation']['status'], 'needs_review')
            self.assertIn('unsupported_foreground_annotation', result['validation']['reasons'])
            np.testing.assert_array_equal(result['alpha'], raw)

    def test_all_four_gates_report_loss_and_actual_pixel_regions(self):
        module = self.module()
        seed, raw = self.fixture()
        candidate = raw.copy()
        candidate[30:50, 40:60] = 0
        candidate[:5, :20] = 255
        report = module.validate_alpha(candidate, raw, seed)
        self.assertEqual(report['status'], 'needs_review')
        self.assertEqual(set(report['gates']), {'component', 'local', 'thin', 'background'})
        self.assertFalse(report['gates']['local']['accepted'])
        self.assertFalse(report['gates']['background']['accepted'])
        loss = [r for r in report['regions'] if r['reason'] == 'source_local_material_loss'][0]
        self.assertEqual(loss['bbox'], [40, 30, 20, 20])
        self.assertEqual(loss['pixels'], 400)
        self.assertTrue(loss['polygon'])

    def test_thin_detached_hair_strap_lace_is_checked(self):
        module = self.module()
        seed, raw = self.fixture()
        seed[20:90, 90] = cv2.GC_PR_FGD
        raw[20:90, 90] = 255
        candidate = raw.copy()
        candidate[20:90, 90] = 0
        report = module.validate_alpha(candidate, raw, seed)
        self.assertFalse(report['gates']['thin']['accepted'])
        self.assertIn('source_thin_material_loss', report['reasons'])

    def test_strict_limits_reject_floats_bounds_large_replay_and_boxes(self):
        module = self.module()
        seed, raw = self.fixture()
        valid = {'kind': 'foreground', 'tool': 'point', 'radius': 2, 'points': [[50, 50]]}
        invalid = [dict(valid, points=[[50.1, 50]]), dict(valid, points=[[-1, 50]]),
                   dict(valid, radius=13), dict(valid, hardness=float('nan')),
                   dict(valid, tool='bbox'), dict(valid, bbox=[0, 0, 100, 120])]
        for action in invalid:
            with self.subTest(action=action), self.assertRaises(ValueError):
                module.revise_alpha(raw, seed, [action])
        with self.assertRaises(ValueError):
            module.revise_alpha(raw, seed, [valid] * 65)


if __name__ == '__main__':
    unittest.main()
