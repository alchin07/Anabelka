<?php

class AdminCategoryController extends Controller
{
    public function index()
    {
        $categories = Category::getAllForAdmin();
        $languages = Language::active();

        foreach ($categories as &$category) {
            $category['translations'] =
                CategoryTranslator::getForCategory(
                    (int) $category['id']
                );
        }
        unset($category);

        $this->view(
            'admin/categories/index',
            [
                'categories' => $categories,
                'languages' => $languages
            ]
        );
    }


    public function update()
    {
        $categoryId =
            (int) ($_POST['category_id'] ?? 0);

        $name =
            trim((string) ($_POST['name'] ?? ''));

        $description =
            trim((string) ($_POST['description'] ?? ''));

        if ($categoryId <= 0 || $name === '') {
            $this->jsonErrorResponse(
                'Название категории обязательно.',
                400
            );
        }

        try {
            $updated = Category::updateAdmin(
                $categoryId,
                $name,
                $description
            );

            if (!$updated) {
                throw new RuntimeException(
                    'Не удалось сохранить категорию.'
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

                CategoryTranslator::saveForCategory(
                    $categoryId,
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
                        'Категория и переводы сохранены.'
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
