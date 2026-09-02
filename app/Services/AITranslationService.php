<?php

class AITranslationService
{
    private const DEFAULT_PROVIDER_SETTING =
        'ai_translation.default_provider';

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

        /*
         * Одноразово переносимо попередній вибір із сесії до бази.
         */
        $sessionProvider = strtolower(
            trim((string) ($_SESSION['ai_translation_provider'] ?? ''))
        );

        if ($this->isConfiguredProvider($sessionProvider)) {
            AppSetting::set(
                self::DEFAULT_PROVIDER_SETTING,
                $sessionProvider
            );

            return $sessionProvider;
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

        $_SESSION['ai_translation_provider'] = $providerCode;

        return $providerCode;
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

        $providerCode = $providerCode !== null
            ? $this->normalizeProviderCode($providerCode)
            : $this->getDefaultProviderCode();

        $provider = $this->providers[$providerCode];

        if (!$provider->isConfigured()) {
            throw new RuntimeException(
                $provider->getName()
                . ' ещё не настроен: отсутствует API-ключ.'
            );
        }

        $translation = $provider->translate(
            $targetLanguage,
            $name,
            $description,
            $context
        );

        $translation['provider'] = $providerCode;
        $translation['provider_name'] = $provider->getName();

        return $translation;
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
