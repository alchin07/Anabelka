"""Synthetic regressions for review locks on emulated and native storage."""

import errno
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import sys
import subprocess
import tempfile
import time
from types import SimpleNamespace
import unittest
from unittest.mock import patch

import cv2
import numpy as np
from PIL import Image


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / 'tools' / 'image-processor'
sys.path.insert(0, str(PROCESSOR_DIR))


# Each worker imports server independently; no lock descriptors are inherited.
WORKER_SETUP = '''
import importlib.util, json, os, sys, time
from contextlib import contextmanager
from pathlib import Path
config = json.loads(sys.argv[1])
processor = Path(config['processor'])
sys.path.insert(0, str(processor))
spec = importlib.util.spec_from_file_location('private_lock_worker_server', processor / 'server.py')
server = importlib.util.module_from_spec(spec)
spec.loader.exec_module(server)
server.REVIEW_ROOT = Path(config['review_root'])
server.REVIEW_LOCK_ROOT = Path(config['lock_root'])
def wait_for(path):
    deadline = time.monotonic() + 15
    while not Path(path).exists():
        if time.monotonic() >= deadline:
            raise TimeoutError('test coordination marker did not arrive: ' + str(path))
        time.sleep(0.01)
def write_result(path, value):
    destination = Path(path)
    temporary = destination.with_name(destination.name + '.tmp')
    temporary.write_text(json.dumps(value))
    os.replace(temporary, destination)
'''

LOCK_WORKER = '''
logical = Path(config['logical'])
with server.private_file_lock(logical, blocking=config.get('blocking', True)) as acquired:
    write_result(config['result'], {'acquired': acquired})
    if acquired and config.get('release'):
        wait_for(config['release'])
'''

REVISION_WORKER = '''
real_lock = server.private_file_lock
@contextmanager
def observed_lock(logical, *, blocking=True):
    Path(config['attempt']).write_text('attempting')
    with real_lock(logical, blocking=blocking) as acquired:
        yield acquired
server.private_file_lock = observed_lock
real_revise = server.revise_alpha
def observed_revise(*args, **kwargs):
    Path(config['entered']).write_text('inside revision')
    wait_for(config['finish'])
    return real_revise(*args, **kwargs)
server.revise_alpha = observed_revise
Path(config['ready']).write_text('ready')
wait_for(config['begin'])
try:
    result = server.revise_mask_review(config['payload'])
    outcome = {'accepted': True, 'revision': result['revision']}
except ValueError as error:
    outcome = {'accepted': False, 'error': str(error)}
write_result(config['result'], outcome)
'''

CREATION_WORKER = '''
from PIL import Image
original = Path(config['original'])
image = Image.open(original).convert('RGB')
seed = server.np.full((120, 100), server.cv2.GC_PR_BGD, server.np.uint8)
seed[:8] = server.cv2.GC_BGD
seed[10:110, 25:75] = server.cv2.GC_PR_FGD
raw = server.np.where(seed == server.cv2.GC_PR_FGD, 255, 0).astype(server.np.uint8)
capture = {'alpha': Image.fromarray(raw), 'seed': seed,
           'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}}
real_metadata_write = server.review_metadata_write
real_remove = server.shutil.rmtree
def paused_metadata_write(directory, metadata):
    if config.get('fail_write'):
        raise OSError('synthetic write failure')
    Path(config['paused']).write_text('writing')
    wait_for(config['release'])
    return real_metadata_write(directory, metadata)
def paused_remove(directory, *args, **kwargs):
    if Path(directory).name == config['job']:
        Path(config['paused']).write_text('cleaning failed write')
        wait_for(config['release'])
    return real_remove(directory, *args, **kwargs)
server.review_metadata_write = paused_metadata_write
if config.get('fail_write'):
    server.shutil.rmtree = paused_remove
try:
    server.create_mask_review(config['job'], original, image, capture, {}, 'uploads/products/synthetic.png')
    outcome = {'failed': False}
except OSError:
    outcome = {'failed': True}
write_result(config['result'], outcome)
'''


