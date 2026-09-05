<?php

class AdminProductVariantController extends Controller
{
    public function index()
    {
        try {
            $productId = (int) ($_GET['product_id'] ?? 0);

            if ($productId <= 0 || !AdminProduct::find($productId)) {
                throw new InvalidArgumentException('Товар не знайдено.');
            }

            $map = ProductVariantStock::forProducts([$productId]);
            $this->jsonResponse([
                'success' => true,
                'rows' => $map[$productId] ?? []
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    public function save()
    {
        try {
            $this->assertCsrf();
            $productId = (int) ($_POST['product_id'] ?? 0);

            if ($productId <= 0 || !AdminProduct::find($productId)) {
                throw new InvalidArgumentException('Товар не знайдено.');
            }

            $raw = (string) ($_POST['variant_stock_json'] ?? '[]');
            $matrix = json_decode($raw, true);

            if (!is_array($matrix)) {
                throw new InvalidArgumentException(
                    'Некоректні дані залишків за кольорами та розмірами.'
                );
            }

            if (count($matrix) > 1000) {
                throw new InvalidArgumentException(
                    'Надто багато комбінацій розміру та кольору.'
                );
            }

            ProductVariantStock::syncFromMatrix($productId, $matrix);

            $this->jsonResponse([
                'success' => true,
                'message' => 'Залишки за розмірами та кольорами збережено.'
            ]);
        } catch (Throwable $e) {
            $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    private function assertCsrf()
    {
        $submitted = (string) ($_POST['csrf_token'] ?? '');
        $stored = (string) ($_SESSION['admin_product_csrf'] ?? '');

        if ($stored === '' || !hash_equals($stored, $submitted)) {
            throw new InvalidArgumentException(
                'Сторінка застаріла. Оновіть її та повторіть дію.'
            );
        }
    }


    private function jsonResponse(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
