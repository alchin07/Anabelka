
import importlib.util
import sys
import tempfile
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
    "anabelka_process_path_test",
    PROCESSOR_DIR / "server.py",
)

processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class ProcessImageSourcePathTests(unittest.TestCase):
    def test_process_image_forwards_immutable_original(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)

            source = root / "source.jpg"

            image = Image.new(
                "RGB",
                (120, 180),
                "white",
            )
            image.save(source)

            master = Image.new(
                "RGB",
                processor.MASTER_SIZE,
                "white",
            )

            diagnostics = {
                "background_profile":
                    processor.BACKGROUND_PROFILE_STUDIO,
            }

            with (
                patch.object(
                    processor,
                    "safe_source_path",
                    return_value=source,
                ),
                patch.object(
                    processor,
                    "ORIGINAL_ROOT",
                    root / "originals",
                ),
                patch.object(
                    processor,
                    "PROCESSED_ROOT",
                    root / "processed",
                ),
                patch.object(
                    processor,
                    "normalized_image",
                    return_value=image,
                ),
                patch.object(
                    processor,
                    "project_relative",
                    side_effect=lambda path: str(path),
                ),
                patch.object(
                    processor,
                    "normalized_master",
                    return_value=(
                        master,
                        diagnostics,
                    ),
                ) as normalized,
            ):
                processor.process_image(
                    "ignored.jpg",
                    processor.BACKGROUND_PROFILE_STUDIO,
                )

            call = normalized.call_args

            self.assertIn(
                "source_path",
                call.kwargs,
                "process_image does not forward source_path",
            )

            forwarded = call.kwargs[
                "source_path"
            ]

            self.assertNotEqual(
                forwarded,
                source,
            )

            self.assertEqual(
                forwarded.name,
                source.name,
            )

            self.assertTrue(
                forwarded.is_file()
            )

            self.assertEqual(
                forwarded.read_bytes(),
                source.read_bytes(),
            )


if __name__ == "__main__":
    unittest.main()
