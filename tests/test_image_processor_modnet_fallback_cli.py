import contextlib
import importlib.util
import io
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image, ImageDraw


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location(
    "anabelka_modnet_fallback_cli_test",
    PROCESSOR_DIR / "check_modnet_fallback.py",
)
cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cli)
processor = cli.processor


class ModnetFallbackCliTests(unittest.TestCase):
    def compare_fixture(self, timeout=False, foreign_error=False):
        # Exercise the real CLI, GrabCut, bridge, geometry and file writes.
        # Only detector/inference dependencies are replaced with fixed inputs.
        image = Image.new("RGB", (120, 160), (145, 107, 78))
        draw = ImageDraw.Draw(image)
        draw.rectangle((40, 30, 79, 149), fill=(225, 170, 140))
        draw.rectangle((45, 10, 74, 30), fill=(50, 25, 20))
        draw.rectangle((30, 50, 39, 109), fill=(225, 170, 140))
        draw.rectangle((40, 70, 79, 99), fill=(245, 240, 230))
        for x in range(42, 78, 3):
            for y in range(72, 98, 3):
                draw.point((x, y), fill=(70, 45, 55))
        alpha = Image.new("L", image.size, 0)
        draw_alpha = ImageDraw.Draw(alpha)
        for rectangle in ((40, 30, 79, 149), (45, 10, 74, 30), (30, 50, 39, 109)):
            draw_alpha.rectangle(rectangle, fill=255)
        detection = ((30, 10, 50, 140), .95, (60., 80.), (60., 40.),
                     (60., 120.), 80., 25.)

        def detector(_image):
            if foreign_error:
                # Simulate an unrelated request changing legacy health state.
                processor._modnet_worker_error = "another-request-error"
            return detection

        def inference(command, **kwargs):
            if timeout:
                raise subprocess.TimeoutExpired(command, processor.MODNET_WORKER_TIMEOUT)
            output = Path(command[command.index("--alpha") + 1])
            alpha.save(output, "PNG")
            return subprocess.CompletedProcess(
                command,
                0,
                stdout=json.dumps({"ok": True, "foreground_ratio": float(
                    processor.np.mean(processor.np.asarray(alpha) >= 128)),
                                   "inference_ms": 5., "provider": "CPUExecutionProvider",
                                   "onnxruntime": "test-inference"}),
                stderr="",
            )

        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            uploads = root / "uploads" / "products"
            uploads.mkdir(parents=True)
            source = uploads / "control.png"
            image.save(source, "PNG")
            original = source.read_bytes()
            captured = io.StringIO()
            with (
                patch.object(processor, "PROJECT_ROOT", root),
                patch.object(processor, "SOURCE_ROOT", uploads),
                patch.object(processor, "WORK_ROOT", root / "storage" / "image-processor"),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor, "_modnet_worker_error", "stale-health-error"),
                patch.object(processor, "detect_mediapipe_person_bbox", side_effect=detector),
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor.subprocess, "run", side_effect=inference),
                contextlib.redirect_stdout(captured),
            ):
                result = cli.main([source.name]) == 0
            reports = list((root / "storage" / "image-processor" / "modnet-fallback-check").glob(
                "*/control/diagnostics.json"))
            self.assertEqual(len(reports), 1)
            output = reports[0].parent
            report = json.loads((output / "diagnostics.json").read_text(encoding="utf-8"))
            sizes = {}
            for preview in output.glob("*.webp"):
                with Image.open(preview) as opened:
                    sizes[preview.name] = opened.size
            with Image.open(output / "comparison.jpg") as opened:
                comparison_size = opened.size
            return result, report, captured.getvalue(), original, source.read_bytes(), sizes, comparison_size

    def test_cli_keeps_probe_reasons_counts_and_real_worker_calls(self):
        result, report, output, *_ = self.compare_fixture()
        self.assertTrue(result)
        automatic = report["stages"]["auto"]
        self.assertIn("mask_selection", automatic["normalization"])
        selection = automatic["normalization"]["mask_selection"]
        self.assertEqual(automatic["normalization"]["mask_method"], "opencv-grabcut")
        self.assertEqual(selection["fallback_status"], "skipped")
        self.assertTrue(selection["fallback_reason"])
        self.assertIn("counts", selection["probe"])
        self.assertTrue(selection["probe"]["counts"])
        for stage, expected_count in (("original", 0), ("grabcut", 0), ("modnet", 1), ("auto", 0)):
            record = report["stages"][stage]
            self.assertEqual(record["modnet_worker_calls"], expected_count)
            if "mask_selection" in record["normalization"]:
                self.assertEqual(record["normalization"]["mask_selection"]["modnet_worker_calls"], expected_count)
        self.assertEqual(report["stages"]["modnet"]["normalization"]["mask_method"], "modnet")
        self.assertIn("FALLBACK: ", output)
        self.assertIn("PROBE: stage=", output)
        self.assertIn(" | minimum_required=", output)
        self.assertIn("PROBE_COUNTS: ", output)

    def test_cli_timeout_diagnostic_is_local_to_its_stage(self):
        result, report, output, *_ = self.compare_fixture(timeout=True, foreign_error=True)
        self.assertTrue(result)  # A usable GrabCut result is still a success.
        forced = report["stages"]["modnet"]
        self.assertEqual(forced["normalization"]["mask_method"], "opencv-grabcut")
        self.assertEqual(forced["worker_error"], "worker-timeout")
        for stage in ("original", "grabcut", "auto"):
            self.assertEqual(report["stages"][stage]["worker_error"], "", stage)
        self.assertNotIn("another-request-error", json.dumps(report))
        self.assertIn("WORKER_ERROR: worker-timeout", output)

    def test_cli_writes_all_previews_without_changing_source(self):
        result, report, _, before, after, sizes, comparison_size = self.compare_fixture()
        self.assertTrue(result)
        self.assertEqual(before, after)
        self.assertTrue(report["source_unchanged"])
        self.assertEqual(report["source_sha256_before"], report["source_sha256_after"])
        self.assertEqual(set(sizes), {"original.webp", "grabcut.webp", "modnet.webp", "auto.webp"})
        self.assertTrue(all(size == (1200, 1800) for size in sizes.values()))
        self.assertEqual(comparison_size, (1280, 544))


if __name__ == "__main__":
    unittest.main()
