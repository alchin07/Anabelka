import concurrent.futures
import importlib.util
import inspect
import json
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
from PIL import Image, ImageDraw


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))
spec = importlib.util.spec_from_file_location(
    "anabelka_modnet_selection_diagnostics_test",
    PROCESSOR_DIR / "server.py",
)
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


class SelectionDiagnosticsTests(unittest.TestCase):
    def require_diagnostics(self, function):
        self.assertIn("diagnostics", inspect.signature(function).parameters)

    def comparison_fixture(self):
        core = np.zeros((100, 100), dtype=bool)
        core[15:85, 30:70] = True
        residual = np.zeros_like(core)
        residual[30:85, 70:90] = True
        original = np.where(core | residual, 255, 0).astype(np.uint8)
        candidate = Image.new("RGBA", (100, 100), (220, 160, 140, 0))
        candidate.putalpha(Image.fromarray(np.where(core, 255, 0).astype(np.uint8)))
        evidence = {"source_size": (100, 100), "alpha": original,
                    "residual": residual, "background_like": ~core, "protected": core}
        return candidate, evidence

    def subject_fixture(self):
        image = Image.new("RGB", (100, 140), (145, 107, 78))
        ImageDraw.Draw(image).rectangle((30, 10, 69, 129), fill=(225, 170, 140))
        return image, (30, 10, 40, 120)

    def test_comparison_reports_protected_foreground_loss(self):
        self.require_diagnostics(processor.modnet_fallback_is_better)
        candidate, evidence = self.comparison_fixture()
        alpha = np.asarray(candidate.getchannel("A")).copy()
        alpha[15:85, 30:50] = 0
        candidate.putalpha(Image.fromarray(alpha))
        diagnostics = {}
        self.assertFalse(processor.modnet_fallback_is_better(
            candidate, .14, evidence, diagnostics=diagnostics))
        self.assertEqual(diagnostics["reason"], "protected_foreground_loss")
        self.assertLess(diagnostics["protected_retention"], .98)
        json.dumps(diagnostics, allow_nan=False)

    def test_comparison_reports_confirmed_improvement(self):
        self.require_diagnostics(processor.modnet_fallback_is_better)
        candidate, evidence = self.comparison_fixture()
        diagnostics = {}
        self.assertTrue(processor.modnet_fallback_is_better(
            candidate, .28, evidence, diagnostics=diagnostics))
        self.assertEqual(diagnostics["reason"], "accepted")
        self.assertEqual(diagnostics["residual_after"], 0)
        json.dumps(diagnostics, allow_nan=False)

    def test_comparison_reports_local_detail_loss(self):
        candidate, evidence = self.comparison_fixture()
        alpha = np.asarray(candidate.getchannel("A")).copy()
        alpha[15:20, 30:38] = 0  # Only 40 of 2800 core pixels; global score passes.
        candidate.putalpha(Image.fromarray(alpha))
        diagnostics = {}
        self.assertFalse(processor.modnet_fallback_is_better(
            candidate, .276, evidence, diagnostics=diagnostics))
        self.assertEqual(diagnostics["reason"], "local_foreground_loss")
        self.assertGreaterEqual(diagnostics["protected_retention"], .98)
        self.assertLess(diagnostics["local_retention"], .90)

    def test_material_loss_has_its_own_reason_even_when_coarse_scores_pass(self):
        candidate, evidence = self.comparison_fixture()
        material = np.zeros((100, 100), dtype=bool)
        material[40:44, 42:46] = True
        evidence["material_protected"] = material
        alpha = np.asarray(candidate.getchannel("A")).copy()
        alpha[material] = 0
        candidate.putalpha(Image.fromarray(alpha))
        diagnostics = {}
        self.assertFalse(processor.modnet_fallback_is_better(
            candidate, .2784, evidence, diagnostics=diagnostics))
        self.assertEqual(diagnostics["reason"], "material_foreground_loss")
        self.assertGreaterEqual(diagnostics["protected_retention"], .98)
        self.assertEqual(diagnostics["material_retention"], 0)
        json.dumps(diagnostics, allow_nan=False)

    def test_missing_source_path_has_explicit_skip_without_worker(self):
        self.require_diagnostics(processor.custom_background_master)
        image, bbox = self.subject_fixture()
        diagnostics = {}
        result = processor.custom_background_master(
            image, bbox, None, "subject-bbox", None, None, None,
            "studio-light", source_path=None, diagnostics=diagnostics)
        self.assertIsNotNone(result)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(diagnostics["fallback_status"], "skipped")
        self.assertEqual(diagnostics["fallback_reason"], "no_source_path")
        self.assertEqual(diagnostics["modnet_worker_calls"], 0)
        self.assertEqual(diagnostics["worker_error"], "")

    def test_primary_worker_timeout_is_recorded_and_never_retried(self):
        self.require_diagnostics(processor.custom_background_master)
        image, bbox = self.subject_fixture()
        diagnostics = {}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source.png"
            image.save(source)
            with (
                patch.object(processor, "complex_background_prefers_modnet", return_value=True),
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor.subprocess, "run", side_effect=subprocess.TimeoutExpired("worker", 45)),
            ):
                result = processor.custom_background_master(
                    image, bbox, None, "subject-bbox", None, None, None,
                    "studio-light", source, diagnostics=diagnostics)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(diagnostics["primary_route"], "modnet")
        self.assertEqual(diagnostics["modnet_worker_calls"], 1)
        self.assertEqual(diagnostics["worker_error"], "worker-timeout")
        self.assertEqual(diagnostics["fallback_status"], "worker_failed")
        self.assertEqual(diagnostics["fallback_reason"], "primary_worker_failed")
        json.dumps(diagnostics, allow_nan=False)

    def test_probe_failure_is_reported_without_discarding_grabcut(self):
        image, bbox = self.subject_fixture()
        baseline = processor.custom_background_master(
            image, bbox, None, "subject-bbox", None, None, None, "studio-light")
        diagnostics = {}
        with (
            patch.object(processor, "complex_background_prefers_modnet", return_value=False),
            patch.object(processor, "grabcut_fallback_evidence", side_effect=RuntimeError("probe failed")),
        ):
            result = processor.custom_background_master(
                image, bbox, None, "subject-bbox", None, None, None,
                "studio-light", Path("/tmp/source.png"), diagnostics=diagnostics)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))
        self.assertEqual(diagnostics["fallback_reason"], "probe_error")
        self.assertEqual(diagnostics["fallback_status"], "error")
        self.assertEqual(diagnostics["modnet_worker_calls"], 0)
        self.assertIn("probe failed", diagnostics["worker_error"])

    def test_worker_diagnostics_do_not_change_after_another_request(self):
        self.require_diagnostics(processor.run_modnet_worker)
        image, _ = self.subject_fixture()
        failed, succeeded = {}, {}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source.png"
            image.save(source)
            with (
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor.subprocess, "run", side_effect=subprocess.TimeoutExpired("worker", 45)),
            ):
                self.assertIsNone(processor.run_modnet_worker(source, image, diagnostics=failed))

            def inference(command, **kwargs):
                alpha = Image.new("L", image.size, 0)
                ImageDraw.Draw(alpha).rectangle((30, 10, 69, 129), fill=255)
                alpha.save(Path(command[command.index("--alpha") + 1]), "PNG")
                return subprocess.CompletedProcess(
                    command, 0, stdout=json.dumps({"ok": True, "foreground_ratio": 4800 / 14000,
                                                  "inference_ms": 5., "provider": "CPUExecutionProvider"}),
                    stderr="")

            with (
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor.subprocess, "run", side_effect=inference),
            ):
                self.assertIsNotNone(processor.run_modnet_worker(source, image, diagnostics=succeeded))
        self.assertEqual(failed["worker_error"], "worker-timeout")
        self.assertEqual(succeeded["worker_error"], "")
        self.assertEqual(succeeded["status"], "succeeded")
        json.dumps({"failed": failed, "succeeded": succeeded}, allow_nan=False)

    def test_concurrent_worker_errors_remain_request_local(self):
        image, _ = self.subject_fixture()
        barrier = threading.Barrier(2)
        failed, succeeded = {}, {}
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            sources = [root / name for name in ("failed.png", "succeeded.png")]
            for source in sources:
                image.save(source)

            def inference(command, **kwargs):
                source = Path(command[command.index("--source") + 1])
                barrier.wait(timeout=10)
                if source.name == "failed.png":
                    raise subprocess.TimeoutExpired(command, 45)
                alpha = Image.new("L", image.size, 0)
                ImageDraw.Draw(alpha).rectangle((30, 10, 69, 129), fill=255)
                alpha.save(Path(command[command.index("--alpha") + 1]), "PNG")
                return subprocess.CompletedProcess(command, 0, stdout=json.dumps({
                    "ok": True, "foreground_ratio": 4800 / 14000,
                    "inference_ms": 5., "provider": "CPUExecutionProvider",
                }), stderr="")

            with (
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor.subprocess, "run", side_effect=inference),
                concurrent.futures.ThreadPoolExecutor(max_workers=2) as executor,
            ):
                failing = executor.submit(processor.run_modnet_worker, sources[0], image, diagnostics=failed)
                successful = executor.submit(processor.run_modnet_worker, sources[1], image, diagnostics=succeeded)
                self.assertIsNone(failing.result(timeout=15))
                self.assertIsNotNone(successful.result(timeout=15))
        self.assertEqual(failed["worker_error"], "worker-timeout")
        self.assertEqual(succeeded["worker_error"], "")
        json.dumps({"failed": failed, "succeeded": succeeded}, allow_nan=False)

    def test_valid_worker_alpha_with_nonfinite_timing_has_json_safe_diagnostics(self):
        image, _ = self.subject_fixture()
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = root / "source.png"
            image.save(source)
            for reported_ms in (float("nan"), float("inf"), float("-inf")):
                with self.subTest(reported_ms=reported_ms):
                    def inference(command, **kwargs):
                        alpha = Image.new("L", image.size, 0)
                        ImageDraw.Draw(alpha).rectangle((30, 10, 69, 129), fill=255)
                        alpha.save(Path(command[command.index("--alpha") + 1]), "PNG")
                        return subprocess.CompletedProcess(command, 0, stdout=json.dumps({
                            "ok": True, "foreground_ratio": 4800 / 14000,
                            "inference_ms": reported_ms, "provider": "CPUExecutionProvider",
                        }), stderr="")

                    diagnostics = {}
                    with (
                        patch.object(processor, "modnet_worker_ready", return_value=True),
                        patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                        patch.object(processor.subprocess, "run", side_effect=inference),
                    ):
                        result = processor.run_modnet_worker(source, image, diagnostics=diagnostics)
                    self.assertIsNotNone(result)
                    self.assertIsNone(diagnostics["inference_ms"])
                    self.assertEqual(diagnostics["status"], "succeeded")
                    self.assertFalse(np.isfinite(result[2]["inference_ms"]))
                    json.dumps(diagnostics, allow_nan=False)


if __name__ == "__main__":
    unittest.main()
