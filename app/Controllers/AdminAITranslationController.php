<?php

class AdminAITranslationController extends Controller
{
    public function index()
    {
        try {
            $service = new AITranslationService();
            $statistics = [
                'periods' => [],
                'providers' => [],
                'recent' => []
            ];
            $statisticsError = '';
            $providerHealth = [];
            $healthError = '';

            try {
                $statistics = AITranslationUsage::dashboard();
            } catch (Throwable $e) {
                $statisticsError = $e->getMessage();
            }

            try {
                $providerHealth = AITranslationProviderHealth::all();
            } catch (Throwable $e) {
                $healthError = $e->getMessage();
            }

            $this->view(
                'admin/ai-translation/index',
                [
                    'providers' => $service->getProviders(),
                    'defaultProvider' =>
                        $service->getDefaultProviderCode(),
                    'fallbackProvider' =>
                        $service->getFallbackProviderCode(),
                    'providerHealth' => $providerHealth,
                    'healthError' => $healthError,
                    'statistics' => $statistics,
                    'statisticsError' => $statisticsError,
                    'saved' => !empty($_GET['saved']),
                    'testedProvider' => strtolower(
                        trim((string) ($_GET['tested'] ?? ''))
                    ),
                    'testResponseMs' => max(
                        0,
                        (int) ($_GET['response_ms'] ?? 0)
                    ),
                    'settingsError' => trim(
                        (string) ($_GET['error'] ?? '')
                    )
                ]
            );

        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/ai-translation/index',
                [
                    'providers' => [],
                    'defaultProvider' => '',
                    'fallbackProvider' => '',
                    'providerHealth' => [],
                    'healthError' => '',
                    'statistics' => [
                        'periods' => [],
                        'providers' => [],
                        'recent' => []
                    ],
                    'statisticsError' => '',
                    'saved' => false,
                    'testedProvider' => '',
                    'testResponseMs' => 0,
                    'settingsError' => $e->getMessage()
                ]
            );
        }
    }


    public function suggest()
    {
        $targetLanguage = strtolower(
            trim((string) ($_POST['target_language'] ?? ''))
        );

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $context = trim((string) ($_POST['context'] ?? 'catalog'));
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );

        if ($targetLanguage === '') {
            $this->jsonError('Не выбран язык перевода.', 400);
        }

        try {
            $service = new AITranslationService();

            $translation = $service->suggest(
                $targetLanguage,
                $name,
                $description,
                $context,
                $provider !== '' ? $provider : null
            );

            $this->jsonSuccess([
                'translation' => $translation,
                'selected_provider' => $service->getCurrentProviderCode(),
                'fallback_provider' =>
                    $service->getFallbackProviderCode(),
                'providers' => $service->getProviders()
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    public function providers()
    {
        try {
            $service = new AITranslationService();

            $this->jsonSuccess([
                'selected_provider' => $service->getCurrentProviderCode(),
                'default_provider' => $service->getDefaultProviderCode(),
                'fallback_provider' =>
                    $service->getFallbackProviderCode(),
                'providers' => $service->getProviders()
            ]);

        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    public function setProvider()
    {
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );

        if ($provider === '') {
            $this->jsonError('Не выбран провайдер ИИ.', 400);
        }

        try {
            $service = new AITranslationService();
            $selected = $service->setCurrentProviderCode($provider);

            $this->jsonSuccess([
                'selected_provider' => $selected,
                'default_provider' => $service->getDefaultProviderCode(),
                'fallback_provider' =>
                    $service->getFallbackProviderCode(),
                'providers' => $service->getProviders()
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    public function setDefaultProvider()
    {
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );
        $fallbackProvider = strtolower(
            trim((string) ($_POST['fallback_provider'] ?? ''))
        );

        if ($provider === '') {
            $this->redirectToSettings(
                'Оберіть ШІ за замовчуванням.'
            );
        }

        try {
            $service = new AITranslationService();
            $service->configureDefaultProviders(
                $provider,
                $fallbackProvider
            );

            /*
             * Після зміни основного сервісу верхній список одразу
             * починає слідувати новому значенню за замовчуванням.
             */
            $service->clearCurrentProviderCode();

            header(
                'Location: /Anabelka/admin/ai-translation?saved=1'
            );
            exit;

        } catch (Throwable $e) {
            $this->redirectToSettings($e->getMessage());
        }
    }


    public function testProvider()
    {
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );

        if ($provider === '') {
            $this->redirectToSettings(
                'Не вказано сервіс для перевірки.'
            );
        }

        try {
            $service = new AITranslationService();
            $result = $service->testProvider($provider);

            header(
                'Location: /Anabelka/admin/ai-translation?tested='
                . rawurlencode($provider)
                . '&response_ms='
                . (int) ($result['response_ms'] ?? 0)
                . '#provider-'
                . rawurlencode($provider)
            );
            exit;

        } catch (Throwable $e) {
            $this->redirectToSettings(
                $e->getMessage(),
                'provider-' . $provider
            );
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


    private function redirectToSettings($error, $anchor = '')
    {
        $location = '/Anabelka/admin/ai-translation?error='
            . rawurlencode((string) $error);

        $anchor = preg_replace(
            '/[^a-z0-9_-]/i',
            '',
            (string) $anchor
        );

        if ($anchor !== '') {
            $location .= '#' . $anchor;
        }

        header('Location: ' . $location);
        exit;
    }
}
