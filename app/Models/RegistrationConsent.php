<?php

class RegistrationConsent
{
    public const TERMS_VERSION = 'dev-2026-09-10-1';
    public const PRIVACY_VERSION = 'dev-2026-09-10-1';

    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS customer_registration_consents
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                terms_version VARCHAR(60) NOT NULL,
                privacy_version VARCHAR(60) NOT NULL,
                terms_accepted_at DATETIME NOT NULL,
                privacy_accepted_at DATETIME NOT NULL,
                marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(40) NOT NULL DEFAULT 'registration',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_customer_registration_consent_version
                    (user_id, terms_version, privacy_version),
                KEY idx_customer_registration_consent_user
                    (user_id, created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        Database::connect()->exec("
            DELETE c
            FROM customer_registration_consents c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE u.id IS NULL
        ");

        self::$schemaReady = true;
    }


    public static function recordRegistration($userId, $marketingOptIn, $source = 'registration')
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $source = strtolower(trim((string) $source));

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $source)) {
            $source = 'registration';
        }

        $now = date('Y-m-d H:i:s');
        $stmt = Database::connect()->prepare("
            INSERT INTO customer_registration_consents
            (
                user_id,
                terms_version,
                privacy_version,
                terms_accepted_at,
                privacy_accepted_at,
                marketing_opt_in,
                source
            )
            VALUES
            (
                :user_id,
                :terms_version,
                :privacy_version,
                :terms_accepted_at,
                :privacy_accepted_at,
                :marketing_opt_in,
                :source
            )
            ON DUPLICATE KEY UPDATE
                terms_accepted_at = VALUES(terms_accepted_at),
                privacy_accepted_at = VALUES(privacy_accepted_at),
                marketing_opt_in = VALUES(marketing_opt_in),
                source = VALUES(source)
        ");

        $stmt->execute([
            'user_id' => $userId,
            'terms_version' => self::TERMS_VERSION,
            'privacy_version' => self::PRIVACY_VERSION,
            'terms_accepted_at' => $now,
            'privacy_accepted_at' => $now,
            'marketing_opt_in' => $marketingOptIn ? 1 : 0,
            'source' => $source
        ]);
    }
}
