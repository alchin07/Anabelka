<?php

class GeminiTranslationProvider implements AITranslationProviderInterface
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'gemini';
    }

    public function getName()
    {
        return 'Google Gemini';
    }

    public function isConfigured()
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }

    public function translate(
        array $targetLanguage,
        $name,
        $description = '',
        $context = 'catalog'
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('На сервере PHP не включено расширение cURL.');
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException('Gemini не настроен: отсутствует API-ключ.');
        }

        $apiKey = trim((string) $this->config['api_key']);
        $model = trim((string) ($this->config['model'] ?? 'gemini-3.7-flash'));

        // Gemini 3.7 Flash по умолчанию использует medium thinking,
        // что для короткого перевода может заметно увеличить задержку.
        // Для админ-панели нам важнее быстрый отклик.
        $timeout = max(60, (int) ($this->config['timeout'] ?? 60));

        $prompt = [
            'task' => 'Translate ecommerce content from Ukrainian.',
            'rules' => [
                'Return only valid JSON with exactly two string fields: name and description.',
                'Preserve brand names, model names, product codes and numbers.',
                'Use natural online-store terminology.',
                'Do not add explanations.'
            ],
            'source_language' => Language::SOURCE_CODE,
            'target_language' => (string) ($targetLanguage['code'] ?? ''),
            'target_language_name' => (string) ($targetLanguage['name'] ?? ''),
            'target_locale' => (string) ($targetLanguage['locale'] ?? ''),
            'context' => $context !== '' ? $context : 'catalog',
            'name' => trim((string) $name),
            'description' => trim((string) $description)
        ];

        $promptText = json_encode(
            $prompt,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $payload = json_encode([
            'contents' => [[
                'role' => 'user',
                'parts' => [[
                    'text' => $promptText
                ]]
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'thinkingConfig' => [
                    'thinkingLevel' => 'low'
                ]
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Не удалось подготовить запрос к Gemini.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model)
            . ':generateContent';

        $curl = curl_init($url);

        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload
        ];

        // На некоторых Android-сетях KSWEB/cURL зависает на IPv6-маршруте
        // к Google API. Предпочитаем IPv4, если эта возможность доступна.
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $curlOptions[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        curl_setopt_array($curl, $curlOptions);

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            throw new RuntimeException(
                'Не удалось связаться с Gemini: '
                . ($curlError !== '' ? $curlError : 'ошибка соединения')
            );
        }

        $response = json_decode($rawResponse, true);
        if (!is_array($response)) {
            throw new RuntimeException('Gemini вернул некорректный ответ.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'Ошибка Gemini: '
                . trim((string) ($response['error']['message'] ?? 'Неизвестная ошибка API.'))
            );
        }

        $outputText = trim((string) (
            $response['candidates'][0]['content']['parts'][0]['text'] ?? ''
        ));

        return AITranslationService::decodeTranslationJson($outputText, 'Gemini');
    }
}
