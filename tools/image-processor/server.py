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
import PIL
from PIL import Image, ImageOps


HOST = "127.0.0.1"
PORT = int(os.environ.get("ANABELKA_IMAGE_PROCESSOR_PORT", "8765"))
MAX_JSON_BYTES = 64 * 1024
MAX_SOURCE_BYTES = 40 * 1024 * 1024

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SOURCE_ROOT = (PROJECT_ROOT / "uploads" / "products").resolve()
WORK_ROOT = (PROJECT_ROOT / "storage" / "image-processor").resolve()
ORIGINAL_ROOT = WORK_ROOT / "originals"
PROCESSED_ROOT = WORK_ROOT / "processed"

MASTER_MAX_EDGE = 2400
THUMB_MAX_EDGE = 480


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


def resized_copy(image: Image.Image, max_edge: int) -> Image.Image:
    result = image.copy()
    result.thumbnail(
        (max_edge, max_edge),
        Image.Resampling.LANCZOS,
    )
    return result


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

        master = resized_copy(image, MASTER_MAX_EDGE)
        thumb = resized_copy(image, THUMB_MAX_EDGE)

        master_path = processed_dir / "master.webp"
        thumb_path = processed_dir / "thumb.webp"

        save_webp(master, master_path, 92)
        save_webp(thumb, thumb_path, 86)

        return {
            "ok": True,
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
    server_version = "AnabelkaImageProcessor/0.1"

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
                "version": "0.1",
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
        except (ValueError, OSError, Image.UnidentifiedImageError) as error:
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
