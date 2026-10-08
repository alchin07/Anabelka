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
    "anabelka_modnet_routing_test",
    PROCESSOR_DIR / "server.py",
)

processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class ModnetRoutingTests(unittest.TestCase):
    def test_complex_background_prefers_modnet(self):
        image = Image.new(
            "RGB",
            (100, 140),
            (120, 80, 60),
        )

        with patch.object(
            processor,
            "uniform_border_background_mask",
            return_value=None,
        ):
            self.assertTrue(
                processor
                .complex_background_prefers_modnet(
                    image
                )
            )

    def test_uniform_background_keeps_grabcut(self):
        image = Image.new(
            "RGB",
            (100, 140),
            (230, 230, 230),
        )

        with patch.object(
            processor,
            "uniform_border_background_mask",
            return_value=object(),
        ):
            self.assertFalse(
                processor
                .complex_background_prefers_modnet(
                    image
                )
            )

    def test_custom_background_accepts_source_path(self):
        with tempfile.TemporaryDirectory() as temp:
            source = (
                Path(temp)
                / "source.jpg"
            )

            image = Image.new(
                "RGB",
                (100, 140),
                (120, 80, 60),
            )

            image.save(source)

            processor.custom_background_master(
                image,
                (20, 10, 60, 120),
                None,
                "subject-bbox",
                None,
                None,
                None,
                processor.BACKGROUND_PROFILE_STUDIO,
                source,
            )

    def test_complex_background_calls_modnet_worker(self):
        image = Image.new(
            "RGB",
            (100, 140),
            (120, 80, 60),
        )

        subject = Image.new(
            "RGBA",
            image.size,
            (180, 120, 90, 255),
        )

        source = Path(
            "/tmp/anabelka-routing-source.jpg"
        )

        metadata = {
            "inference_ms": 1200.0,
            "onnxruntime": "1.29.0",
            "provider": "CPUExecutionProvider",
        }

        with (
            patch.object(
                processor,
                "complex_background_prefers_modnet",
                return_value=True,
            ),
            patch.object(
                processor,
                "run_modnet_worker",
                return_value=(
                    subject,
                    0.3719,
                    metadata,
                ),
            ) as modnet,
            patch.object(
                processor,
                "build_subject_rgba",
                return_value=(
                    subject,
                    0.40,
                ),
            ),
            patch.object(
                processor,
                "cosmetic_cleanup_subject_fringes",
                side_effect=lambda image, subject, bbox: subject,
            ),
            patch.object(
                processor,
                "refine_upper_enclosed_background_gaps",
                side_effect=lambda image, subject, bbox: subject,
            ),
        ):
            processor.custom_background_master(
                image,
                (20, 10, 60, 120),
                None,
                "subject-bbox",
                None,
                None,
                None,
                processor.BACKGROUND_PROFILE_STUDIO,
                source,
            )

        modnet.assert_called_once_with(
            source,
            image,
        )


if __name__ == "__main__":
    unittest.main()
