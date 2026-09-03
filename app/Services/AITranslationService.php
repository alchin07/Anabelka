<?php

class AITranslationService
{
    private const DEFAULT_PROVIDER_SETTING =
        'ai_translation.default_provider';

    private const FALLBACK_PROVIDER_SETTING =
        'ai_translation.fallback_provider';

    private $config;
    private $providers = [];


    public function __construct()
    {
        $configFile = __DIR__ . '/../../config/ai.php';
        $this->config = is_file($configFile)
            ? require $configFile
            : [];

        $providerConfigs = $this->config['providers'] ?? [];

        $this->providers = [
            'openai' => new OpenAITranslationProvider(
                is_array($providerConfigs['openai'] ?? null)
                    ? $providerConfigs['openai']
                    : []
            ),
            'gemini' => new GeminiTranslationProvider(
                is_array($providerConfigs['gemini'] ?? null)
                    ? $providerConfigs['gemini']
                    : []
            ),
            'groq' => new GroqTranslationProvider(
                is_array($providerConfigs['groq'] ?? null)
                    ? $providerConfigs['groq']
                    : []
            ),
            'deepl' => new DeepLTranslationProvider(
                is_array($providerConfigs['deepl'] ?? null)
                    ? $providerConfigs['deepl']
                    : []
            )
        ];
    }


    public function getProviders()
    {
        $result = [];

        foreach ($this->providers as $code => $provider) {
            $result[$code] = [
                'code' => $provider->getCode(),
                'name' => $provider->getName(),
                'configured' => $provider->isConfigured()
            ];
        }

        return $result;
    }


    public function getDefaultProviderCode()
    {
        $storedProvider = strtolower(
            trim((string) AppSetting::get(
                self::DEFAULT_PROVIDER_SETTING,
                ''
            ))
        );

        if ($this->isConfiguredProvider($storedProvider)) {
            return $storedProvider;
        }

        $configuredDefault = strtolower(
            trim((string) ($this->config['default_provider'] ?? 'openai'))
        );

        if ($this->isConfiguredProvider($configuredDefault)) {
            return $configuredDefault;
        }

        foreach ($this->providers as $code => $provider) {
            if ($provider->isConfigured()) {
                return $code;
            }
        }

        if (isset($this->providers[$storedProvider])) {
            return $storedProvider;
        }

        return isset($this->providers[$configuredDefault])
            ? $configuredDefault
            : 'openai';
    }


    public function getCurrentProviderCode()
    {
        $sessionProvider = strtolower(
            trim((string) ($_SESSION['ai_translation_provider'] ?? ''))
        );

        if ($this->isConfiguredProvider($sessionProvider)) {
            return $sessionProvider;
        }

        if ($sessionProvider !== '') {
            unset($_SESSION['ai_translation_provider']);
        }

        return $this->getDefaultProviderCode();
    }


    public function getFallbackProviderCode()
    {
        $storedProvider = strtolower(
            trim((string) AppSetting::get(
                self::FALLBACK_PROVIDER_SETTING,
                ''
            ))
        );

        return $this->isConfiguredProvider($storedProvider)
            ? $storedProvider
            : '';
    }


    public function setCurrentProviderCode($providerCode)
    {
        $providerCode = $this->normalizeProviderCode($providerCode);

        if (!$this->providers[$providerCode]->isConfigured()) {
            throw new InvalidArgumentException(
                $this->providers[$providerCode]->getName()
                . ' не можна вибрати: спочатку додайте API-ключ.'
            );
        }

        $_SESSION['ai_translation_provider'] = $providerCode;

        return $providerCode;
    }


    public function clearCurrentProviderCode()
    {
        unset($_SESSION['ai_translation_provider']);
    }


    public function setDefaultProviderCode($providerCode)
    {
        $providerCode = $this->normalizeProviderCode($providerCode);

        if (!$this->providers[$providerCode]->isConfigured()) {
            throw new InvalidArgumentException(
                $this->providers[$providerCode]->getName()
                . ' не можна зробити основним: спочатку додайте API-ключ.'
            );
        }

        AppSetting::set(
            self::DEFAULT_PROVIDER_SETTING,
            $providerCode
        );

        return $providerCode;
    }


