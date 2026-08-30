<?php

class OpenAITranslationProvider implements AITranslationProviderInterface
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'openai';
    }

    public function getName()
    {
        return 'OpenAI';
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
            throw new RuntimeException('OpenAI не настроен: отсутствует API-ключ.');
        }

        $apiKey = trim((string) $this->config['api_key']);
        $model = trim((string) ($this->config['model'] ?? 'gpt-5.6-luna'));
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
            'instructions' =>
                'You are a professional ecommerce translator. '
                . 'Translate Ukrainian source content into the requested target language. '
                . 'Preserve brand names, model names, product codes, numbers and plain-text meaning. '
                . 'Use natural terminology suitable for an online store. '
                . 'Do not add explanations. Return only valid JSON with exactly two string fields: '
                . '"name" and "description".',
            'input' => json_encode(
                $input,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Не удалось подготовить запрос к OpenAI.');
        }

        $curl = curl_init('https://api.openai.com/v1/responses');
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
                'Не удалось связаться с OpenAI: '
                . ($curlError !== '' ? $curlError : 'ошибка соединения')
            );
        }

        $response = json_decode($rawResponse, true);
        if (!is_array($response)) {
            throw new RuntimeException('OpenAI вернул некорректный ответ.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'Ошибка OpenAI: '
                . trim((string) ($response['error']['message'] ?? 'Неизвестная ошибка API.'))
            );
        }

        $outputText = '';
        foreach ($response['output'] ?? [] as $outputItem) {
            foreach ($outputItem['content'] ?? [] as $contentItem) {
                if (isset($contentItem['text']) && is_string($contentItem['text'])) {
                    $outputText = trim($contentItem['text']);
                    break 2;
                }
            }
        }

        return AITranslationService::decodeTranslationJson($outputText, 'OpenAI');
    }
}
