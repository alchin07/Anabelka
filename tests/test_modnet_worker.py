import importlib.util
import sys
import unittest
from pathlib import Path

import numpy as np


WORKER = (
    Path(__file__).resolve().parents[1]
    / "tools"
    / "image-processor"
    / "modnet_worker.py"
)

if not WORKER.is_file():
    raise RuntimeError(
        "modnet_worker.py is missing"
    )

spec = importlib.util.spec_from_file_location(
    "anabelka_modnet_worker",
    WORKER,
)

worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class ModnetWorkerTests(unittest.TestCase):
    def test_input_size_portrait(self):
        self.assertEqual(
            worker.modnet_input_size(
                2500,
                3250,
            ),
            (512, 640),
        )

    def test_input_size_landscape(self):
        self.assertEqual(
            worker.modnet_input_size(
                3250,
                2500,
            ),
            (640, 512),
        )

    def test_input_dimensions_are_multiple_of_32(self):
        for width, height in (
            (1000, 1333),
            (1333, 1000),
            (500, 700),
        ):
            with self.subTest(
                size=(width, height)
            ):
                out_w, out_h = (
                    worker.modnet_input_size(
                        width,
                        height,
                    )
                )
                self.assertEqual(
                    out_w % 32,
                    0,
                )
                self.assertEqual(
                    out_h % 32,
                    0,
                )
                self.assertGreaterEqual(
                    out_w,
                    32,
                )
                self.assertGreaterEqual(
                    out_h,
                    32,
                )

    def test_normalization_range(self):
        pixels = np.array(
            [
                [
                    [0, 127, 255],
                    [255, 0, 127],
                ]
            ],
            dtype=np.uint8,
        )

        tensor = worker.normalize_rgb(
            pixels
        )

        self.assertEqual(
            tensor.dtype,
            np.float32,
        )

        self.assertGreaterEqual(
            float(tensor.min()),
            -1.0,
        )

        self.assertLessEqual(
            float(tensor.max()),
            1.0,
        )


if __name__ == "__main__":
    unittest.main()