    public function configureDefaultProviders(
        $defaultProviderCode,
        $fallbackProviderCode = ''
    ) {
        $defaultProviderCode = $this->normalizeProviderCode(
            $defaultProviderCode
        );

        if (!$this->isConfiguredProvider($defaultProviderCode)) {
            throw new InvalidArgumentException(
                $this->providers[$defaultProviderCode]->getName()
                . ' не можна зробити основним: спочатку додайте API-ключ.'
            );
        }

        $fallbackProviderCode = strtolower(
            trim((string) $fallbackProviderCode)
        );

        if ($fallbackProviderCode !== '') {
            $fallbackProviderCode = $this->normalizeProviderCode(
                $fallbackProviderCode
            );

            if (!$this->isConfiguredProvider($fallbackProviderCode)) {
                throw new InvalidArgumentException(
                    $this->providers[$fallbackProviderCode]->getName()
                    . ' не можна зробити резервним: спочатку додайте API-ключ.'
                );
            }

            if ($fallbackProviderCode === $defaultProviderCode) {
                throw new InvalidArgumentException(
                    'Основний і резервний ШІ мають бути різними.'
                );
            }
        }

        AppSetting::set(
            self::DEFAULT_PROVIDER_SETTING,
            $defaultProviderCode
        );

        AppSetting::set(
            self::FALLBACK_PROVIDER_SETTING,
            $fallbackProviderCode
        );

        return [
            'default_provider' => $defaultProviderCode,
            'fallback_provider' => $fallbackProviderCode
        ];
    }


    public function suggest(
        $targetLanguageCode,
        $name,
        $description = '',
        $context = 'catalog',
        $providerCode = null
    ) {
        $targetLanguageCode = strtolower(
            trim((string) $targetLanguageCode)
        );

        $name = trim((string) $name);
        $description = trim((string) $description);
        $context = trim((string) $context);

        if ($name === '' && $description === '') {
            throw new InvalidArgumentException(
                'Нет исходного текста для перевода.'
            );
        }

        if ($targetLanguageCode === Language::SOURCE_CODE) {
            return [
                'name' => $name,
                'description' => $description,
                'provider' => 'source'
            ];
        }

        $targetLanguage = Language::findByCode($targetLanguageCode);

        if (!$targetLanguage || empty($targetLanguage['is_active'])) {
            throw new InvalidArgumentException(
                'Выбранный язык недоступен.'
            );
        }

        $primaryProviderCode = $providerCode !== null
            ? $this->normalizeProviderCode($providerCode)
            : $this->getCurrentProviderCode();

        $primaryProvider = $this->providers[$primaryProviderCode];

        if (!$primaryProvider->isConfigured()) {
            throw new RuntimeException(
                $primaryProvider->getName()
                . ' ещё не настроен: отсутствует API-ключ.'
            );
        }

        $inputCharacters = $this->characterLength($name)
            + $this->characterLength($description);

        try {
            $result = $this->translateWithProvider(
                $primaryProviderCode,
                $targetLanguage,
                $targetLanguageCode,
                $name,
                $description,
                $context,
                $inputCharacters
            );
        } catch (Throwable $primaryError) {
            $fallbackProviderCode = $this->getFallbackProviderCode();

            if (
                $fallbackProviderCode === ''
                || $fallbackProviderCode === $primaryProviderCode
            ) {
                throw $primaryError;
            }

            try {
                $result = $this->translateWithProvider(
                    $fallbackProviderCode,
                    $targetLanguage,
                    $targetLanguageCode,
                    $name,
                    $description,
                    $context,
                    $inputCharacters
                );
            } catch (Throwable $fallbackError) {
                throw new RuntimeException(
                    $primaryProvider->getName()
                    . ' і резервний '
                    . $this->providers[$fallbackProviderCode]->getName()
                    . ' зараз недоступні. Остання помилка: '
                    . $this->sanitizeProviderError(
                        $fallbackError->getMessage()
                    ),
                    0,
                    $fallbackError
                );
            }

            $translation = $result['translation'];
            $translation['provider'] = $fallbackProviderCode;
            $translation['provider_name'] =
                $this->providers[$fallbackProviderCode]->getName();
            $translation['fallback_used'] = true;
            $translation['primary_provider'] = $primaryProviderCode;
            $translation['primary_provider_name'] =
                $primaryProvider->getName();

            return $translation;
        }

        $translation = $result['translation'];
        $translation['provider'] = $primaryProviderCode;
        $translation['provider_name'] = $primaryProvider->getName();
        $translation['fallback_used'] = false;

        return $translation;
    }


