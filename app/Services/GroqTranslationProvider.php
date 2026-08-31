<?php

class GroqTranslationProvider implements AITranslationProviderInterface
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'groq';
    }

    public function getName()
    {
        return 'Groq';
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
            throw new RuntimeException('Groq не настроен: отсутствует API-ключ.');
        }

        $apiKey = trim((string) $this->config['api_key']);
        $model = trim((string) ($this->config['model'] ?? 'openai/gpt-oss-20b'));
        $timeout = max(5, (int) ($this->config['timeout'] ?? 30));

        $input = [
            'source_language' => Language::SOURCE_CODE,
            'target_language' => (string) ($targetLanguage['code'] ?? ''),
            'target_language_name' => (string) ($targetLanguage['name'] ?? ''),
            'target_locale' => (string) ($targetLanguage['locale'] ?? ''),
            'context' => $context !== '' ? $context : 'catalog',
            'name' => trim((string) $name),
            'description' => trim((string) $description)
        ];

        $payload = json_encode([
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' =>
                        'You are a professional ecommerce translator. '
                        . 'Translate Ukrainian source content into the requested target language. '
                        . 'Preserve brand names, model names, product codes, numbers and plain-text meaning. '
                        . 'Use natural terminology suitable for an online store. '
                        . 'Do not add explanations. Return only valid JSON with exactly two string fields: '
                        . '"name" and "description".'
                ],
                [
                    'role' => 'user',
                    'content' => json_encode(
                        $input,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )
                ]
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Не удалось подготовить запрос к Groq.');
        }

        $curl = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => $payload
        ]);

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            throw new RuntimeException(
                'Не удалось связаться с Groq: '
                . ($curlError !== '' ? $curlError : 'ошибка соединения')
            );
        }

        $response = json_decode($rawResponse, true);
        if (!is_array($response)) {
            throw new RuntimeException('Groq вернул некорректный ответ.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'Ошибка Groq: '
                . trim((string) ($response['error']['message'] ?? 'Неизвестная ошибка API.'))
            );
        }

        $outputText = trim((string) (
            $response['choices'][0]['message']['content'] ?? ''
        ));

        return AITranslationService::decodeTranslationJson($outputText, 'Groq');
    }
}
