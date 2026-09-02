<?php

class AITranslationUsage
{
    private static $schemaReady = false;


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS ai_translation_usage
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_code VARCHAR(32) NOT NULL,
                target_language VARCHAR(16) NOT NULL,
                translation_context VARCHAR(64) NOT NULL,
                input_characters INT UNSIGNED NOT NULL DEFAULT 0,
                output_characters INT UNSIGNED NOT NULL DEFAULT 0,
                is_success TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_ai_usage_created_at (created_at),
                INDEX idx_ai_usage_provider (provider_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function record(
        $providerCode,
        $targetLanguage,
        $context,
        $inputCharacters,
        $outputCharacters,
        $isSuccess
    ) {
        self::ensureTable();

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO ai_translation_usage
            (
                provider_code,
                target_language,
                translation_context,
                input_characters,
                output_characters,
                is_success
            )
            VALUES
            (
                :provider_code,
                :target_language,
                :translation_context,
                :input_characters,
                :output_characters,
                :is_success
            )
        ");

        return $stmt->execute([
            'provider_code' => substr(
                strtolower(trim((string) $providerCode)),
                0,
                32
            ),
            'target_language' => substr(
                strtolower(trim((string) $targetLanguage)),
                0,
                16
            ),
            'translation_context' => substr(
                trim((string) $context),
                0,
                64
            ),
            'input_characters' => max(0, (int) $inputCharacters),
            'output_characters' => max(0, (int) $outputCharacters),
            'is_success' => $isSuccess ? 1 : 0
        ]);
    }


    public static function dashboard()
    {
        self::ensureTable();

        return [
            'periods' => self::periods(),
            'providers' => self::providers(),
            'recent' => self::recent()
        ];
    }


    private static function periods()
    {
        $db = Database::connect();

        $row = $db->query("
            SELECT
                COUNT(*) AS total_requests,
                SUM(
                    CASE WHEN is_success = 1 THEN 1 ELSE 0 END
                ) AS total_success,
                SUM(
                    CASE WHEN is_success = 0 THEN 1 ELSE 0 END
                ) AS total_errors,
                SUM(input_characters) AS total_input,
                SUM(output_characters) AS total_output,
                SUM(
                    CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END
                ) AS today_requests,
                SUM(
                    CASE
                        WHEN created_at >= CURDATE()
                        THEN input_characters + output_characters
                        ELSE 0
                    END
                ) AS today_characters,
                SUM(
                    CASE
                        WHEN created_at >= DATE_FORMAT(
                            CURDATE(),
                            '%Y-%m-01'
                        )
                        THEN 1
                        ELSE 0
                    END
                ) AS month_requests,
                SUM(
                    CASE
                        WHEN created_at >= DATE_FORMAT(
                            CURDATE(),
                            '%Y-%m-01'
                        )
                        THEN input_characters + output_characters
                        ELSE 0
                    END
                ) AS month_characters
            FROM ai_translation_usage
        ")->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'today' => [
                'requests' => (int) ($row['today_requests'] ?? 0),
                'characters' => (int) ($row['today_characters'] ?? 0)
            ],
            'month' => [
                'requests' => (int) ($row['month_requests'] ?? 0),
                'characters' => (int) ($row['month_characters'] ?? 0)
            ],
            'total' => [
                'requests' => (int) ($row['total_requests'] ?? 0),
                'success' => (int) ($row['total_success'] ?? 0),
                'errors' => (int) ($row['total_errors'] ?? 0),
                'input_characters' => (int) ($row['total_input'] ?? 0),
                'output_characters' => (int) ($row['total_output'] ?? 0),
                'characters' => (int) ($row['total_input'] ?? 0)
                    + (int) ($row['total_output'] ?? 0)
            ]
        ];
    }


    private static function providers()
    {
        $db = Database::connect();

        $rows = $db->query("
            SELECT
                provider_code,
                COUNT(*) AS requests,
                SUM(
                    CASE WHEN is_success = 1 THEN 1 ELSE 0 END
                ) AS success,
                SUM(
                    CASE WHEN is_success = 0 THEN 1 ELSE 0 END
                ) AS errors,
                SUM(input_characters) AS input_characters,
                SUM(output_characters) AS output_characters
            FROM ai_translation_usage
            GROUP BY provider_code
            ORDER BY requests DESC, provider_code ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            function ($row) {
                $input = (int) ($row['input_characters'] ?? 0);
                $output = (int) ($row['output_characters'] ?? 0);

                return [
                    'provider_code' => (string) (
                        $row['provider_code'] ?? ''
                    ),
                    'requests' => (int) ($row['requests'] ?? 0),
                    'success' => (int) ($row['success'] ?? 0),
                    'errors' => (int) ($row['errors'] ?? 0),
                    'input_characters' => $input,
                    'output_characters' => $output,
                    'characters' => $input + $output
                ];
            },
            $rows
        );
    }


    private static function recent()
    {
        $db = Database::connect();

        return $db->query("
            SELECT
                provider_code,
                target_language,
                translation_context,
                input_characters,
                output_characters,
                is_success,
                created_at
            FROM ai_translation_usage
            ORDER BY id DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
