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
            $currentSource = $this->loadCategorySource(
                $db,
                $categoryId
            );
            $storedBefore = CategoryTranslator::getForCategory(
                $categoryId
            );

            $db->beginTransaction();

            $sourceChanged = TranslationWorkflow::sourceChanged(
                $currentSource['name'] ?? '',
                $currentSource['description'] ?? '',
                $name,
                $description
            );

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

            if ($sourceChanged) {
                CategoryTranslator::markOutdated($categoryId);
            }

            $translationNames =
                $_POST['translation_name'] ?? [];

            $translationDescriptions =
                $_POST['translation_description'] ?? [];

            $translationSources =
                $_POST['translation_source'] ?? [];

            $translationStatuses =
                $_POST['translation_status'] ?? [];

            if (!is_array($translationNames)) {
                $translationNames = [];
            }

            if (!is_array($translationDescriptions)) {
                $translationDescriptions = [];
            }

            if (!is_array($translationSources)) {
                $translationSources = [];
            }

            if (!is_array($translationStatuses)) {
                $translationStatuses = [];
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

                $storedTranslation = is_array(
                    $storedBefore[$code] ?? null
                )
                    ? $storedBefore[$code]
                    : [];

                $translationSource =
                    TranslationWorkflow::normalizeSource(
                        $translationSources[$code]
                        ?? ($storedTranslation['source'] ?? 'manual')
                    );

                $translationStatus =
                    TranslationWorkflow::normalizeStatus(
                        $translationStatuses[$code]
                        ?? ($storedTranslation['status'] ?? 'approved'),
                        $translationName !== ''
                            || $translationDescription !== ''
                    );

                $translationChanged =
                    TranslationWorkflow::translationChanged(
                        $storedTranslation,
                        $translationName,
                        $translationDescription
                    );

                $statusChanged = $translationStatus !==
                    TranslationWorkflow::normalizeStatus(
                        $storedTranslation['status'] ?? 'approved',
                        !empty($storedTranslation)
                    );

                if (
                    $sourceChanged
                    && !empty($storedTranslation)
                    && !$translationChanged
                    && !$statusChanged
                ) {
                    $expectedTranslations[$code] = [
                        'name' => (string) (
                            $storedTranslation['name'] ?? ''
                        ),
                        'description' => (string) (
                            $storedTranslation['description'] ?? ''
                        ),
                        'source' => (string) (
                            $storedTranslation['source'] ?? 'manual'
                        ),
                        'status' => 'outdated'
                    ];

                    continue;
                }

                CategoryTranslator::saveForCategory(
                    $categoryId,
                    $code,
                    $translationName,
                    $translationDescription,
                    $translationSource,
                    $translationStatus
                );

                $expectedTranslations[$code] = [
                    'name' => $translationName,
                    'description' => $translationDescription,
                    'source' => $translationSource,
                    'status' => $translationStatus
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
                || (string) ($stored['source'] ?? '')
                    !== (string) ($expected['source'] ?? 'manual')
                || (string) ($stored['status'] ?? '')
                    !== (string) ($expected['status'] ?? 'approved')
            ) {
                throw new RuntimeException(
                    'База даних не підтвердила збереження перекладу '
                    . strtoupper($code) . '.'
                );
            }
        }
    }


    private function loadCategorySource(PDO $db, $categoryId)
    {
        $stmt = $db->prepare("
            SELECT name, description
            FROM categories
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $categoryId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Категорію не знайдено.');
        }

        return $row;
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
