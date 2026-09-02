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
            : $this->getCurrentProviderCode();

        $provider = $this->providers[$providerCode];

        if (!$provider->isConfigured()) {
            throw new RuntimeException(
                $provider->getName()
                . ' ещё не настроен: отсутствует API-ключ.'
            );
        }

        $inputCharacters = $this->characterLength($name)
            + $this->characterLength($description);

        try {
            $translation = $provider->translate(
                $targetLanguage,
                $name,
                $description,
                $context
            );
        } catch (Throwable $e) {
            $this->recordUsageSafely(
                $providerCode,
                $targetLanguageCode,
                $context,
                $inputCharacters,
                0,
                false
            );

            throw $e;
        }

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
