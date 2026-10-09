<?php

class ImageProcessorException extends RuntimeException
{
    public $diagnostics;

    public function __construct($message, array $diagnostics = [])
    {
        parent::__construct($message);
        $this->diagnostics = $diagnostics;
    }
}

class ImageProcessorClient
{
    private const DEFAULT_ENDPOINT = 'http://127.0.0.1:8765';
    private const PRODUCT_PREFIX = 'uploads/products/';
    private const BACKGROUND_PROFILES = [
        'original-canvas',
        'studio-light',
        'anabelka-brand'
    ];


    public static function health()
    {
        return self::request('GET', '/health', null, 3);
    }


    public static function processProductImage(
        $path,
        $backgroundProfile = 'original-canvas',
        $maskMode = 'auto'
    ) {
        $relativePath = self::normalizeProductImagePath($path);
        $backgroundProfile = self::normalizeBackgroundProfile(
            $backgroundProfile
        );
        $maskMode = self::normalizeMaskMode($maskMode);
        $absolutePath = self::projectRoot() . '/' . $relativePath;

        if (!is_file($absolutePath)) {
            throw new InvalidArgumentException(
                'Файл фотографії товару не знайдено.'
            );
        }

        return self::request(
            'POST',
            '/process',
            [
                'source' => $relativePath,
                'background_profile' => $backgroundProfile,
                'mask_mode' => $maskMode
            ],
            180
        );
    }


    private static function request(
        $method,
        $path,
        $payload = null,
        $timeout = 5
    ) {
        $method = strtoupper(trim((string) $method));
        $path = '/' . ltrim((string) $path, '/');
        $headers = [
            'Accept: application/json'
        ];
        $options = [
            'method' => $method,
            'timeout' => max(1, (int) $timeout),
            'ignore_errors' => true
        ];

        if ($payload !== null) {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if (!is_string($json)) {
                throw new RuntimeException(
                    'Не вдалося підготувати запит до обробника зображень.'
                );
            }

            $headers[] = 'Content-Type: application/json; charset=UTF-8';
            $headers[] = 'Content-Length: ' . strlen($json);
            $options['content'] = $json;
        }

        $options['header'] = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => $options
        ]);
        $responseHeaders = [];
        $body = @file_get_contents(
            self::endpoint() . $path,
            false,
            $context
        );

        $receivedHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);
        if (is_array($receivedHeaders)) { $responseHeaders = $receivedHeaders; }

        if ($body === false) {
            throw new RuntimeException(
                'Локальний сервіс обробки зображень недоступний.'
            );
        }

        $status = self::statusCode($responseHeaders);
        $data = json_decode((string) $body, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                'Обробник зображень повернув некоректну відповідь.'
            );
        }

        if (
            $status < 200
            || $status >= 300
            || empty($data['ok'])
        ) {
            $message = trim((string) ($data['error'] ?? ''));

            $diagnostics = is_array($data['normalization'] ?? null)
                ? $data['normalization'] : [];
            foreach (['mask_mode_requested', 'mask_method', 'processor_version', 'worker_error'] as $key) {
                if (array_key_exists($key, $data)) {
                    $diagnostics[$key] = $data[$key];
                }
            }
            throw new ImageProcessorException(
                $message !== ''
                    ? $message
                    : 'Обробник зображень не виконав операцію.',
                $diagnostics
            );
        }

        return $data;
    }


    private static function normalizeBackgroundProfile($profile)
    {
        $profile = strtolower(trim((string) $profile));

        if ($profile === '') {
            $profile = 'original-canvas';
        }

        if (!in_array($profile, self::BACKGROUND_PROFILES, true)) {
            throw new InvalidArgumentException(
                'Невідомий профіль фону фотографії.'
            );
        }

        return $profile;
    }


    public static function normalizeMaskMode($maskMode)
    {
        if (!is_string($maskMode)) {
            throw new InvalidArgumentException('Некоректний метод маски фотографії.');
        }
        $maskMode = strtolower(trim((string) $maskMode));
        if (!in_array($maskMode, ['auto', 'grabcut', 'modnet'], true)) {
            throw new InvalidArgumentException('Невідомий метод маски фотографії.');
        }
        return $maskMode;
    }


    private static function normalizeProductImagePath($path)
    {
        $path = trim(str_replace('\\', '/', (string) $path));

        if (strpos($path, '/Anabelka/') === 0) {
            $path = substr($path, strlen('/Anabelka/'));
        }

        $path = ltrim($path, '/');

        if (
            $path === ''
            || strpos($path, "\0") !== false
            || strpos($path, '../') !== false
            || strpos($path, '/..') !== false
            || strpos($path, self::PRODUCT_PREFIX) !== 0
        ) {
            throw new InvalidArgumentException(
                'Некоректний шлях до фотографії товару.'
            );
        }

        $filename = substr(
            $path,
            strlen(self::PRODUCT_PREFIX)
        );

        if (
            $filename === ''
            || basename($filename) !== $filename
            || preg_match('/^[A-Za-z0-9._-]+$/', $filename) !== 1
        ) {
            throw new InvalidArgumentException(
                'Некоректне ім’я файла фотографії товару.'
            );
        }

        return self::PRODUCT_PREFIX . $filename;
    }


    private static function endpoint()
    {
        $configured = trim((string) getenv(
            'ANABELKA_IMAGE_PROCESSOR_URL'
        ));

        if ($configured === '') {
            return self::DEFAULT_ENDPOINT;
        }

        $configured = rtrim($configured, '/');

        if (
            preg_match(
                '#^https?://(?:127\\.0\\.0\\.1|localhost)(?::[0-9]{1,5})?$#i',
                $configured
            ) !== 1
        ) {
            throw new RuntimeException(
                'Адреса локального обробника зображень некоректна.'
            );
        }

        return $configured;
    }


    private static function projectRoot()
    {
        return dirname(__DIR__, 2);
    }


    private static function statusCode(array $headers)
    {
        if (empty($headers)) {
            return 0;
        }

        if (
            preg_match(
                '#\\s([0-9]{3})(?:\\s|$)#',
                (string) $headers[0],
                $matches
            ) !== 1
        ) {
            return 0;
        }

        return (int) $matches[1];
    }
}
