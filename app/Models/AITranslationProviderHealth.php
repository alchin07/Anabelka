<?php

class AITranslationProviderHealth
{
    private static $schemaReady = false;


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_translation_provider_health
            (
                provider_code VARCHAR(32) NOT NULL,
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                response_ms INT UNSIGNED NULL,
                error_message VARCHAR(500) NOT NULL DEFAULT '',
                checked_at TIMESTAMP NULL DEFAULT NULL,
                last_success_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (provider_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function record(
        $providerCode,
        $isSuccess,
        $responseMs,
        $errorMessage = ''
    ) {
        self::ensureTable();

        $providerCode = substr(
            strtolower(trim((string) $providerCode)),
            0,
            32
        );

        if ($providerCode === '') {
            throw new InvalidArgumentException(
                'Не вказано сервіс для перевірки.'
            );
        }

        $errorMessage = self::limitText($errorMessage, 500);
        $isSuccess = $isSuccess ? 1 : 0;

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO ai_translation_provider_health
            (
                provider_code,
                is_success,
                response_ms,
                error_message,
                checked_at,
                last_success_at
            )
            VALUES
            (
                :provider_code,
                :is_success,
                :response_ms,
                :error_message,
                CURRENT_TIMESTAMP,
                CASE
                    WHEN :last_success_flag = 1
                    THEN CURRENT_TIMESTAMP
                    ELSE NULL
                END
            )
            ON DUPLICATE KEY UPDATE
                is_success = VALUES(is_success),
                response_ms = VALUES(response_ms),
                error_message = VALUES(error_message),
                checked_at = VALUES(checked_at),
                last_success_at = CASE
                    WHEN VALUES(is_success) = 1
                    THEN VALUES(checked_at)
                    ELSE last_success_at
                END
        ");

        return $stmt->execute([
            'provider_code' => $providerCode,
            'is_success' => $isSuccess,
            'response_ms' => max(0, (int) $responseMs),
            'error_message' => $isSuccess ? '' : $errorMessage,
            'last_success_flag' => $isSuccess
        ]);
    }


    public static function all()
    {
        self::ensureTable();

        $db = Database::connect();
        $rows = $db->query("
            SELECT
                provider_code,
                is_success,
                response_ms,
                error_message,
                checked_at,
                last_success_at
            FROM ai_translation_provider_health
            ORDER BY provider_code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($rows as $row) {
            $code = strtolower(
                trim((string) ($row['provider_code'] ?? ''))
            );

            if ($code === '') {
                continue;
            }

            $result[$code] = [
                'provider_code' => $code,
                'is_success' => !empty($row['is_success']),
                'response_ms' => (int) ($row['response_ms'] ?? 0),
                'error_message' => (string) (
                    $row['error_message'] ?? ''
                ),
                'checked_at' => (string) ($row['checked_at'] ?? ''),
                'last_success_at' => (string) (
                    $row['last_success_at'] ?? ''
                )
            ];
        }

        return $result;
    }


    private static function limitText($text, $limit)
    {
        $text = trim((string) $text);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_substr')) {
            return (string) mb_substr($text, 0, $limit, 'UTF-8');
        }

        return substr($text, 0, $limit);
    }
}
