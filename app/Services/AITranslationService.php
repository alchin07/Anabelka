<?php

class AITranslationService
{
    private $config;


    public function __construct()
    {
        $configFile = __DIR__ . '/../../config/ai.php';
        $this->config = is_file($configFile)
            ? require $configFile
            : [];
    }


    public function isConfigured()
    {
        return trim((string) ($this->config['api_key'] ?? '')) !== '';
    }


    public function suggest(
        $targetLanguageCode,
        $name,
        $description = '',
        $context = 'catalog'
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
                'description' => $description
            ];
        }

        $targetLanguage = Language::findByCode(
            $targetLanguageCode
        );

        if (!$targetLanguage || empty($targetLanguage['is_active'])) {
            throw new InvalidArgumentException(
                'Выбранный язык недоступен.'
            );
        }

        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'ИИ-перевод ещё не настроен: отсутствует API-ключ.'
            );
        }

        $provider = strtolower(
            trim((string) ($this->config['provider'] ?? 'openai'))
        );

        if ($provider !== 'openai') {
            throw new RuntimeException(
                'Этот провайдер ИИ пока не поддерживается.'
            );
        }

        return $this->translateWithOpenAI(
            $targetLanguage,
            $name,
            $description,
            $context
        );
    }


    private function translateWithOpenAI(
        array $targetLanguage,
        $name,
        $description,
        $context
    ) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(
                'На сервере PHP не включено расширение cURL.'
            );
        }

        $apiKey = trim((string) $this->config['api_key']);
        $model = trim((string) (
            $this->config['model'] ?? 'gpt-5.6-luna'
        ));
        $timeout = max(
            5,
            (int) ($this->config['timeout'] ?? 30)
        );

        $languageName = trim((string) (
            $targetLanguage['name']
            ?? $targetLanguage['code']
            ?? ''
        ));

        $locale = trim((string) (
            $targetLanguage['locale'] ?? ''
        ));

        $instructions =
            'You are a professional ecommerce translator. '
            . 'Translate Ukrainian source content into the requested target language. '
            . 'Preserve brand names, model names, product codes, numbers and HTML-free plain text. '
            . 'Use natural terminology suitable for an online store. '
            . 'Do not add explanations. Return only valid JSON with exactly two string fields: '
            . '"name" and "description".';

        $input = [
            'source_language' => 'uk',
            'target_language' => (string) ($targetLanguage['code'] ?? ''),
            'target_language_name' => $languageName,
            'target_locale' => $locale,
            'context' => $context !== '' ? $context : 'catalog',
            'name' => $name,
            'description' => $description
        ];

        $payload = json_encode(
            [
                'model' => $model,
                'instructions' => $instructions,
                'input' => json_encode(
                    $input,
                    JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                )
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Не удалось подготовить запрос к ИИ.'
            );
        }

        $curl = curl_init(
            'https://api.openai.com/v1/responses'
        );

        curl_setopt_array(
            $curl,
            [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => $payload
            ]
        );

        $rawResponse = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );
        curl_close($curl);

        if ($rawResponse === false || $curlError !== '') {
            throw new RuntimeException(
                'Не удалось связаться с сервисом ИИ: '
                . ($curlError !== '' ? $curlError : 'ошибка соединения')
            );
        }

        $response = json_decode(
            $rawResponse,
            true
        );

        if (!is_array($response)) {
            throw new RuntimeException(
                'Сервис ИИ вернул некорректный ответ.'
            );
        }

        if ($httpStatus < 200 || $httpStatus >= 300) {
            $apiMessage = trim((string) (
                $response['error']['message']
                ?? 'Неизвестная ошибка API.'
            ));

            throw new RuntimeException(
                'Ошибка ИИ-перевода: ' . $apiMessage
            );
        }

        $outputText = $this->extractOutputText($response);

        if ($outputText === '') {
            throw new RuntimeException(
                'ИИ не вернул текст перевода.'
            );
        }

        $translation = $this->decodeTranslationJson(
            $outputText
        );

        return [
            'name' => trim((string) (
                $translation['name'] ?? ''
            )),
            'description' => trim((string) (
                $translation['description'] ?? ''
            ))
        ];
    }


    private function extractOutputText(array $response)
    {
        foreach ($response['output'] ?? [] as $outputItem) {
            foreach ($outputItem['content'] ?? [] as $contentItem) {
                if (
                    isset($contentItem['text'])
                    && is_string($contentItem['text'])
                ) {
                    return trim($contentItem['text']);
                }
            }
        }

        return '';
    }


    private function decodeTranslationJson($text)
    {
        $text = trim((string) $text);

        if (strpos($text, '```') === 0) {
            $text = preg_replace(
                '/^```(?:json)?\s*/i',
                '',
                $text
            );
            $text = preg_replace(
                '/\s*```$/',
                '',
                $text
            );
            $text = trim((string) $text);
        }

        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'ИИ вернул перевод в неожиданном формате.'
            );
        }

        return $decoded;
    }
}