    public function testProvider($providerCode)
    {
        $providerCode = $this->normalizeProviderCode($providerCode);

        if (!$this->isConfiguredProvider($providerCode)) {
            throw new InvalidArgumentException(
                $this->providers[$providerCode]->getName()
                . ' не налаштовано: спочатку додайте API-ключ.'
            );
        }

        $sourceText = 'Перевірка з’єднання';
        $targetLanguage = [
            'code' => 'en',
            'name' => 'English',
            'locale' => 'en_US',
            'is_active' => 1
        ];

        try {
            $result = $this->translateWithProvider(
                $providerCode,
                $targetLanguage,
                'en',
                $sourceText,
                '',
                'connection_test',
                $this->characterLength($sourceText)
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->sanitizeProviderError($e->getMessage()),
                0,
                $e
            );
        }

        return [
            'provider' => $providerCode,
            'provider_name' => $this->providers[$providerCode]->getName(),
            'response_ms' => (int) ($result['response_ms'] ?? 0)
        ];
    }


    private function normalizeProviderCode($providerCode)
    {
        $providerCode = strtolower(trim((string) $providerCode));

        if (!isset($this->providers[$providerCode])) {
            throw new InvalidArgumentException(
                'Неизвестный провайдер ИИ-перевода.'
            );
        }

        return $providerCode;
    }


    private function isConfiguredProvider($providerCode)
    {
        return isset($this->providers[$providerCode])
            && $this->providers[$providerCode]->isConfigured();
    }


    private function translateWithProvider(
        $providerCode,
        array $targetLanguage,
        $targetLanguageCode,
        $name,
        $description,
        $context,
        $inputCharacters
    ) {
        $provider = $this->providers[$providerCode];
        $startedAt = microtime(true);

        try {
            $translation = $provider->translate(
                $targetLanguage,
                $name,
                $description,
                $context
            );
        } catch (Throwable $e) {
            $responseMs = $this->elapsedMilliseconds($startedAt);

            $this->recordUsageSafely(
                $providerCode,
                $targetLanguageCode,
                $context,
                $inputCharacters,
                0,
                false
            );

            $this->recordHealthSafely(
                $providerCode,
                false,
                $responseMs,
                $this->sanitizeProviderError($e->getMessage())
            );

            throw $e;
        }

        $responseMs = $this->elapsedMilliseconds($startedAt);
        $outputCharacters = $this->characterLength(
            (string) ($translation['name'] ?? '')
        ) + $this->characterLength(
            (string) ($translation['description'] ?? '')
        );

        $this->recordUsageSafely(
            $providerCode,
            $targetLanguageCode,
            $context,
            $inputCharacters,
            $outputCharacters,
            true
        );

        $this->recordHealthSafely(
            $providerCode,
            true,
            $responseMs,
            ''
        );

        return [
            'translation' => $translation,
            'response_ms' => $responseMs
        ];
    }


    private function elapsedMilliseconds($startedAt)
    {
        return max(
            1,
            (int) round((microtime(true) - (float) $startedAt) * 1000)
        );
    }


    private function sanitizeProviderError($message)
    {
        $message = trim((string) $message);

        foreach (($this->config['providers'] ?? []) as $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }

            $apiKey = trim((string) ($providerConfig['api_key'] ?? ''));

            if ($apiKey !== '') {
                $message = str_replace($apiKey, '***', $message);
            }
        }

        return $message !== ''
            ? $message
            : 'Невідома помилка сервісу.';
    }


    private function characterLength($text)
    {
        $text = (string) $text;

        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }

        $count = preg_match_all('/./us', $text, $characters);

        return $count === false ? strlen($text) : (int) $count;
    }


    private function recordUsageSafely(
        $providerCode,
        $targetLanguageCode,
        $context,
        $inputCharacters,
        $outputCharacters,
        $isSuccess
    ) {
        try {
            AITranslationUsage::record(
                $providerCode,
                $targetLanguageCode,
                $context,
                $inputCharacters,
                $outputCharacters,
                $isSuccess
            );
        } catch (Throwable $e) {
            error_log(
                'AI translation usage statistics error: '
                . $e->getMessage()
            );
        }
    }


    private function recordHealthSafely(
        $providerCode,
        $isSuccess,
        $responseMs,
        $errorMessage
    ) {
        try {
            AITranslationProviderHealth::record(
                $providerCode,
                $isSuccess,
                $responseMs,
                $errorMessage
            );
        } catch (Throwable $e) {
            error_log(
                'AI provider health statistics error: '
                . $e->getMessage()
            );
        }
    }


    public static function decodeTranslationJson($text, $providerName = 'ИИ')
    {
        $text = trim((string) $text);

        if ($text === '') {
            throw new RuntimeException(
                $providerName . ' не вернул текст перевода.'
            );
        }

        if (strpos($text, '```') === 0) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                $providerName . ' вернул перевод в неожиданном формате.'
            );
        }

        return [
            'name' => trim((string) ($decoded['name'] ?? '')),
            'description' => trim((string) ($decoded['description'] ?? ''))
        ];
    }
}
