<?php

class CustomerSocialIdentity
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();
        $db->exec("\n            CREATE TABLE IF NOT EXISTS customer_social_identities\n            (\n                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n                user_id INT UNSIGNED NOT NULL,\n                provider VARCHAR(30) NOT NULL,\n                provider_user_id VARCHAR(191) NOT NULL,\n                email VARCHAR(190) NOT NULL,\n                profile_json TEXT NULL,\n                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                last_login_at DATETIME NULL,\n                PRIMARY KEY (id),\n                UNIQUE KEY uq_social_provider_identity\n                    (provider, provider_user_id),\n                UNIQUE KEY uq_social_user_provider\n                    (user_id, provider),\n                KEY idx_social_email (email)\n            ) ENGINE=InnoDB\n              DEFAULT CHARSET=utf8mb4\n              COLLATE=utf8mb4_unicode_ci\n        ");

        $db->exec("\n            DELETE si\n            FROM customer_social_identities si\n            LEFT JOIN users u ON u.id = si.user_id\n            WHERE u.id IS NULL\n        ");

        self::$schemaReady = true;
    }


    public static function allForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("\n            SELECT *\n            FROM customer_social_identities\n            WHERE user_id = :user_id\n            ORDER BY created_at ASC, id ASC\n        ");
        $stmt->execute(['user_id' => (int) $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }


    public static function findByProviderIdentity($provider, $providerUserId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("\n            SELECT *\n            FROM customer_social_identities\n            WHERE provider = :provider\n              AND provider_user_id = :provider_user_id\n            LIMIT 1\n        ");
        $stmt->execute([
            'provider' => self::normalizeProvider($provider),
            'provider_user_id' => trim((string) $providerUserId)
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    public static function findByUserProvider($userId, $provider)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("\n            SELECT *\n            FROM customer_social_identities\n            WHERE user_id = :user_id\n              AND provider = :provider\n            LIMIT 1\n        ");
        $stmt->execute([
            'user_id' => (int) $userId,
            'provider' => self::normalizeProvider($provider)
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }


    public static function link($userId, $provider, $providerUserId, $email, array $profile = [])
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $provider = self::normalizeProvider($provider);
        $providerUserId = trim((string) $providerUserId);
        $email = strtolower(trim((string) $email));

        if ($userId <= 0 || $providerUserId === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Некоректні дані зовнішнього акаунта.');
        }

        $byIdentity = self::findByProviderIdentity($provider, $providerUserId);

        if ($byIdentity && (int) $byIdentity['user_id'] !== $userId) {
            throw new RuntimeException('Цей зовнішній акаунт уже прив’язаний до іншого користувача.');
        }

        $byUser = self::findByUserProvider($userId, $provider);

        if (
            $byUser
            && (string) $byUser['provider_user_id'] !== $providerUserId
        ) {
            throw new RuntimeException('До цього користувача вже прив’язаний інший акаунт цього провайдера.');
        }

        $profileJson = !empty($profile)
            ? json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        if ($byIdentity || $byUser) {
            $stmt = Database::connect()->prepare("\n                UPDATE customer_social_identities\n                SET email = :email,\n                    profile_json = :profile_json,\n                    last_login_at = NOW()\n                WHERE user_id = :user_id\n                  AND provider = :provider\n                  AND provider_user_id = :provider_user_id\n            ");
        } else {
            $stmt = Database::connect()->prepare("\n                INSERT INTO customer_social_identities\n                (user_id, provider, provider_user_id, email, profile_json, last_login_at)\n                VALUES\n                (:user_id, :provider, :provider_user_id, :email, :profile_json, NOW())\n            ");
        }

        $stmt->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'profile_json' => $profileJson
        ]);

        return true;
    }


    public static function deleteUserProvider($userId, $provider)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("\n            DELETE FROM customer_social_identities\n            WHERE user_id = :user_id\n              AND provider = :provider\n        ");

        return $stmt->execute([
            'user_id' => (int) $userId,
            'provider' => self::normalizeProvider($provider)
        ]);
    }


    public static function deleteForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("\n            DELETE FROM customer_social_identities\n            WHERE user_id = :user_id\n        ");

        return $stmt->execute(['user_id' => (int) $userId]);
    }


    private static function normalizeProvider($provider)
    {
        $provider = strtolower(trim((string) $provider));

        if (!preg_match('/^[a-z0-9_-]{2,30}$/', $provider)) {
            throw new InvalidArgumentException('Некоректний провайдер авторизації.');
        }

        return $provider;
    }
}
