
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image


PROCESSOR_DIR = (
    Path(__file__).resolve().parents[1]
    / "tools"
    / "image-processor"
)

sys.path.insert(
    0,
    str(PROCESSOR_DIR),
)

spec = importlib.util.spec_from_file_location(
    "anabelka_source_path_test",
    PROCESSOR_DIR / "server.py",
)

processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class ModnetSourcePathTests(unittest.TestCase):
    def test_normalized_master_forwards_source_path(self):
        image = Image.new(
            "RGB",
            (300, 450),
            "white",
        )

        source = Path(
            "/tmp/anabelka-source-path.jpg"
        )

        output = Image.new(
            "RGB",
            processor.MASTER_SIZE,
            "white",
        )

        with (
            patch.object(
                processor,
                "detect_mediapipe_person_bbox",
                return_value=None,
            ),
            patch.object(
                processor,
                "detect_face_subject_bbox",
                return_value=(60, 40, 180, 360),
            ),
            patch.object(
                processor,
                "detect_person_bbox",
                return_value=None,
            ),
            patch.object(
                processor,
                "subject_crop_box",
                return_value=None,
            ),
            patch.object(
                processor,
                "custom_background_master",
                return_value=(
                    output,
                    0.40,
                    "modnet",
                ),
            ) as custom,
        ):
            _, diagnostics = processor.normalized_master(
                image,
                processor.BACKGROUND_PROFILE_STUDIO,
                source_path=source,
            )

        self.assertEqual(
            custom.call_count,
            1,
        )

        self.assertEqual(
            diagnostics["mask_method"],
            "modnet",
        )

        args = custom.call_args.args

        self.assertEqual(
            args[-1],
            source,
        )


if __name__ == "__main__":
    unittest.main()
