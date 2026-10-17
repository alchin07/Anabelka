import importlib.util
import io
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import numpy as np
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


class GrabcutFallbackTests(unittest.TestCase):
    def fixture(self, spill=True):
        # A shadowed brown backdrop crosses the frame edge. The old strict
        # colour cleanup misses it; the body, hair, arms and lace are distinct.
        pixels = np.full((160, 120, 3), (145, 107, 78), dtype=np.uint8)
        pixels[50:140, 80:] = (115, 83, 60)
        good = np.zeros((160, 120), dtype=np.uint8)
        good[20:150, 40:80] = 255
        good[10:30, 45:75] = 255
        good[50:110, 30:40] = 255
        pixels[good > 0] = (220, 160, 140)
        pixels[10:30, 45:75] = (50, 25, 20)
        pixels[70:100, 45:75] = (245, 240, 230)
        pixels[72:98:3, 45:75:3] = (70, 45, 55)
        image = Image.fromarray(pixels)

        def subject(alpha):
            rgba = image.convert("RGBA")
            rgba.putalpha(Image.fromarray(alpha))
            return rgba

        alpha = good.copy()
        if spill:
            alpha[50:140, 80:110] = 255
        return image, subject(alpha), subject(good), (30, 10, 50, 140)

    def render(self, image, grabcut, candidate, bbox, profile="studio-light",
               source=Path("/tmp/fallback-source.jpg"), **geometry):
        ratio = float(np.mean(np.asarray(grabcut.getchannel("A")) >= 128))
        with (
            patch.object(processor, "build_subject_rgba", return_value=(grabcut, ratio)),
            patch.object(processor, "run_modnet_worker", **(
                {"side_effect": candidate} if callable(candidate) or isinstance(candidate, Exception)
                else {"return_value": candidate})) as worker,
        ):
            result = processor.custom_background_master(
                image, bbox, geometry.get("crop_box"),
                geometry.get("crop_strategy", "subject-bbox"),
                geometry.get("zoom_scale"), geometry.get("torso_center"),
                geometry.get("shoulder_center"), profile, source,
                **({"diagnostics": geometry["diagnostics"]} if "diagnostics" in geometry else {}),
            )
        return result, worker.call_count

    def candidate(self, subject, ratio=None):
        if ratio is None:
            ratio = float(np.mean(np.asarray(subject.getchannel("A")) >= 128))
        return subject, ratio, {"inference_ms": 100, "provider": "CPUExecutionProvider"}

    def noisy_fixture(self, jpeg=False, spill=True, fabric=False):
        image, grabcut, good, bbox = self.fixture(spill=spill)
        size = (384, 512)
        image = image.resize(size, Image.Resampling.NEAREST)
        grabcut = grabcut.resize(size, Image.Resampling.NEAREST)
        good = good.resize(size, Image.Resampling.NEAREST)
        bbox = tuple(round(value * 3.2) for value in bbox)
        pixels = np.asarray(image).astype(np.float32)
        background = np.asarray(good.getchannel("A")) == 0
        rows, columns = np.indices(background.shape)
        # Small tonal ripples/gradient, common in supplier JPEG backdrops.
        # Each source background band reaches a transparent frame edge.
        noise = 2 * np.sin(rows / 6) + 2 * columns / size[0]
        pixels[background] += noise[background, None]
        if fabric:
            pixels[230:290, 220:256] = (115, 83, 60)
            pixels[235:285:12, 220:256:12] = (119, 85, 62)
        image = Image.fromarray(np.clip(np.rint(pixels), 0, 255).astype(np.uint8))
        if jpeg:
            encoded = io.BytesIO()
            image.save(encoded, "JPEG", quality=95, subsampling=0)
            encoded.seek(0)
            with Image.open(encoded) as opened:
                image = opened.convert("RGB")
        for subject in (grabcut, good):
            alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(alpha)
        return image, grabcut, good, bbox

    def test_background_noise_does_not_hide_spill_or_reject_good_modnet(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                image, grabcut, good, bbox = self.noisy_fixture(jpeg=jpeg)
                self.assertFalse(processor.complex_background_prefers_modnet(image))
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                self.assertIsNotNone(evidence)
                self.assertFalse(np.any(evidence["protected"] & evidence["residual"]))
                result, calls = self.render(image, grabcut, self.candidate(good), bbox)
                self.assertEqual(result[2], "modnet")
                self.assertEqual(calls, 1)

    def test_good_grabcut_with_background_noise_keeps_output_without_worker(self):
        image, grabcut, good, bbox = self.noisy_fixture(jpeg=True, spill=False)
        self.assertFalse(processor.complex_background_prefers_modnet(image))
        baseline, _ = self.render(image, grabcut, None, bbox, source=None)
        result, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_noisy_backdrop_cannot_license_loss_of_model(self):
        image, grabcut, good, bbox = self.noisy_fixture(jpeg=True)
        damaged = good.copy()
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[32:96, 144:240] = 0
        damaged.putalpha(Image.fromarray(alpha))
        selection = {}
        result, calls = self.render(image, grabcut, self.candidate(damaged), bbox,
                                    diagnostics=selection)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)
        self.assertFalse(selection["comparison"]["accepted"])

    def test_low_contrast_seam_keeps_surrounding_fabric(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                image, grabcut, good, bbox = self.noisy_fixture(fabric=True)
                pixels = np.asarray(image).copy()
                pixels[230:290, 220:256] = (115, 83, 60)
                pixels[249:251, 220:256] = (119, 85, 62)
                image = Image.fromarray(pixels)
                if jpeg:
                    encoded = io.BytesIO()
                    image.save(encoded, "JPEG", quality=95, subsampling=0)
                    encoded.seek(0)
                    with Image.open(encoded) as opened:
                        image = opened.convert("RGB")
                for subject in (grabcut, good):
                    alpha = subject.getchannel("A")
                    subject.paste(image, (0, 0))
                    subject.putalpha(alpha)
                preserved, calls = self.render(image, grabcut, self.candidate(good), bbox)
                self.assertEqual(preserved[2], "modnet")
                self.assertEqual(calls, 1)
                alpha = np.asarray(good.getchannel("A")).copy()
                alpha[230:247, 220:256] = 0
                alpha[253:290, 220:256] = 0
                damaged = good.copy()
                damaged.putalpha(Image.fromarray(alpha))
                result, calls = self.render(image, grabcut, self.candidate(damaged), bbox)
                self.assertEqual(result[2], "opencv-grabcut")
                self.assertEqual(calls, 1)

    def test_noise_probe_diagnostics_preserve_stage_counts_and_decision(self):
        image, grabcut, good, bbox = self.noisy_fixture(jpeg=True)
        selection = {}
        result, calls = self.render(image, grabcut, self.candidate(good), bbox,
                                    diagnostics=selection)
        self.assertEqual(result[2], "modnet")
        self.assertEqual(calls, 1)
        self.assertEqual(selection["fallback_reason"], "accepted")
        probe = selection["probe"]
        self.assertEqual(probe["stage"], "ready")
        self.assertEqual(probe["reason"], "residual_confirmed")
        counts = probe["counts"]
        for stage in ("before_continuity", "after_continuity", "after_texture", "after_detail_filter"):
            self.assertGreaterEqual(counts[stage], probe["minimum_required"])
        self.assertEqual(counts["removed_by_texture"], counts["after_continuity"] - counts["after_texture"])
        self.assertEqual(counts["protected_residual_overlap"], 0)
        self.assertGreater(probe["texture_threshold"], probe["texture_background_p90"])

    def exterior_fabric_fixture(self, jpeg=False, seam_colour=None):
        image, grabcut, good, bbox = self.noisy_fixture()
        pixels = np.asarray(image).copy()
        pixels[230:290, 256:288] = (115, 83, 60)
        if seam_colour is None:
            pixels[235:285:12, 256:288:12] = (119, 85, 62)
        else:
            pixels[245, 256:288] = seam_colour
        image = Image.fromarray(pixels)
        if jpeg:
            encoded = io.BytesIO()
            image.save(encoded, "JPEG", quality=95, subsampling=0)
            encoded.seek(0)
            with Image.open(encoded) as opened:
                image = opened.convert("RGB")
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[230:290, 256:288] = 255
        good.putalpha(Image.fromarray(alpha))
        for subject in (grabcut, good):
            alpha = subject.getchannel("A")
            subject.paste(image, (0, 0))
            subject.putalpha(alpha)
        return image, grabcut, good, bbox

    def check_exterior_material(self, jpeg, seam_colour=None):
        image, grabcut, good, bbox = self.exterior_fabric_fixture(jpeg, seam_colour)
        preserved, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(preserved[2], "modnet")
        self.assertEqual(calls, 1)
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[230:290, 256:288] = 0
        damaged = good.copy()
        damaged.putalpha(Image.fromarray(alpha))
        result, calls = self.render(image, grabcut, self.candidate(damaged), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)

    def test_weak_textured_garment_outside_body_core_is_preserved(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                self.check_exterior_material(jpeg)

    def test_long_seam_outside_body_core_is_preserved(self):
        for jpeg in (False, True):
            for colour in ((119, 85, 62), (130, 92, 66)):
                with self.subTest(jpeg=jpeg, colour=colour):
                    self.check_exterior_material(jpeg, colour)

    def test_weak_fabric_structure_survives_backdrop_noise_calibration(self):
        for jpeg in (False, True):
            with self.subTest(jpeg=jpeg):
                self.check_weak_fabric_structure(jpeg)

    def check_weak_fabric_structure(self, jpeg):
        image, grabcut, good, bbox = self.noisy_fixture(jpeg=jpeg, fabric=True)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        preserved, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(preserved[2], "modnet")
        self.assertEqual(calls, 1)
        pixels = np.asarray(image)
        matching_fabric = np.zeros((512, 384), dtype=bool)
        matching_fabric[230:290, 220:256] = True
        if jpeg:
            # Preserve the stitches and their compressed pixel neighbourhoods,
            # lose the material between them. Quality is not a seed count.
            for row in range(235, 285, 12):
                for column in range(220, 256, 12):
                    matching_fabric[row - 1:row + 2, column - 1:column + 2] = False
        else:
            matching_fabric &= np.all(pixels == (115, 83, 60), axis=2)
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[matching_fabric] = 0  # Retain stitches but lose their fabric.
        damaged = good.copy()
        damaged.putalpha(Image.fromarray(alpha))
        selection = {}
        result, calls = self.render(image, grabcut, self.candidate(damaged), bbox,
                                    diagnostics=selection)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)
        self.assertIn(selection["comparison"]["reason"], {
            "protected_foreground_loss", "local_foreground_loss", "material_foreground_loss"})

    def test_good_grabcut_does_not_run_modnet(self):
        image, grabcut, good, bbox = self.fixture(spill=False)
        for profile in ("studio-light", "anabelka-brand"):
            with self.subTest(profile=profile):
                baseline, _ = self.render(image, grabcut, None, bbox, profile, source=None)
                result, calls = self.render(image, grabcut, self.candidate(good), bbox, profile)
                self.assertEqual(result[2], "opencv-grabcut")
                self.assertEqual(calls, 0)
                np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_existing_cleanup_resolves_spill_without_modnet(self):
        image, _, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        pixels[50:140, 80:] = (145, 107, 78)
        image = Image.fromarray(pixels)
        # This cleanup is inside build_subject_rgba(), so exercise real
        # GrabCut rather than supply a mask from before its broad-spill pass.
        subject_result = processor.build_subject_rgba(image, bbox)
        self.assertIsNotNone(subject_result)
        grabcut = subject_result[0]
        result, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)

    def test_shadow_spill_triggers_comparison_and_better_modnet_is_used(self):
        image, grabcut, good, bbox = self.fixture()
        self.assertFalse(processor.complex_background_prefers_modnet(image))
        for profile in ("studio-light", "anabelka-brand"):
            with self.subTest(profile=profile):
                result, calls = self.render(image, grabcut, self.candidate(good), bbox, profile)
                self.assertEqual(result[2], "modnet")
                self.assertEqual(calls, 1)
                self.assertAlmostEqual(result[1], 6100 / 19200)
                self.assertEqual(result[0].size, (1200, 1800))

    def test_same_mask_does_not_offer_evidence_of_improvement(self):
        image, grabcut, _, bbox = self.fixture()
        result, calls = self.render(image, grabcut, self.candidate(grabcut), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)

    def test_smaller_mask_with_remaining_background_is_rejected(self):
        image, grabcut, _, bbox = self.fixture()
        wrong = grabcut.copy()
        alpha = np.asarray(wrong.getchannel("A")).copy()
        alpha[20:70, 40:80] = 0  # Lose the model, keep the backdrop.
        wrong.putalpha(Image.fromarray(alpha))
        result, calls = self.render(image, grabcut, self.candidate(wrong), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)

    def test_localized_hair_arm_or_lace_loss_rejects_candidate(self):
        image, grabcut, good, bbox = self.fixture()
        for region in ((10, 30, 45, 75), (50, 110, 30, 40), (70, 100, 45, 75)):
            with self.subTest(region=region):
                damaged = good.copy()
                alpha = np.asarray(good.getchannel("A")).copy()
                top, bottom, left, right = region
                alpha[top:bottom, left:right] = 0
                damaged.putalpha(Image.fromarray(alpha))
                result, _ = self.render(image, grabcut, self.candidate(damaged), bbox)
                self.assertEqual(result[2], "opencv-grabcut")

    def test_invalid_candidate_keeps_grabcut(self):
        image, grabcut, good, bbox = self.fixture()
        empty = good.copy()
        empty.putalpha(0)
        full = good.copy()
        full.putalpha(255)
        candidates = [self.candidate(empty), self.candidate(full),
                      self.candidate(good.resize((60, 80))),
                      self.candidate(good, float("nan"))]
        for candidate in candidates:
            with self.subTest(size=candidate[0].size, ratio=candidate[1]):
                result, _ = self.render(image, grabcut, candidate, bbox)
                self.assertEqual(result[2], "opencv-grabcut")

    def test_worker_failure_keeps_exact_grabcut_output(self):
        image, grabcut, _, bbox = self.fixture()
        baseline, _ = self.render(image, grabcut, None, bbox, source=None)
        result, calls = self.render(image, grabcut, None, bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_unexpected_worker_or_probe_error_keeps_grabcut(self):
        image, grabcut, good, bbox = self.fixture()
        baseline, _ = self.render(image, grabcut, None, bbox, source=None)
        result, _ = self.render(image, grabcut, RuntimeError("worker failed"), bbox)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))
        with patch.object(processor, "grabcut_fallback_evidence", side_effect=RuntimeError("probe failed")):
            result, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_real_bridge_timeout_keeps_grabcut(self):
        image, grabcut, _, bbox = self.fixture()
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            source = root / "source.jpg"
            image.save(source)
            with (
                patch.object(processor, "modnet_worker_ready", return_value=True),
                patch.object(processor, "MODNET_WORKER_ROOT", root / "worker"),
                patch.object(processor.subprocess, "run", side_effect=subprocess.TimeoutExpired("modnet", 45)),
            ):
                result, calls = self.render(image, grabcut, processor.run_modnet_worker, bbox, source=source)
            self.assertEqual(result[2], "opencv-grabcut")
            self.assertEqual(calls, 1)
            self.assertEqual(processor._modnet_worker_error, "worker-timeout")
            self.assertEqual(list((root / "worker").iterdir()), [])

    def test_candidate_cannot_restore_contrasting_background(self):
        image, grabcut, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        pixels[20:40, 5:25] = (20, 140, 180)
        image = Image.fromarray(pixels)
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[20:40, 5:25] = 255
        candidate = image.convert("RGBA")
        candidate.putalpha(Image.fromarray(alpha))
        result, calls = self.render(image, grabcut, self.candidate(candidate), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)

    def test_narrow_dark_detail_cannot_be_completely_lost(self):
        image, grabcut, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        pixels[45:70, 25:27] = (40, 20, 25)
        image = Image.fromarray(pixels)
        alpha = np.asarray(grabcut.getchannel("A")).copy()
        alpha[45:70, 25:27] = 255
        grabcut = image.convert("RGBA")
        grabcut.putalpha(Image.fromarray(alpha))
        result, _ = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")

    def test_confirmed_spill_inside_detector_zones_accepts_good_modnet(self):
        image, grabcut, good, _ = self.fixture()
        # Widening a detector box does not turn this unchanged smooth backdrop
        # into body/arm. This reproduces the real Android protection overlap.
        result, calls = self.render(image, grabcut, self.candidate(good), (20, 10, 90, 140))
        self.assertEqual(result[2], "modnet")
        self.assertEqual(calls, 1)

    def test_confirmed_residual_is_not_reprotected_by_detector_zones(self):
        image, grabcut, _, _ = self.fixture()
        before_image, before_alpha = np.asarray(image).copy(), np.asarray(grabcut).copy()
        for bbox in ((30, 10, 50, 140), (20, 10, 90, 140), (0, 0, 120, 160)):
            with self.subTest(bbox=bbox):
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                self.assertIsNotNone(evidence)
                self.assertTrue(evidence["residual"][90, 95])
                self.assertFalse(np.any(evidence["protected"] & evidence["residual"]))
                self.assertTrue(evidence["protected"][60, 60])
        np.testing.assert_array_equal(np.asarray(image), before_image)
        np.testing.assert_array_equal(np.asarray(grabcut), before_alpha)

    def test_large_source_keeps_bounded_probe_and_preserves_selection(self):
        image, grabcut, good, bbox = self.fixture()
        size = (3000, 4000)
        scale = size[0] // image.width
        image = image.resize(size, Image.Resampling.NEAREST)
        grabcut = grabcut.resize(size, Image.Resampling.NEAREST)
        good = good.resize(size, Image.Resampling.NEAREST)
        bbox = tuple(value * scale for value in bbox)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        self.assertEqual(evidence["alpha"].shape, (512, 384))
        self.assertFalse(np.any(evidence["protected"] & evidence["residual"]))
        self.assertTrue(processor.modnet_fallback_is_better(good, self.candidate(good)[1], evidence))
        damaged = good.copy()
        alpha = np.asarray(damaged.getchannel("A")).copy()
        alpha[10 * scale:30 * scale, 45 * scale:75 * scale] = 0
        damaged.putalpha(Image.fromarray(alpha))
        self.assertFalse(processor.modnet_fallback_is_better(
            damaged, self.candidate(damaged)[1], evidence))

    def test_similar_colour_hair_or_arm_contour_stays_protected(self):
        for name, region, point, colour in (
            ("hair", (10, 30, 45, 75), (20, 60), (160, 120, 90)),
            ("low-contrast hair", (10, 30, 45, 75), (20, 60), (149, 109, 80)),
            ("arm", (50, 110, 30, 40), (80, 35), (160, 120, 90)),
            ("low-contrast arm", (50, 110, 30, 40), (80, 35), (149, 109, 80)),
        ):
            with self.subTest(part=name):
                image, grabcut, good, bbox = self.fixture()
                top, bottom, left, right = region
                pixels = np.asarray(image).copy()
                # This colour still matches the backdrop palette, but its
                # visible source contour is evidence of a real body detail.
                pixels[top:bottom, left:right] = colour
                image = Image.fromarray(pixels)
                evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
                self.assertIsNotNone(evidence)
                self.assertTrue(evidence["background_like"][point])
                self.assertFalse(evidence["residual"][point])
                self.assertTrue(evidence["protected"][point])
                preserved, _ = self.render(image, grabcut, self.candidate(good), bbox)
                self.assertEqual(preserved[2], "modnet")
                alpha = np.asarray(good.getchannel("A")).copy()
                alpha[top:bottom, left:right] = 0
                damaged = image.convert("RGBA")
                damaged.putalpha(Image.fromarray(alpha))
                result, _ = self.render(image, grabcut, self.candidate(damaged), bbox)
                self.assertEqual(result[2], "opencv-grabcut")

    def test_similar_foreground_without_backdrop_spill_does_not_run_worker(self):
        image, grabcut, good, bbox = self.fixture(spill=False)
        pixels = np.asarray(image).copy()
        pixels[10:30, 45:75] = (160, 120, 90)
        pixels[50:110, 30:40] = (160, 120, 90)
        image = Image.fromarray(pixels)
        result, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)

    def test_unconfirmed_source_continuity_keeps_grabcut_without_worker(self):
        image, grabcut, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        backdrop = np.asarray(good.getchannel("A")) == 0
        checker = np.indices(backdrop.shape).sum(axis=0) % 2 == 0
        pixels[backdrop & checker] += 4
        image = Image.fromarray(pixels)
        self.assertFalse(processor.complex_background_prefers_modnet(image))
        self.assertIsNone(processor.grabcut_fallback_evidence(image, grabcut, bbox))
        baseline, _ = self.render(image, grabcut, None, bbox, source=None)
        result, calls = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 0)
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(baseline[0]))

    def test_textured_lingerie_matching_backdrop_is_not_confirmed_residual(self):
        for spacing in (3, 5, 8, 12, 15, 25):
            for colour in ((145, 107, 78), (119, 85, 62)):
                with self.subTest(stitch_spacing=spacing, stitch_colour=colour):
                    self.check_textured_lingerie(spacing, colour)

    def check_textured_lingerie(self, spacing, colour):
        image, grabcut, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        # The fabric meets the backdrop and has its colour; contrast stitches
        # are the observable subject evidence, rather than bbox membership.
        pixels[70:100, 65:80] = (115, 83, 60)
        pixels[72:98:spacing, 65:80:spacing] = colour
        image = Image.fromarray(pixels)
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        matching_fabric = np.zeros((160, 120), dtype=bool)
        matching_fabric[70:100, 65:80] = True
        matching_fabric &= np.all(pixels == (115, 83, 60), axis=2)
        self.assertTrue(np.all(evidence["background_like"][matching_fabric]))
        self.assertTrue(np.any(evidence["protected"] & matching_fabric))
        preserved, _ = self.render(image, grabcut, self.candidate(good), bbox)
        self.assertEqual(preserved[2], "modnet")
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[matching_fabric] = 0  # Keep stitches, lose background-coloured fabric.
        damaged = image.convert("RGBA")
        damaged.putalpha(Image.fromarray(alpha))
        result, _ = self.render(image, grabcut, self.candidate(damaged), bbox)
        self.assertEqual(result[2], "opencv-grabcut")

    def test_thin_matching_hair_joined_to_thick_spill_remains_ambiguous(self):
        image, grabcut, good, bbox = self.fixture()
        pixels = np.asarray(image).copy()
        pixels[25:50, 80:83] = (115, 83, 60)
        image = Image.fromarray(pixels)
        for subject in (grabcut, good):
            alpha = np.asarray(subject.getchannel("A")).copy()
            alpha[25:50, 80:83] = 255
            subject.putalpha(Image.fromarray(alpha))
        evidence = processor.grabcut_fallback_evidence(image, grabcut, bbox)
        self.assertIsNotNone(evidence)
        self.assertTrue(evidence["background_like"][35, 81])
        self.assertFalse(evidence["residual"][35, 81])
        self.assertTrue(evidence["protected"][35, 81])
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[25:50, 80:83] = 0
        damaged = image.convert("RGBA")
        damaged.putalpha(Image.fromarray(alpha))
        result, _ = self.render(image, grabcut, self.candidate(damaged), bbox)
        self.assertEqual(result[2], "opencv-grabcut")

    def test_extreme_aspect_ratio_cannot_break_optional_probe(self):
        image = Image.new("RGB", (24, 25000), (145, 107, 78))
        subject = image.convert("RGBA")
        subject.putalpha(255)
        self.assertIsNone(processor.grabcut_fallback_evidence(image, subject, (0, 0, 24, 25000)))

    def test_failed_primary_modnet_is_not_retried(self):
        image, grabcut, _, bbox = self.fixture()
        with patch.object(processor, "complex_background_prefers_modnet", return_value=True):
            result, calls = self.render(image, grabcut, None, bbox)
        self.assertEqual(result[2], "opencv-grabcut")
        self.assertEqual(calls, 1)

    def test_original_profile_skips_both_segmenters(self):
        image, grabcut, good, bbox = self.fixture()
        result, calls = self.render(image, grabcut, self.candidate(good), bbox, "original-canvas")
        self.assertIsNone(result)
        self.assertEqual(calls, 0)

    def test_crop_and_soft_modnet_alpha_are_preserved(self):
        image, grabcut, good, bbox = self.fixture()
        alpha = np.asarray(good.getchannel("A")).copy()
        alpha[30:140, 40] = 180
        good.putalpha(Image.fromarray(alpha))
        before = np.asarray(good).copy()
        crop = (10, 5, 110, 155)
        result, _ = self.render(image, grabcut, self.candidate(good), bbox,
                                crop_box=crop, crop_strategy="aspect-fill")
        self.assertEqual(result[2], "modnet")
        expected = processor.compose_subject_on_background(
            processor.transparent_standard_canvas(good.crop(crop), (1200, 1800)), "studio-light")
        np.testing.assert_array_equal(np.asarray(result[0]), np.asarray(expected))
        np.testing.assert_array_equal(np.asarray(good), before)

    def test_fallback_keeps_all_four_crop_strategies_and_actual_diagnostics(self):
        for length, expected_strategy in ((20, "torso-normalize"), (60, "aspect-fill"),
                                          (110, "torso-zoom-out"), (110, "preserve-closeup")):
            with self.subTest(strategy=expected_strategy):
                image, grabcut, good, bbox = self.fixture()
                if expected_strategy == "preserve-closeup":
                    pixels = np.asarray(image).copy()
                    pixels[:, 40:80] = (220, 160, 140)
                    image = Image.fromarray(pixels)
                    for subject in (grabcut, good):
                        alpha = np.asarray(subject.getchannel("A")).copy()
                        alpha[:, 40:80] = 255
                        subject.paste(image, (0, 0))
                        subject.putalpha(Image.fromarray(alpha))
                detection = (bbox, .95, (60., 80.), (60., 35.),
                             (60., 35. + length), float(length), 20.)
                ratio = float(np.mean(np.asarray(grabcut.getchannel("A")) >= 128))
                with (
                    patch.object(processor, "detect_mediapipe_person_bbox", return_value=detection),
                    patch.object(processor, "build_subject_rgba", return_value=(grabcut, ratio)),
                    patch.object(processor, "run_modnet_worker", return_value=self.candidate(good)),
                ):
                    _, before = processor.normalized_master(image, "studio-light", source_path=None)
                    automatic, after = processor.normalized_master(
                        image, "studio-light", source_path=Path("/tmp/fallback-source.jpg"))
                    with patch.object(processor, "complex_background_prefers_modnet", return_value=True):
                        forced, _ = processor.normalized_master(
                            image, "studio-light", source_path=Path("/tmp/fallback-source.jpg"))
                self.assertEqual(after["mask_method"], "modnet")
                self.assertEqual(after["crop_strategy"], expected_strategy)
                for key in ("crop_strategy", "crop_applied", "crop_box", "person_bbox", "method",
                            "torso_ratio_before", "torso_ratio_after", "zoom_scale", "zoom_out_applied"):
                    self.assertEqual(after.get(key), before.get(key), key)
                np.testing.assert_array_equal(np.asarray(automatic), np.asarray(forced))


if __name__ == "__main__":
    unittest.main()
