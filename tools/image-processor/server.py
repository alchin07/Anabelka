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
from PIL import Image, ImageOps, UnidentifiedImageError

from mp_persondet import MPPersonDet


HOST = "127.0.0.1"
VERSION = "0.5"
PROFILE = "model-normalize-v2"
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
PERSON_DETECT_MAX_EDGE = 900
FACE_DETECT_MAX_EDGE = 1000
FACE_MIN_AREA_RATIO = 0.0015
PERSON_MIN_AREA_RATIO = 0.06
PERSON_MAX_CROP_AREA_RATIO = 0.92
PERSON_SIDE_MARGIN = 0.18
PERSON_TOP_MARGIN = 0.08
PERSON_BOTTOM_MARGIN = 0.10

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
) -> tuple[tuple[int, int, int, int], float] | None:
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

    return (
        (
            left,
            top,
            right - left,
            bottom - top,
        ),
        score,
    )


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
    cascade_path = (
        Path(cv2.data.haarcascades)
        / "haarcascade_frontalface_default.xml"
    )
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
) -> tuple[Image.Image, dict[str, Any]]:
    bbox = None
    method = ""
    person_score = None

    mediapipe_result = detect_mediapipe_person_bbox(image)

    if mediapipe_result is not None:
        bbox, person_score = mediapipe_result
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
            },
        )

    crop_box = subject_crop_box(image, bbox)
    diagnostics = {
        "subject_detected": True,
        "crop_applied": crop_box is not None,
        "method": (
            method
            if crop_box is not None
            else method + "-no-crop"
        ),
        "person_bbox": list(bbox),
    }

    if person_score is not None:
        diagnostics["person_score"] = round(
            max(0.0, min(1.0, person_score)),
            4,
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


def process_image(source_value: Any) -> dict[str, Any]:
    source = safe_source_path(source_value)
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

        master, normalization = normalized_master(image)
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
                "master_size": {
                    "width": MASTER_SIZE[0],
                    "height": MASTER_SIZE[1],
                },
                "thumb_size": {
                    "width": THUMB_SIZE[0],
                    "height": THUMB_SIZE[1],
                },
                "subject_detector": (
                    "mediapipe-persondet+haar-face+hog-fallback"
                    if person_model_ready()
                    else "haar-face+hog-person-fallback"
                ),
                "person_model_ready": person_model_ready(),
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

            result = process_image(payload.get("source"))
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
