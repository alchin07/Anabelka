"""Stdlib-only private paths and one experiment lock across both model types."""
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import uuid

from model_manifest import MODELS

PROJECT_ROOT = Path(__file__).resolve().parents[3]
SHARED_ROOTS = (Path("/storage"), Path("/sdcard"), Path("/mnt/sdcard"), Path("/mnt/media_rw"))


def lexical_path(path):
    path = Path(path).expanduser()
    if ".." in path.parts:
        raise ValueError("Path aliases are forbidden.")
    path = path.absolute()
    for root in SHARED_ROOTS:
        if path == root or root in path.parents:
            raise ValueError("Android shared storage is forbidden for experimental artifacts.")
    for part in reversed((path, *path.parents)):
        if part.is_symlink():
            raise ValueError("Artifact symlink paths are forbidden.")
        bare_repository = ((part / "HEAD").is_file() and (part / "config").is_file()
                           and (part / "objects").is_dir() and (part / "refs").is_dir())
        if (part / ".git").exists() or (part / ".git").is_symlink() or bare_repository or part == PROJECT_ROOT:
            raise ValueError("Experimental artifacts must be outside every git repository.")
    return path


def private_directory(path, *, create=False):
    path = lexical_path(path)
    if create and not path.exists():
        private_directory(path.parent)
        path.mkdir(mode=0o700)
    info = path.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise ValueError("Artifact directory must be private (owned, mode 0700).")
    return path


def private_file(path):
    path = lexical_path(path)
    private_directory(path.parent)
    if not path.exists():
        raise ValueError("Model file is missing.")
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1:
        raise ValueError("Artifact must be a regular file with one link.")
    if info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise ValueError("Artifact file must be private (owned, mode 0600).")
    return path


def sha256_file(path):
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def check_model(path, method):
    if method not in MODELS:
        raise ValueError("Unknown model method.")
    path = private_file(path)
    manifest = MODELS[method]
    if path.stat().st_size != manifest["bytes"]:
        raise ValueError("Model size mismatch.")
    actual = sha256_file(path)
    if actual != manifest["sha256"]:
        raise ValueError("Model SHA-256 mismatch.")
    return {"bytes": manifest["bytes"], "sha256": actual}


def new_run_directory(root):
    root = private_directory(root)
    path = root / ("run-" + uuid.uuid4().hex)
    path.mkdir(mode=0o700)
    return private_directory(path)


@contextmanager
def exclusive_private_file(path, *, binary=True):
    path = lexical_path(path)
    private_directory(path.parent)
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, "wb" if binary else "w", **({} if binary else {"encoding": "utf-8"})) as handle:
        yield handle


def write_private_json(path, value):
    with exclusive_private_file(path, binary=False) as handle:
        json.dump(value, handle, ensure_ascii=False, indent=2, allow_nan=False)
        handle.write("\n")


@contextmanager
def experiment_lock():
    """All launchers use the normal unchanged HOME; that UID is trusted."""
    if not sys.platform.startswith("linux"):
        raise ValueError("Safe experiment supervision supports Linux/Termux only.")
    import fcntl
    home = lexical_path(Path.home())
    root = home / ".anabelka-image-experiments"
    if not root.exists():
        root.mkdir(mode=0o700)
    private_directory(root)
    path = root / "experiment.lock"
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    acquired = False
    try:
        private_file(path)
        info = os.fstat(fd)
        current = path.lstat()
        if (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino):
            raise ValueError("Experiment lock inode changed.")
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            acquired = True
        except BlockingIOError as error:
            raise ValueError("An image experiment is already running.") from error
        current = path.lstat()
        if (info.st_dev, info.st_ino) != (current.st_dev, current.st_ino) or current.st_nlink != 1:
            raise ValueError("Experiment lock inode changed.")
        yield
    finally:
        if acquired:
            fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)