class PrivateReviewLockTests(unittest.TestCase):
    def server(self):
        spec = importlib.util.spec_from_file_location('private_lock_server_tests', PROCESSOR_DIR / 'server.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def require_path_locks(self, server):
        self.assertTrue(callable(getattr(server, 'private_lock_path', None)),
                        'review locks must resolve logical Paths to native lock files')

    def configured(self, server, root):
        review_root = root / 'emulated' / 'reviews'
        lock_root = root / 'native-locks'
        return (patch.object(server, 'REVIEW_ROOT', review_root),
                patch.object(server, 'REVIEW_LOCK_ROOT', lock_root, create=True))

    def worker(self, root, script=LOCK_WORKER, **parameters):
        config = {'processor': str(PROCESSOR_DIR),
                  'review_root': str(root / 'emulated' / 'reviews'),
                  'lock_root': str(root / 'native-locks'), **parameters}
        process = subprocess.Popen([sys.executable, '-c', WORKER_SETUP + script, json.dumps(config)],
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        self.addCleanup(self.stop_worker, process)
        return process

    def stop_worker(self, process):
        if process.poll() is None:
            process.kill()
        process.communicate(timeout=10)

    def await_marker(self, marker, process=None):
        deadline = time.monotonic() + 15
        while not marker.exists():
            if process is not None and process.poll() is not None:
                if marker.exists():
                    break
                output, errors = process.communicate()
                self.fail('worker exited before marker ' + marker.name + ': ' + output + errors)
            if time.monotonic() >= deadline:
                self.fail('worker did not produce marker ' + marker.name)
            time.sleep(0.01)
        return json.loads(marker.read_text()) if marker.suffix == '.json' else marker.read_text()

    def finish_worker(self, process):
        output, errors = process.communicate(timeout=15)
        self.assertEqual(process.returncode, 0, output + errors)

    def fixture(self, root):
        image = Image.new('RGB', (100, 120), (200, 140, 100))
        original = root / 'synthetic-source.png'
        image.save(original)
        seed = np.full((120, 100), cv2.GC_PR_BGD, np.uint8)
        seed[:8] = cv2.GC_BGD
        seed[10:110, 25:75] = cv2.GC_PR_FGD
        raw = np.where(seed == cv2.GC_PR_FGD, 255, 0).astype(np.uint8)
        capture = {'alpha': Image.fromarray(raw), 'seed': seed,
                   'geometry': {'crop_box': None, 'crop_strategy': 'preserve-source-frame'}}
        return original, image, capture

    @unittest.skipUnless(os.name == 'posix' and Path('/proc/self/fd').is_dir(), 'Linux fd paths required')
    def test_emulated_review_storage_never_receives_a_native_flock(self):
        server = self.server()
        real_flock = server._fcntl.flock
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_root = root / 'emulated' / 'reviews'
            native_root = root / 'native-locks'
            original, image, capture = self.fixture(root)
            for unsupported in (errno.ENOSYS, errno.EOPNOTSUPP):
                with self.subTest(errno=unsupported):
                    def reject_emulated(fd, operation):
                        actual = Path(os.readlink('/proc/self/fd/' + str(fd))).resolve()
                        if actual.is_relative_to(review_root.resolve()):
                            raise OSError(unsupported, 'emulated storage cannot flock')
                        return real_flock(fd, operation)

                    with (patch.object(server, 'REVIEW_ROOT', review_root),
                          patch.object(server, 'REVIEW_LOCK_ROOT', native_root, create=True),
                          patch.object(server._fcntl, 'flock', side_effect=reject_emulated)):
                        job = ('a' if unsupported == errno.ENOSYS else 'b') * 32
                        result = server.create_mask_review(job, original, image, capture, {}, 'uploads/products/synthetic.png')
                        self.assertEqual(result['revision'], 0)
                        payload = {'job_id': job, 'source_sha256': server.sha256_file(original),
                                   'revision': 0, 'actions': [
                                       {'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]},
                                   ]}
                        self.assertEqual(server.revise_mask_review(payload)['revision'], 1)
                        self.assertFalse((review_root / 'jobs.lock').exists())
                        self.assertFalse((review_root / job / 'review.lock').exists())

    def test_default_lock_root_uses_private_native_home_directory(self):
        server = self.server()
        self.assertEqual(getattr(server, 'REVIEW_LOCK_ROOT', None),
                         Path.home() / '.anabelka-image-processor-locks')

    def test_native_paths_are_namespaced_stable_and_bounded_to_256_review_stripes(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                registry = server.private_lock_path(server.REVIEW_ROOT / 'jobs.lock')
                namespace = hashlib.sha256(str(server.REVIEW_ROOT.resolve()).encode('utf-8')).hexdigest()
                self.assertEqual(registry, server.REVIEW_LOCK_ROOT / namespace / 'jobs.lock')
                logical_paths = [server.REVIEW_ROOT / format(index, '032x') / 'review.lock'
                                 for index in range(4096)]
                logical_by_native = {server.private_lock_path(path): path for path in logical_paths}
                native_paths = set(logical_by_native)
                self.assertLessEqual(len(native_paths), 256)
                self.assertGreater(len(native_paths), 200, 'review sessions should use the stable stripe pool')
                self.assertNotIn(registry, native_paths, 'registry lock must not share a review stripe')
                self.assertTrue(all(path.parent == registry.parent for path in native_paths))
                repeated = logical_paths[0]
                native = server.private_lock_path(repeated)
                with server.private_file_lock(repeated) as acquired:
                    self.assertTrue(acquired)
                    self.assertTrue(native.is_file())
                    initial_inode = native.stat().st_ino
                self.assertTrue(native.is_file(), 'releasing a lock must keep its persistent native inode')
                with server.private_file_lock(repeated) as acquired:
                    self.assertTrue(acquired)
                    self.assertEqual(native.stat().st_ino, initial_inode)
                for path in native_paths:
                    logical = logical_by_native[path]
                    with server.private_file_lock(logical) as acquired:
                        self.assertTrue(acquired)
                with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock') as acquired:
                    self.assertTrue(acquired)
                self.assertLessEqual(len(list(registry.parent.iterdir())), 257)
                with patch.object(server, 'REVIEW_ROOT', root / 'another-review-root'):
                    other = server.private_lock_path(server.REVIEW_ROOT / 'jobs.lock')
                    self.assertNotEqual(other.parent, registry.parent)

    @unittest.skipUnless(os.name == 'posix', 'POSIX flock fault injection')
    def test_unsupported_native_lock_fails_closed_without_backend_fallback(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            for code in (errno.ENOSYS, errno.EOPNOTSUPP):
                for blocking in (True, False):
                    with self.subTest(errno=code, blocking=blocking):
                        entered = False
                        fake_windows = SimpleNamespace(LK_LOCK=1, LK_NBLCK=2, LK_UNLCK=3,
                                                       locking=lambda *args: None)
                        with (review_patch, lock_patch,
                              patch.object(server._fcntl, 'flock', side_effect=OSError(code, 'native unsupported')),
                              patch.object(server, '_msvcrt', fake_windows)):
                            with self.assertRaises(ValueError):
                                with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock', blocking=blocking):
                                    entered = True
                            self.assertFalse(entered, 'unsupported native locking must not enter the protected body')

    @unittest.skipUnless(os.name == 'posix', 'POSIX flock fault injection')
    def test_busy_native_lock_is_controlled_for_blocking_and_nonblocking_callers(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for code in (errno.EAGAIN, errno.EACCES):
                with self.subTest(errno=code):
                    review_patch, lock_patch = self.configured(server, root)
                    with (review_patch, lock_patch,
                          patch.object(server._fcntl, 'flock', side_effect=OSError(code, 'native busy'))):
                        with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock', blocking=False) as acquired:
                            self.assertFalse(acquired)
                        entered = False
                        with self.assertRaises(ValueError):
                            with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock'):
                                entered = True
                        self.assertFalse(entered)

    def test_no_native_lock_backend_fails_closed(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            entered = False
            with review_patch, lock_patch, patch.object(server, '_fcntl', None), patch.object(server, '_msvcrt', None):
                with self.assertRaises(ValueError):
                    with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock'):
                        entered = True
                self.assertFalse(entered)

    def test_independent_subprocesses_exclude_same_logical_lock_then_acquire_after_release(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            logical = root / 'emulated' / 'reviews' / ('c' * 32) / 'review.lock'
            held = root / 'held.json'
            release = root / 'release'
            holder = self.worker(root, logical=str(logical), result=str(held), release=str(release))
            self.assertTrue(self.await_marker(held, holder)['acquired'])
            blocked = root / 'blocked.json'
            contender = self.worker(root, logical=str(logical), result=str(blocked), blocking=False)
            self.assertFalse(self.await_marker(blocked, contender)['acquired'])
            self.finish_worker(contender)
            release.touch()
            self.finish_worker(holder)
            retried = root / 'retried.json'
            retry = self.worker(root, logical=str(logical), result=str(retried), blocking=False)
            self.assertTrue(self.await_marker(retried, retry)['acquired'])
            self.finish_worker(retry)

    def test_killed_lock_holder_releases_os_lock_without_removing_native_file(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            logical = root / 'emulated' / 'reviews' / 'jobs.lock'
            held = root / 'held.json'
            holder = self.worker(root, logical=str(logical), result=str(held), release=str(root / 'never-release'))
            self.assertTrue(self.await_marker(held, holder)['acquired'])
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                native = server.private_lock_path(logical)
                inode = native.stat().st_ino
                holder.kill()
                holder.communicate(timeout=10)
                self.assertTrue(native.is_file())
                retried = root / 'retry.json'
                retry = self.worker(root, logical=str(logical), result=str(retried), blocking=False)
                self.assertTrue(self.await_marker(retried, retry)['acquired'])
                self.finish_worker(retry)
                self.assertEqual(native.stat().st_ino, inode)

    def test_old_native_timestamps_do_not_expire_a_held_process_lock(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            logical = root / 'emulated' / 'reviews' / ('d' * 32) / 'review.lock'
            held = root / 'held.json'
            release = root / 'release'
            holder = self.worker(root, logical=str(logical), result=str(held), release=str(release))
            self.assertTrue(self.await_marker(held, holder)['acquired'])
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                native = server.private_lock_path(logical)
                old = time.time() - 10 * 365 * 86400
                os.utime(native, (old, old))
                inode = native.stat().st_ino
                blocked = root / 'blocked.json'
                contender = self.worker(root, logical=str(logical), result=str(blocked), blocking=False)
                self.assertFalse(self.await_marker(blocked, contender)['acquired'])
                self.finish_worker(contender)
                self.assertEqual(native.stat().st_ino, inode)
                release.touch()
                self.finish_worker(holder)

    def test_expired_sweep_preserves_process_locked_and_claimed_sessions_until_release(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            original, image, capture = self.fixture(root)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                directories = []
                for digit in ('1', '2', '3'):
                    server.create_mask_review(digit * 32, original, image, capture, {}, 'uploads/products/synthetic.png')
                    directory = server.REVIEW_ROOT / (digit * 32)
                    directories.append(directory)
                for directory in directories:
                    metadata = json.loads((directory / 'metadata.json').read_text())
                    metadata['created_at'] = time.time() - server.REVIEW_TTL_SECONDS - 10
                    server.review_metadata_write(directory, metadata)
                orphan, active, claimed = directories
                claim = claimed / 'php-owner.json'
                claim.write_text(json.dumps({'preview_id': 'e' * 64, 'expires_at': int(time.time()) + 300}))
                native = server.private_lock_path(active / 'review.lock')
                held = root / 'held.json'
                release = root / 'release'
                holder = self.worker(root, logical=str(active / 'review.lock'), result=str(held), release=str(release))
                self.assertTrue(self.await_marker(held, holder)['acquired'])
                server.cleanup_expired_mask_reviews()
                self.assertFalse(orphan.exists())
                self.assertTrue(active.is_dir())
                self.assertTrue(claimed.is_dir())
                self.assertTrue(native.is_file())
                inode = native.stat().st_ino
                release.touch()
                self.finish_worker(holder)
                claim.write_text(json.dumps({'preview_id': 'e' * 64, 'expires_at': int(time.time()) - 1}))
                server.cleanup_expired_mask_reviews()
                self.assertFalse(active.exists())
                self.assertFalse(claimed.exists())
                self.assertEqual(native.stat().st_ino, inode, 'session cleanup must retain the native lock inode')

    def test_expired_sweep_preserves_unknown_claim_shapes_and_broken_claim_symlinks(self):
        server = self.server()
        self.require_path_locks(server)
        for claim_kind in ('broken-symlink', 'list', 'scalar'):
            with self.subTest(claim=claim_kind), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                original, image, capture = self.fixture(root)
                review_patch, lock_patch = self.configured(server, root)
                with review_patch, lock_patch:
                    job = '4' * 32
                    server.create_mask_review(job, original, image, capture, {}, 'uploads/products/synthetic.png')
                    directory = server.REVIEW_ROOT / job
                    metadata = json.loads((directory / 'metadata.json').read_text())
                    metadata['created_at'] = time.time() - server.REVIEW_TTL_SECONDS - 10
                    server.review_metadata_write(directory, metadata)
                    claim = directory / 'php-owner.json'
                    if claim_kind == 'broken-symlink':
                        claim.symlink_to(root / 'missing-ownership-proof')
                    else:
                        claim.write_text('[]' if claim_kind == 'list' else '42')
                    server.cleanup_expired_mask_reviews()
                    self.assertTrue(directory.is_dir(), 'unrecognized ownership must preserve the session')
                    self.assertTrue((directory / 'raw-alpha.png').is_file())

    def test_expired_sweep_preserves_non_object_metadata_without_crashing(self):
        server = self.server()
        self.require_path_locks(server)
        for metadata in ('[]', '42', 'null'):
            with self.subTest(metadata=metadata), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                original, image, capture = self.fixture(root)
                review_patch, lock_patch = self.configured(server, root)
                with review_patch, lock_patch:
                    job = '5' * 32
                    server.create_mask_review(job, original, image, capture, {}, 'uploads/products/synthetic.png')
                    directory = server.REVIEW_ROOT / job
                    (directory / 'metadata.json').write_text(metadata)
                    old = time.time() - server.REVIEW_TTL_SECONDS - 10
                    os.utime(directory, (old, old))
                    server.cleanup_expired_mask_reviews()
                    self.assertTrue(directory.is_dir(), 'unknown metadata must not justify deleting review data')

    def test_expired_sweep_skips_creator_writes_and_failed_write_cleanup_until_release(self):
        server = self.server()
        self.require_path_locks(server)
        for fail_write in (False, True):
            with self.subTest(failed_write=fail_write), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                original, _, _ = self.fixture(root)
                job = '6' * 32
                paused, release, result = root / 'paused', root / 'release', root / 'created.json'
                creator = self.worker(root, CREATION_WORKER, original=str(original), job=job,
                                      paused=str(paused), release=str(release), result=str(result),
                                      fail_write=fail_write)
                self.await_marker(paused, creator)
                review_patch, lock_patch = self.configured(server, root)
                with review_patch, lock_patch:
                    directory = server.REVIEW_ROOT / job
                    old = time.time() - server.REVIEW_TTL_SECONDS - 10
                    os.utime(directory, (old, old))
                    server.cleanup_expired_mask_reviews()
                    self.assertTrue(directory.is_dir())
                    self.assertTrue((directory / 'raw-alpha.png').is_file())
                    release.touch()
                    self.assertEqual(self.await_marker(result, creator)['failed'], fail_write)
                    self.finish_worker(creator)
                    native = server.private_lock_path(directory / 'review.lock')
                    self.assertTrue(native.is_file())
                    if fail_write:
                        self.assertFalse(directory.exists())
                    else:
                        metadata = json.loads((directory / 'metadata.json').read_text())
                        metadata['created_at'] = old
                        server.review_metadata_write(directory, metadata)
                        server.cleanup_expired_mask_reviews()
                        self.assertFalse(directory.exists())

    @unittest.skipUnless(os.name == 'posix', 'POSIX flock fault injection')
    def test_unsupported_native_lock_prevents_creation_and_expired_deletion(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            original, image, capture = self.fixture(root)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                with patch.object(server._fcntl, 'flock', side_effect=OSError(errno.ENOSYS, 'native unsupported')):
                    with self.assertRaises(ValueError):
                        server.create_mask_review('7' * 32, original, image, capture, {}, 'uploads/products/synthetic.png')
                self.assertFalse(server.REVIEW_ROOT.exists(), 'failed native locking must prevent review writes')
                job = '8' * 32
                server.create_mask_review(job, original, image, capture, {}, 'uploads/products/synthetic.png')
                directory = server.REVIEW_ROOT / job
                metadata = json.loads((directory / 'metadata.json').read_text())
                metadata['created_at'] = time.time() - server.REVIEW_TTL_SECONDS - 10
                server.review_metadata_write(directory, metadata)
                with patch.object(server._fcntl, 'flock', side_effect=OSError(errno.EOPNOTSUPP, 'native unsupported')):
                    with self.assertRaises(ValueError):
                        server.cleanup_expired_mask_reviews()
                self.assertTrue(directory.is_dir())
                self.assertTrue((directory / 'raw-alpha.png').is_file())

    def test_two_concurrent_identical_revision_processes_share_one_transaction(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            original, image, capture = self.fixture(root)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                job = 'f' * 32
                server.create_mask_review(job, original, image, capture, {}, 'uploads/products/synthetic.png')
                payload = {'job_id': job, 'source_sha256': server.sha256_file(original), 'revision': 0,
                           'actions': [{'kind': 'background', 'tool': 'point', 'radius': 3, 'points': [[45, 55]]}]}
                begin, finish = root / 'begin', root / 'finish'
                workers = []
                for index in range(2):
                    parameters = {name: str(root / (name + str(index) + ('.json' if name == 'result' else '')))
                                  for name in ('ready', 'attempt', 'entered', 'result')}
                    process = self.worker(root, REVISION_WORKER, payload=payload,
                                          begin=str(begin), finish=str(finish), **parameters)
                    workers.append((process, parameters))
                for process, parameters in workers:
                    self.await_marker(Path(parameters['ready']), process)
                begin.touch()
                for process, parameters in workers:
                    self.await_marker(Path(parameters['attempt']), process)
                deadline = time.monotonic() + 15
                while not any(Path(parameters['entered']).exists() for _, parameters in workers):
                    self.assertLess(time.monotonic(), deadline, 'no revision entered its protected operation')
                    time.sleep(0.01)
                finish.touch()
                outcomes = []
                for process, parameters in workers:
                    outcomes.append(self.await_marker(Path(parameters['result']), process))
                    self.finish_worker(process)
                self.assertEqual(sum(outcome['accepted'] for outcome in outcomes), 2, outcomes)
                self.assertEqual([outcome['revision'] for outcome in outcomes if outcome['accepted']], [1, 1])
                self.assertEqual(sum(Path(parameters['entered']).exists() for _, parameters in workers), 1)
                metadata = json.loads((server.REVIEW_ROOT / job / 'metadata.json').read_text())
                self.assertEqual(metadata['revision'], 1)
                self.assertFalse((server.REVIEW_ROOT / job / 'alpha-2.png').exists())

    def test_native_lock_root_rejects_symlink_and_non_directory_locations(self):
        server = self.server()
        self.require_path_locks(server)
        for location in ('symlink', 'file'):
            with self.subTest(location=location), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                outside = root / 'outside'
                outside.mkdir()
                sentinel = outside / 'sentinel'
                sentinel.write_text('keep unchanged')
                lock_root = root / 'native-locks'
                if location == 'symlink':
                    lock_root.symlink_to(outside, target_is_directory=True)
                else:
                    lock_root.write_text('not a directory')
                review_patch, lock_patch = self.configured(server, root)
                with review_patch, lock_patch:
                    entered = False
                    with self.assertRaises(ValueError):
                        with server.private_file_lock(server.REVIEW_ROOT / 'jobs.lock'):
                            entered = True
                    self.assertFalse(entered)
                    self.assertEqual(sentinel.read_text(), 'keep unchanged')
                    self.assertEqual(list(outside.iterdir()), [sentinel])

    def test_native_lock_file_rejects_symlink_without_touching_its_target(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                logical = server.REVIEW_ROOT / 'jobs.lock'
                native = server.private_lock_path(logical)
                with server.private_file_lock(logical) as acquired:
                    self.assertTrue(acquired)
                native.unlink()
                target = root / 'outside-lock-target'
                target.write_text('must remain unchanged')
                native.symlink_to(target)
                entered = False
                with self.assertRaises(ValueError):
                    with server.private_file_lock(logical):
                        entered = True
                self.assertFalse(entered)
                self.assertEqual(target.read_text(), 'must remain unchanged')

    def test_native_namespace_rejects_symlink_without_writing_outside(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                logical = server.REVIEW_ROOT / 'jobs.lock'
                native = server.private_lock_path(logical)
                server.REVIEW_LOCK_ROOT.mkdir(mode=0o700)
                outside = root / 'outside'
                outside.mkdir(mode=0o700)
                native.parent.symlink_to(outside, target_is_directory=True)
                entered = False
                with self.assertRaises(ValueError):
                    with server.private_file_lock(logical):
                        entered = True
                self.assertFalse(entered)
                self.assertEqual(list(outside.iterdir()), [])

    @unittest.skipUnless(os.name == 'posix', 'POSIX native file types and hard links')
    def test_native_lock_leaf_rejects_directory_fifo_and_hardlink(self):
        server = self.server()
        self.require_path_locks(server)
        for leaf_kind in ('directory', 'fifo', 'hardlink'):
            with self.subTest(leaf=leaf_kind), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                review_patch, lock_patch = self.configured(server, root)
                with review_patch, lock_patch:
                    logical = server.REVIEW_ROOT / 'jobs.lock'
                    with server.private_file_lock(logical) as acquired:
                        self.assertTrue(acquired)
                    native = server.private_lock_path(logical)
                    native.unlink()
                    outside = root / 'outside-lock-target'
                    outside.write_text('must remain unchanged')
                    if leaf_kind == 'directory':
                        native.mkdir(mode=0o700)
                    elif leaf_kind == 'fifo':
                        os.mkfifo(native, 0o600)
                    else:
                        os.chmod(outside, 0o600)
                        os.link(outside, native)
                    entered = False
                    with self.assertRaises(ValueError):
                        with server.private_file_lock(logical):
                            entered = True
                    self.assertFalse(entered)
                    self.assertEqual(outside.read_text(), 'must remain unchanged')

    @unittest.skipUnless(os.name == 'posix', 'POSIX private permissions')
    def test_native_lock_directories_and_files_are_private(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                logical = server.REVIEW_ROOT / 'jobs.lock'
                with server.private_file_lock(logical) as acquired:
                    self.assertTrue(acquired)
                    native = server.private_lock_path(logical)
                    for directory in (server.REVIEW_LOCK_ROOT, native.parent):
                        self.assertEqual(directory.stat().st_uid, os.getuid())
                        self.assertEqual(directory.stat().st_mode & 0o777, 0o700)
                    self.assertEqual(native.stat().st_uid, os.getuid())
                    self.assertEqual(native.stat().st_mode & 0o777, 0o600)

    @unittest.skipUnless(os.name == 'posix', 'POSIX open fault injection')
    def test_unwritable_native_lock_root_returns_controlled_failure(self):
        server = self.server()
        self.require_path_locks(server)
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                logical = server.REVIEW_ROOT / 'jobs.lock'
                with server.private_file_lock(logical) as acquired:
                    self.assertTrue(acquired)
                entered = False
                # Fault injection also applies when the test runner has root privileges.
                with patch.object(server.os, 'open', side_effect=PermissionError(errno.EACCES, 'root is not writable')):
                    with self.assertRaises(ValueError):
                        with server.private_file_lock(logical):
                            entered = True
                self.assertFalse(entered)

    @unittest.skipUnless(os.name == 'posix', 'POSIX inode replacement fault injection')
    def test_native_inode_replacement_during_acquisition_fails_closed(self):
        server = self.server()
        self.require_path_locks(server)
        real_flock = server._fcntl.flock
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            review_patch, lock_patch = self.configured(server, root)
            with review_patch, lock_patch:
                logical = server.REVIEW_ROOT / 'jobs.lock'
                with server.private_file_lock(logical) as acquired:
                    self.assertTrue(acquired)
                native = server.private_lock_path(logical)
                original_inode = native.stat().st_ino
                def replace_after_lock(fd, operation):
                    result = real_flock(fd, operation)
                    if operation & server._fcntl.LOCK_EX:
                        native.unlink()
                        native.touch(mode=0o600)
                    return result
                entered = False
                with patch.object(server._fcntl, 'flock', side_effect=replace_after_lock):
                    with self.assertRaises(ValueError):
                        with server.private_file_lock(logical):
                            entered = True
                self.assertFalse(entered)
                self.assertNotEqual(native.stat().st_ino, original_inode)


if __name__ == '__main__':
    unittest.main()
