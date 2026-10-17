
import importlib.util
import sys
import unittest
from pathlib import Path

import cv2
import numpy as np
from PIL import Image


PROCESSOR_DIR = Path(__file__).resolve().parents[1] / "tools" / "image-processor"
sys.path.insert(0, str(PROCESSOR_DIR))

spec = importlib.util.spec_from_file_location(
    "anabelka_enclosed_gap_processor",
    PROCESSOR_DIR / "server.py",
)
processor = importlib.util.module_from_spec(spec)
spec.loader.exec_module(processor)


def make_subject(pixels, alpha):
    return Image.fromarray(
        np.dstack((pixels, alpha)),
        mode="RGBA",
    )


class EnclosedUpperGapTests(unittest.TestCase):
    def test_upper_hair_neck_gap_is_removed(self):
        pixels = np.full(
            (160, 120, 3),
            (214, 191, 160),
            dtype=np.uint8,
        )

        # Main subject.
        pixels[10:150, 20:100] = (205, 150, 120)

        # Hair beside the neck.
        pixels[20:80, 72:88] = (45, 28, 15)

        # Supplier-background gap trapped between neck and hair.
        pixels[30:70, 62:68] = (214, 191, 160)

        alpha = np.zeros((160, 120), dtype=np.uint8)
        alpha[10:150, 20:100] = 255

        image = Image.fromarray(pixels)
        subject = make_subject(pixels, alpha)

        image_before = np.asarray(image).copy()
        subject_before = np.asarray(subject).copy()

        cv2.setRNGSeed(0)
        cleaned = processor.refine_upper_enclosed_background_gaps(
            image,
            subject,
            (0, 0, 120, 160),
        )

        cleaned_alpha = np.asarray(cleaned.getchannel("A"))

        # Gap disappears.
        self.assertEqual(int(cleaned_alpha[45, 65]), 0)

        # Neck and hair survive.
        self.assertEqual(int(cleaned_alpha[45, 55]), 255)
        self.assertEqual(int(cleaned_alpha[45, 78]), 255)

        # RGB is never redrawn.
        np.testing.assert_array_equal(
            np.asarray(cleaned)[:, :, :3],
            pixels,
        )

        # Inputs are immutable.
        np.testing.assert_array_equal(np.asarray(image), image_before)
        np.testing.assert_array_equal(np.asarray(subject), subject_before)

    def test_similar_lower_garment_detail_is_preserved(self):
        pixels = np.full(
            (160, 120, 3),
            (214, 191, 160),
            dtype=np.uint8,
        )
        pixels[10:150, 20:100] = (205, 150, 120)

        # Same background-like colour, but in the lower garment zone.
        pixels[105:140, 62:68] = (214, 191, 160)

        alpha = np.zeros((160, 120), dtype=np.uint8)
        alpha[10:150, 20:100] = 255

        image = Image.fromarray(pixels)
        subject = make_subject(pixels, alpha)

        cv2.setRNGSeed(0)
        cleaned = processor.refine_upper_enclosed_background_gaps(
            image,
            subject,
            (0, 0, 120, 160),
        )

        np.testing.assert_array_equal(
            np.asarray(cleaned),
            np.asarray(subject),
        )

    def test_wide_ambiguous_upper_detail_is_preserved(self):
        pixels = np.full(
            (160, 120, 3),
            (214, 191, 160),
            dtype=np.uint8,
        )
        pixels[10:150, 20:100] = (205, 150, 120)

        # Too wide to be accepted as the narrow hair/neck-type gap.
        pixels[30:70, 52:76] = (214, 191, 160)

        alpha = np.zeros((160, 120), dtype=np.uint8)
        alpha[10:150, 20:100] = 255

        image = Image.fromarray(pixels)
        subject = make_subject(pixels, alpha)

        cv2.setRNGSeed(0)
        cleaned = processor.refine_upper_enclosed_background_gaps(
            image,
            subject,
            (0, 0, 120, 160),
        )

        np.testing.assert_array_equal(
            np.asarray(cleaned),
            np.asarray(subject),
        )

    def test_existing_transparency_is_never_restored(self):
        pixels = np.full(
            (160, 120, 3),
            (214, 191, 160),
            dtype=np.uint8,
        )
        pixels[10:150, 20:100] = (205, 150, 120)
        pixels[30:70, 62:68] = (214, 191, 160)

        alpha = np.zeros((160, 120), dtype=np.uint8)
        alpha[10:150, 20:100] = 255
        alpha[35:65, 63:67] = 0

        image = Image.fromarray(pixels)
        subject = make_subject(pixels, alpha)

        cv2.setRNGSeed(0)
        cleaned = processor.refine_upper_enclosed_background_gaps(
            image,
            subject,
            (0, 0, 120, 160),
        )

        cleaned_alpha = np.asarray(cleaned.getchannel("A"))

        self.assertEqual(
            np.count_nonzero(
                (alpha == 0) & (cleaned_alpha > 0)
            ),
            0,
        )


if __name__ == "__main__":
    unittest.main()
