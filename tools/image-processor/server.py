#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import os
import shutil
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

import cv2
import numpy as np
import PIL
from PIL import Image, ImageFilter, ImageOps, UnidentifiedImageError

from mp_persondet import MPPersonDet


HOST = "127.0.0.1"
VERSION = "0.8"
PROFILE = "model-normalize-v5"
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

_person_detector = None
_person_detector_error = ""


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


def normalized_image(source: Path) -> Image.Image:
    probe = cv2.imread(str(source), cv2.IMREAD_UNCHANGED)

    if probe is None:
        raise ValueError("OpenCV не зміг прочитати фотографію.")

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


def build_subject_rgba(
    image: Image.Image,
    bbox: tuple[int, int, int, int],
) -> tuple[Image.Image, float] | None:
    rgb = flattened_rgb(image)
    source_width, source_height = rgb.size
    x, y, width, height = bbox

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
        work = rgb.resize(
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

    try:
        cv2.grabCut(
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
            cv2.GC_INIT_WITH_RECT,
        )
    except cv2.error:
        return None

    foreground = np.where(
        (mask == cv2.GC_FGD)
        | (mask == cv2.GC_PR_FGD),
        255,
        0,
    ).astype(np.uint8)

    kernel = np.ones((3, 3), dtype=np.uint8)
    foreground = cv2.morphologyEx(
        foreground,
        cv2.MORPH_CLOSE,
        kernel,
        iterations=1,
    )
    foreground = cv2.GaussianBlur(
        foreground,
        (5, 5),
        0,
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
        alpha = alpha.resize(
            (source_width, source_height),
            Image.Resampling.LANCZOS,
        )

    rgba = rgb.convert("RGBA")
    rgba.putalpha(alpha)

    return (
        rgba,
        foreground_ratio,
    )


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


def custom_background_master(
    image: Image.Image,
    bbox: tuple[int, int, int, int],
    crop_box: tuple[int, int, int, int] | None,
    crop_strategy: str,
    zoom_scale: float | None,
    torso_center: tuple[float, float] | None,
    shoulder_center: tuple[float, float] | None,
    background_profile: str,
) -> tuple[Image.Image, float] | None:
    if background_profile == BACKGROUND_PROFILE_ORIGINAL:
        return None

    subject_result = build_subject_rgba(
        image,
        bbox,
    )

    if subject_result is None:
        return None

    subject, foreground_ratio = subject_result

    if (
        crop_strategy == "torso-zoom-out"
        and zoom_scale is not None
        and torso_center is not None
        and shoulder_center is not None
    ):
        subject_canvas = transparent_zoom_out_canvas(
            subject,
            torso_center,
            shoulder_center,
            zoom_scale,
        )

        if subject_canvas is None:
            return None
    elif crop_box is not None:
        subject_canvas = transparent_standard_canvas(
            subject.crop(crop_box),
            MASTER_SIZE,
        )
    else:
        subject_canvas = transparent_standard_canvas(
            subject,
            MASTER_SIZE,
        )

    return (
        compose_subject_on_background(
            subject_canvas,
            background_profile,
        ),
        foreground_ratio,
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


def normalized_master(
    image: Image.Image,
    background_profile: str = DEFAULT_BACKGROUND_PROFILE,
) -> tuple[Image.Image, dict[str, Any]]:
    background_profile = normalize_background_profile(
        background_profile
    )
    bbox = None
    method = ""
    person_score = None

    mediapipe_result = detect_mediapipe_person_bbox(image)
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
        bbox = detect_face_subject_bbox(image)
        method = "opencv-haar-face-subject"

    if bbox is None:
        bbox = detect_person_bbox(image)
        method = "opencv-hog-person"

    if bbox is None:
        return (
            standard_canvas(image, MASTER_SIZE),
            {
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
            },
        )

    crop_strategy = "subject-bbox"
    crop_box = subject_crop_box(image, bbox)
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
        torso_crop = mediapipe_torso_crop_box(
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
            torso_zoom_out = mediapipe_torso_zoom_out_canvas(
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
        aspect_fill_crop = mediapipe_aspect_fill_crop_box(
            image,
            mediapipe_torso_center,
        )

        if aspect_fill_crop is not None:
            crop_box = aspect_fill_crop
            crop_strategy = "aspect-fill"

    diagnostics = {
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
    }

    if (
        crop_box is not None
        or crop_strategy == "torso-zoom-out"
    ):
        diagnostics["crop_strategy"] = crop_strategy

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
        custom_background = custom_background_master(
            image,
            bbox,
            crop_box,
            crop_strategy,
            zoom_scale,
            mediapipe_torso_center,
            mediapipe_shoulder_center,
            background_profile,
        )

        if custom_background is not None:
            background_master, mask_foreground_ratio = (
                custom_background
            )
            diagnostics["background_profile"] = (
                background_profile
            )
            diagnostics["background_fallback"] = False
            diagnostics["subject_mask_applied"] = True
            diagnostics["mask_method"] = "opencv-grabcut"
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
            standard_canvas(image, MASTER_SIZE),
            diagnostics,
        )

    diagnostics["crop_box"] = list(crop_box)
    cropped = image.crop(crop_box)

    return (
        standard_canvas(cropped, MASTER_SIZE),
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


def process_image(
    source_value: Any,
    background_profile_value: Any = None,
) -> dict[str, Any]:
    source = safe_source_path(source_value)
    background_profile = normalize_background_profile(
        background_profile_value
    )
    job_id = uuid.uuid4().hex

    original_dir = ORIGINAL_ROOT / job_id
    processed_dir = PROCESSED_ROOT / job_id
    original_dir.mkdir(parents=True, exist_ok=False)
    processed_dir.mkdir(parents=True, exist_ok=False)

    original = original_dir / source.name

    try:
        shutil.copy2(source, original)
        original_hash = sha256_file(original)

        image = normalized_image(original)
        width, height = image.size

        master, normalization = normalized_master(
            image,
            background_profile,
        )
        thumb = master.resize(
            THUMB_SIZE,
            Image.Resampling.LANCZOS,
        )

        master_path = processed_dir / "master.webp"
        thumb_path = processed_dir / "thumb.webp"

        save_webp(master, master_path, 92)
        save_webp(thumb, thumb_path, 86)

        return {
            "ok": True,
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
            )
            self.send_json(200, result)
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
        self.send_response(status)
        self.send_header(
            "Content-Type",
            "application/json; charset=utf-8",
        )
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

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
