#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import sys
import time
import uuid
from contextlib import contextmanager
from functools import wraps
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import PIL
from PIL import Image, ImageFilter, ImageOps, UnidentifiedImageError

from mp_persondet import MPPersonDet


HOST = "127.0.0.1"
VERSION = "0.11"
PROFILE = "model-normalize-v8"
PORT = int(os.environ.get("ANABELKA_IMAGE_PROCESSOR_PORT", "8765"))
MAX_JSON_BYTES = 64 * 1024
MAX_SOURCE_BYTES = 40 * 1024 * 1024

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SOURCE_ROOT = (PROJECT_ROOT / "uploads" / "products").resolve()
WORK_ROOT = (PROJECT_ROOT / "storage" / "image-processor").resolve()
ORIGINAL_ROOT = WORK_ROOT / "originals"
PROCESSED_ROOT = (SOURCE_ROOT / "processed").resolve()

MASTER_SIZE = (1200, 1800)
THUMB_SIZE = (320, 480)
CANVAS_BACKGROUND = (250, 250, 250)
BACKGROUND_PROFILE_ORIGINAL = "original-canvas"
BACKGROUND_PROFILE_STUDIO = "studio-light"
BACKGROUND_PROFILE_BRAND = "anabelka-brand"
BACKGROUND_PROFILES = (
    BACKGROUND_PROFILE_ORIGINAL,
    BACKGROUND_PROFILE_STUDIO,
    BACKGROUND_PROFILE_BRAND,
)
MASK_MODES = ("auto", "grabcut", "modnet")
DEFAULT_BACKGROUND_PROFILE = BACKGROUND_PROFILE_ORIGINAL
SUBJECT_MASK_MAX_EDGE = 1200
SUBJECT_MASK_MIN_RATIO = 0.02
SUBJECT_MASK_MAX_RATIO = 0.90
PERSON_DETECT_MAX_EDGE = 900
FACE_DETECT_MAX_EDGE = 1000
FACE_MIN_AREA_RATIO = 0.0015
FACE_CASCADE_FILENAME = "haarcascade_frontalface_default.xml"
PERSON_MIN_AREA_RATIO = 0.06
PERSON_MAX_CROP_AREA_RATIO = 0.92
PERSON_SIDE_MARGIN = 0.18
PERSON_TOP_MARGIN = 0.08
PERSON_BOTTOM_MARGIN = 0.10
TORSO_TARGET_RATIO = 0.31
TORSO_TRIGGER_RATIO = 0.285
TORSO_ZOOM_OUT_TARGET_RATIO = 0.40
TORSO_ZOOM_OUT_TRIGGER_RATIO = 0.46
TORSO_ZOOM_OUT_MIN_SCALE = 0.75
TORSO_MIN_RETAINED_HEIGHT_RATIO = 0.72
TORSO_SHOULDER_Y_RATIO = 0.28
TORSO_LOWER_MARGIN_RATIO = 1.35
TORSO_UPPER_RADIUS_MARGIN = 1.08
TORSO_SIDE_RADIUS_MARGIN = 1.12

PERSON_MODEL_PATH = (
    WORK_ROOT
    / "models"
    / "person_detection_mediapipe_2023mar.onnx"
)
PERSON_MODEL_BYTES = 11990159
PERSON_MODEL_SHA256 = (
    "47fd5599d6fa17608f03e0eb0ae230baa6e597d7e8a2c8199fe00abea55a701f"
)
PERSON_MODEL_SCORE_THRESHOLD = 0.45

MODNET_WORKER_PATH = (
    Path(__file__).resolve().parent
    / "modnet_worker.py"
)
MODNET_MODEL_PATH = (
    WORK_ROOT
    / "models"
    / "modnet_photographic.onnx"
)
MODNET_MODEL_BYTES = 25969398
MODNET_WORKER_TIMEOUT = 45
MODNET_WORKER_ROOT = (
    WORK_ROOT
    / "modnet-worker"
)

_person_detector = None
_person_detector_error = ""

_modnet_worker_error = ""
_BACKGROUND_MASK_UNSET = object()


class MaskProcessingError(RuntimeError):
    """A requested mask failed; retain diagnostics after job cleanup."""

    def __init__(self, reason: str, normalization: dict[str, Any]):
        super().__init__("Не вдалося застосувати маску фотографії: " + reason)
        self.reason = reason
        self.normalization = normalization


@contextmanager
def timed_stage(timings: dict[str, Any], name: str):
    """Record inclusive wall time, including failures, in one request's dict."""
    started = time.perf_counter()
    try:
        yield
    finally:
        elapsed = (time.perf_counter() - started) * 1000
        timings[name] = round(timings.get(name, 0.0) + elapsed, 3)


def timed_function(function):
    """Give an instrumented function a private collector and a total timer."""
    @wraps(function)
    def measured(*args, timings=None, **kwargs):
        collector = {} if timings is None else timings
        with timed_stage(collector, "total"):
            return function(*args, timings=collector, **kwargs)
    measured.accepts_stage_timings = True
    return measured


def measured_call(timings, name, function, *args, detail=None, **kwargs):
    # Older integrations replace these functions with two/three-argument
    # callables. Only our explicitly instrumented functions receive a kwarg.
    if detail is not None and getattr(function, "accepts_stage_timings", False) is True:
        kwargs["timings"] = detail
    with timed_stage(timings, name):
        return function(*args, **kwargs)


def json_bytes(payload: dict[str, Any]) -> bytes:
    return json.dumps(
        payload,
        ensure_ascii=False,
        separators=(",", ":"),
    ).encode("utf-8")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()

    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)

    return digest.hexdigest()


def project_relative(path: Path) -> str:
    return path.resolve().relative_to(PROJECT_ROOT).as_posix()


def safe_source_path(value: Any) -> Path:
    raw = str(value or "").strip().replace("\\", "/")

    if raw.startswith("/Anabelka/"):
        raw = raw[len("/Anabelka/"):]

    raw = raw.lstrip("/")
    relative = Path(raw)

    if (
        not raw
        or relative.is_absolute()
        or ".." in relative.parts
        or len(relative.parts) != 3
        or relative.parts[0] != "uploads"
        or relative.parts[1] != "products"
    ):
        raise ValueError("Некоректний шлях до фотографії товару.")

    candidate = (PROJECT_ROOT / relative).resolve()

    if not candidate.is_relative_to(SOURCE_ROOT):
        raise ValueError("Фотографія знаходиться поза дозволеною папкою.")

    if not candidate.is_file():
        raise ValueError("Файл фотографії товару не знайдено.")

    if candidate.stat().st_size > MAX_SOURCE_BYTES:
        raise ValueError("Фотографія перевищує допустимий розмір.")

    return candidate


@timed_function
def normalized_image(source: Path, *, timings: dict[str, Any]) -> Image.Image:
    probe = measured_call(timings, "opencv_probe", cv2.imread, str(source), cv2.IMREAD_UNCHANGED)

    if probe is None:
        raise ValueError("OpenCV не зміг прочитати фотографію.")

    with timed_stage(timings, "pillow_decode"):
        with Image.open(source) as opened:
            image = ImageOps.exif_transpose(opened)

            if image.mode in ("RGBA", "LA"):
                return image.convert("RGBA")

            if image.mode == "P" and "transparency" in image.info:
                return image.convert("RGBA")

            return image.convert("RGB")


def flattened_rgb(image: Image.Image) -> Image.Image:
    if image.mode == "RGBA":
        flattened = Image.new(
            "RGB",
            image.size,
            CANVAS_BACKGROUND,
        )
        flattened.paste(
            image,
            (0, 0),
            image,
        )
        return flattened

    return image.convert("RGB")



def modnet_worker_ready() -> bool:
    try:
        return (
            MODNET_WORKER_PATH.is_file()
            and MODNET_MODEL_PATH.is_file()
            and MODNET_MODEL_PATH.stat().st_size
            == MODNET_MODEL_BYTES
        )
    except OSError:
        return False


@timed_function
def run_modnet_worker(
    source: Path,
    image: Image.Image,
    *,
    diagnostics: dict[str, Any] | None = None,
    timings: dict[str, Any],
) -> tuple[
    Image.Image,
    float,
    dict[str, Any],
] | None:
    if diagnostics is not None:
        diagnostics.clear()
        diagnostics.update(status="running", worker_error="", timings_ms=timings)

    def record_error(message: str) -> None:
        # Keep the legacy health field, but give each request its own result.
        global _modnet_worker_error
        _modnet_worker_error = message
        if diagnostics is not None:
            diagnostics.update(
                status="failed" if message else "succeeded",
                worker_error=message,
            )

    if not source.is_file():
        record_error(
            "source-missing"
        )
        return None

    if not modnet_worker_ready():
        record_error(
            "worker-not-ready"
        )
        return None

    try:
        MODNET_WORKER_ROOT.mkdir(parents=True, exist_ok=True)
    except OSError as error:
        record_error((type(error).__name__ + ":" + str(error))[:200])
        return None

    alpha_path = (
        MODNET_WORKER_ROOT
        / (
            uuid.uuid4().hex
            + ".png"
        )
    )

    command = [
        sys.executable,
        "-B",
        str(
            MODNET_WORKER_PATH
        ),
        "--source",
        str(source),
        "--model",
        str(
            MODNET_MODEL_PATH
        ),
        "--alpha",
        str(alpha_path),
    ]

    try:
        completed = measured_call(timings, "subprocess", subprocess.run,
            command,
            cwd=str(
                PROJECT_ROOT
            ),
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=(
                MODNET_WORKER_TIMEOUT
            ),
            check=False,
        )

        lines = [
            line.strip()
            for line
            in completed.stdout.splitlines()
            if line.strip()
        ]

        if not lines:
            record_error(
                "worker-no-json"
            )
            return None

        try:
            payload = json.loads(
                lines[-1]
            )
        except json.JSONDecodeError:
            record_error(
                "worker-invalid-json"
            )
            return None

        if (
            completed.returncode
            != 0
            or payload.get(
                "ok"
            )
            is not True
        ):
            message = str(
                payload.get(
                    "error"
                )
                or completed.stderr
                or "worker-failed"
            ).strip()

            record_error(
                "worker-error:"
                + message[:180]
            )
            return None

        try:
            foreground_ratio = float(
                payload[
                    "foreground_ratio"
                ]
            )
        except (
            KeyError,
            TypeError,
            ValueError,
        ):
            record_error(
                "worker-invalid-ratio"
            )
            return None

        if (
            foreground_ratio
            < SUBJECT_MASK_MIN_RATIO
            or foreground_ratio
            > SUBJECT_MASK_MAX_RATIO
        ):
            record_error(
                "worker-ratio-out-of-range"
            )
            return None

        if not alpha_path.is_file():
            record_error(
                "worker-alpha-missing"
            )
            return None

        with timed_stage(timings, "alpha_decode"):
            with Image.open(alpha_path) as opened:
                alpha = opened.convert("L").copy()

        if (
            alpha.size
            != image.size
        ):
            record_error(
                "worker-alpha-size-mismatch"
            )
            return None

        with timed_stage(timings, "subject_rgb"):
            subject = flattened_rgb(image).convert("RGBA")

        subject.putalpha(
            alpha
        )

        metadata = {
            "inference_ms":
                payload.get(
                    "inference_ms"
                ),
            "onnxruntime":
                payload.get(
                    "onnxruntime"
                ),
            "provider":
                payload.get(
                    "provider"
                ),
        }

        record_error("")
        if diagnostics is not None:
            diagnostics.update(metadata)
            # Worker metadata is optional; a valid alpha must not make the
            # request-local JSON contain NaN or Infinity timing values.
            try:
                inference_ms = float(metadata["inference_ms"])
            except (TypeError, ValueError, OverflowError):
                inference_ms = None
            diagnostics["inference_ms"] = (
                inference_ms if inference_ms is not None and np.isfinite(inference_ms) else None
            )

        return (
            subject,
            foreground_ratio,
            metadata,
        )

    except subprocess.TimeoutExpired:
        record_error(
            "worker-timeout"
        )
        return None

    except OSError as error:
        record_error((type(error).__name__ + ":" + str(error))[:200])
        return None

    finally:
        try:
            alpha_path.unlink(
                missing_ok=True
            )
        except OSError:
            pass


def person_model_ready() -> bool:
    try:
        return (
            PERSON_MODEL_PATH.is_file()
            and PERSON_MODEL_PATH.stat().st_size == PERSON_MODEL_BYTES
        )
    except OSError:
        return False


def get_person_detector() -> MPPersonDet | None:
    global _person_detector
    global _person_detector_error

    if _person_detector is not None:
        return _person_detector

    if not person_model_ready():
        _person_detector_error = "model-missing"
        return None

    try:
        if sha256_file(PERSON_MODEL_PATH) != PERSON_MODEL_SHA256:
            _person_detector_error = "model-sha256-mismatch"
            return None

        _person_detector = MPPersonDet(
            str(PERSON_MODEL_PATH),
            scoreThreshold=PERSON_MODEL_SCORE_THRESHOLD,
            nmsThreshold=0.3,
            topK=1000,
            backendId=cv2.dnn.DNN_BACKEND_OPENCV,
            targetId=cv2.dnn.DNN_TARGET_CPU,
        )
        _person_detector_error = ""
        return _person_detector
    except Exception as error:
        _person_detector_error = (
            type(error).__name__
            + ":"
            + str(error)
        )[:240]
        return None


def detect_mediapipe_person_bbox(
    image: Image.Image,
) -> tuple[
    tuple[int, int, int, int],
    float,
    tuple[float, float],
    tuple[float, float],
    tuple[float, float],
    float,
    float,
] | None:
    detector = get_person_detector()

    if detector is None:
        return None

    rgb = flattened_rgb(image)
    frame = cv2.cvtColor(
        np.asarray(rgb),
        cv2.COLOR_RGB2BGR,
    )

    try:
        results = detector.infer(frame)
    except Exception as error:
        global _person_detector_error
        _person_detector_error = (
            type(error).__name__
            + ":"
            + str(error)
        )[:240]
        return None

    if results is None or len(results) == 0:
        return None

    best = max(
        results,
        key=lambda result: float(result[-1]),
    )
    score = float(best[-1])
    landmarks = np.asarray(
        best[4:-1],
        dtype=np.float64,
    ).reshape(4, 2)

    hip_center = landmarks[0]
    full_body_point = landmarks[1]
    radius = float(
        np.linalg.norm(
            hip_center - full_body_point
        )
    )

    if not np.isfinite(radius) or radius <= 1.0:
        return None

    source_width, source_height = rgb.size
    left = max(
        0,
        int(round(hip_center[0] - radius)),
    )
    top = max(
        0,
        int(round(hip_center[1] - radius)),
    )
    right = min(
        source_width,
        int(round(hip_center[0] + radius)),
    )
    bottom = min(
        source_height,
        int(round(hip_center[1] + radius)),
    )

    if right <= left or bottom <= top:
        return None

    shoulder_center = landmarks[2]
    upper_body_point = landmarks[3]
    torso_length = float(
        np.linalg.norm(
            shoulder_center - hip_center
        )
    )
    upper_radius = float(
        np.linalg.norm(
            shoulder_center - upper_body_point
        )
    )

    if (
        not np.isfinite(torso_length)
        or torso_length <= 1.0
        or not np.isfinite(upper_radius)
        or upper_radius <= 1.0
    ):
        return None

    torso_center = (
        float((hip_center[0] + shoulder_center[0]) / 2.0),
        float((hip_center[1] + shoulder_center[1]) / 2.0),
    )

    return (
        (
            left,
            top,
            right - left,
            bottom - top,
        ),
        score,
        torso_center,
        (float(shoulder_center[0]), float(shoulder_center[1])),
        (float(hip_center[0]), float(hip_center[1])),
        torso_length,
        upper_radius,
    )


def mediapipe_torso_crop_box(
    image: Image.Image,
    shoulder_center: tuple[float, float],
    hip_center: tuple[float, float],
    torso_length: float,
    upper_radius: float,
) -> tuple[
    tuple[int, int, int, int],
    float,
] | None:
    source_width, source_height = image.size

    if (
        source_width <= 0
        or source_height <= 0
        or not np.isfinite(torso_length)
        or torso_length <= 1.0
    ):
        return None

    ratio_before = torso_length / source_height

    if ratio_before >= TORSO_TRIGGER_RATIO:
        return None

    desired_height = round(
        torso_length / TORSO_TARGET_RATIO
    )
    minimum_height = round(
        source_height * TORSO_MIN_RETAINED_HEIGHT_RATIO
    )
    crop_height = max(
        desired_height,
        minimum_height,
    )
    crop_height = min(
        crop_height,
        source_height,
    )
    crop_width = round(
        crop_height
        * MASTER_SIZE[0]
        / MASTER_SIZE[1]
    )

    if (
        crop_width <= 0
        or crop_height <= 0
        or crop_width > source_width
        or crop_height >= source_height
    ):
        return None

    shoulder_x, shoulder_y = shoulder_center
    hip_x, hip_y = hip_center

    safe_top = max(
        0.0,
        shoulder_y
        - upper_radius * TORSO_UPPER_RADIUS_MARGIN,
    )
    safe_bottom = min(
        float(source_height),
        hip_y
        + torso_length * TORSO_LOWER_MARGIN_RATIO,
    )

    if safe_bottom - safe_top > crop_height:
        return None

    requested_top = (
        shoulder_y
        - crop_height * TORSO_SHOULDER_Y_RATIO
    )
    top_min = max(
        0.0,
        safe_bottom - crop_height,
    )
    top_max = min(
        float(source_height - crop_height),
        safe_top,
    )

    if top_min > top_max:
        return None

    crop_top = round(
        min(
            max(requested_top, top_min),
            top_max,
        )
    )
    crop_bottom = crop_top + crop_height

    side_radius = max(
        upper_radius * TORSO_SIDE_RADIUS_MARGIN,
        torso_length * 0.72,
    )
    safe_left = max(
        0.0,
        min(shoulder_x, hip_x) - side_radius,
    )
    safe_right = min(
        float(source_width),
        max(shoulder_x, hip_x) + side_radius,
    )

    if safe_right - safe_left > crop_width:
        return None

    torso_center_x = (
        shoulder_x + hip_x
    ) / 2.0
    requested_left = torso_center_x - crop_width / 2
    left_min = max(
        0.0,
        safe_right - crop_width,
    )
    left_max = min(
        float(source_width - crop_width),
        safe_left,
    )

    if left_min > left_max:
        return None

    crop_left = round(
        min(
            max(requested_left, left_min),
            left_max,
        )
    )
    crop_right = crop_left + crop_width

    return (
        (
            crop_left,
            crop_top,
            crop_right,
            crop_bottom,
        ),
        ratio_before,
    )


def estimated_canvas_background(
    image: Image.Image,
) -> tuple[int, int, int]:
    probe = flattened_rgb(image).copy()
    probe.thumbnail(
        (256, 256),
        Image.Resampling.BILINEAR,
    )
    pixels = np.asarray(probe)

    if (
        pixels.ndim != 3
        or pixels.shape[0] <= 0
        or pixels.shape[1] <= 0
        or pixels.shape[2] < 3
    ):
        return CANVAS_BACKGROUND

    height, width = pixels.shape[:2]
    band_y = max(1, round(height * 0.08))
    band_x = max(1, round(width * 0.08))
    border = np.concatenate(
        [
            pixels[:band_y, :, :3].reshape(-1, 3),
            pixels[-band_y:, :, :3].reshape(-1, 3),
            pixels[:, :band_x, :3].reshape(-1, 3),
            pixels[:, -band_x:, :3].reshape(-1, 3),
        ],
        axis=0,
    )

    if border.size == 0:
        return CANVAS_BACKGROUND

    median = np.median(border, axis=0)

    return tuple(
        int(round(float(channel)))
        for channel in median[:3]
    )


def mediapipe_torso_zoom_out_canvas(
    image: Image.Image,
    torso_center: tuple[float, float],
    shoulder_center: tuple[float, float],
    torso_length: float,
) -> tuple[
    Image.Image,
    float,
    float,
    float,
] | None:
    source_width, source_height = image.size
    target_width, target_height = MASTER_SIZE

    if (
        source_width <= 0
        or source_height <= 0
        or not np.isfinite(torso_length)
        or torso_length <= 1.0
        or not np.isfinite(torso_center[0])
        or not np.isfinite(shoulder_center[1])
    ):
        return None

    standard_scale = min(
        target_width / source_width,
        target_height / source_height,
    )
    ratio_before = (
        torso_length
        * standard_scale
        / target_height
    )

    if ratio_before <= TORSO_ZOOM_OUT_TRIGGER_RATIO:
        return None

    zoom_scale = min(
        1.0,
        TORSO_ZOOM_OUT_TARGET_RATIO / ratio_before,
    )
    zoom_scale = max(
        TORSO_ZOOM_OUT_MIN_SCALE,
        zoom_scale,
    )
    final_scale = standard_scale * zoom_scale
    resized_width = max(
        1,
        round(source_width * final_scale),
    )
    resized_height = max(
        1,
        round(source_height * final_scale),
    )

    if (
        resized_width > target_width
        or resized_height > target_height
    ):
        return None

    rgb = flattened_rgb(image)
    resized = rgb.resize(
        (resized_width, resized_height),
        Image.Resampling.LANCZOS,
    )
    canvas = Image.new(
        "RGB",
        MASTER_SIZE,
        estimated_canvas_background(rgb),
    )

    requested_left = (
        target_width / 2
        - torso_center[0] * final_scale
    )
    requested_top = (
        target_height * TORSO_SHOULDER_Y_RATIO
        - shoulder_center[1] * final_scale
    )
    left = min(
        max(0, round(requested_left)),
        target_width - resized_width,
    )
    top = min(
        max(0, round(requested_top)),
        target_height - resized_height,
    )
    canvas.paste(
        resized,
        (left, top),
    )

    ratio_after = ratio_before * zoom_scale

    return (
        canvas,
        ratio_before,
        ratio_after,
        zoom_scale,
    )


