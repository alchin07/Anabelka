<?php

class AdminProductController extends Controller
{
    public function index()
    {
        $products = AdminProduct::all();
        $languages = Language::active();

        foreach ($products as &$product) {
            $product['translations'] =
                ProductTranslator::getForProduct(
                    (int) $product['id']
                );
        }
        unset($product);

        $this->view(
            'admin/products/index',
            [
                'products' => $products,
                'languages' => $languages
            ]
        );
    }


    public function update()
    {
        $productId =
            (int) ($_POST['product_id'] ?? 0);

        $name =
            trim((string) ($_POST['name'] ?? ''));

        $description =
            trim((string) ($_POST['description'] ?? ''));

        if ($productId <= 0 || $name === '') {
            $this->jsonErrorResponse(
                'Название товара обязательно.',
                400
            );
        }

        try {
            $updated = AdminProduct::updateText(
                $productId,
                $name,
                $description
            );

            if (!$updated) {
                throw new RuntimeException(
                    'Не удалось сохранить товар.'
                );
            }

            $translationNames =
                $_POST['translation_name'] ?? [];

            $translationDescriptions =
                $_POST['translation_description'] ?? [];

            if (!is_array($translationNames)) {
                $translationNames = [];
            }

            if (!is_array($translationDescriptions)) {
                $translationDescriptions = [];
            }

            foreach (Language::active() as $language) {
                $code = strtolower(
                    trim((string) ($language['code'] ?? ''))
                );

                if (
                    $code === ''
                    || $code === Language::SOURCE_CODE
                ) {
                    continue;
                }

                ProductTranslator::saveForProduct(
                    $productId,
                    $code,
                    $translationNames[$code] ?? '',
                    $translationDescriptions[$code] ?? '',
                    'manual'
                );
            }

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                [
                    'success' => true,
                    'message' =>
                        'Товар и переводы сохранены.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;

        } catch (Throwable $e) {
            $this->jsonErrorResponse(
                $e->getMessage(),
                500
            );
        }
    }


    private function jsonErrorResponse($message, $status)
    {
        http_response_code((int) $status);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'success' => false,
                'message' => (string) $message
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
