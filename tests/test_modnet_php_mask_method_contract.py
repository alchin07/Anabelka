
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]

SERVICE = (
    ROOT
    / "app"
    / "Services"
    / "ProductImageProcessingService.php"
)


class ModnetPhpMaskMethodContractTests(unittest.TestCase):
    def test_php_accepts_grabcut_and_modnet(self):
        text = SERVICE.read_text(
            encoding="utf-8"
        )

        self.assertIn(
            "'opencv-grabcut'",
            text,
        )

        self.assertIn(
            "'modnet'",
            text,
            "PHP drops MODNet mask_method",
        )

        self.assertIn(
            "$normalizedDiagnostics['mask_method']",
            text,
        )


if __name__ == "__main__":
    unittest.main()