def mediapipe_aspect_fill_crop_box(
    image: Image.Image,
    torso_center: tuple[float, float],
) -> tuple[int, int, int, int] | None:
    source_width, source_height = image.size

    if source_width <= 0 or source_height <= 0:
        return None

    target_ratio = MASTER_SIZE[0] / MASTER_SIZE[1]
    source_ratio = source_width / source_height

    # For the first conservative v0.5 correction, only remove
    # moderate horizontal surplus. This avoids cutting head/legs while
    # eliminating letterbox bars on common supplier portraits.
    if source_ratio <= target_ratio * 1.01:
        return None

    crop_width = round(source_height * target_ratio)
    crop_height = source_height

    if crop_width <= 0 or crop_width >= source_width:
        return None

    retained_area_ratio = crop_width / source_width

    if retained_area_ratio < 0.80:
        return None

    center_x = float(torso_center[0])

    if not np.isfinite(center_x):
        center_x = source_width / 2

    crop_left = round(center_x - crop_width / 2)
    crop_left = min(
        max(0, crop_left),
        source_width - crop_width,
    )
    crop_right = crop_left + crop_width

    return (
        crop_left,
        0,
        crop_right,
        crop_height,
    )


def normalize_background_profile(value: Any) -> str:
    profile = str(value or DEFAULT_BACKGROUND_PROFILE).strip().lower()

    if profile not in BACKGROUND_PROFILES:
        raise ValueError("Невідомий профіль фону фотографії.")

    return profile


def normalize_mask_mode(value: Any) -> str:
    mode = "auto" if value is None else str(value).strip().lower()
    if mode not in MASK_MODES:
        raise ValueError("Невідомий метод маски фотографії.")
    return mode


def gradient_background(
    size: tuple[int, int],
    top_color: tuple[int, int, int],
    bottom_color: tuple[int, int, int],
) -> Image.Image:
    width, height = size

    if width <= 0 or height <= 0:
        raise ValueError("Некоректний розмір фону.")

    if height == 1:
        colors = [top_color]
    else:
        colors = []

        for y in range(height):
            ratio = y / (height - 1)
            colors.append(
                tuple(
                    round(
                        top_color[channel]
                        + (
                            bottom_color[channel]
                            - top_color[channel]
                        )
                        * ratio
                    )
                    for channel in range(3)
                )
            )

    strip = Image.new("RGB", (1, height))
    strip.putdata(colors)

    return strip.resize(
        (width, height),
        Image.Resampling.BILINEAR,
    )


def background_profile_canvas(
    size: tuple[int, int],
    profile: str,
) -> Image.Image:
    if profile == BACKGROUND_PROFILE_STUDIO:
        return gradient_background(
            size,
            (252, 251, 249),
            (244, 241, 246),
        )

    if profile == BACKGROUND_PROFILE_BRAND:
        return gradient_background(
            size,
            (252, 249, 255),
            (239, 228, 249),
        )

    return Image.new(
        "RGB",
        size,
        CANVAS_BACKGROUND,
    )


def uniform_border_background_mask(
    image: Image.Image,
) -> np.ndarray | None:
    rgb = image if image.mode == "RGB" else image.convert("RGB")
    width, height = rgb.size

    if (
        height <= 4
        or width <= 4
    ):
        return None

    band_y = max(1, round(height * 0.035))
    band_x = max(1, round(width * 0.035))
    border = np.concatenate(
        [
            np.asarray(rgb.crop((0, 0, width, band_y))).reshape(-1, 3),
            np.asarray(rgb.crop((0, height - band_y, width, height))).reshape(-1, 3),
            np.asarray(rgb.crop((0, 0, band_x, height))).reshape(-1, 3),
            np.asarray(rgb.crop((width - band_x, 0, width, height))).reshape(-1, 3),
        ],
        axis=0,
    )

    if border.size == 0:
        return None

    background_color = np.median(
        border,
        axis=0,
    ).astype(np.float32)
    tile_edge = 256
    tile_pixels = tile_edge * tile_edge
    border_distance = np.empty(len(border), dtype=np.float32)

    for start in range(0, len(border), tile_pixels):
        end = start + tile_pixels
        border_distance[start:end] = np.linalg.norm(
            border[start:end].astype(np.float32) - background_color,
            axis=1,
        )
    border_spread = float(
        np.median(border_distance)
    )

    # Apply colour cleanup only when the supplier background
    # is genuinely close to a flat studio colour.
    if (
        not np.isfinite(border_spread)
        or border_spread > 12.0
    ):
        return None

    threshold = max(
        12.0,
        min(
            22.0,
            12.0 + border_spread * 1.5,
        ),
    )
    # Similar brightness does not make pale skin or blonde hair background.
    # Channel differences retain colour information under lighting changes.
    border_chroma = np.column_stack(
        (
            border[:, 0].astype(np.int16) - border[:, 1],
            border[:, 1].astype(np.int16) - border[:, 2],
        )
    )
    background_chroma = np.median(border_chroma, axis=0)

    for start in range(0, len(border), tile_pixels):
        end = start + tile_pixels
        border_distance[start:end] = np.linalg.norm(
            border_chroma[start:end].astype(np.float32)
            - background_chroma,
            axis=1,
        )

    chroma_threshold = max(
        4.0,
        min(8.0, 4.0 + float(np.median(border_distance)) * 1.5),
    )
    # Keep float buffers bounded even when cleaning a source-size alpha.
    candidate_background = np.ones((height + 2, width + 2), dtype=np.uint8)
    candidates = candidate_background[1:-1, 1:-1]

    for top in range(0, height, tile_edge):
        bottom = min(height, top + tile_edge)

        for left in range(0, width, tile_edge):
            right = min(width, left + tile_edge)
            tile = np.asarray(
                rgb.crop((left, top, right, bottom)),
                dtype=np.float32,
            )
            distance = np.linalg.norm(
                tile - background_color,
                axis=2,
            )
            chroma_distance = np.hypot(
                tile[:, :, 0] - tile[:, :, 1] - background_chroma[0],
                tile[:, :, 1] - tile[:, :, 2] - background_chroma[1],
            )
            candidates[top:bottom, left:right] = (
                (distance <= threshold)
                & (chroma_distance <= chroma_threshold)
            )

    # The added border joins all four image edges for one 8-connected
    # flood fill. All enclosed colour matches remain ambiguous.
    cv2.floodFill(candidate_background, None, (0, 0), 2, flags=8)
    # Do not classify enclosed garment details as gaps by shape alone.
    return candidates == 2


def suppress_uniform_border_background(
    image: Image.Image,
    foreground: np.ndarray,
    *,
    background_mask: np.ndarray | None | object = _BACKGROUND_MASK_UNSET,
) -> np.ndarray:
    height, width = foreground.shape[:2]

    if image.size != (width, height):
        return foreground

    border_connected_background = (
        uniform_border_background_mask(image)
        if background_mask is _BACKGROUND_MASK_UNSET
        else background_mask
    )

    if border_connected_background is None:
        return foreground

    # Colour similarity may also match real subject pixels, especially
    # blonde hair against a warm/light supplier background. Protect narrow
    # matching details; clean their outer shell and broad background spills.
    foreground_distance = cv2.distanceTransform(
        (foreground >= 128).astype(np.uint8),
        cv2.DIST_L2,
        3,
    )
    edge_cleanup_band = foreground_distance <= 1.25

    # Narrow background-coloured details can be real subject pixels
    # (for example blonde hair). Large, wide regions are much more
    # likely to be supplier-background spill captured by GrabCut.
    suspect_foreground = (
        border_connected_background
        & (foreground > 0)
    ).astype(np.uint8)

    suspect_distance = cv2.distanceTransform(
        suspect_foreground,
        cv2.DIST_L2,
        3,
    )
    # Remove a wide spill together with its narrow attached tails.
    # A fixed-radius dilation left those tails beside arms and fingers.
    # Separate narrow details without a thick core remain protected.
    count, labels = cv2.connectedComponents(suspect_foreground, connectivity=8)
    thick_labels = np.zeros(count, dtype=bool)
    thick_labels[labels[suspect_distance >= 3.0]] = True
    thick_labels[0] = False
    thick_background = thick_labels[labels]

    cleaned = foreground.copy()
    cleaned[
        (cleaned > 0)
        & border_connected_background
        & (edge_cleanup_band | thick_background)
    ] = 0

    return cleaned


suppress_uniform_border_background.accepts_background_mask = True


@timed_function
def cosmetic_cleanup_subject_fringes(
    image: Image.Image,
    subject: Image.Image,
    bbox: tuple[int, int, int, int],
    *,
    timings: dict[str, Any],
) -> Image.Image:
    if (
        subject.mode != "RGBA"
        or subject.size != image.size
    ):
        return subject

    background_like = measured_call(timings, "border_analysis", uniform_border_background_mask, image)

    if background_like is None:
        return subject

    alpha = np.asarray(
        subject.getchannel("A"),
    ).copy()
    height, width = alpha.shape[:2]
    x, y, bbox_width, bbox_height = bbox

    if bbox_width <= 0 or bbox_height <= 0:
        return subject

    left = max(0, int(x))
    right = min(width, int(x + bbox_width))
    top = max(
        0,
        round(y + bbox_height * 0.34),
    )
    bottom = min(
        height,
        round(y + bbox_height * 0.64),
    )

    if (
        right <= left
        or bottom <= top
    ):
        return subject

    # Cosmetic pass for catalogue backgrounds:
    # find background-coloured foreground fragments only in the
    # shoulder/chest/waist band. This is deliberately separate from
    # GrabCut so the primary segmentation remains conservative.
    suspect = (
        background_like
        & (alpha > 0)
    ).astype(np.uint8)

    # Label whole components before limiting the cosmetic zone.
    count, labels, stats, _ = cv2.connectedComponentsWithStats(
        suspect,
        connectivity=8,
    )

    if count <= 1:
        return subject

    bbox_area = max(1, bbox_width * bbox_height)
    min_area = max(
        10,
        round(bbox_area * 0.00008),
    )
    max_area = max(
        160,
        round(bbox_area * 0.004),
    )
    min_long_side = max(
        8,
        round(max(bbox_width, bbox_height) * 0.025),
    )
    max_short_side = max(
        6,
        round(min(bbox_width, bbox_height) * 0.04),
    )
    bbox_center_x = x + bbox_width / 2

    remove_mask = np.zeros_like(suspect)

    for label in range(1, count):
        area = int(
            stats[label, cv2.CC_STAT_AREA]
        )
        component_left = int(
            stats[label, cv2.CC_STAT_LEFT]
        )
        component_width = int(
            stats[label, cv2.CC_STAT_WIDTH]
        )
        component_height = int(
            stats[label, cv2.CC_STAT_HEIGHT]
        )
        long_side = max(
            component_width,
            component_height,
        )
        short_side = min(
            component_width,
            component_height,
        )
        component_center_x = (
            component_left
            + component_width / 2
        )

        component_top = int(stats[label, cv2.CC_STAT_TOP])
        # Keep components touching/crossing any boundary of the zone.
        if (
            component_left <= left
            or component_top <= top
            or component_left + component_width >= right
            or component_top + component_height >= bottom
        ):
            continue

        # Keep central garment details. A cosmetic fringe should live
        # toward either side of the detected body and be narrow/elongated.
        if (
            area < min_area
            or area > max_area
            or long_side < min_long_side
            or short_side > max_short_side
            or long_side < short_side * 1.8
            or abs(component_center_x - bbox_center_x)
            < bbox_width * 0.12
        ):
            continue

        remove_mask[labels == label] = 1

    if not np.any(remove_mask):
        return subject

    # Remove selected pixels only; never expand into neighbouring skin/fabric.
    cleaned_alpha = alpha.copy()
    cleaned_alpha[
        (remove_mask > 0)
        & background_like
        & (alpha > 0)
    ] = 0

    cleaned = subject.copy()
    cleaned.putalpha(
        Image.fromarray(
            cleaned_alpha,
            mode="L",
        )
    )

    return cleaned



@timed_function
def refine_upper_enclosed_background_gaps(
    image: Image.Image,
    subject: Image.Image,
    bbox: tuple[int, int, int, int],
    *,
    timings: dict[str, Any],
) -> Image.Image:
    """Remove only small validated upper-body enclosed background gaps."""
    timings.update(candidate_components=0, local_inference_calls=0,
                   local_inference_ms=[], local_inference=0.0)
    colour_started = time.perf_counter()
    if (
        subject.mode != "RGBA"
        or subject.size != image.size
    ):
        return subject

    rgb = image if image.mode == "RGB" else image.convert("RGB")
    width, height = rgb.size

    if width <= 8 or height <= 8:
        return subject

    alpha = np.asarray(
        subject.getchannel("A")
    ).copy()

    x, y, bbox_width, bbox_height = bbox

    if bbox_width <= 0 or bbox_height <= 0:
        return subject

    pixels = np.asarray(rgb)

    band_y = max(1, round(height * 0.035))
    band_x = max(1, round(width * 0.035))

    border = np.concatenate(
        (
            pixels[:band_y, :, :3].reshape(-1, 3),
            pixels[-band_y:, :, :3].reshape(-1, 3),
            pixels[:, :band_x, :3].reshape(-1, 3),
            pixels[:, -band_x:, :3].reshape(-1, 3),
        ),
        axis=0,
    )

    if border.size == 0:
        return subject

    background_color = np.median(
        border,
        axis=0,
    ).astype(np.float32)

    border_distance = np.linalg.norm(
        border.astype(np.float32) - background_color,
        axis=1,
    )
    border_spread = float(
        np.median(border_distance)
    )

    # Keep the same conservative studio-background requirement
    # used by the normal colour cleanup.
    if (
        not np.isfinite(border_spread)
        or border_spread > 12.0
    ):
        return subject

    threshold = max(
        12.0,
        min(
            22.0,
            12.0 + border_spread * 1.5,
        ),
    )

    border_chroma = np.column_stack(
        (
            border[:, 0].astype(np.int16) - border[:, 1],
            border[:, 1].astype(np.int16) - border[:, 2],
        )
    )
    background_chroma = np.median(
        border_chroma,
        axis=0,
    )

    chroma_spread = np.linalg.norm(
        border_chroma.astype(np.float32)
        - background_chroma,
        axis=1,
    )
    chroma_threshold = max(
        4.0,
        min(
            8.0,
            4.0
            + float(np.median(chroma_spread)) * 1.5,
        ),
    )

    # Unlike uniform_border_background_mask(), keep ALL colour
    # candidates here. The enclosed ones are exactly what we need
    # to validate locally.
    candidates = np.zeros(
        (height, width),
        dtype=np.uint8,
    )

    tile_edge = 256

    for top in range(0, height, tile_edge):
        bottom = min(
            height,
            top + tile_edge,
        )

        for left in range(0, width, tile_edge):
            right = min(
                width,
                left + tile_edge,
            )

            tile = np.asarray(
                rgb.crop(
                    (
                        left,
                        top,
                        right,
                        bottom,
                    )
                ),
                dtype=np.float32,
            )

            distance = np.linalg.norm(
                tile - background_color,
                axis=2,
            )

            chroma_distance = np.hypot(
                tile[:, :, 0]
                - tile[:, :, 1]
                - background_chroma[0],
                tile[:, :, 1]
                - tile[:, :, 2]
                - background_chroma[1],
            )

            candidates[
                top:bottom,
                left:right,
            ] = (
                (distance <= threshold)
                & (
                    chroma_distance
                    <= chroma_threshold
                )
            )

    count, labels, stats, _ = (
        cv2.connectedComponentsWithStats(
            candidates,
            connectivity=8,
        )
    )
    timings["colour_candidates"] = round((time.perf_counter() - colour_started) * 1000, 3)

    if count <= 1:
        return subject

    # Hair/neck-type openings live above the garment area.
    zone_left = max(
        0,
        round(
            x
            + bbox_width * 0.25
        ),
    )
    zone_right = min(
        width,
        round(
            x
            + bbox_width * 0.75
        ),
    )
    zone_top = max(
        0,
        round(
            y
            + bbox_height * 0.14
        ),
    )
    zone_bottom = min(
        height,
        round(
            y
            + bbox_height * 0.45
        ),
    )

    bbox_area = max(
        1,
        bbox_width * bbox_height,
    )

    min_area = max(
        64,
        round(
            bbox_area * 0.00015
        ),
    )
    max_area = max(
        1000,
        round(
            bbox_area * 0.01
        ),
    )

    min_long_side = max(
        6,
        round(
            min(
                bbox_width,
                bbox_height,
            )
            * 0.025
        ),
    )
    max_short_side = max(
        8,
        round(
            min(
                bbox_width,
                bbox_height,
            )
            * 0.08
        ),
    )

    cleaned_alpha = alpha.copy()

    for label in range(1, count):
        component_left = int(
            stats[
                label,
                cv2.CC_STAT_LEFT,
            ]
        )
        component_top = int(
            stats[
                label,
                cv2.CC_STAT_TOP,
            ]
        )
        component_width = int(
            stats[
                label,
                cv2.CC_STAT_WIDTH,
            ]
        )
        component_height = int(
            stats[
                label,
                cv2.CC_STAT_HEIGHT,
            ]
        )
        component_area = int(
            stats[
                label,
                cv2.CC_STAT_AREA,
            ]
        )

        component_right = (
            component_left
            + component_width
        )
        component_bottom = (
            component_top
            + component_height
        )

        # A candidate connected to the real outer background is
        # not an enclosed gap.
        if (
            component_left <= 0
            or component_top <= 0
            or component_right >= width
            or component_bottom >= height
        ):
            continue

        if (
            component_left < zone_left
            or component_top < zone_top
            or component_right > zone_right
            or component_bottom > zone_bottom
        ):
            continue

        long_side = max(
            component_width,
            component_height,
        )
        short_side = min(
            component_width,
            component_height,
        )

        if (
            component_area < min_area
            or component_area > max_area
            or long_side < min_long_side
            or short_side > max_short_side
            or long_side < short_side * 1.5
        ):
            continue

        timings["candidate_components"] += 1
        component = labels == label

        # Do not process regions which the main mask already
        # mostly considers background.
        if (
            float(
                np.mean(
                    alpha[component] > 0
                )
            )
            < 0.90
        ):
            continue

        pad_x = max(
            8,
            round(
                component_width * 1.8
            ),
        )
        pad_y = max(
            8,
            round(
                component_height * 0.35
            ),
        )

        local_left = max(
            0,
            component_left - pad_x,
        )
        local_top = max(
            0,
            component_top - pad_y,
        )
        local_right = min(
            width,
            component_right + pad_x,
        )
        local_bottom = min(
            height,
            component_bottom + pad_y,
        )

        roi = np.asarray(
            rgb.crop(
                (
                    local_left,
                    local_top,
                    local_right,
                    local_bottom,
                )
            )
        )

        component_roi = component[
            local_top:local_bottom,
            local_left:local_right,
        ]

        seed = np.full(
            component_roi.shape,
            cv2.GC_PR_FGD,
            dtype=np.uint8,
        )
        seed[
            component_roi
        ] = cv2.GC_PR_BGD

        inside_component = (
            cv2.distanceTransform(
                component_roi.astype(
                    np.uint8
                ),
                cv2.DIST_L2,
                3,
            )
        )

        roi_float = roi.astype(
            np.float32
        )
        roi_distance = np.linalg.norm(
            roi_float
            - background_color,
            axis=2,
        )
        roi_chroma_distance = np.hypot(
            roi_float[:, :, 0]
            - roi_float[:, :, 1]
            - background_chroma[0],
            roi_float[:, :, 1]
            - roi_float[:, :, 2]
            - background_chroma[1],
        )

        # Strongly protect pixels that do not resemble the
        # supplier background. These are typically skin/hair.
        protected = (
            (
                roi_distance
                > threshold * 1.8
            )
            | (
                roi_chroma_distance
                > chroma_threshold * 1.8
            )
        )

        edge = max(
            2,
            round(
                min(
                    component_roi.shape
                )
                * 0.03
            ),
        )

        seed[:edge, :] = cv2.GC_FGD
        seed[-edge:, :] = cv2.GC_FGD
        seed[:, :edge] = cv2.GC_FGD
        seed[:, -edge:] = cv2.GC_FGD

        seed[protected] = cv2.GC_FGD

        hard_background = (
            (inside_component >= 2.0)
            & (~protected)
        )

        if not np.any(
            hard_background
        ):
            continue

        seed[
            hard_background
        ] = cv2.GC_BGD

        local_started = time.perf_counter()
        timings["local_inference_calls"] += 1
        try:
            cv2.grabCut(
                cv2.cvtColor(
                    roi,
                    cv2.COLOR_RGB2BGR,
                ),
                seed,
                None,
                np.zeros(
                    (1, 65),
                    dtype=np.float64,
                ),
                np.zeros(
                    (1, 65),
                    dtype=np.float64,
                ),
                5,
                cv2.GC_INIT_WITH_MASK,
            )
        except cv2.error:
            continue
        finally:
            local_ms = (time.perf_counter() - local_started) * 1000
            timings["local_inference_ms"].append(round(local_ms, 3))
            timings["local_inference"] = round(timings["local_inference"] + local_ms, 3)

        local_background = np.isin(
            seed,
            (
                cv2.GC_BGD,
                cv2.GC_PR_BGD,
            ),
        ).astype(np.uint8)

        _, local_labels = (
            cv2.connectedComponents(
                local_background,
                connectivity=8,
            )
        )

        hard_labels = local_labels[
            hard_background
        ]
        hard_labels = hard_labels[
            hard_labels > 0
        ]

        if hard_labels.size == 0:
            continue

        target_label = int(
            np.bincount(
                hard_labels
            ).argmax()
        )

        remove = (
            local_labels
            == target_label
        )

        # A valid enclosed hole cannot escape the local ROI.
        if (
            remove[0, :].any()
            or remove[-1, :].any()
            or remove[:, 0].any()
            or remove[:, -1].any()
        ):
            continue

        remove_area = int(
            np.count_nonzero(
                remove
            )
        )

        if (
            remove_area
            < max(
                16,
                round(
                    component_area
                    * 0.45
                ),
            )
            or remove_area
            > max(
                round(
                    component_area
                    * 3.5
                ),
                component_area + 500,
            )
        ):
            continue

        candidate_fraction = float(
            np.mean(
                component_roi[
                    remove
                ]
            )
        )

        # Reject a result that expanded mostly into non-background
        # pixels instead of merely cleaning antialiasing around the gap.
        if candidate_fraction < 0.65:
            continue

        remove &= (
            cleaned_alpha[
                local_top:local_bottom,
                local_left:local_right,
            ]
            > 0
        )

        if not np.any(remove):
            continue

        # Feather inward only. Never enlarge the removal outside
        # the locally confirmed background region.
        inside_remove = (
            cv2.distanceTransform(
                remove.astype(
                    np.uint8
                ),
                cv2.DIST_L2,
                3,
            )
        )

        feather_radius = max(
            1.8,
            min(
                width,
                height,
            )
            * 0.0015,
        )

        keep = (
            1.0
            - np.clip(
                inside_remove
                / feather_radius,
                0.0,
                1.0,
            )
        )

        local_alpha = (
            cleaned_alpha[
                local_top:local_bottom,
                local_left:local_right,
            ].astype(
                np.float32
            )
        )

        cleaned_alpha[
            local_top:local_bottom,
            local_left:local_right,
        ] = np.rint(
            local_alpha * keep
        ).astype(
            np.uint8
        )

    if np.array_equal(
        cleaned_alpha,
        alpha,
    ):
        return subject

    cleaned = subject.copy()
    cleaned.putalpha(
        Image.fromarray(
            cleaned_alpha,
            mode="L",
        )
    )

    return cleaned


