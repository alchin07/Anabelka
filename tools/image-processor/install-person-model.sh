#!/data/data/com.termux/files/usr/bin/bash
set -eu

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MODEL_DIR="$PROJECT_ROOT/storage/image-processor/models"
MODEL_NAME="person_detection_mediapipe_2023mar.onnx"
MODEL_PATH="$MODEL_DIR/$MODEL_NAME"
TMP_PATH="$MODEL_PATH.tmp"
MODEL_URL="https://github.com/opencv/opencv_zoo/raw/main/models/person_detection_mediapipe/$MODEL_NAME"
EXPECTED_SIZE="11990159"
EXPECTED_SHA256="47fd5599d6fa17608f03e0eb0ae230baa6e597d7e8a2c8199fe00abea55a701f"

mkdir -p "$MODEL_DIR"
rm -f "$TMP_PATH"

echo "Downloading OpenCV Zoo MediaPipe person detector..."
curl -fL --retry 3 --retry-delay 2 \
  "$MODEL_URL" \
  -o "$TMP_PATH"

ACTUAL_SIZE="$(wc -c < "$TMP_PATH" | tr -d '[:space:]')"

if [ "$ACTUAL_SIZE" != "$EXPECTED_SIZE" ]; then
  echo "Unexpected model size: $ACTUAL_SIZE bytes" >&2
  rm -f "$TMP_PATH"
  exit 1
fi

ACTUAL_SHA256="$(
  python - "$TMP_PATH" <<'PY'
import hashlib
import sys

path = sys.argv[1]
digest = hashlib.sha256()
with open(path, "rb") as handle:
    for chunk in iter(lambda: handle.read(1024 * 1024), b""):
        digest.update(chunk)
print(digest.hexdigest())
PY
)"

if [ "$ACTUAL_SHA256" != "$EXPECTED_SHA256" ]; then
  echo "Model SHA-256 mismatch." >&2
  rm -f "$TMP_PATH"
  exit 1
fi

mv "$TMP_PATH" "$MODEL_PATH"

echo "Person detector installed:"
echo "$MODEL_PATH"
