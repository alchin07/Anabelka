<?php

class DeepLTranslationProvider implements AITranslationProviderInterface
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'deepl';
    }

    public function getName()
    {
        return 'DeepL';
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
            throw new RuntimeException('DeepL не настроен: отсутствует API-ключ.');
        }

        $apiKey = trim((string) $this->config['api_key']);
        $timeout = max(5, (int) ($this->config['timeout'] ?? 30));
        $endpoint = trim((string) (
            $this->config['endpoint'] ?? 'https://api.deepl.com/v2/translate'
        ));

        $texts = [trim((string) $name)];
        $hasDescription = trim((string) $description) !== '';

        if ($hasDescription) {
            $texts[] = trim((string) $description);
        }

        $targetCode = strtoupper((string) ($targetLanguage['code'] ?? ''));

        $payloadData = [
            'text' => $texts,
            'source_lang' => 'UK',
            'target_lang' => $targetCode,
            'preserve_formatting' => true
        ];

        if (trim((string) $context) !== '') {
            $payloadData['context'] =
                'Ecommerce content. Context: ' . trim((string) $context);
        }

        $payload = json_encode(
            $payloadData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new RuntimeException('Не удалось подготовить запрос к DeepL.');
        }

        $curl = curl_init($endpoint);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: DeepL-Auth-Key ' . $apiKey,
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
                'Не удалось связаться с DeepL: '
                . ($curlError !== '' ? $curlError : 'ошибка соединения')
            );
        }

        $response = json_decode($rawResponse, true);
        if (!is_array($response)) {
            throw new RuntimeException('DeepL вернул некорректный ответ.');
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException(
                'Ошибка DeepL: '
                . trim((string) ($response['message'] ?? 'Неизвестная ошибка API.'))
            );
        }

        $translations = $response['translations'] ?? [];

        if (empty($translations[0]['text'])) {
            throw new RuntimeException('DeepL не вернул перевод названия.');
        }

        return [
            'name' => trim((string) $translations[0]['text']),
            'description' => $hasDescription
                ? trim((string) ($translations[1]['text'] ?? ''))
                : ''
        ];
    }
}
