
import re
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]

JS = (
    ROOT
    / "js"
    / "admin-products.js"
)

VIEW = (
    ROOT
    / "views"
    / "admin"
    / "products"
    / "index.php"
)


class AdminProductsModnetBadgeTests(unittest.TestCase):
    def test_mask_badge_uses_mask_method(self):
        text = JS.read_text(
            encoding="utf-8"
        )

        self.assertIn(
            "normalization.mask_method",
            text,
        )

        self.assertIn(
            "'modnet': 'MODNet'",
            text,
        )

        self.assertIn(
            "'opencv-grabcut': 'GrabCut'",
            text,
        )

    def test_admin_products_js_cache_is_bumped(self):
        text = VIEW.read_text(
            encoding="utf-8"
        )

        match = re.search(
            r"admin-products\.js\?v=(\d+)",
            text,
        )

        self.assertIsNotNone(
            match
        )

        self.assertGreaterEqual(
            int(match.group(1)),
            22,
        )


if __name__ == "__main__":
    unittest.main()
