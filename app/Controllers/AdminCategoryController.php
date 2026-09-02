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

        $activeLanguages = Language::active();

        /*
         * ensureTable() виконує DDL, тому готуємо таблицю до початку
         * транзакції. Інакше MySQL може неявно завершити транзакцію.
         */
        CategoryTranslator::getForCategory(0);

        $db = Database::connect();

        try {
            $db->beginTransaction();

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

            $expectedTranslations = [];

            foreach ($activeLanguages as $language) {
                $code = strtolower(
                    trim((string) ($language['code'] ?? ''))
                );

                if (
                    $code === ''
                    || $code === Language::SOURCE_CODE
                ) {
                    continue;
                }

                $translationName = trim(
                    (string) ($translationNames[$code] ?? '')
                );

                $translationDescription = trim(
                    (string) ($translationDescriptions[$code] ?? '')
                );

                CategoryTranslator::saveForCategory(
                    $categoryId,
                    $code,
                    $translationName,
                    $translationDescription,
                    'manual'
                );

                $expectedTranslations[$code] = [
                    'name' => $translationName,
                    'description' => $translationDescription
                ];
            }

            $this->verifySavedTranslations(
                CategoryTranslator::getForCategory($categoryId),
                $expectedTranslations
            );

            $db->commit();

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
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $this->jsonErrorResponse(
                $e->getMessage(),
                500
            );
        }
    }


    private function verifySavedTranslations(
        array $storedTranslations,
        array $expectedTranslations
    ) {
        foreach ($expectedTranslations as $code => $expected) {
            $expectedName = trim(
                (string) ($expected['name'] ?? '')
            );

            $expectedDescription = trim(
                (string) ($expected['description'] ?? '')
            );

            if ($expectedName === '' && $expectedDescription === '') {
                if (isset($storedTranslations[$code])) {
                    throw new RuntimeException(
                        'Порожній переклад ' . strtoupper($code)
                        . ' не було видалено.'
                    );
                }

                continue;
            }

            $stored = $storedTranslations[$code] ?? null;

            if (
                !$stored
                || trim((string) ($stored['name'] ?? ''))
                    !== $expectedName
                || trim((string) ($stored['description'] ?? ''))
                    !== $expectedDescription
                || (string) ($stored['status'] ?? '') !== 'approved'
            ) {
                throw new RuntimeException(
                    'База даних не підтвердила збереження перекладу '
                    . strtoupper($code) . '.'
                );
            }
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
