
import importlib.util
import json
import subprocess
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

sys.path.insert(0, str(PROCESSOR_DIR))

spec = importlib.util.spec_from_file_location(
    "anabelka_server_bridge_test",
    PROCESSOR_DIR / "server.py",
)

processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class ModnetBridgeTests(unittest.TestCase):
    def require_bridge(self):
        if not hasattr(
            processor,
            "run_modnet_worker",
        ):
            self.fail(
                "run_modnet_worker is missing"
            )

    def test_success_returns_rgba_and_metadata(self):
        self.require_bridge()

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / "source.jpg"

            image = Image.new(
                "RGB",
                (64, 64),
                (170, 120, 90),
            )
            image.save(source)

            def fake_run(command, **kwargs):
                alpha = Path(
                    command[
                        command.index("--alpha") + 1
                    ]
                )

                alpha.parent.mkdir(
                    parents=True,
                    exist_ok=True,
                )

                Image.new(
                    "L",
                    (64, 64),
                    128,
                ).save(alpha)

                payload = {
                    "ok": True,
                    "foreground_ratio": 0.3719,
                    "inference_ms": 1228.3,
                    "onnxruntime": "1.29.0",
                    "provider": "CPUExecutionProvider",
                }

                return subprocess.CompletedProcess(
                    command,
                    0,
                    stdout=json.dumps(payload),
                    stderr="",
                )

            with (
                patch.object(
                    processor,
                    "modnet_worker_ready",
                    return_value=True,
                ),
                patch.object(
                    processor,
                    "MODNET_WORKER_ROOT",
                    root / "worker-output",
                ),
                patch.object(
                    processor.subprocess,
                    "run",
                    side_effect=fake_run,
                ),
            ):
                result = (
                    processor.run_modnet_worker(
                        source,
                        image,
                    )
                )

            self.assertIsNotNone(result)

            subject, ratio, metadata = result

            self.assertEqual(
                subject.mode,
                "RGBA",
            )
            self.assertEqual(
                subject.size,
                (64, 64),
            )
            self.assertAlmostEqual(
                ratio,
                0.3719,
                places=4,
            )
            self.assertEqual(
                metadata["provider"],
                "CPUExecutionProvider",
            )
            self.assertEqual(
                subject.getchannel("A").getextrema(),
                (128, 128),
            )

    def test_timeout_is_safe_fallback(self):
        self.require_bridge()

        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / "source.jpg"

            image = Image.new(
                "RGB",
                (64, 64),
                "white",
            )
            image.save(source)

            with (
                patch.object(
                    processor,
                    "modnet_worker_ready",
                    return_value=True,
                ),
                patch.object(
                    processor,
                    "MODNET_WORKER_ROOT",
                    root / "worker-output",
                ),
                patch.object(
                    processor.subprocess,
                    "run",
                    side_effect=subprocess.TimeoutExpired(
                        cmd="modnet_worker",
                        timeout=45,
                    ),
                ),
            ):
                result = (
                    processor.run_modnet_worker(
                        source,
                        image,
                    )
                )

            self.assertIsNone(result)
            self.assertEqual(
                processor._modnet_worker_error,
                "worker-timeout",
            )


if __name__ == "__main__":
    unittest.main()
