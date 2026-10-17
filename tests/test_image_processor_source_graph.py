"""Source run graphs must match independent four-neighbour pixel paths."""

from collections import deque
import unittest

import numpy as np

import test_image_processor_modnet_routing as routing

processor = routing.processor


def pixel_paths(usable, block_x, block_y, alpha):
    """Flood from clear frame pixels, checking each source link directly."""
    height, width = usable.shape
    reached = np.zeros(usable.shape, dtype=bool)
    pending = deque()
    for row in range(height):
        for column in range(width):
            if (row in (0, height - 1) or column in (0, width - 1)):
                if usable[row, column] and alpha[row, column] <= 16:
                    reached[row, column] = True
                    pending.append((row, column))
    while pending:
        row, column = pending.popleft()
        for next_row, next_column in (
            (row, column - 1), (row, column + 1),
            (row - 1, column), (row + 1, column),
        ):
            if not (0 <= next_row < height and 0 <= next_column < width):
                continue
            if not usable[next_row, next_column] or reached[next_row, next_column]:
                continue
            blocked = (
                block_x[row, min(column, next_column)]
                if row == next_row
                else block_y[min(row, next_row), column]
            )
            if not blocked:
                reached[next_row, next_column] = True
                pending.append((next_row, next_column))
    return reached


def graph_counts(usable, block_x, block_y):
    """Enumerate horizontal intervals and unique vertical pairs explicitly."""
    height, width = usable.shape
    labels = np.zeros(usable.shape, dtype=np.int32)
    runs = 0
    for row in range(height):
        for column in range(width):
            if not usable[row, column]:
                continue
            if column == 0 or not usable[row, column - 1] or block_x[row, column - 1]:
                runs += 1
            labels[row, column] = runs
    pairs = set()
    for row in range(1, height):
        for column in range(width):
            if usable[row, column] and usable[row - 1, column] and not block_y[row - 1, column]:
                pairs.add((int(labels[row, column]), int(labels[row - 1, column])))
    return runs, len(pairs)


class SourceGraphTests(unittest.TestCase):
    def assert_pixel_equivalent(self, usable, block_x, block_y, alpha):
        confirmed, runs, links = processor.source_clear_edge_paths(
            usable, block_x, block_y, alpha)
        self.assertIsNotNone(confirmed)
        np.testing.assert_array_equal(confirmed, pixel_paths(usable, block_x, block_y, alpha))
        self.assertEqual((runs, links), graph_counts(usable, block_x, block_y))
        self.assertFalse(np.any(confirmed & ~usable))

    def test_random_source_links_and_weak_guard_holes_match_pixel_paths(self):
        rng = np.random.default_rng(614)
        for shape in ((1, 1), (1, 17), (19, 1), (3, 4), (9, 13), (24, 31)):
            for sample in range(12):
                with self.subTest(shape=shape, sample=sample):
                    candidates = rng.random(shape) > 0.2
                    weak_guard = rng.random(shape) < 0.15
                    usable = candidates & ~weak_guard
                    block_x = rng.random((shape[0], shape[1] - 1)) < 0.35
                    block_y = rng.random((shape[0] - 1, shape[1])) < 0.35
                    alpha = rng.choice(np.array([0, 16, 17, 128, 192, 255], dtype=np.uint8), size=shape)
                    self.assert_pixel_equivalent(usable, block_x, block_y, alpha)

    def test_only_clear_frame_pixels_seed_paths(self):
        usable = np.ones((5, 7), dtype=bool)
        block_x = np.zeros((5, 6), dtype=bool)
        block_y = np.zeros((4, 7), dtype=bool)
        alpha = np.full((5, 7), 255, dtype=np.uint8)
        alpha[2, 3] = 0  # A clear interior pixel is not a frame seed.
        alpha[0, 3] = 17
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, _, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertFalse(np.any(confirmed))
        alpha[0, 3] = 16
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, _, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertTrue(np.all(confirmed))

    def test_diagonals_and_uncertainty_holes_do_not_create_paths(self):
        usable = np.eye(5, dtype=bool)
        block_x = np.zeros((5, 4), dtype=bool)
        block_y = np.zeros((4, 5), dtype=bool)
        alpha = np.full((5, 5), 255, dtype=np.uint8)
        alpha[0, 0] = 0
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, _, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertEqual(int(np.count_nonzero(confirmed)), 1)

        usable[:] = True
        usable[:, 2] = False  # The full uncertainty guard cuts the source path.
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, _, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertTrue(np.all(confirmed[:, :2]))
        self.assertFalse(np.any(confirmed[:, 2:]))

    def test_horizontal_and_vertical_contours_each_block_source_paths(self):
        usable = np.ones((7, 9), dtype=bool)
        block_x = np.zeros((7, 8), dtype=bool)
        block_y = np.zeros((6, 9), dtype=bool)
        alpha = np.full((7, 9), 255, dtype=np.uint8)
        alpha[0, 0] = 0
        block_x[:, 3] = True
        block_y[2, :] = True
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, _, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        expected = np.zeros(usable.shape, dtype=bool)
        expected[:3, :4] = True
        np.testing.assert_array_equal(confirmed, expected)

    def test_run_work_limit_accepts_last_allowed_size_and_refuses_next(self):
        usable = np.zeros((256, 256), dtype=bool)
        usable[:, ::2] = True
        block_x = np.zeros((256, 255), dtype=bool)
        block_y = np.zeros((255, 256), dtype=bool)
        alpha = np.full(usable.shape, 255, dtype=np.uint8)
        alpha[0] = 0
        usable[-1, -2] = False
        self.assert_pixel_equivalent(usable, block_x, block_y, alpha)
        confirmed, runs, _ = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertEqual(runs, 32767)
        np.testing.assert_array_equal(confirmed, usable)

        usable[-1, -2] = True
        confirmed, runs, links = processor.source_clear_edge_paths(usable, block_x, block_y, alpha)
        self.assertIsNone(confirmed)
        self.assertEqual(runs, 32768)
        self.assertEqual(links, 0)


if __name__ == '__main__':
    unittest.main()