def keep_primary_foreground_component(
    foreground: np.ndarray,
) -> np.ndarray:
    binary = (
        foreground >= 128
    ).astype(np.uint8)
    count, labels, stats, _ = cv2.connectedComponentsWithStats(
        binary,
        connectivity=8,
    )

    if count <= 2:
        return foreground

    areas = stats[
        1:,
        cv2.CC_STAT_AREA,
    ]

    if areas.size == 0:
        return foreground

    primary_label = 1 + int(
        np.argmax(areas)
    )
    primary_area = int(
        stats[
            primary_label,
            cv2.CC_STAT_AREA,
        ]
    )

    if primary_area <= 0:
        return foreground

    return np.where(
        labels == primary_label,
        255,
        0,
    ).astype(np.uint8)


def refine_subject_edge(
    foreground: np.ndarray,
) -> np.ndarray:
    binary = np.where(
        foreground >= 128,
        255,
        0,
    ).astype(np.uint8)

    # A tiny median pass removes one-pixel hooks and stair-steps
    # without aggressively eroding hair or underwear edges.
    binary = cv2.medianBlur(
        binary,
        3,
    )

    kernel = cv2.getStructuringElement(
        cv2.MORPH_ELLIPSE,
        (3, 3),
    )
    binary = cv2.morphologyEx(
        binary,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=1,
    )

    inside = cv2.distanceTransform(
        (binary > 0).astype(np.uint8),
        cv2.DIST_L2,
        3,
    )
    outside = cv2.distanceTransform(
        (binary == 0).astype(np.uint8),
        cv2.DIST_L2,
        3,
    )
    signed_distance = inside - outside
    feather_radius = 1.8
    alpha = np.clip(
        (
            signed_distance
            + feather_radius
        )
        / (2.0 * feather_radius),
        0.0,
        1.0,
    )
    # Smoothing and feathering must not restore supplier-background
    # pixels already removed from the mask, including narrow gaps.
    alpha[foreground == 0] = 0.0

    return np.rint(
        alpha * 255.0
    ).astype(np.uint8)



def build_corner_background_seed_mask(image: Image.Image) -> np.ndarray | None:
    """Fallback INITIAL labels only. Never use this as a removal/alpha mask."""
    rgb = image.convert("RGB")
    width, height = rgb.size
    if width < 40 or height < 40:
        return None
    dx = max(2, round(width * 0.05))
    dy = max(2, round(height * 0.05))
    patches = [np.asarray(rgb.crop(box), dtype=np.float32).reshape(-1, 3)
               for box in ((0, 0, dx, dy), (width - dx, 0, width, dy))]
    centers = [np.median(patch, axis=0) for patch in patches]
    if any(float(np.percentile(np.linalg.norm(patch - center, axis=1), 90)) > 4.0
           for patch, center in zip(patches, centers)):
        return None
    if float(np.linalg.norm(centers[0] - centers[1])) > 8.0:
        return None
    color = (centers[0] + centers[1]) * 0.5
    chroma = (color[0] - color[1], color[1] - color[2])
    candidates = np.zeros((height, width), dtype=np.uint8)
    for top in range(0, height, 128):
        bottom = min(height, top + 128)
        tile = np.asarray(rgb.crop((0, top, width, bottom)), dtype=np.float32)
        distance = np.linalg.norm(tile - color, axis=2)
        chroma_distance = np.hypot(
            tile[:, :, 0] - tile[:, :, 1] - chroma[0],
            tile[:, :, 1] - tile[:, :, 2] - chroma[1],
        )
        candidates[top:bottom] = (distance <= 12.0) & (chroma_distance <= 4.0)
    if (float(candidates[:dy, :dx].mean()) < 0.98
            or float(candidates[:dy, -dx:].mean()) < 0.98):
        return None
    count, labels = cv2.connectedComponents(candidates, connectivity=8)
    selected = np.zeros(count, dtype=bool)
    selected[labels[:dy, :dx]] = True
    selected[labels[:dy, -dx:]] = True
    selected[0] = False
    probable = selected[labels]
    if np.count_nonzero(probable) < max(64, round(width * height * 0.02)):
        return None
    if np.count_nonzero(~probable) < 5:
        return None
    seed = np.full((height, width), cv2.GC_PR_FGD, dtype=np.uint8)
    seed[probable] = cv2.GC_PR_BGD
    # Hard background labels are confined to matching upper-corner samples.
    seed[:dy, :dx][candidates[:dy, :dx] > 0] = cv2.GC_BGD
    seed[:dy, -dx:][candidates[:dy, -dx:] > 0] = cv2.GC_BGD
    return seed


def build_undetected_seed_mask(image: Image.Image) -> np.ndarray | None:
    """Reject ambiguous ownership before using any corner as a training seed.

    Without a detector, a homogeneous internal patch could equally be an
    object or a background pocket surrounded by cropped skin. Require source
    material variation and corroborating corner samples; otherwise fail closed.
    These are conservative cues, not proof of semantic foreground ownership.
    """
    seed = build_corner_background_seed_mask(image)
    if seed is None:
        return None
    probe = flattened_rgb(image).copy()
    probe.thumbnail((512, 512), Image.Resampling.BILINEAR)
    pixels = np.asarray(probe, dtype=np.float32)
    height, width = pixels.shape[:2]
    dx, dy = max(2, round(width * .05)), max(2, round(height * .05))
    patches = [pixels[-dy:, :dx].reshape(-1, 3), pixels[-dy:, -dx:].reshape(-1, 3)]
    centers = [np.median(patch, axis=0) for patch in patches]
    if (all(float(np.percentile(np.linalg.norm(patch - center, axis=1), 90)) <= 4
            for patch, center in zip(patches, centers))
            and float(np.linalg.norm(centers[0] - centers[1])) <= 8
            and float(np.linalg.norm(centers[0] - np.median(pixels[:dy, :dx].reshape(-1, 3), axis=0))) > 12):
        # A second uniform supplier tone or cropped body at both lower corners
        # cannot safely inherit ownership from the upper samples.
        return None
    probe_seed = seed if probe.size == image.size else build_corner_background_seed_mask(probe)
    if probe_seed is None:
        return None
    core = cv2.erode((probe_seed == cv2.GC_PR_FGD).astype(np.uint8), np.ones((3, 3), np.uint8))
    samples = pixels[core > 0]
    if len(samples) < 16:
        return None
    different = np.linalg.norm(samples - np.median(samples, axis=0), axis=1) > 20
    if np.count_nonzero(different) < max(16, round(len(samples) * .02)):
        return None
    return seed


