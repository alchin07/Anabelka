import importlib.util
import http.client
import json
import os
import sys
import tempfile
import threading
import time
from contextlib import contextmanager
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

    def rgb(self, seed):
        rgb = np.full((*seed.shape, 3), 240, np.uint8)
        rgb[seed == cv2.GC_PR_FGD] = (180, 110, 90)
        return rgb

    @contextmanager
    def private_review(self, raw=None):
        server = self.server()
        seed, baseline = self.fixture()
        if raw is None:
            raw = baseline
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.fromarray(self.rgb(seed))
            image.save(source)
            job = 'a' * 32
            with (patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'REVIEW_LOCK_ROOT', root / 'locks')):
                server.create_mask_review(job, source, image,
                    {'alpha': Image.fromarray(raw), 'seed': seed,
                     'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}},
                    {}, 'uploads/products/photo.png')
                yield server, root / 'reviews' / job, {
                    'job_id': job, 'source_sha256': server.sha256_file(source), 'revision': 0,
                }

    def test_private_review_replay_revisions_hashes_and_raw_are_immutable(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'create_mask_review'), 'private review storage is required')
        seed, raw = self.fixture()
        raw[50:54, 48:52] = 64
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.fromarray(self.rgb(seed))
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
                retry = server.revise_mask_review(request)
                self.assertEqual(retry['revision'], 1)
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


    def test_successful_segmentation_with_rejected_review_gates_enters_manual_review(self):
        server = self.server()
        seed, raw = self.fixture()
        # The segmentation itself reports no quality error, but it retains
        # known source background. The independent source gate must still win.
        raw[:8, :] = 255
        self.assertEqual(self.module().validate_alpha(raw, raw, seed)['status'], 'needs_review')
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            Image.new('RGB', (100, 120), (200, 140, 100)).save(source)

            def successful_segmentation(image, background_profile, source_path=None,
                                        mask_mode='auto', review_capture=None):
                if review_capture is not None:
                    review_capture.update(
                        alpha=Image.fromarray(raw), seed=seed,
                        geometry={'crop_box': None,
                                  'crop_strategy': 'preserve-source-frame'})
                # This successful return intentionally lacks quality_reason.
                return Image.new('RGB', server.MASTER_SIZE), {
                    'background_profile': background_profile}

            with (patch.object(server, 'ORIGINAL_ROOT', root / 'originals'),
                  patch.object(server, 'PROCESSED_ROOT', root / 'processed'),
                  patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'REVIEW_LOCK_ROOT', root / 'locks'),
                  patch.object(server, 'safe_source_path', return_value=source),
                  patch.object(server, 'project_relative',
                               side_effect=lambda path: path.relative_to(root).as_posix()),
                  patch.object(server, 'normalized_master',
                               side_effect=successful_segmentation)):
                result = server.process_image(
                    'uploads/products/photo.png', 'studio-light', 'auto',
                    allow_review=True)
                self.assertEqual(result['state'], 'needs_review')
                self.assertFalse(result['review']['gates']['background']['accepted'])
                self.assertNotIn('master', result)
                self.assertNotIn('thumb', result)
                self.assertTrue(
                    (root / 'reviews' / result['job_id'] / 'raw-alpha.png').is_file())
                self.assertEqual(list((root / 'processed').iterdir()), [])

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
                    self.assertEqual(result['revision'], 0)
                    self.assertNotIn('path', json.dumps(result))
                    connection.close()
                    connection = http.client.HTTPConnection('127.0.0.1', http_server.server_port)
                    connection.request('POST', '/mask-review', body=json.dumps(request),
                                       headers={'Content-Type': 'application/json'})
                    response = connection.getresponse()
                    self.assertEqual(response.status, 200)
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
                symlink = root / 'reviews' / ('d' * 32)
                symlink_created = True
                try:
                    symlink.symlink_to(outside, target_is_directory=True)
                except OSError as error:
                    # Creating directory symlinks on Windows may require Developer Mode
                    # or the SeCreateSymbolicLinkPrivilege user right.
                    if os.name != 'nt' or getattr(error, 'winerror', None) != 1314:
                        raise
                    symlink_created = False
                active = root / 'reviews' / ('c' * 32)
                with server.private_file_lock(active / 'review.lock'):
                    server.cleanup_expired_mask_reviews()
                    self.assertTrue(active.is_dir())
                self.assertFalse((root / 'reviews' / ('a' * 32)).exists())
                self.assertTrue(claimed.is_dir())
                self.assertEqual((outside / 'sentinel').read_text(), 'keep')
                if symlink_created:
                    self.assertTrue(symlink.is_symlink())
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
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / 'reviews' / 'jobs.lock'
            with patch.object(server, '_fcntl', None), patch.object(server, '_msvcrt', windows):
                with server.private_file_lock(path) as acquired:
                    self.assertTrue(acquired)
                self.assertEqual(calls, [(1, 1), (3, 1)])
                self.assertEqual(server.private_lock_path(path).read_bytes(), b'')

    def test_owned_discard_skips_active_work_checks_claim_and_retries(self):
        server = self.server()
        self.assertTrue(hasattr(server, 'discard_mask_review'), 'owned worker cleanup is required')
        seed, raw = self.fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.new('RGB', (100, 120), (200, 140, 100))
            image.save(source)
            job, token = 'a' * 32, 'b' * 64
            with (patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'REVIEW_LOCK_ROOT', root / 'native-locks')):
                server.create_mask_review(job, source, image,
                    {'alpha': Image.fromarray(raw), 'seed': seed,
                     'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}}, {}, 'photo.png')
                directory = root / 'reviews' / job
                claim = directory / 'php-owner.json'
                claim.write_text(json.dumps({'preview_id': token, 'expires_at': int(time.time()) + 1800}))
                request = {'job_id': job, 'preview_id': token}
                with self.assertRaises(ValueError):
                    server.discard_mask_review({**request, 'preview_id': 'c' * 64})
                with server.private_file_lock(directory / 'review.lock'):
                    self.assertFalse(server.discard_mask_review(request)['removed'])
                    self.assertTrue((directory / 'raw-alpha.png').is_file())
                    self.assertTrue(claim.is_file())
                self.assertTrue(server.discard_mask_review(request)['removed'])
                self.assertFalse(directory.exists())
                self.assertTrue(server.discard_mask_review(request)['removed'])

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

    def test_each_action_reports_its_own_disposition_without_refusal_problem_regions(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[50:54, 48:52] = 64
        actions = [
            {'kind': 'foreground', 'tool': 'point', 'radius': 3, 'points': [[50, 52]]},
            {'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]},
            {'kind': 'foreground', 'tool': 'point', 'radius': 3, 'points': [[5, 4]]},
            {'kind': 'unsure', 'tool': 'point', 'radius': 3, 'points': [[50, 55]]},
        ]
        result = module.revise_alpha(raw, seed, actions, source_rgb=self.rgb(seed))
        self.assertEqual([item['index'] for item in result.get('action_results', [])], [0, 1, 2, 3])
        self.assertEqual([item['status'] for item in result['action_results']],
                         ['accepted', 'rejected', 'rejected', 'unresolved'])
        self.assertEqual([item['reasons'] for item in result['action_results']],
                         [[], ['unsafe_background_annotation'], ['unsupported_foreground_annotation'],
                          ['annotation_unsure']])
        self.assertTrue(result['action_results'][1]['conflicts'])
        self.assertEqual(result['validation']['regions'], [])
        self.assertEqual(result['validation']['status'], 'needs_review')
        self.assertEqual(result['alpha'][52, 50], 255)
        self.assertEqual(result['alpha'][55, 45], raw[55, 45])

    def test_duplicate_rejected_annotations_do_not_increase_actual_mask_problem_regions(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[30:50, 40:60] = 0
        action = {'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]}
        baseline = module.validate_alpha(raw, raw, seed)
        self.assertTrue(baseline['regions'])
        for count in (1, 2, 16):
            with self.subTest(actions=count):
                result = module.revise_alpha(raw, seed, [action] * count)
                self.assertEqual(result['validation']['regions'], baseline['regions'])
                self.assertEqual(len(result.get('action_results', [])), count)
                self.assertTrue(all(item['status'] == 'rejected' for item in result['action_results']))
                np.testing.assert_array_equal(result['alpha'], raw)

    def test_maximum_rejected_journal_stays_bounded_and_can_be_undone(self):
        server = self.server()
        seed = np.full((200, 200), cv2.GC_BGD, np.uint8)
        seed[20:100, 20:100] = cv2.GC_PR_FGD
        raw = np.where(seed == cv2.GC_PR_FGD, 255, 0).astype(np.uint8)
        points = [[10 + 5 * index, 150] for index in range(32)]
        for x, y in points:
            raw[y - 1:y + 2, x:x + 3] = 255
        action = {'kind': 'background', 'tool': 'brush', 'radius': 2,
                  'hardness': 1, 'points': points}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / 'photo.png'
            image = Image.fromarray(self.rgb(seed))
            image.save(source)
            job = 'a' * 32
            with (patch.object(server, 'REVIEW_ROOT', root / 'reviews'),
                  patch.object(server, 'REVIEW_LOCK_ROOT', root / 'locks')):
                server.create_mask_review(job, source, image,
                    {'alpha': Image.fromarray(raw), 'seed': seed,
                     'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}},
                    {}, 'uploads/products/photo.png')
                request = {'job_id': job, 'source_sha256': server.sha256_file(source), 'revision': 0}
                result = server.revise_mask_review({**request, 'actions': [action] * 64})
                directory = root / 'reviews' / job
                self.assertLessEqual((directory / 'metadata.json').stat().st_size, server.MAX_JSON_BYTES * 4)
                self.assertLess(len(server.json_bytes(result)), 1024 * 1024)
                self.assertEqual(len(result['review']['action_results']), 64)
                for item in result['review']['action_results']:
                    self.assertEqual(item['status'], 'rejected')
                    self.assertEqual(item['reasons'], ['unsafe_background_annotation'])
                    self.assertEqual(len(item['conflicts']), 1)
                    self.assertEqual(item['conflicts'][0]['bbox'], [10, 149, 157, 3])
                    self.assertEqual(item['conflicts'][0]['pixels'], 285)
                    self.assertNotIn('polygon', item['conflicts'][0])
                np.testing.assert_array_equal(np.asarray(Image.open(directory / 'alpha-1.png')), raw)
                undo = server.revise_mask_review({**request, 'revision': 1, 'actions': []})
                self.assertEqual(undo['revision'], 2)
                self.assertEqual(undo['review']['actions'], [])
                self.assertEqual(undo['review']['action_results'], [])
                np.testing.assert_array_equal(np.asarray(Image.open(directory / 'alpha-2.png')), raw)

    def test_unsure_remains_explicitly_unresolved_without_alpha_changes(self):
        module = self.module()
        seed, raw = self.fixture()
        result = module.revise_alpha(raw, seed, [
            {'kind': 'unsure', 'tool': 'point', 'radius': 2, 'points': [[50, 50]]},
        ])
        self.assertEqual(result['validation']['status'], 'needs_review')
        self.assertIn('annotation_unsure', result['validation']['reasons'])
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_initial_empty_snapshot_has_no_transaction_or_revision_file(self):
        with self.private_review() as (server, directory, request):
            before = (directory / 'metadata.json').read_bytes()
            result = server.revise_mask_review({**request, 'actions': []})
            self.assertEqual(result['revision'], 0)
            self.assertEqual(result['review'].get('actions'), [])
            self.assertEqual(result['review'].get('action_results'), [])
            self.assertEqual(result['review'].get('initial_region_count'), 0)
            self.assertEqual((directory / 'metadata.json').read_bytes(), before)
            self.assertFalse((directory / 'alpha-1.png').exists())

    def test_current_and_immediate_parent_normalized_retry_do_not_write_or_consume_revision(self):
        action = {'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]}
        with self.private_review() as (server, directory, request):
            result = server.revise_mask_review({**request, 'actions': [action]})
            self.assertEqual(result['revision'], 1)
            before = (directory / 'metadata.json').read_bytes()
            for revision in (0, 1):
                retry = server.revise_mask_review({**request, 'revision': revision,
                    'actions': [{**action, 'hardness': 1}]})
                self.assertEqual(retry['revision'], 1)
                self.assertEqual(retry['review']['actions'], [{**action, 'hardness': 1}])
                self.assertEqual(retry['review']['action_results'][0]['status'], 'rejected')
                self.assertEqual(retry['review']['regions'], [])
                self.assertEqual((directory / 'metadata.json').read_bytes(), before)
                self.assertFalse((directory / 'alpha-2.png').exists())
            with self.assertRaises(ValueError):
                server.revise_mask_review({**request, 'actions': []})

    def test_input_and_footprint_rejections_prove_the_locked_snapshot_was_not_committed(self):
        invalid_actions = [
            {'kind': 'background', 'tool': 'point', 'radius': 2, 'points': [[100, 55]]},
            {'kind': 'background', 'tool': 'brush', 'radius': 80, 'points': [[50, 55]]},
        ]
        with self.private_review() as (server, directory, request):
            self.assertTrue(hasattr(server, 'MaskReviewEditRejected'), 'certain edit failures require typed proof')
            before = (directory / 'metadata.json').read_bytes()
            for action in invalid_actions:
                with self.subTest(action=action), self.assertRaises(server.MaskReviewEditRejected) as caught:
                    server.revise_mask_review({**request, 'actions': [action]})
                error = caught.exception
                self.assertEqual((error.job_id, error.source_sha256, error.revision),
                                 (request['job_id'], request['source_sha256'], 0))
                self.assertEqual((directory / 'metadata.json').read_bytes(), before)
                self.assertFalse((directory / 'alpha-1.png').exists())
            corrected = server.revise_mask_review({**request, 'actions': [
                {'kind': 'background', 'tool': 'point', 'radius': 2, 'points': [[45, 55]]},
            ]})
            self.assertEqual(corrected['revision'], 1)
            undo = server.revise_mask_review({**request, 'revision': 1, 'actions': []})
            self.assertEqual(undo['revision'], 2)

    def test_stale_or_file_errors_never_claim_a_definite_edit_rejection(self):
        action = {'kind': 'background', 'tool': 'point', 'radius': 2, 'points': [[45, 55]]}
        with self.private_review() as (server, directory, request):
            self.assertTrue(hasattr(server, 'MaskReviewEditRejected'), 'certain edit failures require typed proof')
            with patch.object(Image.Image, 'save', side_effect=OSError('synthetic save failure')):
                with self.assertRaises(OSError) as caught:
                    server.revise_mask_review({**request, 'actions': [action]})
            self.assertNotIsInstance(caught.exception, server.MaskReviewEditRejected)
            self.assertFalse((directory / 'alpha-1.png').exists())
            server.revise_mask_review({**request, 'actions': [action]})
            for stale_actions in ([], [{**action, 'points': [[100, 55]]}]):
                with self.subTest(actions=stale_actions), self.assertRaises(ValueError) as caught:
                    server.revise_mask_review({**request, 'actions': stale_actions})
                self.assertNotIsInstance(caught.exception, server.MaskReviewEditRejected)

    def test_http_rejected_edits_return_bound_proof_and_allow_a_corrected_snapshot(self):
        with self.private_review() as (server, directory, request):
            http_server = server.ThreadingHTTPServer(('127.0.0.1', 0), server.Handler)
            thread = threading.Thread(target=http_server.serve_forever, daemon=True)
            thread.start()
            def post(payload):
                connection = http.client.HTTPConnection('127.0.0.1', http_server.server_port)
                try:
                    connection.request('POST', '/mask-review', body=json.dumps(payload),
                                       headers={'Content-Type': 'application/json'})
                    response = connection.getresponse()
                    return response.status, json.loads(response.read())
                finally:
                    connection.close()
            try:
                for action in (
                    {'kind': 'background', 'tool': 'point', 'radius': 2, 'points': [[100, 55]]},
                    {'kind': 'background', 'tool': 'brush', 'radius': 80, 'points': [[50, 55]]},
                ):
                    status, error = post({**request, 'actions': [action]})
                    self.assertEqual(status, 400)
                    self.assertTrue(error.get('review_edit_rejected'), error)
                    self.assertEqual({key: error[key] for key in request}, request)
                    self.assertEqual(set(error), {'ok', 'error', 'review_edit_rejected',
                                                 'job_id', 'source_sha256', 'revision'})
                    self.assertFalse((directory / 'alpha-1.png').exists())
                action = {'kind': 'background', 'tool': 'point', 'radius': 2, 'points': [[45, 55]]}
                status, result = post({**request, 'actions': [action]})
                self.assertEqual((status, result['revision']), (200, 1))
                status, error = post({**request, 'actions': []})
                self.assertEqual(status, 400)
                self.assertNotIn('review_edit_rejected', error)
            finally:
                http_server.shutdown()
                http_server.server_close()
                thread.join()

    def test_undo_and_redo_replay_the_original_alpha_and_keep_initial_region_count(self):
        seed, raw = self.fixture()
        raw[30:50, 40:60] = 0
        raw[50:54, 48:52] = 64
        action = {'kind': 'foreground', 'tool': 'point', 'radius': 3,
                  'hardness': .5, 'points': [[50, 52]]}
        with self.private_review(raw) as (server, directory, request):
            first = server.revise_mask_review({**request, 'actions': [action]})
            repaired = np.asarray(Image.open(directory / 'alpha-1.png')).copy()
            self.assertGreater(repaired[52, 50], raw[52, 50])
            initial = first['review'].get('initial_region_count')
            self.assertEqual(initial, len(self.module().validate_alpha(raw, raw, seed)['regions']))
            undo = server.revise_mask_review({**request, 'revision': 1, 'actions': []})
            self.assertEqual(undo['revision'], 2)
            self.assertEqual(undo['review']['actions'], [])
            self.assertEqual(undo['review']['action_results'], [])
            self.assertEqual(undo['review']['initial_region_count'], initial)
            np.testing.assert_array_equal(np.asarray(Image.open(directory / 'alpha-2.png')), raw)
            redo = server.revise_mask_review({**request, 'revision': 2, 'actions': [action]})
            self.assertEqual(redo['revision'], 3)
            np.testing.assert_array_equal(np.asarray(Image.open(directory / 'alpha-3.png')), repaired)

    def test_identical_snapshot_is_allowed_at_revision_limit_but_changes_remain_bounded(self):
        action = {'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]}
        with self.private_review() as (server, directory, request):
            server.revise_mask_review({**request, 'actions': [action]})
            metadata = json.loads((directory / 'metadata.json').read_text())
            metadata['revision'] = 32
            (directory / 'alpha-32.png').write_bytes((directory / 'alpha-1.png').read_bytes())
            server.review_metadata_write(directory, metadata)
            before = (directory / 'metadata.json').read_bytes()
            for revision in (31, 32):
                result = server.revise_mask_review({**request, 'revision': revision, 'actions': [action]})
                self.assertEqual(result['revision'], 32)
            self.assertEqual((directory / 'metadata.json').read_bytes(), before)
            with self.assertRaises(ValueError):
                server.revise_mask_review({**request, 'revision': 32, 'actions': []})
            with self.assertRaises(ValueError):
                server.revise_mask_review({**request, 'revision': 30, 'actions': [action]})

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
            restored = module.revise_alpha(raw, seed, [{
                'kind': 'restore', 'tool': 'point', 'radius': 4,
                'points': [[50, 52]],
            }], source_rgb=rgb)
            self.assertEqual(restored['action_results'][0]['status'], 'rejected')
            self.assertIn('unsafe_material_restore', restored['validation']['reasons'])
            np.testing.assert_array_equal(restored['alpha'], raw)

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


    def test_explicit_restore_reaches_probable_material_past_the_existing_alpha_edge(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[30:90, 35:65] = 0
        source_rgb = self.rgb(seed)
        result = module.revise_alpha(raw, seed, [{
            'kind': 'restore', 'tool': 'point', 'radius': 3,
            'points': [[50, 60]],
        }], source_rgb=source_rgb)
        self.assertEqual(result['action_results'][0]['status'], 'accepted')
        self.assertGreater(result['alpha'][60, 50], raw[60, 50])
        self.assertEqual(result['validation']['status'], 'needs_review')

    def test_explicit_restore_refuses_known_background(self):
        module = self.module()
        seed, raw = self.fixture()
        source_rgb = self.rgb(seed)
        seed[60, 50] = cv2.GC_BGD
        result = module.revise_alpha(raw, seed, [{
            'kind': 'restore', 'tool': 'point', 'radius': 2,
            'points': [[50, 60]],
        }], source_rgb=source_rgb)
        self.assertEqual(result['action_results'][0]['status'], 'rejected')
        self.assertEqual(result['action_results'][0]['reasons'], ['unsafe_material_restore'])
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_explicit_restore_refuses_source_background_color_even_inside_candidate_seed(self):
        module = self.module()
        seed, raw = self.fixture()
        raw[60, 50] = 0  # Exercise a real cutout that restoration would promote.
        source_rgb = self.rgb(seed)
        source_rgb[60, 50] = (240, 240, 240)
        result = module.revise_alpha(raw, seed, [{
            'kind': 'restore', 'tool': 'point', 'radius': 1,
            'points': [[50, 60]],
        }], source_rgb=source_rgb)
        self.assertEqual(result['action_results'][0]['status'], 'rejected')
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_explicit_restore_requires_source_and_background_evidence(self):
        module = self.module()
        seed, raw = self.fixture()
        result = module.revise_alpha(raw, seed, [{
            'kind': 'restore', 'tool': 'point', 'radius': 2,
            'points': [[50, 60]],
        }])
        self.assertEqual(result['action_results'][0]['status'], 'rejected')
        np.testing.assert_array_equal(result['alpha'], raw)

    def test_explicit_restore_has_a_separate_four_percent_area_limit(self):
        module = self.module()
        seed, raw = self.fixture()
        action = {'kind': 'restore', 'tool': 'brush', 'radius': 80,
                  'hardness': 1, 'points': [[50, 60]]}
        with self.assertRaises(ValueError):
            list(module.action_weights([action], raw.shape))

if __name__ == '__main__':
    unittest.main()
