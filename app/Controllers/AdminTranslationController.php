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
                    'coverage' => [],
                    'languageCoverage' => [],
                    'dashboardError' => $e->getMessage()
                ]
            );
        }
    }


    public function missing()
    {
        /*
         * Список має відображати щойно збережені переклади, зокрема
         * після повернення кнопкою браузера на мобільному пристрої.
         */
        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );
        header('Pragma: no-cache');

        $section = strtolower(
            trim((string) ($_GET['section'] ?? ''))
        );

        $filters = [
            'language' => $_GET['language'] ?? '',
            'key' => $_GET['translation_key'] ?? '',
            'source' => $_GET['source_text'] ?? ''
        ];

        try {
            $service = new TranslationDashboardService();
            $data = $service->getMissingTranslations(
                $section,
                $filters
            );

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
                    'totalItems' => 0,
                    'filters' => [
                        'language' => '',
                        'key' => '',
                        'source' => ''
                    ],
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
                    'totalItems' => 0,
                    'filters' => [
                        'language' => '',
                        'key' => '',
                        'source' => ''
                    ],
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

        $returnUrl = $this->normalizeInterfaceReturnUrl(
            $_GET['return_url'] ?? ''
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
                    'focusLanguage' => $focusLanguage,
                    'returnUrl' => $returnUrl
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
                    'returnUrl' => $returnUrl,
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
                    'returnUrl' => $returnUrl,
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
        $translationSources =
            $_POST['translation_source'] ?? [];
        $translationStatuses =
            $_POST['translation_status'] ?? [];

        $returnUrl = $this->normalizeInterfaceReturnUrl(
            $_POST['return_url'] ?? ''
        );

        if (is_array($sourceValue)) {
            $sourceValue = '';
        }

        if (!is_array($translationValues)) {
            $translationValues = [];
        }

        if (!is_array($translationSources)) {
            $translationSources = [];
        }

        if (!is_array($translationStatuses)) {
            $translationStatuses = [];
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

            $oldSourceValue = trim((string) (
                $existing[Language::SOURCE_CODE]['value'] ?? ''
            ));
            $newSourceValue = trim((string) $sourceValue);
            $sourceChanged = $oldSourceValue !== $newSourceValue;

            Translator::saveForKey(
                $key,
                Language::SOURCE_CODE,
                $sourceValue,
                'manual'
            );

            if ($sourceChanged) {
                Translator::markOutdatedForKey($key);
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

                $value = $translationValues[$code] ?? '';

                if (is_array($value)) {
                    $value = '';
                }

                $value = trim((string) $value);
                $storedTranslation = is_array(
                    $existing[$code] ?? null
                )
                    ? $existing[$code]
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
                        $value !== ''
                    );

                $translationChanged = trim((string) (
                    $storedTranslation['value'] ?? ''
                )) !== $value;

                $statusChanged = $translationStatus !==
                    TranslationWorkflow::normalizeStatus(
                        $storedTranslation['status'] ?? 'approved',
                        trim((string) (
                            $storedTranslation['value'] ?? ''
                        )) !== ''
                    );

                if (
                    $sourceChanged
                    && trim((string) (
                        $storedTranslation['value'] ?? ''
                    )) !== ''
                    && !$translationChanged
                    && !$statusChanged
                ) {
                    continue;
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
                    $translationSource,
                    $translationStatus
                );
            }

            $db->commit();

            $this->jsonSuccess([
                'message' => 'Переклади інтерфейсу збережено.',
                'return_url' => $returnUrl
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


    private function normalizeInterfaceReturnUrl($value)
    {
        $defaultUrl =
            '/Anabelka/admin/translations/missing?section=interface';

        if (!is_scalar($value)) {
            return $defaultUrl;
        }

        $value = trim((string) $value);

        if ($value === '' || strlen($value) > 4000) {
            return $defaultUrl;
        }

        $parts = parse_url($value);

        if (
            !is_array($parts)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || (string) ($parts['path'] ?? '')
                !== '/Anabelka/admin/translations/missing'
        ) {
            return $defaultUrl;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ((string) ($query['section'] ?? '') !== 'interface') {
            return $defaultUrl;
        }

        $params = ['section' => 'interface'];
        $language = $this->returnFilterValue(
            $query['language'] ?? '',
            16
        );

        if (
            $language !== ''
            && preg_match('/^[a-z0-9_-]+$/i', $language)
        ) {
            $params['language'] = strtolower($language);
        }

        $filterKey = $this->returnFilterValue(
            $query['translation_key'] ?? '',
            190
        );

        if ($filterKey !== '') {
            $params['translation_key'] = $filterKey;
        }

        $sourceText = $this->returnFilterValue(
            $query['source_text'] ?? '',
            250
        );

        if ($sourceText !== '') {
            $params['source_text'] = $sourceText;
        }

        return '/Anabelka/admin/translations/missing?'
            . http_build_query(
                $params,
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }


    private function returnFilterValue($value, $limit)
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
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