def build_closeup_validation_seed_mask(image: Image.Image) -> np.ndarray | None:
    """Source evidence for a ready MODNet mask, never GrabCut training labels.

    Cropped lower corners need not be background. Uniform upper samples may
    differ in illumination, but must retain the same chromatic family. A
    disconnected lower backdrop needs its own corroborating corner sample.
    """
    pixels = np.asarray(image.convert("RGB"), dtype=np.float32)
    height, width = pixels.shape[:2]
    if min(height, width) < 40:
        return None
    dx, dy = max(2, round(width * .05)), max(2, round(height * .05))
    corners = [(slice(0, dy), slice(0, dx)), (slice(0, dy), slice(-dx, None)),
               (slice(-dy, None), slice(0, dx)), (slice(-dy, None), slice(-dx, None))]
    patches = [pixels[region].reshape(-1, 3) for region in corners]
    centers = [np.median(patch, axis=0) for patch in patches]
    uniform = [float(np.percentile(np.linalg.norm(patch - center, axis=1), 90)) <= 4
               for patch, center in zip(patches, centers)]
    chroma = np.stack((pixels[:, :, 0] - pixels[:, :, 1],
                       pixels[:, :, 1] - pixels[:, :, 2]), axis=2)
    center_chroma = [np.array((c[0] - c[1], c[1] - c[2])) for c in centers]
    brightness = [float(c.mean()) for c in centers]
    if (not all(uniform[:2]) or np.linalg.norm(centers[0] - centers[1]) > 48
            or np.linalg.norm(center_chroma[0] - center_chroma[1]) > 8):
        return None
    anchors = [0, 1]
    for index in (2, 3):
        if uniform[index] and any(
                abs(brightness[index] - brightness[top]) <= 24
                and np.linalg.norm(center_chroma[index] - center_chroma[top]) <= 6
                for top in (0, 1)):
            anchors.append(index)
    # A bounded source-colour family, not an unrestricted chroma-only model.
    level = pixels.mean(axis=2)
    candidates = ((level >= min(brightness[i] for i in anchors) - 6)
                  & (level <= max(brightness[i] for i in anchors) + 6)
                  & np.logical_or.reduce([
                      np.linalg.norm(chroma - center_chroma[i], axis=2) <= 4
                      for i in anchors])).astype(np.uint8)
    if any(float(candidates[corners[i]].mean()) < .98 for i in anchors):
        return None
    count, labels = cv2.connectedComponents(candidates, connectivity=8)
    selected = np.zeros(count, dtype=bool)
    for index in (0, 1):
        selected[labels[corners[index]]] = True
    selected[0] = False
    upper_core = cv2.erode((~selected[labels]).astype(np.uint8), np.ones((3, 3), np.uint8))
    region = np.s_[:height // 2, width // 3:2 * width // 3]
    upper_material = pixels[region][upper_core[region] > 0]
    minimum = max(16, round(width * height * .001))
    for index in (2, 3):
        if not uniform[index]:
            continue
        matches = np.count_nonzero(np.linalg.norm(upper_material - centers[index], axis=1) <= 20)
        if matches >= minimum and index in anchors:
            # Matching enclosed source material contradicts lower-background
            # ownership. Keep that corner as material and check its retention.
            anchors.remove(index)
        elif matches < minimum and index not in anchors:
            # A new solid supplier tone cannot become foreground merely
            # because MODNet kept it opaque.
            return None
    for index in anchors:
        selected[labels[corners[index]]] = True
    selected[0] = False
    background = selected[labels]
    if np.count_nonzero(background) < max(64, round(width * height * .02)):
        return None
    core = cv2.erode((~background).astype(np.uint8), np.ones((3, 3), np.uint8))
    samples = pixels[core > 0]
    if len(samples) < 16 or np.count_nonzero(
            np.linalg.norm(samples - np.median(samples, axis=0), axis=1) > 20
    ) < max(16, round(len(samples) * .02)):
        return None
    seed = np.full((height, width), cv2.GC_PR_FGD, dtype=np.uint8)
    seed[background] = cv2.GC_PR_BGD
    for index in anchors:
        seed[corners[index]][candidates[corners[index]] > 0] = cv2.GC_BGD
    return seed


@timed_function
def build_subject_rgba(
    image: Image.Image,
    bbox: tuple[int, int, int, int] | None,
    *,
    timings: dict[str, Any],
) -> tuple[Image.Image, float] | None:
    timings["inference_calls"] = 0
    seed_started = time.perf_counter()
    rgb = flattened_rgb(image)
    source_width, source_height = rgb.size
    x, y, width, height = bbox or (0, 0, source_width, source_height)

    if (
        source_width <= 2
        or source_height <= 2
        or width <= 2
        or height <= 2
    ):
        return None

    max_edge = max(source_width, source_height)
    scale = min(1.0, SUBJECT_MASK_MAX_EDGE / max_edge)
    work_width = max(2, round(source_width * scale))
    work_height = max(2, round(source_height * scale))

    if scale < 1.0:
        work = measured_call(timings, "work_resize", rgb.resize,
            (work_width, work_height),
            Image.Resampling.BILINEAR,
        )
    else:
        work = rgb

    work_x = round(x * scale)
    work_y = round(y * scale)
    work_box_width = max(2, round(width * scale))
    work_box_height = max(2, round(height * scale))
    pad_x = max(2, round(work_box_width * 0.08))
    pad_y = max(2, round(work_box_height * 0.05))

    left = max(1, work_x - pad_x)
    top = max(1, work_y - pad_y)
    right = min(
        work_width - 1,
        work_x + work_box_width + pad_x,
    )
    bottom = min(
        work_height - 1,
        work_y + work_box_height + pad_y,
    )

    if right - left < 3 or bottom - top < 3:
        return None

    frame = cv2.cvtColor(
        np.asarray(work),
        cv2.COLOR_RGB2BGR,
    )
    mask = np.zeros(
        (work_height, work_width),
        dtype=np.uint8,
    )
    background_model = np.zeros(
        (1, 65),
        dtype=np.float64,
    )
    foreground_model = np.zeros(
        (1, 65),
        dtype=np.float64,
    )
    grabcut_mode = cv2.GC_INIT_WITH_RECT
    background_hint = measured_call(timings, "border_seed", uniform_border_background_mask, work)

    if bbox is None:
        # No detector rectangle: train only from independently sampled,
        # matching corners. All possible material stays PROBABLE foreground.
        # In a closeup the median frame colour can be skin, so it cannot
        # override the corner samples or make the frame definite background.
        seed = measured_call(timings, "corner_seed", build_undetected_seed_mask, work)
        if seed is None:
            return None
        if background_hint is not None and not np.all(background_hint[seed == cv2.GC_BGD]):
            background_hint = None
        mask[:] = seed
        grabcut_mode = cv2.GC_INIT_WITH_MASK

    elif background_hint is not None:
        # A cropped body can reach the photo edge. Do not train GrabCut's
        # certain-background model on that skin or hair. Extend only frame
        # edges that contain non-background pixels; keep the other edges
        # as background samples, without hard-labelling a wider band.
        seed_left = (
            0 if left == 1 and not background_hint[top:bottom, 0].all()
            else left
        )
        seed_top = (
            0 if top == 1 and not background_hint[0, left:right].all()
            else top
        )
        seed_right = (
            work_width
            if right == work_width - 1 and not background_hint[top:bottom, -1].all()
            else right
        )
        seed_bottom = (
            work_height
            if bottom == work_height - 1 and not background_hint[-1, left:right].all()
            else bottom
        )
        mask[seed_top:seed_bottom, seed_left:seed_right] = cv2.GC_PR_FGD

        # ANABELKA_CONNECTED_TOP_SEEDS_V1
        # A detector box can miss the crown. Rescue only non-background
        # components crossing its upper seed boundary; keep them probable.
        if seed_top > 0:
            upper_candidates = (~background_hint[:seed_top + 1, :]).astype(np.uint8)
            count, labels = cv2.connectedComponents(upper_candidates, connectivity=8)
            connected = np.zeros(count, dtype=bool)
            connected[labels[seed_top, seed_left:seed_right]] = True
            connected[0] = False
            upper_mask = mask[:seed_top, :]
            upper_mask[connected[labels[:seed_top, :]]] = cv2.GC_PR_FGD

        # ANABELKA_EXTERNAL_BG_SEEDS_V1
        # Border-connected colour matches are probable background, not
        # initial subject samples. Leave enclosed matches undecided.
        external_bg = background_hint & (mask == cv2.GC_PR_FGD)
        mask[external_bg] = cv2.GC_PR_BGD

        # ANABELKA_FRAME_BG_SEEDS_V1
        # If the subject touches all four edges, the expanded seed region
        # has no background labels. Do not fall back to a rectangle that
        # trains the background model on cropped skin and hair.
        if not np.any(mask == cv2.GC_BGD):
            mask[background_hint] = cv2.GC_PR_BGD
            mask[0, background_hint[0, :]] = cv2.GC_BGD
            mask[-1, background_hint[-1, :]] = cv2.GC_BGD
            mask[background_hint[:, 0], 0] = cv2.GC_BGD
            mask[background_hint[:, -1], -1] = cv2.GC_BGD

        if np.any(mask == cv2.GC_BGD) and np.any(mask == cv2.GC_PR_FGD):
            grabcut_mode = cv2.GC_INIT_WITH_MASK


    # ANABELKA_CORNER_BG_FALLBACK_V2
    # Only the full-frame path without a reliable border hint uses this fallback.
    if (
        bbox is not None
        and
        background_hint is None
        and grabcut_mode == cv2.GC_INIT_WITH_RECT
        and (left, top, right, bottom)
        == (1, 1, work_width - 1, work_height - 1)
    ):
        corner_seed_mask = build_corner_background_seed_mask(work)
        if corner_seed_mask is not None:
            mask[:] = corner_seed_mask
            grabcut_mode = cv2.GC_INIT_WITH_MASK

    timings["seed_prepare"] = round((time.perf_counter() - seed_started) * 1000, 3)
    try:
        timings["inference_calls"] += 1
        measured_call(timings, "inference", cv2.grabCut,
            frame,
            mask,
            (
                left,
                top,
                right - left,
                bottom - top,
            ),
            background_model,
            foreground_model,
            5,
            grabcut_mode,
        )
    except cv2.error:
        return None

    foreground = np.where(
        (mask == cv2.GC_FGD)
        | (mask == cv2.GC_PR_FGD),
        255,
        0,
    ).astype(np.uint8)
    suppression_kwargs = (
        {"background_mask": background_hint}
        if getattr(suppress_uniform_border_background, "accepts_background_mask", False) is True
        else {}
    )
    foreground = measured_call(timings, "border_suppression_work", suppress_uniform_border_background,
        work,
        foreground,
        **suppression_kwargs,
    )
    if bbox is not None:
        foreground = measured_call(timings, "primary_component", keep_primary_foreground_component,
            foreground,
        )

    foreground = measured_call(timings, "edge_refinement", refine_subject_edge,
        foreground,
    )

    foreground_ratio = float(
        np.count_nonzero(foreground >= 128)
        / max(1, work_width * work_height)
    )

    if (
        foreground_ratio < SUBJECT_MASK_MIN_RATIO
        or foreground_ratio > SUBJECT_MASK_MAX_RATIO
    ):
        return None

    alpha = Image.fromarray(
        foreground,
        mode="L",
    )

    if scale < 1.0:
        alpha = measured_call(timings, "alpha_resize", alpha.resize,
            (source_width, source_height),
            Image.Resampling.LANCZOS,
        )
        # Upsampling can reintroduce a fringe above half opacity too.
        # Clean nonopaque pixels while preserving fully opaque foreground.
        resized_alpha = np.asarray(alpha)
        cleaned_alpha = (resized_alpha if bbox is None and background_hint is None else
            measured_call(timings, "border_suppression_source", suppress_uniform_border_background,
                rgb, resized_alpha))
        alpha = Image.fromarray(
            np.where(
                resized_alpha < 255,
                cleaned_alpha,
                resized_alpha,
            ),
            mode="L",
        )

    rgba = rgb.convert("RGBA")
    rgba.putalpha(alpha)

    return (
        rgba,
        foreground_ratio,
    )


def validate_undetected_subject(
    image: Image.Image, subject: Image.Image, reported_ratio: float,
    diagnostics: dict[str, Any],
    *, allow_closeup_evidence: bool = False,
) -> bool:
    """Conservative structural checks; never a semantic person detector."""
    diagnostics.clear()
    diagnostics.update(status="rejected", reason="invalid_alpha", retention="not_evaluated")
    if (subject.mode != "RGBA" or subject.size != image.size
            or not np.isfinite(reported_ratio)
            or not SUBJECT_MASK_MIN_RATIO <= reported_ratio <= SUBJECT_MASK_MAX_RATIO):
        return False
    alpha = np.asarray(subject.getchannel("A"))
    actual_ratio = float(np.mean(alpha >= 128))
    diagnostics["actual_foreground_ratio"] = round(actual_ratio, 6)
    if (not SUBJECT_MASK_MIN_RATIO <= actual_ratio <= SUBJECT_MASK_MAX_RATIO
            or np.count_nonzero(alpha >= 192) < max(16, alpha.size * 0.005)
            or np.count_nonzero(alpha <= 32) < max(16, alpha.size * 0.02)):
        return False
    probe = flattened_rgb(image).copy()
    probe.thumbnail((512, 512), Image.Resampling.BILINEAR)
    opacity = np.asarray(subject.getchannel("A").resize(probe.size, Image.Resampling.BILINEAR))
    seed = build_undetected_seed_mask(probe)
    closeup_evidence = False
    if (seed is None and allow_closeup_evidence and actual_ratio >= .50
            and float(np.mean(opacity[-max(2, round(probe.height * .05)):] >= 128)) >= .50):
        seed = build_closeup_validation_seed_mask(probe)
        closeup_evidence = seed is not None
        if closeup_evidence:
            diagnostics["source_evidence"] = "closeup_corner_samples"
    if seed is None:
        diagnostics["reason"] = "source_evidence_unavailable"
        return False
    if seed is not None:
        # Check separate source-supported cores, including detached hands and
        # garments. Colour evidence does not authorize removing uncertain
        # detail; reject the candidate rather than repairing its alpha.
        core = cv2.erode((seed == cv2.GC_PR_FGD).astype(np.uint8), np.ones((3, 3), np.uint8))
        count, labels, stats, _ = cv2.connectedComponentsWithStats(core, connectivity=8)
        minimum = max(16, round(core.size * 0.001))
        retention = []
        for index in range(1, count):
            if stats[index, cv2.CC_STAT_AREA] >= minimum:
                retention.append(float(np.mean(opacity[labels == index] >= 128)))
        diagnostics.update(retention="corner_source_cores", component_retention=retention)
        if not retention or min(retention) < 0.90:
            diagnostics["reason"] = "source_component_loss"
            return False
        lost = ((core > 0) & (opacity < 128)).astype(np.uint8)
        _, _, loss_stats, _ = cv2.connectedComponentsWithStats(lost, connectivity=8)
        # An enclosed swatch/hand can disappear inside a large retained torso.
        # A global percentage alone would conceal that local material loss.
        if len(loss_stats) > 1 and np.any(loss_stats[1:, cv2.CC_STAT_AREA] >= minimum):
            diagnostics["reason"] = "source_local_material_loss"
            return False
        # Erosion deliberately ignores soft boundaries, but must not silently
        # discard an entire narrow hair/strap component or a thin branch.
        strands = ((seed == cv2.GC_PR_FGD)
                   & (cv2.dilate(core, np.ones((3, 3), np.uint8)) == 0)).astype(np.uint8)
        strand_count, strand_labels, strand_stats, _ = cv2.connectedComponentsWithStats(strands, connectivity=8)
        for index in range(1, strand_count):
            if (strand_stats[index, cv2.CC_STAT_AREA] >= minimum
                    and float(np.mean(opacity[strand_labels == index] >= 64)) < .90):
                diagnostics["reason"] = "source_thin_material_loss"
                return False
        background_samples = seed == cv2.GC_BGD
        background_retention = float(np.mean(opacity[background_samples] <= 32))
        if closeup_evidence:
            # Also check the independently supported backdrop interiors. Clear
            # upper samples alone must not conceal a retained lower background.
            backdrop = cv2.erode((seed != cv2.GC_PR_FGD).astype(np.uint8),
                                np.ones((3, 3), np.uint8)).astype(bool)
            background_retention = min(background_retention,
                                       float(np.mean(opacity[backdrop] <= 32)))
        if background_retention < 0.90:
            diagnostics["reason"] = "source_background_retained"
            return False
    diagnostics.update(status="accepted", reason="structural_checks_passed")
    return True


def transparent_standard_canvas(
    image: Image.Image,
    size: tuple[int, int],
) -> Image.Image:
    target_width, target_height = size
    source_width, source_height = image.size

    if source_width <= 0 or source_height <= 0:
        raise ValueError("Некоректний розмір фотографії.")

    scale = min(
        target_width / source_width,
        target_height / source_height,
    )
    resized_width = max(1, round(source_width * scale))
    resized_height = max(1, round(source_height * scale))
    resized = image.convert("RGBA").resize(
        (resized_width, resized_height),
        Image.Resampling.LANCZOS,
    )
    canvas = Image.new(
        "RGBA",
        size,
        (0, 0, 0, 0),
    )
    left = (target_width - resized_width) // 2
    top = (target_height - resized_height) // 2
    canvas.alpha_composite(
        resized,
        (left, top),
    )

    return canvas


def transparent_zoom_out_canvas(
    image: Image.Image,
    torso_center: tuple[float, float],
    shoulder_center: tuple[float, float],
    zoom_scale: float,
) -> Image.Image | None:
    target_width, target_height = MASTER_SIZE
    source_width, source_height = image.size
    standard_scale = min(
        target_width / source_width,
        target_height / source_height,
    )
    final_scale = standard_scale * zoom_scale
    resized_width = max(1, round(source_width * final_scale))
    resized_height = max(1, round(source_height * final_scale))

    if (
        resized_width > target_width
        or resized_height > target_height
    ):
        return None

    resized = image.convert("RGBA").resize(
        (resized_width, resized_height),
        Image.Resampling.LANCZOS,
    )
    requested_left = (
        target_width / 2
        - torso_center[0] * final_scale
    )
    requested_top = (
        target_height * TORSO_SHOULDER_Y_RATIO
        - shoulder_center[1] * final_scale
    )
    left = min(
        max(0, round(requested_left)),
        target_width - resized_width,
    )
    top = min(
        max(0, round(requested_top)),
        target_height - resized_height,
    )
    canvas = Image.new(
        "RGBA",
        MASTER_SIZE,
        (0, 0, 0, 0),
    )
    canvas.alpha_composite(
        resized,
        (left, top),
    )

    return canvas


def compose_subject_on_background(
    subject_canvas: Image.Image,
    profile: str,
) -> Image.Image:
    background = background_profile_canvas(
        subject_canvas.size,
        profile,
    ).convert("RGBA")
    alpha = subject_canvas.getchannel("A")
    shadow_alpha = alpha.filter(
        ImageFilter.GaussianBlur(radius=24),
    ).point(
        lambda value: round(value * 0.16)
    )
    shifted_shadow = Image.new(
        "L",
        subject_canvas.size,
        0,
    )
    shadow_width, shadow_height = subject_canvas.size
    shadow_offset_y = min(18, max(0, shadow_height - 1))
    shifted_shadow.paste(
        shadow_alpha.crop(
            (
                0,
                0,
                shadow_width,
                shadow_height - shadow_offset_y,
            )
        ),
        (0, shadow_offset_y),
    )
    shadow = Image.new(
        "RGBA",
        subject_canvas.size,
        (55, 43, 62, 0),
    )
    shadow.putalpha(shifted_shadow)
    background = Image.alpha_composite(
        background,
        shadow,
    )
    background = Image.alpha_composite(
        background,
        subject_canvas,
    )

    return background.convert("RGB")



def complex_background_prefers_modnet(
    image: Image.Image,
) -> bool:
    # Reuse the existing conservative background analysis.
    # A confirmed near-uniform supplier background stays on GrabCut.
    # No reliable uniform-border mask means the background is complex
    # enough to be considered for the separate MODNet worker.
    probe = flattened_rgb(
        image
    ).copy()

    probe.thumbnail(
        (512, 512),
        Image.Resampling.BILINEAR,
    )

    return (
        uniform_border_background_mask(
            probe
        )
        is None
    )


def weak_source_contour_uncertainty(
    source_lab: np.ndarray,
    alpha: np.ndarray,
    candidates: np.ndarray,
    background_like: np.ndarray,
    diagnostics: dict[str, Any],
) -> dict[str, Any]:
    """Locate weak source edges without vetoing the entire photograph.

    ``source_lab`` is the caller's floating Lab raster, with L scaled to 255
    and a/b shifted by 128. No candidate matte participates in this test.
    Detection and calibration thresholds are unchanged. These maps express
    uncertainty, never background. Only missing independent calibration is a
    global refusal; the source graph must resolve or protect each local zone.
    """
    normal_bin_width = 3
    normal_radius = normal_bin_width * 2
    normal_width = normal_radius * 2 + 1
    tangent_widths = (191, 127, 63, 31, 15)
    minimum_extent = 21
    insufficient_reference = False
    axis_reports = {}
    uncertain_edges = 0
    local_edges = 0
    edges = {name: np.zeros(alpha.shape, dtype=bool) for name in ("x", "y")}
    exposed = {name: np.zeros(alpha.shape, dtype=bool) for name in ("x", "y")}

    for axis, axis_name in ((1, "x"), (0, "y")):
        # Orient both passes so columns measure the contour normal and rows
        # measure its tangent. The same calibration then applies in each axis.
        if axis == 0:
            source = np.swapaxes(source_lab, 0, 1)
            candidate_domain = candidates.T
            oriented_alpha = alpha.T
            oriented_background = background_like.T
        else:
            source = source_lab
            candidate_domain = candidates
            oriented_alpha = alpha
            oriented_background = background_like
        padded = np.pad(
            source, ((0, 0), (normal_radius, normal_radius), (0, 0)),
            mode="edge",
        )

        def normal_mean(start: int, end: int) -> np.ndarray:
            return sum(
                padded[
                    :, normal_radius + start + offset:
                    normal_radius + start + offset + source.shape[1]
                ]
                for offset in range(end - start)
            ) / (end - start)

        near_left = normal_mean(-normal_bin_width, 0)
        near_right = normal_mean(0, normal_bin_width)
        # Average the two source sides directly: subtracting additional
        # short derivatives amplifies noise until a weak real step vanishes.
        # Observed clear-source variation calibrates lighting as well as grain.
        local_step = near_right - near_left
        # Every normal sample must belong to the palette candidate domain.
        # Mask BEFORE tangent averaging so contrasting skin/body contours
        # cannot project into similarly coloured backdrop past a corner.
        valid = cv2.erode(
            candidate_domain.astype(np.uint8),
            np.ones((1, normal_width), dtype=np.uint8),
        ).astype(bool)
        local_step *= valid[:, :, None]
        clear = ((oriented_alpha <= 16) & oriented_background).astype(np.uint8)
        # Use the longest independently calibrated span. Smaller rasters or
        # narrow clear patches use a shorter span instead of skipping an axis.
        # Zero erosion borders exclude reflected/padded filter footprints from
        # noise training: only actual, fully clear source samples count.
        for tangent_width in tangent_widths:
            reference = cv2.erode(
                clear,
                np.ones((tangent_width + 2, normal_width + 2), dtype=np.uint8),
                borderType=cv2.BORDER_CONSTANT, borderValue=0,
            ).astype(bool)
            reference_count = int(np.count_nonzero(reference))
            if reference_count >= 8:
                break
        axis_report = {
            "reference_pixels": reference_count,
            "tangent_width": tangent_width,
        }
        axis_reports[axis_name] = axis_report
        if reference_count < 8:
            axis_report["reason"] = "insufficient_reference"
            insufficient_reference = True
            continue
        coherent_step = cv2.boxFilter(local_step, -1, (1, tangent_width))
        strength = np.linalg.norm(coherent_step, axis=2)

        reference_strength = strength[reference]
        long_limit = max(
            0.1,
            float(reference_strength.max() + 2 * reference_strength.std()),
        )
        short_strength = np.linalg.norm(local_step, axis=2)
        short_reference = short_strength[reference]
        short_noise_envelope = max(
            0.1,
            float(np.percentile(short_reference, 99)
                  + 2 * short_reference.std()),
        )
        # A real long edge must also have local source support: a majority
        # (55%) of short normal steps point in its coherent direction. The weak
        # interval lies below half the observed short-noise envelope, where
        # pixelwise source connectivity cannot establish a reliable outline.
        locally_aligned = (
            (np.sum(local_step * coherent_step, axis=2) > 0)
            .astype(np.float32) * valid
        )
        alignment_density = cv2.boxFilter(
            locally_aligned, -1, (1, tangent_width),
        )
        normal_maximum = cv2.dilate(
            strength, np.ones((1, normal_width), dtype=np.uint8),
        )
        source_response = (
            (strength > long_limit)
            & (strength < 0.5 * short_noise_envelope)
            & (alignment_density > 0.55)
            & valid
            & (strength >= normal_maximum)
        )
        suspected = source_response & (oriented_alpha >= 192)
        # Normal maxima suppress nearby weaker responses of strong backdrop
        # seams. Require a connected 21-pixel tangent extent; isolated
        # compression extrema cannot decline the comparison.
        count, labels, stats, _ = cv2.connectedComponentsWithStats(
            suspected.astype(np.uint8), connectivity=8,
        )
        significant = np.zeros(count, dtype=bool)
        significant[1:] = (
            stats[1:, cv2.CC_STAT_HEIGHT] >= minimum_extent
        )
        suspected = significant[labels]
        edges[axis_name] = suspected.T if axis == 0 else suspected
        axis_count = int(np.count_nonzero(suspected))
        uncertain_edges += axis_count
        axis_report.update({
            "long_background_max": round(float(reference_strength.max()), 4),
            "long_background_std": round(float(reference_strength.std()), 4),
            "long_threshold": round(long_limit, 4),
            "short_noise_envelope": round(short_noise_envelope, 4),
            "weak_upper_limit": round(0.5 * short_noise_envelope, 4),
            "uncertain_edges": axis_count,
        })
        # A long calibrated window can dilute a short real garment contour.
        # Before granting LOCAL background confidence, check the remaining
        # existing spans with exactly the same detector/preservation limits.
        # Keep the original longest-span diagnostic fields unchanged.
        scale_reports = []
        for local_width in tangent_widths:
            if local_width >= tangent_width:
                continue
            # Reuse the independently clear longest-span reference population.
            # Expanding it for a shorter span could train on an exposed real
            # lighting step and hide a shorter matching material contour.
            local_reference = reference
            if np.count_nonzero(local_reference) < 8:
                continue
            local_coherent = cv2.boxFilter(local_step, -1, (1, local_width))
            local_strength = np.linalg.norm(local_coherent, axis=2)
            samples = local_strength[local_reference]
            local_limit = max(0.1, float(samples.max() + 2 * samples.std()))
            noise = short_strength[local_reference]
            local_envelope = max(0.1, float(np.percentile(noise, 99) + 2 * noise.std()))
            aligned = ((np.sum(local_step * local_coherent, axis=2) > 0)
                       .astype(np.float32) * valid)
            density = cv2.boxFilter(aligned, -1, (1, local_width))
            maximum = cv2.dilate(local_strength, np.ones((1, normal_width), dtype=np.uint8))
            local_response = ((local_strength > local_limit)
                               & (local_strength < 0.5 * local_envelope)
                               & (density > 0.55) & valid
                               & (local_strength >= maximum))
            source_response |= local_response
            local_suspected = local_response & (oriented_alpha >= 192)
            local_count, local_labels, local_stats, _ = cv2.connectedComponentsWithStats(
                local_suspected.astype(np.uint8), connectivity=8,
            )
            keep = np.zeros(local_count, dtype=bool)
            keep[1:] = local_stats[1:, cv2.CC_STAT_HEIGHT] >= minimum_extent
            local_suspected = keep[local_labels]
            suspected |= local_suspected
            scale_reports.append({"tangent_width": local_width,
                                  "reference_pixels": int(np.count_nonzero(local_reference)),
                                  "long_threshold": round(local_limit, 4),
                                  "weak_upper_limit": round(0.5 * local_envelope, 4),
                                  "uncertain_edges": int(np.count_nonzero(local_suspected))})
        edges[axis_name] = suspected.T if axis == 0 else suspected
        local_edges += int(np.count_nonzero(suspected))
        axis_report["local_scales"] = scale_reports
        # A response projected by the long tangent filter into clear pixels
        # is NOT evidence of continuation. Independently measure the normal
        # source step in a wholly clear, in-bounds 21x13 footprint. It must
        # exceed the unchanged background envelope, agree in polarity and
        # direction with the detected feature, and persist for 21 pixels.
        clear_support = cv2.erode(
            clear, np.ones((minimum_extent, normal_width), dtype=np.uint8),
            borderType=cv2.BORDER_CONSTANT, borderValue=0,
        ).astype(bool)
        exposed_step = cv2.boxFilter(local_step, -1, (1, minimum_extent))
        exposed_strength = np.linalg.norm(exposed_step, axis=2)
        agreement = np.sum(exposed_step * coherent_step, axis=2)
        clear_steps = (clear_support & source_response
                       & (exposed_strength > long_limit)
                       & (agreement >= 0.8 * exposed_strength * strength))
        clear_count, clear_labels, clear_stats, _ = cv2.connectedComponentsWithStats(
            clear_steps.astype(np.uint8), connectivity=8,
        )
        clear_keep = np.zeros(clear_count, dtype=bool)
        clear_keep[1:] = clear_stats[1:, cv2.CC_STAT_HEIGHT] >= minimum_extent
        clear_steps = clear_keep[clear_labels]
        support_count, support_labels = cv2.connectedComponents(
            source_response.astype(np.uint8), connectivity=8,
        )
        exposed_components = np.zeros(support_count, dtype=bool)
        exposed_components[support_labels[clear_steps]] = True
        exposed_components[0] = False
        continuation = exposed_components[support_labels]
        exposed[axis_name] = continuation.T if axis == 0 else continuation
        axis_report["exposed_continuation_pixels"] = int(np.count_nonzero(clear_steps))

    diagnostics["weak_source_contour_calibration"] = axis_reports
    diagnostics["weak_source_contour_tangent_widths"] = list(tangent_widths)
    diagnostics["weak_source_contour_minimum_extent"] = minimum_extent
    diagnostics["counts"]["weak_source_contour_uncertain_edges"] = uncertain_edges
    diagnostics["counts"]["weak_source_contour_local_edges"] = local_edges
    if insufficient_reference:
        diagnostics["reason"] = "insufficient_weak_source_contour_reference"
    return {**edges, "exposed_x": exposed["x"], "exposed_y": exposed["y"],
            "calibrated": not insufficient_reference}


def source_clear_edge_paths(
    usable: np.ndarray,
    block_x: np.ndarray,
    block_y: np.ndarray,
    alpha: np.ndarray,
) -> tuple[np.ndarray | None, int, int]:
    """Traverse the bounded source graph; no candidate matte participates."""
    breaks = usable.copy()
    breaks[:, 1:] = usable[:, 1:] & (~usable[:, :-1] | block_x)
    run_labels = np.cumsum(breaks.ravel(), dtype=np.int32).reshape(alpha.shape)
    run_labels[~usable] = 0
    run_count = int(run_labels.max()) + 1
    if run_count > 32768:
        return None, run_count - 1, 0
    links = usable[1:] & usable[:-1] & ~block_y
    pair_codes = np.unique(run_labels[1:][links].astype(np.int64) * run_count
                           + run_labels[:-1][links])
    if pair_codes.size > 65536:
        return None, run_count - 1, int(pair_codes.size)
    # Python scalar parent/rank lookups avoid repeated NumPy scalar dispatch.
    parent = list(range(run_count))
    rank = [0] * run_count

    def root(index: int) -> int:
        while parent[index] != index:
            parent[index] = parent[parent[index]]
            index = parent[index]
        return index

    for pair_code in pair_codes.tolist():
        first_root, second_root = root(pair_code // run_count), root(pair_code % run_count)
        if first_root == second_root:
            continue
        if rank[first_root] < rank[second_root]:
            first_root, second_root = second_root, first_root
        parent[second_root] = first_root
        if rank[first_root] == rank[second_root]:
            rank[first_root] += 1
    roots = np.array([root(index) for index in range(run_count)], dtype=np.int32)
    clear_connected = np.zeros(run_count, dtype=bool)
    for edge_labels, edge_alpha in (
        (run_labels[0], alpha[0]), (run_labels[-1], alpha[-1]),
        (run_labels[:, 0], alpha[:, 0]), (run_labels[:, -1], alpha[:, -1]),
    ):
        clear_connected[roots[edge_labels[edge_alpha <= 16]]] = True
    clear_connected[0] = False
    return clear_connected[roots[run_labels]], run_count - 1, int(pair_codes.size)


def source_connected_background(
    image: Image.Image,
    alpha: np.ndarray,
    candidates: np.ndarray,
    background_like: np.ndarray,
    diagnostics: dict[str, Any],
    *,
    raw_source: Image.Image | None = None,
    measure_guard_effect: bool = False,
    ownership_alpha: Image.Image | None = None,
) -> tuple[np.ndarray, np.ndarray, dict[str, np.ndarray]] | None:
    """Find clear-edge backdrop paths without crossing source contours.

    Links join neighbouring source pixels, rather than forbidding entire edge
    neighbourhoods. This keeps the backdrop side of a real contour available
    while retaining independently bounded, similarly coloured model details.
    Float Lab preserves weak RGB differences lost by eight-bit Lab conversion.
    The raster stays at the caller's maximum 512-pixel analysis edge; graph
    work has separate conservative caps and never consults a MODNet candidate.
    """
    counts = diagnostics["counts"]
    source_started = source_checkpoint = time.perf_counter()
    source_timings = diagnostics["source_timings_ms"] = {}

    def checkpoint(name: str) -> None:
        nonlocal source_checkpoint
        now = time.perf_counter()
        source_timings[name] = round((now - source_checkpoint) * 1000, 3)
        source_timings["total"] = round((now - source_started) * 1000, 3)
        source_checkpoint = now

    foreground = alpha >= 128
    source_lab = cv2.cvtColor(
        np.asarray(image).astype(np.float32) / 255,
        cv2.COLOR_RGB2LAB,
    )
    source_lab[:, :, 0] *= 2.55
    source_lab[:, :, 1:] += 128
    weak = weak_source_contour_uncertainty(
        source_lab, alpha, candidates, background_like, diagnostics,
    )
    checkpoint("weak_contours")
    if not weak["calibrated"]:
        return None
    weak_edges = weak["x"] | weak["y"]
    # The normal detector averages three-pixel bins on either side. Exclude
    # their six-pixel support before finding independent source paths. A path
    # through an uncertain pixel cannot prove that pixel to be background.
    weak_radius = 6
    weak_kernel = cv2.getStructuringElement(
        cv2.MORPH_ELLIPSE, (weak_radius * 2 + 1, weak_radius * 2 + 1),
    )
    # OpenCV's discrete ellipse differs from its transpose at its tips.
    # Include both footprints so X/Y orientation cannot drop support pixels.
    weak_kernel = np.maximum(weak_kernel, weak_kernel.T)
    weak_guard = cv2.dilate(weak_edges.astype(np.uint8), weak_kernel).astype(bool)
    local_report = {
        "policy": "source_only_local_protection",
        "guard_radius": weak_radius,
        "region_count": 0,
        "regions": [],
        "auto_effect": "pending_source_paths",
    }
    diagnostics["weak_source_contour_local"] = local_report
    padded_x = np.pad(source_lab, ((0, 0), (1, 1), (0, 0)), mode="edge")
    padded_y = np.pad(source_lab, ((1, 1), (0, 0), (0, 0)), mode="edge")
    delta_x = source_lab[:, 1:] - source_lab[:, :-1]
    delta_y = source_lab[1:] - source_lab[:-1]
    # Subtract same-side slopes: gradual lighting is not an object boundary.
    signed_x = delta_x - 0.5 * (
        (padded_x[:, 3:] - padded_x[:, 2:-1])
        + (padded_x[:, 1:-2] - padded_x[:, :-3])
    )
    signed_y = delta_y - 0.5 * (
        (padded_y[3:] - padded_y[2:-1])
        + (padded_y[1:-2] - padded_y[:-3])
    )
    coherent_x = cv2.boxFilter(signed_x, -1, (1, 15))
    coherent_y = cv2.boxFilter(signed_y, -1, (15, 1))
    strength_x = np.linalg.norm(coherent_x, axis=2)
    strength_y = np.linalg.norm(coherent_y, axis=2)
    clear_reference = cv2.erode(
        ((alpha <= 16) & background_like).astype(np.uint8),
        np.ones((19, 19), dtype=np.uint8),
    ).astype(bool)
    # Lighting transitions are real source edges, not noise samples. Every
    # training footprint must remain within one independently clear tone;
    # both sides of a lighting transition have their own frame-edge seeds.
    tone_kernel = np.ones((19, 19), dtype=np.uint8)
    tone_range = (
        cv2.dilate(source_lab, tone_kernel) - cv2.erode(source_lab, tone_kernel)
    )
    same_tone = (
        (tone_range[:, :, 0] <= 10)
        & (np.linalg.norm(tone_range[:, :, 1:], axis=2) <= 5)
    )
    clear_reference &= same_tone
    counts["source_tone_reference"] = int(np.count_nonzero(clear_reference))
    reference_x = clear_reference[:, 1:] & clear_reference[:, :-1]
    reference_y = clear_reference[1:] & clear_reference[:-1]
    if (np.count_nonzero(reference_x) < 8
            or np.count_nonzero(reference_y) < 8):
        diagnostics["reason"] = "insufficient_contour_reference"
        return None

    high_frequency = source_lab[:, :, 0] - cv2.GaussianBlur(
        source_lab[:, :, 0], (3, 3), 0,
    )

    def neighbour_correlation(
        first: np.ndarray,
        second: np.ndarray,
        reference: np.ndarray,
    ) -> float:
        first_samples = first[reference].astype(np.float64)
        second_samples = second[reference].astype(np.float64)
        first_samples -= first_samples.mean()
        second_samples -= second_samples.mean()
        denominator = np.sqrt(
            np.sum(first_samples * first_samples)
            * np.sum(second_samples * second_samples)
        )
        if denominator <= 1e-8:
            return 0.0
        return float(np.sum(first_samples * second_samples) / denominator)

    correlation_x = neighbour_correlation(
        high_frequency[:, 1:], high_frequency[:, :-1], reference_x,
    )
    correlation_y = neighbour_correlation(
        high_frequency[1:], high_frequency[:-1], reference_y,
    )
    diagnostics["background_noise_correlation_x"] = round(correlation_x, 4)
    diagnostics["background_noise_correlation_y"] = round(correlation_y, 4)
    # A periodic alternating field can hide similarly fine model structure.
    # Ordinary JPEG noise and smooth gradients lack this strong two-axis
    # anticorrelation; structured references conservatively keep GrabCut.
    if correlation_x < -0.7 and correlation_y < -0.7:
        diagnostics["reason"] = "structured_background_texture"
        return None

    limit_x = max(
        0.1, float(np.percentile(strength_x[reference_x], 95)) + 0.05,
    )
    limit_y = max(
        0.1, float(np.percentile(strength_y[reference_y], 95)) + 0.05,
    )
    block_x = strength_x > limit_x
    block_y = strength_y > limit_y
    # Nearby actual source links support compression gaps; a tangent average
    # by itself cannot paint a contour across an otherwise flat backdrop.
    local_x = cv2.boxFilter(delta_x, -1, (1, 3))
    local_y = cv2.boxFilter(delta_y, -1, (3, 1))
    block_x &= np.sum(coherent_x * local_x, axis=2) > 0
    block_y &= np.sum(coherent_y * local_y, axis=2) > 0
    diagnostics["continuity_threshold"] = round(max(limit_x, limit_y), 4)
    diagnostics["source_contour_threshold_x"] = round(limit_x, 4)
    diagnostics["source_contour_threshold_y"] = round(limit_y, 4)
    # Keep central contour maxima, avoiding derivative side-lobe rings which
    # would incorrectly isolate background pixels next to a genuine contour.
    padded_strength_x = np.pad(strength_x, ((0, 0), (1, 1)), mode="edge")
    padded_strength_y = np.pad(strength_y, ((1, 1), (0, 0)), mode="edge")
    block_x &= (
        (strength_x >= padded_strength_x[:, :-2])
        & (strength_x >= padded_strength_x[:, 2:])
    )
    block_y &= (
        (strength_y >= padded_strength_y[:-2])
        & (strength_y >= padded_strength_y[2:])
    )
    # Raw source steps complement slope-cancelled contours at curved corners.
    # Sampling the native colors avoids inventing a thin intermediate-tone
    # strip beside a body edge when the analysis image is downscaled. The
    # primary contour and texture analysis retain their bilinear samples.
    raw_lab = source_lab
    if raw_source is not None:
        raw_lab = cv2.cvtColor(
            np.asarray(raw_source).astype(np.float32) / 255,
            cv2.COLOR_RGB2LAB,
        )
        raw_lab[:, :, 0] *= 2.55
        raw_lab[:, :, 1:] += 128
    raw_delta_x = raw_lab[:, 1:] - raw_lab[:, :-1]
    raw_delta_y = raw_lab[1:] - raw_lab[:-1]
    candidate_x = (candidates[:, 1:] > 0) & (candidates[:, :-1] > 0)
    candidate_y = (candidates[1:] > 0) & (candidates[:-1] > 0)
    # Contrasting body edges already forbid graph links through foreground.
    # They must not project a large averaged response into nearby backdrop.
    masked_delta_x = raw_delta_x * candidate_x[:, :, None]
    masked_delta_y = raw_delta_y * candidate_y[:, :, None]
    for tangent_width in (1, 3, 15):
        raw_x = cv2.boxFilter(masked_delta_x, -1, (1, tangent_width))
        raw_y = cv2.boxFilter(masked_delta_y, -1, (tangent_width, 1))
        raw_strength_x = np.linalg.norm(raw_x, axis=2)
        raw_strength_y = np.linalg.norm(raw_y, axis=2)
        raw_limit_x = max(
            0.1, float(np.percentile(raw_strength_x[reference_x], 99)) + 0.05,
        )
        raw_limit_y = max(
            0.1, float(np.percentile(raw_strength_y[reference_y], 99)) + 0.05,
        )
        diagnostics["source_contour_thresholds_" + str(tangent_width)] = [
            round(raw_limit_x, 4), round(raw_limit_y, 4),
        ]
        raw_block_x = raw_strength_x > raw_limit_x
        raw_block_y = raw_strength_y > raw_limit_y
        if tangent_width == 1:
            # A contrast-body transition may suppress a resampling fringe.
            # An independently exposed backdrop tone transition must not
            # suppress a second genuine weak contour beside that transition:
            # a JPEG lighting seam can otherwise open a path into real hair.
            normal_strength_x = np.linalg.norm(raw_delta_x, axis=2) * ~candidate_x
            normal_strength_y = np.linalg.norm(raw_delta_y, axis=2) * ~candidate_y
        else:
            normal_strength_x = raw_strength_x
            normal_strength_y = raw_strength_y
        padded_normal_x = np.pad(
            normal_strength_x, ((0, 0), (1, 1)), mode="edge",
        )
        padded_normal_y = np.pad(
            normal_strength_y, ((1, 1), (0, 0)), mode="edge",
        )
        raw_block_x &= (
            (raw_strength_x >= padded_normal_x[:, :-2])
            & (raw_strength_x >= padded_normal_x[:, 2:])
        )
        raw_block_y &= (
            (raw_strength_y >= padded_normal_y[:-2])
            & (raw_strength_y >= padded_normal_y[2:])
        )
        # A response must agree with an actual source step at this link.
        raw_block_x &= np.sum(raw_x * raw_delta_x, axis=2) > 0
        raw_block_y &= np.sum(raw_y * raw_delta_y, axis=2) > 0
        block_x |= raw_block_x
        block_y |= raw_block_y

    # Compression may shift a weak source outline by two pixels. Its normal
    # response may support a nearby GrabCut silhouette only beside actual
    # contrasting source foreground. Arbitrary distant spill edges do not
    # acquire protection merely from belonging to the GrabCut foreground.
    source_core = (alpha >= 192) & (candidates == 0)
    distance_to_core = cv2.distanceTransform(
        (~source_core).astype(np.uint8), cv2.DIST_L2, 3,
    )
    near_source_core = distance_to_core <= max(3, min(alpha.shape) * 0.1)
    alpha_edge_x = foreground[:, 1:] != foreground[:, :-1]
    alpha_edge_y = foreground[1:] != foreground[:-1]
    supported_x = cv2.dilate(
        (strength_x > limit_x).astype(np.uint8),
        np.ones((1, 5), dtype=np.uint8),
    ).astype(bool)
    supported_y = cv2.dilate(
        (strength_y > limit_y).astype(np.uint8),
        np.ones((5, 1), dtype=np.uint8),
    ).astype(bool)
    snap_x = (
        alpha_edge_x & supported_x
        & (near_source_core[:, 1:] | near_source_core[:, :-1])
    )
    snap_y = (
        alpha_edge_y & supported_y
        & (near_source_core[1:] | near_source_core[:-1])
    )
    block_x |= snap_x
    block_y |= snap_y
    counts["source_supported_silhouette_edges"] = int(
        np.count_nonzero(snap_x) + np.count_nonzero(snap_y)
    )

    checkpoint("source_contours")
    # Remove weak support from the graph before measuring clear-frame paths.
    # A second, optional traversal measures guard-induced disconnections only;
    # it never grants foreground/background confidence to the guarded pixels.
    usable = candidates.astype(bool) & ~weak_guard
    confirmed, runs, links = source_clear_edge_paths(usable, block_x, block_y, alpha)
    counts["source_graph_runs"] = runs
    counts["source_graph_links"] = links
    checkpoint("source_graph")
    if confirmed is None:
        diagnostics["reason"] = "source_graph_too_complex"
        return None
    graph_confirmed = confirmed.copy()
    counts["source_graph_guard_pixels"] = int(np.count_nonzero(weak_guard))
    counts["source_graph_confirmed"] = int(np.count_nonzero(confirmed))
    observational_masks = {}
    if measure_guard_effect:
        unguarded = confirmed
        if np.any(weak_guard):
            unguarded, _, _ = source_clear_edge_paths(candidates.astype(bool), block_x, block_y, alpha)
        if unguarded is not None:
            observational_masks["_graph_without_guards"] = unguarded
            counts["source_graph_confirmed_without_guards"] = int(np.count_nonzero(unguarded))
            counts["source_graph_guard_induced_disconnected"] = int(np.count_nonzero(
                unguarded & ~confirmed & ~weak_guard))
            diagnostics["guard_comparison"] = "observational_only"
        else:
            diagnostics["guard_comparison"] = "source_graph_too_complex"
        checkpoint("guard_comparison")
    weak_background = np.zeros(alpha.shape, dtype=bool)
    weak_protected = np.zeros(alpha.shape, dtype=bool)
    region_labels = {}
    core_rows = {"x": {}, "y": {}}
    # Reuse the existing source-core support distance, rather than allowing an
    # uncertain edge to claim a corridor across arbitrary parts of the raster.
    unresolved_material_regions = 0
    unresolved_seeds = np.zeros(alpha.shape, dtype=bool)
    # Each normal side needs its OWN clear-frame source path in the graph
    # with ALL uncertainty guards removed. Also check attachment to source
    # foreground; simple colour matching or a MODNet matte is insufficient.
    side_offset = weak_radius * 2 + 1
    material_span = max(side_offset, min(alpha.shape) * 0.1)
    core_near_guard = distance_to_core <= side_offset
    for axis_name in ("x", "y"):
        edge_map = weak[axis_name]
        component_count, component_labels, component_stats, _ = cv2.connectedComponentsWithStats(
            edge_map.astype(np.uint8), connectivity=8,
        )
        region_labels[axis_name] = component_labels
        for label in range(1, component_count):
            local_report["region_count"] += 1
            # Bound Python work as well as JSON. Excess source complexity
            # cannot safely establish the remaining local classifications.
            if local_report["region_count"] > 256:
                diagnostics["reason"] = "weak_source_regions_too_complex"
                local_report["auto_effect"] = "keep_grabcut"
                return None
            x, y, width, height, pixels = map(int, component_stats[label])
            # Component work is restricted to its actual bounds plus normal
            # measurement support, not a fresh full-raster dilation per label.
            left, top = max(0, x - weak_radius), max(0, y - weak_radius)
            right = min(alpha.shape[1], x + width + weak_radius)
            bottom = min(alpha.shape[0], y + height + weak_radius)
            region_slice = np.s_[top:bottom, left:right]
            edge = component_labels[region_slice] == label
            zone = cv2.dilate(edge.astype(np.uint8), weak_kernel,
                              borderType=cv2.BORDER_CONSTANT, borderValue=0).astype(bool)
            rows, columns = np.nonzero(edge)
            rows, columns = rows + top, columns + left
            if axis_name == "x":
                first_rows, second_rows = rows, rows
                first_columns, second_columns = columns - side_offset, columns + side_offset
            else:
                first_rows, second_rows = rows - side_offset, rows + side_offset
                first_columns, second_columns = columns, columns
            in_bounds = (
                (first_rows >= 0) & (second_rows < alpha.shape[0])
                & (first_columns >= 0) & (second_columns < alpha.shape[1])
            )
            independent_sides = bool(np.all(in_bounds))
            side_counts = [0, 0]
            if independent_sides:
                for side, (side_rows, side_columns) in enumerate((
                    (first_rows, first_columns), (second_rows, second_columns),
                )):
                    # Missing/uncertain samples are not background evidence.
                    side_counts[side] = int(np.count_nonzero(confirmed[side_rows, side_columns]))
                    independent_sides &= (side_counts[side] >= 8 and bool(np.all(
                        confirmed[side_rows, side_columns])))
            attachment = bool(np.any(zone & core_near_guard[region_slice]))
            continuation = bool(np.all(weak["exposed_" + axis_name][rows, columns]))
            reasons = []
            if not independent_sides:
                reasons.append("unconfirmed_source_paths")
            if attachment:
                reasons.append("foreground_attachment")
            if not continuation:
                reasons.append("no_exposed_source_continuation")
            safe = independent_sides and not attachment and continuation
            unresolved_interior_rows = 0
            if safe:
                # Reintroduce only the independently resolved local pixels.
                # Broad-spill, source-material and alpha gates still apply.
                weak_background[region_slice] |= zone & background_like[region_slice] & (candidates[region_slice] > 0)
                reasons.extend(("both_sides_clear_source_paths", "exposed_source_continuation"))
            else:
                # Keep uncertain source samples and their normal support.
                # The tangent calibration width is a detector window, not
                # evidence that every pixel under that window is model material.
                weak_protected[region_slice] |= zone & (alpha[region_slice] >= 192)
                oriented_core = source_core if axis_name == "x" else source_core.T
                oriented_protected = weak_protected if axis_name == "x" else weak_protected.T
                oriented_confirmed = graph_confirmed if axis_name == "x" else graph_confirmed.T
                normal_edges = block_x if axis_name == "x" else block_y.T
                edge_rows, edge_columns = (rows, columns) if axis_name == "x" else (columns, rows)
                unresolved_extent = False
                for row in np.unique(edge_rows):
                    bounds = edge_columns[edge_rows == row]
                    if row not in core_rows[axis_name]:
                        core_rows[axis_name][row] = np.flatnonzero(oriented_core[row])
                    core_columns = core_rows[axis_name][row]
                    if not core_columns.size:
                        unresolved_interior_rows += 1
                        # A source-isolated interior stays protected by the
                        # graph. If both sides still connect to the frame, an
                        # unexposed weak outline has no independently known
                        # interior direction/extent. This also covers curved
                        # material whose detected fragment is far from skin.
                        for bound in (int(bounds.min()), int(bounds.max())):
                            before, after = bound - side_offset, bound + side_offset
                            if before >= 0 and after < oriented_core.shape[1]:
                                unresolved_extent |= ((attachment or not continuation)
                                    and bool(oriented_confirmed[row, before]
                                             and oriented_confirmed[row, after]))
                        continue
                    for bound in (int(bounds.min()), int(bounds.max())):
                        nearest = int(core_columns[np.argmin(np.abs(core_columns - bound))])
                        if abs(nearest - bound) > material_span:
                            unresolved_extent = True
                            continue
                        first, last = sorted((nearest, bound))
                        # Do not join across an independently observed source
                        # boundary. Exclude the core boundary itself and the
                        # uncertain detector's normal support at the other end.
                        inner_first = first + (1 if nearest == first else weak_radius)
                        inner_last = last - (weak_radius if bound == last else 1)
                        if np.any(normal_edges[row, inner_first:inner_last]):
                            unresolved_extent = True
                            continue
                        first = max(0, first - weak_radius)
                        last = min(oriented_core.shape[1], last + weak_radius + 1)
                        oriented_protected[row, first:last] = True
                if unresolved_interior_rows:
                    reasons.append("unresolved_interior_direction")
                if unresolved_extent:
                    unresolved_material_regions += 1
                    # A short normal guard does not establish which side is
                    # material or how far its interior extends. Claim both
                    # sides through complete opacity components below, without inventing
                    # a finite rectangle/corridor from detector support.
                    unresolved_seeds[region_slice] |= zone
                    reasons.append("unresolved_material_extent")
            if len(local_report["regions"]) < 64:
                x, y, width, height, pixels = component_stats[label]
                local_report["regions"].append({
                    "axis": axis_name,
                    "component": label,
                    "box": [int(x), int(y), int(width), int(height)],
                    "pixels": int(pixels),
                    "side_reference_pixels": side_counts,
                    "unresolved_interior_rows": unresolved_interior_rows,
                    "normal_support_box": [left, top, right - left, bottom - top],
                    "classification": "confirmed_background" if safe else "protected",
                    "reasons": reasons,
                })
    checkpoint("local_regions")
    counts["unresolved_weak_material_regions"] = unresolved_material_regions
    unresolved_material = np.zeros(alpha.shape, dtype=bool)
    if unresolved_material_regions:
        # Ownership is conservative, never a new background class. A source
        # edge may be lighting through one continuous sleeve; a colour stripe
        # may be part of the same garment. Neither independently establishes
        # the material's extent. Preserve complete original-alpha components,
        # including diagonal and soft-alpha attachments, on BOTH sides of the
        # unresolved support. If this claims the entire connected silhouette,
        # there is no safe independent comparison and GrabCut must survive.
        ownership_domain = alpha > 16
        if ownership_alpha is not None and ownership_alpha.size != (alpha.shape[1], alpha.shape[0]):
            # Averaged analysis alpha can erase a thin native soft connection
            # and expose a whole material island to deletion. Boolean max
            # pooling preserves EVERY native >16 sample and its adjacency;
            # extra coarse connections only cause conservative protection.
            # Reduce columns first, avoiding a full native float32 raster.
            native_domain = np.asarray(ownership_alpha) > 16
            native_height, native_width = native_domain.shape
            columns = (np.arange(alpha.shape[1], dtype=np.int64)
                       * native_width // alpha.shape[1])
            rows = (np.arange(alpha.shape[0], dtype=np.int64)
                    * native_height // alpha.shape[0])
            ownership_domain = np.logical_or.reduceat(
                np.logical_or.reduceat(native_domain, columns, axis=1), rows, axis=0)
        component_count, ownership_labels = cv2.connectedComponents(
            ownership_domain.astype(np.uint8), connectivity=8)
        counts["uncertainty_ownership_components"] = component_count - 1
        checkpoint("uncertainty_ownership")
        if component_count > 32768:
            diagnostics["reason"] = "uncertainty_ownership_too_complex"
            local_report["auto_effect"] = "keep_grabcut"
            return None
        owned = np.zeros(component_count, dtype=bool)
        owned[ownership_labels[unresolved_seeds]] = True
        owned[0] = False
        unresolved_material = owned[ownership_labels]
    counts["unresolved_material_protected"] = int(np.count_nonzero(
        unresolved_material & (alpha >= 192)))
    # A protected footprint always wins an overlap with another resolved zone.
    weak_background &= ~weak_protected
    weak_protected &= alpha >= 192
    weak_background &= ~unresolved_material
    confirmed = (confirmed | weak_background) & ~weak_protected & ~unresolved_material
    local_report["regions_truncated"] = local_report["region_count"] > len(local_report["regions"])
    local_report["auto_effect"] = "compare_with_local_protection"
    counts["weak_source_contour_protected"] = int(np.count_nonzero(weak_protected))
    counts["weak_source_contour_background"] = int(np.count_nonzero(weak_background & (alpha >= 192)))
    checkpoint("classification")
    return confirmed, source_lab, {
        "weak_contours": weak_edges,
        "weak_contour_zones": weak_guard,
        "weak_contour_protected": weak_protected,
        "weak_contour_background": weak_background & (alpha >= 192),
        "unresolved_material_protected": unresolved_material & (alpha >= 192),
        "_graph_confirmed": graph_confirmed,
        **observational_masks,
        "_region_labels_x": region_labels["x"],
        "_region_labels_y": region_labels["y"],
    }


def source_paired_lines(
    source_lab: np.ndarray,
    alpha: np.ndarray,
    background_like: np.ndarray,
    *,
    seed_domain: np.ndarray | None = None,
    tangent_width: int = 15,
    max_gap: int = 4,
) -> tuple[np.ndarray, np.ndarray, list[tuple[float, float, float]]] | None:
    """Protect thin source ridges without mistaking lighting steps for seams.

    Opposed, tangent-coherent normal steps must also exist in the unaveraged
    source. Train their score against clear backdrop, then protect only a small
    normal neighbourhood. Clip seeds before dilation so averaging cannot paint
    a neighbouring foreground stitch into clear background.
    """
    height, width = alpha.shape
    ridges = np.zeros(alpha.shape, dtype=bool)
    guards = np.zeros(alpha.shape, dtype=bool)
    guard_radius = max(3, round(min(alpha.shape) * 0.01))
    limits = []
    for axis in (0, 1):
        directional_ridges = np.zeros(alpha.shape, dtype=bool)
        raw = np.diff(source_lab, axis=axis)
        tangent_kernel = (
            (tangent_width, 1) if axis == 0 else (1, tangent_width)
        )
        coherent = cv2.boxFilter(raw, -1, tangent_kernel)
        strength = np.linalg.norm(coherent, axis=2)
        scores = np.zeros(strength.shape, dtype=np.float32)
        pairs = []
        for gap in range(1, max_gap + 1):
            first = coherent[:-gap] if axis == 0 else coherent[:, :-gap]
            second = coherent[gap:] if axis == 0 else coherent[:, gap:]
            first_strength = (
                strength[:-gap] if axis == 0 else strength[:, :-gap]
            )
            second_strength = (
                strength[gap:] if axis == 0 else strength[:, gap:]
            )
            opposite = np.sum(first * second, axis=2) <= (
                -0.8 * first_strength * second_strength
            )
            score = np.where(
                opposite, np.minimum(first_strength, second_strength), 0,
            )
            pairs.append((gap, score))
            if axis == 0:
                scores[:-gap] = np.maximum(scores[:-gap], score)
                scores[gap:] = np.maximum(scores[gap:], score)
            else:
                scores[:, :-gap] = np.maximum(scores[:, :-gap], score)
                scores[:, gap:] = np.maximum(scores[:, gap:], score)
        clear = (alpha <= 16) & background_like
        reference_shape = (
            (2 * max_gap + 3, tangent_width + 2)
            if axis == 0 else (tangent_width + 2, 2 * max_gap + 3)
        )
        reference = cv2.erode(
            clear.astype(np.uint8),
            np.ones(reference_shape, dtype=np.uint8),
        ).astype(bool)
        reference = reference[:-1] if axis == 0 else reference[:, :-1]
        if np.count_nonzero(reference) < 8:
            return None
        maximum = float(np.max(scores[reference]))
        spread = float(np.std(scores[reference]))
        limit = max(0.1, maximum + 2 * spread)
        limits.append((maximum, spread, limit))
        for gap, score in pairs:
            first_raw = raw[:-gap] if axis == 0 else raw[:, :-gap]
            second_raw = raw[gap:] if axis == 0 else raw[:, gap:]
            first_raw_strength = np.linalg.norm(first_raw, axis=2)
            second_raw_strength = np.linalg.norm(second_raw, axis=2)
            # A tangent average alone cannot licence protecting pixels beside
            # a seam: require an opposed pair at the same source pixel too.
            local_opposite = np.sum(first_raw * second_raw, axis=2) <= (
                -0.8 * first_raw_strength * second_raw_strength
            )
            accepted = (
                (score > limit) & local_opposite
                & (first_raw_strength > 0) & (second_raw_strength > 0)
            )
            for offset in range(1, gap + 1):
                if axis == 0:
                    directional_ridges[
                        offset:height - 1 - gap + offset
                    ] |= accepted
                else:
                    directional_ridges[
                        :, offset:width - 1 - gap + offset
                    ] |= accepted
        directional_ridges &= alpha >= 128
        if seed_domain is not None:
            directional_ridges &= seed_domain
        ridges |= directional_ridges
        normal_shape = (
            (guard_radius * 2 + 1, 1)
            if axis == 0 else (1, guard_radius * 2 + 1)
        )
        guards |= cv2.dilate(
            directional_ridges.astype(np.uint8),
            np.ones(normal_shape, dtype=np.uint8),
        ).astype(bool)
    return ridges, guards, limits


def grabcut_fallback_evidence(
    image: Image.Image,
    subject: Image.Image,
    bbox: tuple[int, int, int, int] | None,
    *,
    diagnostics: dict[str, Any] | None = None,
    measure_guard_effect: bool = False,
) -> dict[str, Any] | None:
    """Find broad backdrop spills, without changing the source or its alpha.

    Colour is only evidence for a second opinion, never permission to erase
    pixels. Use transparent border patches, source-edge connectivity and
    thickness to avoid treating enclosed fabric or thin hair as a backdrop.
    Analysis rasters have a 512-pixel maximum edge. Classification is based
    on source evidence before any optional MODNet candidate is obtained.
    """
    report = diagnostics if diagnostics is not None else {}
    report.clear()
    report.update({"stage": "input", "reason": "not_evaluated", "counts": {}, "timings_ms": {}})
    counts = report["counts"]
    started = stage_started = time.perf_counter()

    def enter_stage(name: str) -> None:
        nonlocal stage_started
        now = time.perf_counter()
        report["timings_ms"][report["stage"]] = round((now - stage_started) * 1000, 3)
        report["timings_ms"]["total"] = round((now - started) * 1000, 3)
        report["stage"] = name
        stage_started = now

    def reject(reason: str) -> None:
        enter_stage(report["stage"])
        report["reason"] = reason
        if "weak_source_contour_local" in report:
            report["weak_source_contour_local"]["auto_effect"] = "keep_grabcut_" + reason
        return None

    if subject.mode != "RGBA" or subject.size != image.size:
        return reject("invalid_subject")
    width, height = image.size
    if min(width, height) < 24:
        return reject("source_too_small")
    scale = min(1.0, 512 / max(width, height))
    size = (round(width * scale), round(height * scale))
    # Too few pixels across an extreme panorama cannot support this probe.
    if min(size) < 8:
        return reject("analysis_too_narrow")
    report["analysis_size"] = list(size)
    enter_stage("foreground_area")
    source_rgb = image if image.mode == "RGB" else flattened_rgb(image)
    rgb = source_rgb.resize(size, Image.Resampling.BILINEAR)
    ownership_alpha = subject.getchannel("A")
    alpha = np.asarray(ownership_alpha.resize(size, Image.Resampling.BILINEAR))
    foreground = alpha >= 128
    area = alpha.size
    foreground_area = int(np.count_nonzero(foreground))
    counts["foreground"] = foreground_area
    report["minimum_required"] = int(np.ceil(max(area * 0.02, foreground_area * 0.08)))
    if not SUBJECT_MASK_MIN_RATIO <= foreground_area / area <= SUBJECT_MASK_MAX_RATIO:
        return reject("invalid_foreground_area")

    lab = cv2.cvtColor(np.asarray(rgb), cv2.COLOR_RGB2LAB).astype(np.float32)
    band = max(2, round(min(size) * 0.035))
    palette = []
    enter_stage("clear_background")
    # Only nearly transparent, locally uniform patches can train the probe.
    for edge_lab, edge_alpha in (
        (lab[:band], alpha[:band]),
        (lab[-band:], alpha[-band:]),
        (lab[:, :band], alpha[:, :band]),
        (lab[:, -band:], alpha[:, -band:]),
    ):
        axis = 1 if edge_alpha.shape[0] == band else 0
        for patch_lab, patch_alpha in zip(
            np.array_split(edge_lab, 8, axis=axis),
            np.array_split(edge_alpha, 8, axis=axis),
        ):
            clear = patch_alpha <= 16
            if np.count_nonzero(clear) < 8 or float(clear.mean()) < 0.85:
                continue
            samples = patch_lab[clear]
            color = np.median(samples, axis=0)
            if float(np.percentile(np.linalg.norm(samples - color, axis=1), 90)) <= 10:
                if not any(
                    float(np.linalg.norm(color - known)) <= 2
                    for known in palette
                ):
                    palette.append(color)
    if not palette:
        return reject("no_clear_background_patches")
    report["palette_size"] = len(palette)
    enter_stage("colour_spill")

    candidates = np.zeros(alpha.shape, dtype=np.uint8)
    for color in palette:
        # Bounded luminance variation accommodates shadows. Keep chroma tight
        # so similarly bright pale skin is not automatically background.
        candidates |= ((np.abs(lab[:, :, 0] - color[0]) <= 30)
                       & (np.linalg.norm(lab[:, :, 1:] - color[1:], axis=2) < 5))
    count, labels = cv2.connectedComponents(candidates, connectivity=8)
    connected = np.zeros(count, dtype=bool)
    for edge_labels, edge_alpha in (
        (labels[0], alpha[0]), (labels[-1], alpha[-1]),
        (labels[:, 0], alpha[:, 0]), (labels[:, -1], alpha[:, -1]),
    ):
        connected[edge_labels[edge_alpha <= 16]] = True
    connected[0] = False
    background_like = connected[labels]
    counts["background_like"] = int(np.count_nonzero(background_like))
    overlap = (background_like & foreground).astype(np.uint8)
    count, labels, stats, _ = cv2.connectedComponentsWithStats(overlap, connectivity=8)
    distance = cv2.distanceTransform(overlap, cv2.DIST_L2, 3)
    thick = np.zeros(count, dtype=bool)
    thick[labels[distance >= max(3, min(size) * 0.015)]] = True
    thick[0] = False
    substantial = stats[:, cv2.CC_STAT_AREA] >= max(32, area * 0.01)
    residual = (thick & substantial)[labels]
    counts["before_continuity"] = int(np.count_nonzero(residual))
    if counts["before_continuity"] < report["minimum_required"]:
        return reject("insufficient_colour_spill")
    # Broad color geometry excludes narrow appendages before any source
    # contour cuts it into smaller pieces. Improvement cores are opened later
    # as well; that second opening must not turn source-proven backdrop corner
    # pixels back into protected model merely because a nearby contour exists.
    detail_radius = max(2, round(min(size) * 0.008))
    detail_kernel = cv2.getStructuringElement(
        cv2.MORPH_ELLIPSE, (detail_radius * 2 + 1, detail_radius * 2 + 1),
    )
    broad_colour_spill = cv2.morphologyEx(
        residual.astype(np.uint8), cv2.MORPH_OPEN, detail_kernel,
    ).astype(bool)
    enter_stage("continuity")

    source_result = source_connected_background(
        rgb, alpha, candidates, background_like, report,
        raw_source=(source_rgb.resize(size, Image.Resampling.NEAREST)
                    if scale < 1 else None),
        measure_guard_effect=measure_guard_effect,
        ownership_alpha=ownership_alpha,
    )
    if source_result is None:
        if "weak_source_contour_local" in report:
            report["weak_source_contour_local"]["auto_effect"] = "keep_grabcut_" + report["reason"]
        return reject(report["reason"])
    confirmed_source, source_lab, weak_masks = source_result
    graph_confirmed = weak_masks.pop("_graph_confirmed")
    graph_without_guards = weak_masks.pop("_graph_without_guards", None)
    graph_residual = residual & graph_confirmed
    counts["after_source_graph"] = int(np.count_nonzero(graph_residual))
    counts["removed_by_source_graph"] = counts["before_continuity"] - counts["after_source_graph"]
    counts["removed_by_graph_guard_pixels"] = int(np.count_nonzero(
        residual & weak_masks["weak_contour_zones"]))
    counts["removed_by_graph_paths"] = (counts["removed_by_source_graph"]
                                        - counts["removed_by_graph_guard_pixels"])
    if graph_without_guards is not None:
        counts["removed_by_source_contours_without_guards"] = int(np.count_nonzero(
            residual & ~graph_without_guards))
        counts["removed_by_uncertainty_guard_pixels"] = int(np.count_nonzero(
            residual & graph_without_guards & weak_masks["weak_contour_zones"]))
        counts["removed_by_uncertainty_guard_disconnection"] = int(np.count_nonzero(
            residual & graph_without_guards & ~graph_confirmed
            & ~weak_masks["weak_contour_zones"]))
    counts["restored_by_local_confirmation"] = int(np.count_nonzero(
        residual & confirmed_source & ~graph_confirmed))
    counts["removed_by_local_protection"] = int(np.count_nonzero(
        residual & graph_confirmed & ~confirmed_source))
    residual &= confirmed_source
    counts["after_continuity"] = int(np.count_nonzero(residual))
    if (counts["unresolved_weak_material_regions"]
            and counts["after_continuity"] < report["minimum_required"]):
        # Subsequent texture/detail filters only remove residual pixels. Once
        # conservative ownership leaves too little independent evidence, they
        # cannot make comparison safe; do not pay for them or start a worker.
        return reject("unresolved_weak_material_extent")
    enter_stage("texture")
    # Texture needs its OWN noise calibration, not the contour threshold.
    # Otherwise ordinary JPEG extrema become seeds, then a wide dilation can
    # protect almost every pixel of a genuinely confirmed backdrop spill.
    texture_radius = max(2, round(min(size) * 0.025))
    texture_width = texture_radius * 2 + 1
    texture_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE,
        (texture_width, texture_width))

    def extrema(kernel: np.ndarray) -> np.ndarray:
        return np.linalg.norm(np.maximum(
            cv2.morphologyEx(lab, cv2.MORPH_TOPHAT, kernel),
            cv2.morphologyEx(lab, cv2.MORPH_BLACKHAT, kernel)), axis=2)

    ordinary_texture = extrema(texture_kernel)
    wide_texture = np.minimum(
        extrema(np.ones((1, texture_width), dtype=np.uint8)),
        extrema(np.ones((texture_width, 1), dtype=np.uint8)))
    fine_texture = np.minimum(
        extrema(np.ones((1, 5), dtype=np.uint8)),
        extrema(np.ones((5, 1), dtype=np.uint8)))
    # Train on ALL exposed, palette-connected backdrop tones. Erosion excludes
    # foreground contours from the filters' reference neighbourhoods. The
    # observed envelope, rather than P90 alone, also covers rare JPEG/seam noise
    # which would otherwise multiply through dilation. Observed spread covers
    # new noise extrema; half a Lab unit remains the quantization floor.
    # These source-detector limits do not change preservation gates.
    clear_texture = cv2.erode(((alpha <= 16) & background_like).astype(np.uint8),
                             texture_kernel).astype(bool)
    if np.count_nonzero(clear_texture) < 8:
        return reject("insufficient_texture_reference")
    ordinary_max = float(np.max(ordinary_texture[clear_texture]))
    wide_max = float(np.max(wide_texture[clear_texture]))
    fine_max = float(np.max(fine_texture[clear_texture]))
    ordinary_std = float(np.std(ordinary_texture[clear_texture]))
    wide_std = float(np.std(wide_texture[clear_texture]))
    fine_std = float(np.std(fine_texture[clear_texture]))
    ordinary_limit = max(1.0, ordinary_max + max(0.5, 2 * ordinary_std))
    wide_limit = max(1.0, wide_max + max(0.5, 2 * wide_std))
    fine_limit = max(1.0, fine_max + max(0.5, 2 * fine_std))

    matching_material = (cv2.boxFilter(background_like.astype(np.float32), -1,
        (texture_width, texture_width)) >= 0.75) | background_like
    trusted_core = (alpha >= 192) & ~background_like
    # Enclosures support ambiguous ONE-direction boundary features only. Direct
    # two-direction material detail must also protect garments/hair protruding
    # beyond the contrasting body core. The enclosure never creates protection
    # by itself or changes output geometry.
    support_enclosure = np.zeros(alpha.shape, dtype=np.uint8)
    contours, _ = cv2.findContours(trusted_core.astype(np.uint8),
                                  cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    for contour in contours:
        if contour.shape[0] >= 3:
            cv2.fillConvexPoly(support_enclosure, cv2.convexHull(contour), 1)
    support_enclosure = support_enclosure.astype(bool)
    core_support = cv2.dilate(trusted_core.astype(np.uint8),
        cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))).astype(bool)
    # Two-direction extrema distinguish material details from tonal bands and
    # provide source evidence even outside the core. A boundary stitch may meet
    # brighter skin in one direction; allow that weaker exception only beside
    # confident foreground, on its enclosed side of the source contour.
    direct_detail = ((wide_texture > wide_limit) | (fine_texture > fine_limit))
    direct_detail &= foreground & matching_material
    # Compression can reduce real stitches to the same one-Lab-unit amplitude
    # as rare noise. Nonzero fine extrema alone are NOT protection: require a
    # local density above the greatest observed clear-backdrop density, with
    # another erosion so each training window is wholly clear. This preserves
    # coherent weak structure without admitting ordinary JPEG extrema en masse.
    weak_texture = (fine_texture >= 1.0).astype(np.float32)
    texture_density = cv2.boxFilter(weak_texture, -1,
                                   (texture_width, texture_width), normalize=False)
    density_reference = cv2.erode(clear_texture.astype(np.uint8),
        np.ones((texture_width, texture_width), dtype=np.uint8)).astype(bool)
    if np.count_nonzero(density_reference) < 8:
        return reject("insufficient_density_reference")
    density_max = float(np.max(texture_density[density_reference]))
    density_std = float(np.std(texture_density[density_reference]))
    density_limit = density_max + max(0.5, density_std)
    weak_detail = ((weak_texture > 0) & (texture_density > density_limit)
                   & foreground & matching_material)
    direct_detail |= weak_detail
    boundary_detail = ((ordinary_texture > ordinary_limit) & core_support
                       & support_enclosure & foreground & matching_material)
    source_lines = source_paired_lines(
        source_lab, alpha, background_like, seed_domain=matching_material,
    )
    if source_lines is None:
        return reject("insufficient_paired_line_reference")
    line_ridges, line_guard, line_limits = source_lines
    line_ridges &= foreground & matching_material
    line_guard &= foreground & matching_material
    counts["paired_line_seeds"] = int(np.count_nonzero(line_ridges))
    report["paired_line_background_limits"] = line_limits
    texture_detail = direct_detail | boundary_detail | line_ridges
    # Protect neighbouring fabric with a SMALL guard, independently of the
    # texture measurement radius. A rare seed cannot reclaim a whole spill.
    guard_radius = max(3, round(min(size) * 0.01))
    guard_kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE,
        (guard_radius * 2 + 1, guard_radius * 2 + 1))
    direct_guard = cv2.dilate(direct_detail.astype(np.uint8), guard_kernel).astype(bool)
    boundary_guard = cv2.dilate(
        boundary_detail.astype(np.uint8), guard_kernel,
    ).astype(bool)
    textured_fabric = (
        direct_guard | (boundary_guard & support_enclosure) | line_guard
    )
    report.update(texture_radius=texture_radius, texture_guard_radius=guard_radius,
                  texture_threshold=round(ordinary_limit, 4),
                  wide_texture_threshold=round(wide_limit, 4),
                  fine_texture_threshold=round(fine_limit, 4),
                  texture_background_p90=round(float(np.percentile(ordinary_texture[clear_texture], 90)), 4),
                  texture_background_max=round(ordinary_max, 4),
                  wide_texture_background_max=round(wide_max, 4),
                  fine_texture_background_max=round(fine_max, 4),
                  texture_background_std=round(ordinary_std, 4),
                  wide_texture_background_std=round(wide_std, 4),
                  fine_texture_background_std=round(fine_std, 4),
                  texture_density_background_std=round(density_std, 4),
                  texture_density_background_max=round(density_max, 4),
                  texture_density_threshold=round(density_limit, 4),
                  residual_texture_p90=round(float(np.percentile(ordinary_texture[residual], 90)), 4) if np.any(residual) else 0.0)
    counts["texture_reference"] = int(np.count_nonzero(clear_texture))
    counts["texture_density_reference"] = int(np.count_nonzero(density_reference))
    counts["weak_structure_seeds"] = int(np.count_nonzero(weak_detail))
    counts["texture_seeds"] = int(np.count_nonzero(texture_detail))
    counts["seeds_in_residual"] = int(np.count_nonzero(texture_detail & residual))
    counts["expanded_texture"] = int(np.count_nonzero(textured_fabric))
    residual &= ~textured_fabric
    counts["after_texture"] = int(np.count_nonzero(residual))
    counts["removed_by_texture"] = counts["after_continuity"] - counts["after_texture"]
    enter_stage("detail_filter")
    # A narrow matching detail must not inherit the confidence of the broad
    # spill it joins. Open locally; do not promote whole components again.
    detail_radius = max(2, round(min(size) * 0.008))
    residual = cv2.morphologyEx(residual.astype(np.uint8), cv2.MORPH_OPEN,
                              detail_kernel).astype(bool)
    confident_background = (
        broad_colour_spill & confirmed_source & ~textured_fabric & (alpha >= 192)
    )
    # Source texture can overrule a two-sided path, never the reverse.
    weak_masks["weak_contour_background"] &= confident_background
    weak_masks["weak_contour_protected"] |= (
        weak_masks["weak_contour_zones"] & (alpha >= 192) & ~confident_background
    )
    counts["weak_source_contour_background"] = int(np.count_nonzero(weak_masks["weak_contour_background"]))
    counts["weak_source_contour_protected"] = int(np.count_nonzero(weak_masks["weak_contour_protected"]))
    for region in report["weak_source_contour_local"]["regions"]:
        x, y, region_width, region_height = region["box"]
        region_slice = np.s_[y:y + region_height, x:x + region_width]
        region_edges = weak_masks["_region_labels_" + region["axis"]][region_slice] == region["component"]
        region["source_classification"] = region["classification"]
        region["background_pixels"] = int(np.count_nonzero(region_edges & confident_background[region_slice]))
        region["protected_pixels"] = int(np.count_nonzero(region_edges & ~confident_background[region_slice]))
        if region["protected_pixels"]:
            region["classification"] = "protected"
            if np.any(region_edges & textured_fabric[region_slice]):
                region["reasons"].append("source_material_detail")
            elif region["source_classification"] == "confirmed_background":
                region["reasons"].append("outside_confirmed_broad_spill")
    # Ownership labels are temporary analysis data, not candidate evidence or
    # JSON/debug output. Final region counts refer only to that axis/component.
    del weak_masks["_region_labels_x"], weak_masks["_region_labels_y"]
    residual_area = int(np.count_nonzero(residual))
    counts["after_detail_filter"] = residual_area
    counts["removed_by_detail_filter"] = counts["after_texture"] - residual_area
    enter_stage("residual_area")
    if residual_area < area * 0.02 or residual_area < foreground_area * 0.08:
        return reject("unresolved_weak_material_extent"
                      if counts["unresolved_weak_material_regions"]
                      else "insufficient_residual")

    if bbox is not None and (bbox[2] <= 0 or bbox[3] <= 0):
        return reject("invalid_bbox")
    # Construct protection AFTER confirming residuals. Textured, source-bounded,
    # enclosed and narrow matching details remain ambiguous/protected; confirmed
    # backdrop never gets protection restored merely by lying inside a bbox.
    protected = (alpha >= 192) & ~confident_background
    enter_stage("protection")
    counts["protected_foreground"] = int(np.count_nonzero(protected))
    counts["protected_residual_overlap"] = int(np.count_nonzero(protected & residual))
    if np.count_nonzero(protected) < 32:
        return reject("insufficient_protected_foreground")
    # Local ambiguity remains protected so real matching material cannot hide
    # a loss inside a whole-body/coarse-cell preservation score.
    localized_detail = protected & (candidates > 0)
    material_protected = (textured_fabric & protected) | localized_detail
    counts["localized_ambiguous_detail"] = int(np.count_nonzero(localized_detail))
    counts["material_protected"] = int(np.count_nonzero(material_protected))
    confident_foreground = protected & (
        ((candidates == 0) & foreground) | textured_fabric
    )
    ambiguous = protected & ~confident_foreground
    counts["confident_foreground"] = int(np.count_nonzero(confident_foreground))
    counts["confident_background"] = int(np.count_nonzero(confident_background))
    counts["ambiguous"] = int(np.count_nonzero(ambiguous))
    enter_stage("ready")
    report.update(
        stage="ready",
        reason="residual_confirmed",
        classification_domain="opaque_grabcut_foreground",
    )
    return {
        "source_size": image.size,
        "alpha": alpha,
        "residual": residual,
        "background_like": background_like,
        "protected": protected,
        "material_protected": material_protected,
        "confident_background": confident_background,
        "confident_foreground": confident_foreground,
        "ambiguous": ambiguous,
        **weak_masks,
    }


def modnet_fallback_is_better(
    candidate: Image.Image,
    foreground_ratio: float,
    evidence: dict[str, Any],
    *,
    diagnostics: dict[str, Any] | None = None,
) -> bool:
    """Require backdrop removal AND global/local foreground preservation."""
    if diagnostics is not None:
        diagnostics.clear()

    def finish(reason: str, accepted: bool = False) -> bool:
        if diagnostics is not None:
            diagnostics.update(reason=reason, accepted=accepted)
        return accepted

    if (candidate.mode != "RGBA" or candidate.size != evidence["source_size"]
            or not np.isfinite(foreground_ratio)
            or not SUBJECT_MASK_MIN_RATIO <= foreground_ratio <= SUBJECT_MASK_MAX_RATIO):
        return finish("invalid_candidate")
    original = evidence["alpha"]
    height, width = original.shape
    alpha = np.asarray(candidate.getchannel("A").resize((width, height), Image.Resampling.BILINEAR))
    actual_ratio = float(np.mean(alpha >= 128))
    if diagnostics is not None:
        diagnostics["actual_foreground_ratio"] = actual_ratio
        # Source classes were fixed by the GrabCut probe before this candidate
        # existed. Candidate losses are diagnostics, never background evidence.
        for name in ("confident_foreground", "confident_background", "ambiguous",
                     "weak_contour_protected", "weak_contour_background",
                     "unresolved_material_protected"):
            mask = evidence.get(name)
            if mask is not None:
                pixels = int(np.count_nonzero(mask))
                diagnostics[name + "_pixels"] = pixels
                diagnostics[name + "_lost_pixels"] = int(np.count_nonzero(mask & (alpha < 128)))
                diagnostics[name + "_opacity_loss"] = float(np.maximum(
                    original[mask].astype(np.float32) - alpha[mask], 0).sum(dtype=np.float64)) / 255
    if not SUBJECT_MASK_MIN_RATIO <= actual_ratio <= SUBJECT_MASK_MAX_RATIO:
        return finish("invalid_alpha_area")
    unresolved_material = evidence.get("unresolved_material_protected")
    if unresolved_material is not None:
        # A whole-component average can hide a lost small hand or garment
        # centre inside a large ambiguous ownership component. Unknown source
        # material has no independent permission for ANY local opaque loss.
        loss = unresolved_material & (
            (alpha < 128) | (alpha.astype(np.float32) < original * 0.90)
        )
        if diagnostics is not None:
            diagnostics["unresolved_material_loss_pixels"] = int(np.count_nonzero(loss))
        if np.any(loss):
            return finish("unresolved_material_foreground_loss")
    residual = evidence["residual"]
    before = float(original[residual].sum(dtype=np.float64)) / 255
    after = float(alpha[residual].sum(dtype=np.float64)) / 255
    if diagnostics is not None:
        diagnostics.update(residual_before=before, residual_after=after,
                           residual_removed=before - after)
    if after > before * 0.5 or before - after < original.size * 0.01:
        return finish("insufficient_improvement")
    # Do not exchange one spill for another outside GrabCut's foreground.
    added_background = evidence["background_like"] & (original <= 16)
    added = float(alpha[added_background].sum(dtype=np.float64)) / 255
    if diagnostics is not None:
        diagnostics["added_background"] = added
    if added > max(original.size * 0.002, before * 0.05):
        return finish("added_background")
    # A different-coloured backdrop/object is still background if GrabCut
    # excluded it. Allow only a narrow contour extension, not a new large island.
    near_foreground = cv2.dilate((original > 16).astype(np.uint8),
                                np.ones((5, 5), dtype=np.uint8)).astype(bool)
    unexplained = float(alpha[~near_foreground].sum(dtype=np.float64)) / 255
    if diagnostics is not None:
        diagnostics["unexplained_foreground"] = unexplained
    if unexplained > original.size * 0.002:
        return finish("unexplained_foreground")
    protected = evidence["protected"]
    protected_retention = float(np.mean(alpha[protected] >= 128))
    protected_mean = float(alpha[protected].mean())
    original_protected_mean = float(original[protected].mean())
    protected_opacity_ratio = protected_mean / original_protected_mean
    if diagnostics is not None:
        diagnostics.update(protected_retention=protected_retention,
                           protected_opacity_ratio=protected_opacity_ratio)
    if protected_retention < 0.98 or protected_mean < original_protected_mean * 0.90:
        return finish("protected_foreground_loss")
    # A good whole-image score can hide a lost hand, head or garment detail.
    for row_index, rows in enumerate(np.array_split(np.arange(height), 6)):
        for column_index, columns in enumerate(np.array_split(np.arange(width), 4)):
            region = np.ix_(rows, columns)
            core = protected[region]
            if np.count_nonzero(core) < 12:
                continue
            retention = float(np.mean(alpha[region][core] >= 128))
            candidate_mean = float(alpha[region][core].mean())
            original_mean = float(original[region][core].mean())
            opacity_ratio = candidate_mean / original_mean
            if retention < 0.90 or candidate_mean < original_mean * 0.85:
                if diagnostics is not None:
                    diagnostics.update(failed_region=[row_index, column_index],
                                       local_retention=retention, local_opacity_ratio=opacity_ratio)
                return finish("local_foreground_loss")
    # Keeping tiny stitches while deleting the surrounding fabric can pass a
    # coarse cell score. Check each source-supported material guard using the
    # same local preservation gates, without expanding protection into backdrop.
    for kind, material in (("material", evidence.get("material_protected")),
                           ("weak_contour", evidence.get("weak_contour_protected"))):
        if material is None:
            continue
        component_count, labels, stats, _ = cv2.connectedComponentsWithStats(
            material.astype(np.uint8), connectivity=8)
        checked_components = 0
        for label in range(1, component_count):
            if stats[label, cv2.CC_STAT_AREA] < 12:
                continue
            checked_components += 1
            x, y, component_width, component_height = stats[label, :4]
            region = np.s_[y:y + component_height, x:x + component_width]
            core = labels[region] == label
            retention = float(np.mean(alpha[region][core] >= 128))
            candidate_mean = float(alpha[region][core].mean())
            original_mean = float(original[region][core].mean())
            opacity_ratio = candidate_mean / original_mean
            if retention < 0.90 or candidate_mean < original_mean * 0.85:
                if diagnostics is not None:
                    diagnostics.update({kind + "_components_checked": checked_components,
                                        "failed_" + kind + "_region": [int(x), int(y), int(component_width), int(component_height)],
                                        kind + "_retention": retention, kind + "_opacity_ratio": opacity_ratio})
                return finish(kind + "_foreground_loss")
        if diagnostics is not None:
            diagnostics[kind + "_components_checked"] = checked_components
    return finish("accepted", True)


@timed_function
def custom_background_master(
    image: Image.Image,
    bbox: tuple[int, int, int, int] | None,
    crop_box: tuple[int, int, int, int] | None,
    crop_strategy: str,
    zoom_scale: float | None,
    torso_center: tuple[float, float] | None,
    shoulder_center: tuple[float, float] | None,
    background_profile: str,
    source_path: Path | None = None,
    *,
    mask_mode: str = "auto",
    diagnostics: dict[str, Any] | None = None,
    timings: dict[str, Any],
) -> tuple[Image.Image, float, str] | None:
    mask_mode = normalize_mask_mode(mask_mode)
    if diagnostics is None and (mask_mode != "auto" or bbox is None):
        diagnostics = {}
    if diagnostics is not None:
        diagnostics.clear()
        diagnostics.update(
            primary_route="opencv-grabcut",
            fallback_status="skipped",
            fallback_reason="no_source_path" if source_path is None else "not_checked",
            modnet_worker_calls=0,
            worker_error="",
            mask_mode_requested=mask_mode,
            mask_method="none",
            processor_version=VERSION,
            background_profile=background_profile,
            timings_ms=timings,
        )
    if background_profile == BACKGROUND_PROFILE_ORIGINAL:
        if diagnostics is not None:
            diagnostics.update(primary_route=BACKGROUND_PROFILE_ORIGINAL,
                               fallback_reason="original_profile")
        return None

    subject = None
    foreground_ratio = None
    modnet_applied = False
    modnet_attempted = False

    def checked_subject(result, method):
        if result is None or bbox is not None:
            return result
        quality: dict[str, Any] = {}
        if diagnostics is not None:
            diagnostics.setdefault("mask_quality", {})[method] = quality
        if not measured_call(timings, "mask_quality", validate_undetected_subject,
                             image, result[0], result[1], quality,
                             allow_closeup_evidence=method == "modnet"):
            if diagnostics is not None:
                diagnostics["failure_reason"] = "mask_quality_rejected"
            return None
        if diagnostics is not None:
            diagnostics.pop("failure_reason", None)
        return (result[0], quality["actual_foreground_ratio"], *result[2:])

    def attempt_modnet() -> tuple[Image.Image, float, dict[str, Any]] | None:
        if diagnostics is None:
            # Preserve the established direct-call contract for older callers.
            return measured_call(timings, "worker", run_modnet_worker, source_path, image)
        worker_diagnostics: dict[str, Any] = {}
        diagnostics["modnet_worker_calls"] += 1
        diagnostics["worker"] = worker_diagnostics
        try:
            result = measured_call(timings, "worker", run_modnet_worker,
                                   source_path, image, diagnostics=worker_diagnostics)
        except Exception as error:
            worker_diagnostics.update(status="failed", worker_error=(
                type(error).__name__ + ":" + str(error))[:200])
            result = None
        if result is None:
            worker_diagnostics.setdefault("status", "failed")
            worker_diagnostics.setdefault("worker_error", "worker-failed")
            if bbox is None:
                diagnostics["failure_reason"] = (
                    "mask_quality_rejected" if worker_diagnostics["worker_error"] in
                    ("worker-invalid-ratio", "worker-ratio-out-of-range", "worker-alpha-size-mismatch")
                    else "modnet_worker_failed"
                )
        else:
            worker_diagnostics.setdefault("status", "succeeded")
            worker_diagnostics.setdefault("worker_error", "")
        diagnostics["worker_error"] = worker_diagnostics["worker_error"]
        return checked_subject(result, "modnet")

    if mask_mode == "modnet" and source_path is None:
        diagnostics.update(worker_error="source-missing", fallback_status="worker_failed",
                           fallback_reason="forced_worker_failed")
        raise MaskProcessingError("modnet_worker_failed" if bbox is None else "source-missing", diagnostics)

    if mask_mode == "modnet" or (
        mask_mode == "auto"
        and source_path is not None
        and background_profile
        in (
            BACKGROUND_PROFILE_STUDIO,
            BACKGROUND_PROFILE_BRAND,
        )
        and measured_call(timings, "primary_route_analysis", complex_background_prefers_modnet,
            image
        )
    ):
        modnet_attempted = True
        if diagnostics is not None:
            diagnostics["primary_route"] = "modnet"
        modnet_result = attempt_modnet()

        if modnet_result is not None:
            (
                subject,
                foreground_ratio,
                _modnet_metadata,
            ) = modnet_result

            modnet_applied = True
            if diagnostics is not None:
                diagnostics.update(fallback_reason=("forced_modnet_selected" if mask_mode == "modnet"
                                                    else "primary_modnet_selected"))
        elif mask_mode == "modnet":
            diagnostics.update(fallback_status="worker_failed", fallback_reason="forced_worker_failed")
            raise MaskProcessingError((diagnostics.get("failure_reason", "modnet_worker_failed")
                                       if bbox is None else diagnostics["worker_error"] or "worker-failed"), diagnostics)
        elif diagnostics is not None:
            diagnostics.update(fallback_status="worker_failed",
                               fallback_reason="primary_worker_failed")

    # AUTO keeps the existing conservative GrabCut fallback when the
    # optional worker fails. Forced MODNet has already failed above.
    if subject is None:
        grabcut_timings: dict[str, Any] = {}
        if diagnostics is not None:
            diagnostics["grabcut_timings_ms"] = grabcut_timings
        subject_result = measured_call(timings, "grabcut", build_subject_rgba,
            image,
            bbox,
            detail=grabcut_timings,
        )

        if bbox is None and diagnostics is not None:
            diagnostics["failure_reason"] = "grabcut_mask_unavailable"
        subject_result = checked_subject(subject_result, "opencv-grabcut")
        if subject_result is None:
            if diagnostics is not None:
                diagnostics.update(fallback_status="error",
                                   fallback_reason=diagnostics.get("failure_reason", "grabcut_mask_unavailable"))
            if mask_mode == "grabcut":
                raise MaskProcessingError(diagnostics.get("failure_reason", "grabcut_mask_unavailable"), diagnostics)
            if bbox is None and source_path is not None and not modnet_attempted:
                modnet_attempted = True
                modnet_result = attempt_modnet()
                if modnet_result is not None:
                    subject, foreground_ratio, _modnet_metadata = modnet_result
                    modnet_applied = True
                    diagnostics.update(fallback_status="accepted", fallback_reason="no_bbox_grabcut_unavailable")
            if subject is None:
                return None

        else:
            subject, foreground_ratio = subject_result

    # These cleanup passes were designed specifically
    # for the GrabCut mask. Keep MODNet's soft alpha intact.
    if (
        not modnet_applied
        and background_profile
        in (
            BACKGROUND_PROFILE_STUDIO,
            BACKGROUND_PROFILE_BRAND,
        )
    ):
        cosmetic_timings: dict[str, Any] = {}
        enclosed_timings: dict[str, Any] = {}
        if diagnostics is not None:
            diagnostics["cosmetic_timings_ms"] = cosmetic_timings
            diagnostics["enclosed_gap_timings_ms"] = enclosed_timings
        if bbox is not None:
            subject = measured_call(timings, "cosmetic_cleanup", cosmetic_cleanup_subject_fringes,
                image, subject, bbox, detail=cosmetic_timings)
            subject = measured_call(timings, "enclosed_gap_cleanup", refine_upper_enclosed_background_gaps,
                image, subject, bbox, detail=enclosed_timings)

        # Recheck the mask that would actually be rendered. Preserve the
        # primary route, and never retry a failed complex-background worker.
        if mask_mode == "auto" and source_path is not None and not modnet_attempted:
            stage = "probe"
            try:
                if diagnostics is None:
                    evidence = measured_call(timings, "probe", grabcut_fallback_evidence, image, subject, bbox)
                else:
                    probe: dict[str, Any] = {}
                    diagnostics["probe"] = probe
                    evidence = measured_call(timings, "probe", grabcut_fallback_evidence,
                                             image, subject, bbox, diagnostics=probe)
                if evidence is not None:
                    stage = "worker"
                    modnet_result = attempt_modnet()
                    if modnet_result is None:
                        if diagnostics is not None:
                            diagnostics.update(fallback_status="worker_failed",
                                               fallback_reason="fallback_worker_failed")
                    else:
                        stage = "comparison"
                        if diagnostics is None:
                            better = measured_call(timings, "comparison", modnet_fallback_is_better,
                                modnet_result[0], modnet_result[1], evidence)
                        else:
                            comparison: dict[str, Any] = {}
                            diagnostics["comparison"] = comparison
                            better = measured_call(timings, "comparison", modnet_fallback_is_better,
                                modnet_result[0], modnet_result[1], evidence, diagnostics=comparison)
                            diagnostics.update(
                                fallback_status="accepted" if better else "rejected",
                                fallback_reason=comparison.get("reason", "candidate_not_better"),
                            )
                        if better:
                            subject, foreground_ratio, _modnet_metadata = modnet_result
                            modnet_applied = True
                elif diagnostics is not None:
                    diagnostics.update(fallback_status="skipped",
                                       fallback_reason=probe.get("reason", "insufficient_residual"))
            except Exception as error:
                # Optional analysis/inference must never discard a usable mask.
                global _modnet_worker_error
                _modnet_worker_error = ("fallback-check:" + type(error).__name__ + ":" + str(error))[:200]
                if diagnostics is not None:
                    diagnostics.update(fallback_status="error", fallback_reason=stage + "_error",
                                       worker_error=("fallback-check:" + type(error).__name__ + ":" + str(error))[:200])

    if (
        crop_strategy == "torso-zoom-out"
        and zoom_scale is not None
        and torso_center is not None
        and shoulder_center is not None
    ):
        subject_canvas = measured_call(timings, "subject_canvas", transparent_zoom_out_canvas,
            subject,
            torso_center,
            shoulder_center,
            zoom_scale,
        )

        if subject_canvas is None:
            if mask_mode != "auto":
                raise MaskProcessingError("subject_canvas_unavailable", diagnostics)
            return None
    elif crop_box is not None:
        subject_canvas = measured_call(timings, "subject_canvas", transparent_standard_canvas,
            subject.crop(crop_box),
            MASTER_SIZE,
        )
    else:
        subject_canvas = measured_call(timings, "subject_canvas", transparent_standard_canvas,
            subject,
            MASTER_SIZE,
        )

    master = measured_call(timings, "compose", compose_subject_on_background,
        subject_canvas,
        background_profile,
    )
    actual_method = "modnet" if modnet_applied else "opencv-grabcut"
    if diagnostics is not None:
        diagnostics["mask_method"] = actual_method
    return (
        master,
        foreground_ratio,
        actual_method,
    )


def find_face_cascade_path() -> Path | None:
    candidates: list[Path] = []

    cv2_data = getattr(cv2, "data", None)
    haarcascades = (
        getattr(cv2_data, "haarcascades", "")
        if cv2_data is not None
        else ""
    )

    if haarcascades:
        candidates.append(
            Path(haarcascades) / FACE_CASCADE_FILENAME
        )

    cv2_file = str(getattr(cv2, "__file__", "") or "").strip()

    if cv2_file:
        cv2_dir = Path(cv2_file).resolve().parent
        candidates.append(
            cv2_dir / "data" / FACE_CASCADE_FILENAME
        )

    for directory in [
        "/data/data/com.termux/files/usr/share/opencv4/haarcascades",
        "/data/data/com.termux/files/usr/share/opencv/haarcascades",
        "/usr/share/opencv4/haarcascades",
        "/usr/share/opencv/haarcascades",
    ]:
        candidates.append(
            Path(directory) / FACE_CASCADE_FILENAME
        )

    seen = set()

    for candidate in candidates:
        key = str(candidate)

        if key in seen:
            continue

        seen.add(key)

        try:
            if candidate.is_file():
                return candidate
        except OSError:
            continue

    return None


def detect_face_subject_bbox(
    image: Image.Image,
) -> tuple[int, int, int, int] | None:
    rgb = flattened_rgb(image)
    source_width, source_height = rgb.size
    max_edge = max(source_width, source_height)

    if max_edge <= 0:
        return None

    scale = min(1.0, FACE_DETECT_MAX_EDGE / max_edge)
    detect_width = max(1, round(source_width * scale))
    detect_height = max(1, round(source_height * scale))

    if scale < 1.0:
        detect_image = rgb.resize(
            (detect_width, detect_height),
            Image.Resampling.BILINEAR,
        )
    else:
        detect_image = rgb

    gray = cv2.cvtColor(
        np.asarray(detect_image),
        cv2.COLOR_RGB2GRAY,
    )
    cascade_path = find_face_cascade_path()

    if cascade_path is None:
        return None

    cascade = cv2.CascadeClassifier(str(cascade_path))

    if cascade.empty():
        return None

    faces = cascade.detectMultiScale(
        gray,
        scaleFactor=1.08,
        minNeighbors=5,
        minSize=(36, 36),
    )

    if len(faces) == 0:
        return None

    frame_area = detect_width * detect_height
    candidates = []

    for rect in faces:
        x, y, width, height = [int(value) for value in rect]
        area = width * height

        if (
            area <= 0
            or area / max(1, frame_area) < FACE_MIN_AREA_RATIO
        ):
            continue

        center_x = x + width / 2
        center_distance = abs(
            center_x - detect_width / 2
        ) / max(1, detect_width)
        score = area * (1.0 - min(0.65, center_distance))

        candidates.append((
            score,
            area,
            x,
            y,
            width,
            height,
        ))

    if not candidates:
        return None

    candidates.sort(
        key=lambda item: (item[0], item[1]),
        reverse=True,
    )
    _, _, x, y, width, height = candidates[0]
    inverse_scale = 1.0 / scale

    face_x = max(0, round(x * inverse_scale))
    face_y = max(0, round(y * inverse_scale))
    face_width = max(1, round(width * inverse_scale))
    face_height = max(1, round(height * inverse_scale))

    subject_left = face_x - round(face_width * 1.65)
    subject_right = face_x + face_width + round(face_width * 1.65)
    subject_top = face_y - round(face_height * 0.75)
    subject_bottom = face_y + round(face_height * 6.7)

    left = max(0, subject_left)
    top = max(0, subject_top)
    right = min(source_width, subject_right)
    bottom = min(source_height, subject_bottom)

    if right <= left or bottom <= top:
        return None

    return (
        left,
        top,
        right - left,
        bottom - top,
    )


def detect_person_bbox(
    image: Image.Image,
) -> tuple[int, int, int, int] | None:
    rgb = flattened_rgb(image)
    source_width, source_height = rgb.size
    max_edge = max(source_width, source_height)

    if max_edge <= 0:
        return None

    scale = min(1.0, PERSON_DETECT_MAX_EDGE / max_edge)
    detect_width = max(1, round(source_width * scale))
    detect_height = max(1, round(source_height * scale))

    if scale < 1.0:
        detect_image = rgb.resize(
            (detect_width, detect_height),
            Image.Resampling.BILINEAR,
        )
    else:
        detect_image = rgb

    frame = cv2.cvtColor(
        np.asarray(detect_image),
        cv2.COLOR_RGB2BGR,
    )
    hog = cv2.HOGDescriptor()
    hog.setSVMDetector(
        cv2.HOGDescriptor_getDefaultPeopleDetector()
    )
    rects, weights = hog.detectMultiScale(
        frame,
        winStride=(8, 8),
        padding=(8, 8),
        scale=1.05,
    )

    if len(rects) == 0:
        return None

    frame_area = detect_width * detect_height
    candidates = []

    for index, rect in enumerate(rects):
        x, y, width, height = [int(value) for value in rect]
        area = width * height

        if area <= 0 or area / frame_area < PERSON_MIN_AREA_RATIO:
            continue

        aspect = width / max(1, height)

        if aspect < 0.18 or aspect > 1.05:
            continue

        weight = float(weights[index]) if index < len(weights) else 0.0
        candidates.append((
            weight,
            area,
            x,
            y,
            width,
            height,
        ))

    if not candidates:
        return None

    candidates.sort(
        key=lambda item: (item[0], item[1]),
        reverse=True,
    )
    _, _, x, y, width, height = candidates[0]
    inverse_scale = 1.0 / scale

    return (
        max(0, round(x * inverse_scale)),
        max(0, round(y * inverse_scale)),
        min(source_width, round(width * inverse_scale)),
        min(source_height, round(height * inverse_scale)),
    )


def subject_crop_box(
    image: Image.Image,
    bbox: tuple[int, int, int, int],
) -> tuple[int, int, int, int] | None:
    source_width, source_height = image.size
    x, y, width, height = bbox

    if width <= 0 or height <= 0:
        return None

    left = x - round(width * PERSON_SIDE_MARGIN)
    right = x + width + round(width * PERSON_SIDE_MARGIN)
    top = y - round(height * PERSON_TOP_MARGIN)
    bottom = y + height + round(height * PERSON_BOTTOM_MARGIN)

    required_width = max(1, right - left)
    required_height = max(1, bottom - top)
    target_ratio = MASTER_SIZE[0] / MASTER_SIZE[1]

    if required_width / required_height < target_ratio:
        crop_height = required_height
        crop_width = round(crop_height * target_ratio)
    else:
        crop_width = required_width
        crop_height = round(crop_width / target_ratio)

    if crop_width > source_width or crop_height > source_height:
        return None

    center_x = x + width / 2
    center_y = y + height / 2

    crop_left = round(center_x - crop_width / 2)
    crop_top = round(center_y - crop_height / 2)
    crop_left = min(
        max(0, crop_left),
        source_width - crop_width,
    )
    crop_top = min(
        max(0, crop_top),
        source_height - crop_height,
    )
    crop_right = crop_left + crop_width
    crop_bottom = crop_top + crop_height

    crop_area_ratio = (
        crop_width * crop_height
        / max(1, source_width * source_height)
    )

    if crop_area_ratio >= PERSON_MAX_CROP_AREA_RATIO:
        return None

    if (
        crop_left > left
        or crop_top > top
        or crop_right < right
        or crop_bottom < bottom
    ):
        return None

    return (
        crop_left,
        crop_top,
        crop_right,
        crop_bottom,
    )



def source_has_cropped_closeup_edges(image: Image.Image) -> bool:
    """Conservative framing hint, not a mask and not an intent classifier."""
    probe = flattened_rgb(image).copy()
    probe.thumbnail((320, 320), Image.Resampling.BILINEAR)
    width, height = probe.size
    if width < 20 or height < 20:
        return False
    background = uniform_border_background_mask(probe)
    if background is None:
        seeds = build_corner_background_seed_mask(probe)
        if seeds is None:
            return False
        foreground = seeds == cv2.GC_PR_FGD
    else:
        foreground = ~background
    count, labels = cv2.connectedComponents(
        foreground.astype(np.uint8), connectivity=8
    )
    left, right = width // 4, width - width // 4
    top = np.bincount(labels[0, left:right], minlength=count)
    bottom = np.bincount(labels[-1, left:right], minlength=count)
    minimum = max(3, round((right - left) * 0.15))
    # The same sizeable component must reach both central frame edges.
    return bool(np.any((top[1:] >= minimum) & (bottom[1:] >= minimum)))


@timed_function
def normalized_master(
    image: Image.Image,
    background_profile: str = DEFAULT_BACKGROUND_PROFILE,
    source_path: Path | None = None,
    *,
    mask_mode: str = "auto",
    timings: dict[str, Any],
) -> tuple[Image.Image, dict[str, Any]]:
    background_profile = normalize_background_profile(
        background_profile
    )
    mask_mode = normalize_mask_mode(mask_mode)
    bbox = None
    method = ""
    person_score = None

    mediapipe_result = measured_call(timings, "detect_mediapipe", detect_mediapipe_person_bbox, image)
    mediapipe_torso_center = None
    mediapipe_shoulder_center = None
    mediapipe_hip_center = None
    mediapipe_torso_length = None
    mediapipe_upper_radius = None

    if mediapipe_result is not None:
        (
            bbox,
            person_score,
            mediapipe_torso_center,
            mediapipe_shoulder_center,
            mediapipe_hip_center,
            mediapipe_torso_length,
            mediapipe_upper_radius,
        ) = mediapipe_result
        method = "mediapipe-persondet"

    if bbox is None:
        bbox = measured_call(timings, "detect_face", detect_face_subject_bbox, image)
        method = "opencv-haar-face-subject"

    if bbox is None:
        bbox = measured_call(timings, "detect_hog", detect_person_bbox, image)
        method = "opencv-hog-person"

    if bbox is None:
        diagnostics = {
            "timings_ms": timings,
            "subject_detected": False,
            "crop_applied": False,
            "method": "standard-canvas-fallback",
            "background_profile_requested": background_profile,
            "background_profile": BACKGROUND_PROFILE_ORIGINAL,
            "background_fallback": (
                background_profile
                != BACKGROUND_PROFILE_ORIGINAL
            ),
            "subject_mask_applied": False,
            "shadow_applied": False,
            "mask_mode_requested": mask_mode,
            "mask_method": "none",
            "processor_version": VERSION,
            "worker_error": "",
            "detection_reason": "subject-not-detected",
            "crop_strategy": "preserve-source-frame",
            "zoom_out_applied": False,
            "zoom_scale": 1.0,
        }
        if background_profile != BACKGROUND_PROFILE_ORIGINAL:
            selection: dict[str, Any] = {}
            diagnostics["mask_selection"] = selection
            try:
                result = measured_call(timings, "custom_background", custom_background_master,
                    image, None, None, "preserve-source-frame", None, None, None,
                    background_profile, source_path, mask_mode=mask_mode, diagnostics=selection)
            except MaskProcessingError as error:
                diagnostics.update(reason_code=error.reason, worker_error=selection.get("worker_error", ""),
                                   background_fallback=False)
                error.normalization = diagnostics
                raise
            diagnostics["worker_error"] = selection.get("worker_error", "")
            if result is not None:
                master, ratio, actual_method = result
                diagnostics.update(method="full-frame-segmentation", background_profile=background_profile,
                                   background_fallback=False, subject_mask_applied=True,
                                   shadow_applied=True, mask_method=actual_method,
                                   mask_foreground_ratio=round(ratio, 4))
                return master, diagnostics
            diagnostics.update(reason_code="background_fallback", fallback_reason="background_fallback")
        return (
            measured_call(timings, "original_canvas", standard_canvas, image, MASTER_SIZE),
            diagnostics,
        )

    geometry_started = time.perf_counter()
    crop_strategy = "subject-bbox"
    crop_box = measured_call(timings, "bbox_crop", subject_crop_box, image, bbox)
    zoomed_master = None

    torso_ratio_before = None
    torso_ratio_after = None
    zoom_scale = None

    if (
        method == "mediapipe-persondet"
        and mediapipe_shoulder_center is not None
        and mediapipe_hip_center is not None
        and mediapipe_torso_length is not None
        and mediapipe_upper_radius is not None
    ):
        torso_crop = measured_call(timings, "torso_crop", mediapipe_torso_crop_box,
            image,
            mediapipe_shoulder_center,
            mediapipe_hip_center,
            mediapipe_torso_length,
            mediapipe_upper_radius,
        )

        if torso_crop is not None:
            crop_box, torso_ratio_before = torso_crop
            crop_strategy = "torso-normalize"
        elif mediapipe_torso_center is not None:
            torso_zoom_out = measured_call(timings, "torso_zoom_out_canvas", mediapipe_torso_zoom_out_canvas,
                image,
                mediapipe_torso_center,
                mediapipe_shoulder_center,
                mediapipe_torso_length,
            )

            if torso_zoom_out is not None:
                (
                    zoomed_master,
                    torso_ratio_before,
                    torso_ratio_after,
                    zoom_scale,
                ) = torso_zoom_out
                crop_box = None
                crop_strategy = "torso-zoom-out"

    if (
        crop_strategy not in (
            "torso-normalize",
            "torso-zoom-out",
        )
        and method == "mediapipe-persondet"
        and mediapipe_torso_center is not None
    ):
        aspect_fill_crop = measured_call(timings, "aspect_fill_crop", mediapipe_aspect_fill_crop_box,
            image,
            mediapipe_torso_center,
        )

        if aspect_fill_crop is not None:
            crop_box = aspect_fill_crop
            crop_strategy = "aspect-fill"

            # Check the size AFTER the proposed crop, not only before it.
            if (
                mediapipe_torso_length is not None
                and np.isfinite(mediapipe_torso_length)
                and mediapipe_torso_length > 1.0
            ):
                crop_scale = min(
                    MASTER_SIZE[0] / (crop_box[2] - crop_box[0]),
                    MASTER_SIZE[1] / (crop_box[3] - crop_box[1]),
                )
                cropped_torso_ratio = (
                    mediapipe_torso_length * crop_scale / MASTER_SIZE[1]
                )
                if cropped_torso_ratio > TORSO_ZOOM_OUT_TRIGGER_RATIO:
                    # Keep the whole source instead of magnifying it.
                    crop_box = None
                    crop_strategy = "subject-bbox"


    # ANABELKA_PRESERVE_CLOSEUP_V1
    # Keep an already frame-cropped close-up instead of zooming it out.
    if (
        crop_strategy == "torso-zoom-out"
        and measured_call(timings, "closeup_analysis", source_has_cropped_closeup_edges, image)
    ):
        crop_strategy = "preserve-closeup"
        crop_box = None
        zoomed_master = None
        torso_ratio_before = None
        torso_ratio_after = None
        zoom_scale = None

    timings["geometry"] = round((time.perf_counter() - geometry_started) * 1000, 3)
    diagnostics = {
        "timings_ms": timings,
        "subject_detected": True,
        "crop_applied": crop_box is not None,
        "method": (
            method
            if (
                crop_box is not None
                or crop_strategy == "torso-zoom-out"
            )
            else method + "-no-crop"
        ),
        "person_bbox": list(bbox),
        "background_profile_requested": background_profile,
        "background_profile": BACKGROUND_PROFILE_ORIGINAL,
        "background_fallback": False,
        "subject_mask_applied": False,
        "shadow_applied": False,
        "mask_mode_requested": mask_mode,
        "mask_method": "none",
        "processor_version": VERSION,
        "worker_error": "",
    }

    if (
        crop_box is not None
        or crop_strategy == "torso-zoom-out"
    ):
        diagnostics["crop_strategy"] = crop_strategy


    if crop_strategy == "preserve-closeup":
        diagnostics["crop_strategy"] = crop_strategy
        diagnostics["zoom_out_applied"] = False
        diagnostics["zoom_scale"] = 1.0

    if torso_ratio_before is not None:
        diagnostics["torso_ratio_before"] = round(
            max(0.0, min(1.0, torso_ratio_before)),
            4,
        )
        diagnostics["torso_target_ratio"] = (
            TORSO_ZOOM_OUT_TARGET_RATIO
            if crop_strategy == "torso-zoom-out"
            else TORSO_TARGET_RATIO
        )

    if torso_ratio_after is not None:
        diagnostics["torso_ratio_after"] = round(
            max(0.0, min(1.0, torso_ratio_after)),
            4,
        )

    if zoom_scale is not None:
        diagnostics["zoom_scale"] = round(
            max(0.0, min(1.0, zoom_scale)),
            4,
        )
        diagnostics["zoom_out_applied"] = True

    if person_score is not None:
        diagnostics["person_score"] = round(
            max(0.0, min(1.0, person_score)),
            4,
        )

    if background_profile != BACKGROUND_PROFILE_ORIGINAL:
        mask_selection: dict[str, Any] = {}
        diagnostics["mask_selection"] = mask_selection
        try:
            custom_background = measured_call(timings, "custom_background", custom_background_master,
                image,
                bbox,
                crop_box,
                crop_strategy,
                zoom_scale,
                mediapipe_torso_center,
                mediapipe_shoulder_center,
                background_profile,
                source_path,
                mask_mode=mask_mode,
                diagnostics=mask_selection,
            )
        except MaskProcessingError as error:
            diagnostics["worker_error"] = mask_selection.get("worker_error", "")
            error.normalization = diagnostics
            raise
        diagnostics["worker_error"] = mask_selection.get("worker_error", "")

        if custom_background is not None:
            (
                background_master,
                mask_foreground_ratio,
                mask_method,
            ) = custom_background
            diagnostics["background_profile"] = (
                background_profile
            )
            diagnostics["background_fallback"] = False
            diagnostics["subject_mask_applied"] = True
            diagnostics["mask_method"] = mask_method
            diagnostics["mask_foreground_ratio"] = round(
                max(
                    0.0,
                    min(1.0, mask_foreground_ratio),
                ),
                4,
            )
            diagnostics["shadow_applied"] = True

            if crop_box is not None:
                diagnostics["crop_box"] = list(crop_box)

            return (
                background_master,
                diagnostics,
            )

        diagnostics["background_fallback"] = True

    if zoomed_master is not None:
        return (
            zoomed_master,
            diagnostics,
        )

    if crop_box is None:
        return (
            measured_call(timings, "original_canvas", standard_canvas, image, MASTER_SIZE),
            diagnostics,
        )

    diagnostics["crop_box"] = list(crop_box)
    cropped = measured_call(timings, "original_crop", image.crop, crop_box)

    return (
        measured_call(timings, "original_canvas", standard_canvas, cropped, MASTER_SIZE),
        diagnostics,
    )


def standard_canvas(
    image: Image.Image,
    size: tuple[int, int],
) -> Image.Image:
    target_width, target_height = size
    source_width, source_height = image.size

    if source_width <= 0 or source_height <= 0:
        raise ValueError("Некоректний розмір фотографії.")

    scale = min(
        target_width / source_width,
        target_height / source_height,
    )
    resized_width = max(1, round(source_width * scale))
    resized_height = max(1, round(source_height * scale))
    resized = image.resize(
        (resized_width, resized_height),
        Image.Resampling.LANCZOS,
    )

    if resized.mode == "RGBA":
        flattened = Image.new(
            "RGB",
            resized.size,
            CANVAS_BACKGROUND,
        )
        flattened.paste(
            resized,
            (0, 0),
            resized,
        )
        resized = flattened
    else:
        resized = resized.convert("RGB")

    canvas = Image.new(
        "RGB",
        (target_width, target_height),
        CANVAS_BACKGROUND,
    )
    left = (target_width - resized_width) // 2
    top = (target_height - resized_height) // 2
    canvas.paste(
        resized,
        (left, top),
    )

    return canvas


def save_webp(image: Image.Image, target: Path, quality: int) -> None:
    target.parent.mkdir(parents=True, exist_ok=True)
    image.save(
        target,
        "WEBP",
        quality=quality,
        method=6,
        exact=True,
    )


@timed_function
def process_image(
    source_value: Any,
    background_profile_value: Any = None,
    mask_mode: Any = None,
    *,
    timings: dict[str, Any],
) -> dict[str, Any]:
    source = measured_call(timings, "source_validation", safe_source_path, source_value)
    background_profile = normalize_background_profile(
        background_profile_value
    )
    mask_mode = normalize_mask_mode(mask_mode)
    job_id = uuid.uuid4().hex

    original_dir = ORIGINAL_ROOT / job_id
    processed_dir = PROCESSED_ROOT / job_id
    original_dir.mkdir(parents=True, exist_ok=False)
    processed_dir.mkdir(parents=True, exist_ok=False)

    original = original_dir / source.name

    try:
        measured_call(timings, "copy_original", shutil.copy2, source, original)
        original_hash = measured_call(timings, "hash_original", sha256_file, original)

        decode_timings: dict[str, Any] = {}
        image = measured_call(timings, "decode", normalized_image, original, detail=decode_timings)
        width, height = image.size

        master, normalization = measured_call(timings, "normalization", normalized_master,
            image,
            background_profile,
            source_path=original,
            mask_mode=mask_mode,
        )
        thumb = measured_call(timings, "thumbnail_resize", master.resize,
            THUMB_SIZE,
            Image.Resampling.LANCZOS,
        )

        master_path = processed_dir / "master.webp"
        thumb_path = processed_dir / "thumb.webp"

        measured_call(timings, "save_master", save_webp, master, master_path, 92)
        measured_call(timings, "save_thumb", save_webp, thumb, thumb_path, 86)

        return {
            "ok": True,
            "timings_ms": timings,
            "decode_timings_ms": decode_timings,
            "processor_version": VERSION,
            "profile": PROFILE,
            "background_profile": (
                normalization.get(
                    "background_profile",
                    BACKGROUND_PROFILE_ORIGINAL,
                )
            ),
            "job_id": job_id,
            "source": project_relative(source),
            "original": {
                "path": project_relative(original),
                "sha256": original_hash,
                "bytes": original.stat().st_size,
                "immutable_by_service": True,
            },
            "input": {
                "width": width,
                "height": height,
            },
            "normalization": normalization,
            "master": {
                "path": project_relative(master_path),
                "width": master.width,
                "height": master.height,
                "bytes": master_path.stat().st_size,
                "format": "webp",
            },
            "thumb": {
                "path": project_relative(thumb_path),
                "width": thumb.width,
                "height": thumb.height,
                "bytes": thumb_path.stat().st_size,
                "format": "webp",
            },
        }
    except Exception:
        shutil.rmtree(original_dir, ignore_errors=True)
        shutil.rmtree(processed_dir, ignore_errors=True)
        raise


class Handler(BaseHTTPRequestHandler):
    server_version = "AnabelkaImageProcessor/" + VERSION

    def do_GET(self) -> None:
        if self.path != "/health":
            self.send_json(
                404,
                {"ok": False, "error": "Маршрут не знайдено."},
            )
            return

        self.send_json(
            200,
            {
                "ok": True,
                "service": "Anabelka Image Processor",
                "version": VERSION,
                "profile": PROFILE,
                "default_background_profile": (
                    DEFAULT_BACKGROUND_PROFILE
                ),
                "background_profiles": list(
                    BACKGROUND_PROFILES
                ),
                "master_size": {
                    "width": MASTER_SIZE[0],
                    "height": MASTER_SIZE[1],
                },
                "thumb_size": {
                    "width": THUMB_SIZE[0],
                    "height": THUMB_SIZE[1],
                },
                "subject_detector": (
                    (
                        "mediapipe-persondet+"
                        if person_model_ready()
                        else ""
                    )
                    + (
                        "haar-face+"
                        if find_face_cascade_path() is not None
                        else ""
                    )
                    + "hog-person-fallback"
                ),
                "person_model_ready": person_model_ready(),
                "face_cascade_ready":
                    find_face_cascade_path() is not None,
                "person_model_path": project_relative(
                    PERSON_MODEL_PATH
                ),
                "person_model_error": _person_detector_error,
                "modnet_worker_ready": modnet_worker_ready(),
                "modnet_worker_path": project_relative(
                    MODNET_WORKER_PATH
                ),
                "modnet_model_path": project_relative(
                    MODNET_MODEL_PATH
                ),
                "modnet_worker_error": _modnet_worker_error,
                "modnet_execution": "separate-process",
                "opencv": cv2.__version__,
                "pillow": PIL.__version__,
                "host": HOST,
                "source_root_ready": SOURCE_ROOT.is_dir(),
                "work_root": project_relative(WORK_ROOT),
            },
        )

    def do_POST(self) -> None:
        if self.path != "/process":
            self.send_json(
                404,
                {"ok": False, "error": "Маршрут не знайдено."},
            )
            return

        length_header = self.headers.get("Content-Length", "")

        try:
            length = int(length_header)
        except ValueError:
            length = -1

        if length <= 0 or length > MAX_JSON_BYTES:
            self.send_json(
                400,
                {"ok": False, "error": "Некоректний розмір запиту."},
            )
            return

        try:
            payload = json.loads(
                self.rfile.read(length).decode("utf-8")
            )

            if not isinstance(payload, dict):
                raise ValueError("Некоректний JSON.")

            result = process_image(
                payload.get("source"),
                payload.get("background_profile"),
                mask_mode=payload.get("mask_mode"),
            )
            self.send_json(200, result)
        except MaskProcessingError as error:
            self.send_json(
                500,
                {
                    "ok": False,
                    "error": str(error),
                    "worker_error": error.normalization.get("worker_error", ""),
                    "normalization": error.normalization,
                },
            )
        except (ValueError, OSError, UnidentifiedImageError) as error:
            self.send_json(
                400,
                {"ok": False, "error": str(error)},
            )
        except Exception as error:
            print(
                "image processor error:",
                type(error).__name__,
                str(error),
                flush=True,
            )
            self.send_json(
                500,
                {
                    "ok": False,
                    "error": "Внутрішня помилка обробника зображень.",
                },
            )

    def send_json(
        self,
        status: int,
        payload: dict[str, Any],
    ) -> None:
        body = json_bytes(payload)

        try:
            self.send_response(status)
            self.send_header(
                "Content-Type",
                "application/json; charset=utf-8",
            )
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)
        except (
            BrokenPipeError,
            ConnectionResetError,
            ConnectionAbortedError,
        ):
            # The client stopped waiting for the response.
            # Processing itself may still have completed successfully.
            return

    def log_message(self, format: str, *args: Any) -> None:
        print(
            "%s - - [%s] %s"
            % (
                self.address_string(),
                self.log_date_time_string(),
                format % args,
            ),
            flush=True,
        )


def main() -> None:
    if PORT < 1024 or PORT > 65535:
        raise SystemExit("Некоректний порт image processor.")

    WORK_ROOT.mkdir(parents=True, exist_ok=True)
    ORIGINAL_ROOT.mkdir(parents=True, exist_ok=True)
    PROCESSED_ROOT.mkdir(parents=True, exist_ok=True)

    server = ThreadingHTTPServer((HOST, PORT), Handler)
    print(
        f"Anabelka Image Processor: http://{HOST}:{PORT}",
        flush=True,
    )
    print(f"Project root: {PROJECT_ROOT}", flush=True)
    print("Ctrl+C to stop.", flush=True)

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
        print("Image processor stopped.", flush=True)


if __name__ == "__main__":
    main()
