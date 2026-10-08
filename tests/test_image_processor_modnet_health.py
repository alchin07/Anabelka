
import importlib.util
import sys
import unittest
from pathlib import Path
from unittest.mock import patch


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
    "anabelka_modnet_health_test",
    PROCESSOR_DIR / "server.py",
)

processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class ModnetHealthTests(unittest.TestCase):
    def test_health_exposes_modnet_worker(self):
        handler = processor.Handler.__new__(
            processor.Handler
        )

        handler.path = "/health"

        captured = {}

        handler.send_json = (
            lambda status, payload:
            captured.update(
                {
                    "status": status,
                    "payload": payload,
                }
            )
        )

        with (
            patch.object(
                processor,
                "person_model_ready",
                return_value=True,
            ),
            patch.object(
                processor,
                "find_face_cascade_path",
                return_value=None,
            ),
            patch.object(
                processor,
                "modnet_worker_ready",
                return_value=True,
            ),
        ):
            processor.Handler.do_GET(
                handler
            )

        self.assertEqual(
            captured["status"],
            200,
        )

        payload = captured["payload"]

        self.assertIs(
            payload["modnet_worker_ready"],
            True,
        )

        self.assertEqual(
            payload["modnet_execution"],
            "separate-process",
        )

        self.assertIn(
            "modnet_worker_error",
            payload,
        )

        self.assertIn(
            "modnet_model_path",
            payload,
        )


if __name__ == "__main__":
    unittest.main()
