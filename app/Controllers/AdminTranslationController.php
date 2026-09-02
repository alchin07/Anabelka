<?php

class AdminTranslationController extends Controller
{
    public function index()
    {
        try {
            $service = new TranslationDashboardService();
            $data = $service->getDashboardData();

            $this->view(
                'admin/translations/index',
                $data
            );

        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/translations/index',
                [
                    'sourceLanguage' => null,
                    'languages' => [],
                    'targetLanguages' => [],
                    'providers' => [],
                    'selectedProvider' => '',
                    'coverage' => [],
                    'dashboardError' => $e->getMessage()
                ]
            );
        }
    }


    public function missing()
    {
        $section = strtolower(
            trim((string) ($_GET['section'] ?? ''))
        );

        try {
            $service = new TranslationDashboardService();
            $data = $service->getMissingTranslations($section);

            $this->view(
                'admin/translations/missing',
                $data
            );

        } catch (InvalidArgumentException $e) {
            http_response_code(400);

            $this->view(
                'admin/translations/missing',
                [
                    'section' => $section,
                    'sectionLabel' => 'Переклади',
                    'sectionUrl' => '/Anabelka/admin/translations',
                    'targetLanguages' => [],
                    'items' => [],
                    'missingError' => $e->getMessage()
                ]
            );
        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/translations/missing',
                [
                    'section' => $section,
                    'sectionLabel' => 'Переклади',
                    'sectionUrl' => '/Anabelka/admin/translations',
                    'targetLanguages' => [],
                    'items' => [],
                    'missingError' => $e->getMessage()
                ]
            );
        }
    }


    public function interfaceEdit()
    {
        $key = trim(
            (string) ($_GET['key'] ?? '')
        );

        $requestedFocusLanguage = strtolower(
            trim((string) ($_GET['focus_language'] ?? ''))
        );

        try {
            if ($key === '' || strlen($key) > 190) {
                throw new InvalidArgumentException(
                    'Не вказано ключ інтерфейсного тексту.'
                );
            }

            ProductInterfaceTranslator::seed();
            PublicInterfaceTranslator::seed();

            $translations = Translator::getForKey($key);
            $source = $translations[Language::SOURCE_CODE] ?? null;

            if (
                !$source
                || trim((string) ($source['value'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException(
                    'Інтерфейсний текст не знайдено.'
                );
            }

            $targetLanguages = [];
            $missingLanguages = [];

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

                $targetLanguages[] = $language;
                $translation = $translations[$code] ?? [];

                if (
                    ($translation['status'] ?? '') !== 'approved'
                    || trim(
                        (string) ($translation['value'] ?? '')
                    ) === ''
                ) {
                    $missingLanguages[] = $code;
                }
            }

            $targetCodes = array_map(
                function ($language) {
                    return strtolower(
                        trim((string) ($language['code'] ?? ''))
                    );
                },
                $targetLanguages
            );

            $focusLanguage = in_array(
                $requestedFocusLanguage,
                $targetCodes,
                true
            )
                ? $requestedFocusLanguage
                : (string) ($missingLanguages[0] ?? '');

            $this->view(
                'admin/translations/interface-edit',
                [
                    'translationKey' => $key,
                    'translations' => $translations,
                    'sourceTranslation' => $source,
                    'targetLanguages' => $targetLanguages,
                    'missingLanguages' => $missingLanguages,
                    'focusLanguage' => $focusLanguage
                ]
            );

        } catch (InvalidArgumentException $e) {
            http_response_code(404);

            $this->view(
                'admin/translations/interface-edit',
                [
                    'translationKey' => $key,
                    'translations' => [],
                    'sourceTranslation' => null,
                    'targetLanguages' => [],
                    'missingLanguages' => [],
                    'focusLanguage' => '',
                    'editorError' => $e->getMessage()
                ]
            );

        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/translations/interface-edit',
                [
                    'translationKey' => $key,
                    'translations' => [],
                    'sourceTranslation' => null,
                    'targetLanguages' => [],
                    'missingLanguages' => [],
                    'focusLanguage' => '',
                    'editorError' => $e->getMessage()
                ]
            );
        }
    }


    public function saveInterface()
    {
        $key = trim(
            (string) ($_POST['translation_key'] ?? '')
        );

        $sourceValue = $_POST['source_value'] ?? '';
        $translationValues =
            $_POST['translation_value'] ?? [];

        if (is_array($sourceValue)) {
            $sourceValue = '';
        }

        if (!is_array($translationValues)) {
            $translationValues = [];
        }

        $db = null;

        try {
            if ($key === '' || strlen($key) > 190) {
                throw new InvalidArgumentException(
                    'Некоректний ключ інтерфейсного тексту.'
                );
            }

            ProductInterfaceTranslator::seed();
            PublicInterfaceTranslator::seed();

            $existing = Translator::getForKey($key);

            if (!isset($existing[Language::SOURCE_CODE])) {
                throw new InvalidArgumentException(
                    'Інтерфейсний текст не знайдено.'
                );
            }

            $db = Database::connect();
            $db->beginTransaction();

            Translator::saveForKey(
                $key,
                Language::SOURCE_CODE,
                $sourceValue,
                'manual'
            );

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

                $value = $translationValues[$code] ?? '';

                if (is_array($value)) {
                    $value = '';
                }

                $this->validatePlaceholders(
                    $sourceValue,
                    $value,
                    $code
                );

                Translator::saveForKey(
                    $key,
                    $code,
                    $value,
                    'manual'
                );
            }

            $db->commit();

            $this->jsonSuccess([
                'message' => 'Переклади інтерфейсу збережено.',
                'return_url' =>
                    '/Anabelka/admin/translations/missing?section=interface'
            ]);

        } catch (InvalidArgumentException $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            $this->jsonError($e->getMessage(), 400);

        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            $this->jsonError($e->getMessage(), 500);
        }
    }


    private function jsonSuccess(array $data)
    {
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            array_merge(['success' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function jsonError($message, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            [
                'success' => false,
                'message' => (string) $message
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }


    private function validatePlaceholders(
        $sourceValue,
        $translatedValue,
        $languageCode
    ) {
        $translatedValue = trim((string) $translatedValue);

        if ($translatedValue === '') {
            return;
        }

        $sourcePlaceholders =
            $this->extractPlaceholders($sourceValue);

        $translatedPlaceholders =
            $this->extractPlaceholders($translatedValue);

        if ($sourcePlaceholders !== $translatedPlaceholders) {
            $expected = $sourcePlaceholders
                ? ' Очікується: '
                    . implode(', ', $sourcePlaceholders)
                : '';

            throw new InvalidArgumentException(
                'У перекладі '
                . strtoupper((string) $languageCode)
                . ' службові вставки мають точно збігатися '
                . 'з вихідним текстом.'
                . $expected
            );
        }
    }


    private function extractPlaceholders($value)
    {
        preg_match_all(
            '/\{[a-zA-Z0-9_.-]+\}/',
            (string) $value,
            $matches
        );

        $placeholders = array_values($matches[0] ?? []);

        sort($placeholders);

        return $placeholders;
    }
}
