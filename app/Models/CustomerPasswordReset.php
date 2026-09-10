<?php

class CustomerPasswordReset
{
    private static $schemaReady = false;

    private const TOKEN_TTL_SECONDS = 3600;
    private const REQUEST_COOLDOWN_SECONDS = 60;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS customer_password_resets
            (
                user_id INT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                token_hash CHAR(64) NULL,
                expires_at DATETIME NULL,
                last_requested_at DATETIME NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                KEY idx_customer_password_reset_token (token_hash),
                KEY idx_customer_password_reset_email (email)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function issueForUser($userId, $email)
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $email = self::normalizeEmail($email);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $existing = Database::connect()->prepare("
            SELECT last_requested_at
            FROM customer_password_resets
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $existing->execute(['user_id' => $userId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if (!empty($row['last_requested_at'])) {
            $lastRequested = strtotime((string) $row['last_requested_at']);

            if (
                $lastRequested !== false
                && time() - $lastRequested < self::REQUEST_COOLDOWN_SECONDS
            ) {
                throw new RuntimeException(
                    'Зачекайте приблизно хвилину перед повторним запитом.'
                );
            }
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);
        $now = date('Y-m-d H:i:s');

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_password_resets
            (
                user_id,
                email,
                token_hash,
                expires_at,
                last_requested_at,
                used_at
            )
            VALUES
            (
                :user_id,
                :email,
                :token_hash,
                :expires_at,
                :last_requested_at,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                token_hash = VALUES(token_hash),
                expires_at = VALUES(expires_at),
                last_requested_at = VALUES(last_requested_at),
                used_at = NULL,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'last_requested_at' => $now
        ]);

        return [
            'token' => $token,
            'email' => $email,
            'expires_at' => $expiresAt
        ];
    }


    public static function validateToken($token)
    {
        self::ensureSchema();
        $token = self::normalizeToken($token);

        $stmt = Database::connect()->prepare("
            SELECT
                pr.user_id,
                pr.email,
                pr.expires_at,
                pr.used_at,
                u.name,
                u.is_active
            FROM customer_password_resets pr
            INNER JOIN users u ON u.id = pr.user_id
            WHERE pr.token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute(['token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertUsableRow($row);

        return [
            'user_id' => (int) $row['user_id'],
            'email' => (string) $row['email'],
            'name' => (string) ($row['name'] ?? ''),
            'expires_at' => (string) $row['expires_at']
        ];
    }


    public static function resetPassword($token, $newPassword)
    {
        self::ensureSchema();
        PasswordPolicy::validate($newPassword);
        $token = self::normalizeToken($token);
        $tokenHash = hash('sha256', $token);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    pr.user_id,
                    pr.email,
                    pr.expires_at,
                    pr.used_at,
                    u.email AS current_email,
                    u.is_active
                FROM customer_password_resets pr
                INNER JOIN users u ON u.id = pr.user_id
                WHERE pr.token_hash = :token_hash
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['token_hash' => $tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            self::assertUsableRow($row);

            if (
                strtolower(trim((string) $row['current_email']))
                !== strtolower(trim((string) $row['email']))
            ) {
                throw new RuntimeException(
                    'Email акаунта було змінено. Створіть новий запит на відновлення пароля.'
                );
            }

            $db->prepare("
                UPDATE users
                SET password = :password
                WHERE id = :user_id
            ")->execute([
                'password' => password_hash((string) $newPassword, PASSWORD_DEFAULT),
                'user_id' => (int) $row['user_id']
            ]);

            $db->prepare("
                UPDATE customer_password_resets
                SET
                    token_hash = NULL,
                    expires_at = NULL,
                    used_at = NOW()
                WHERE user_id = :user_id
            ")->execute(['user_id' => (int) $row['user_id']]);

            $db->commit();

            return [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email']
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function deleteForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            DELETE FROM customer_password_resets
            WHERE user_id = :user_id
        ");

        return $stmt->execute(['user_id' => (int) $userId]);
    }


    private static function assertUsableRow($row)
    {
        if (!$row || !empty($row['used_at'])) {
            throw new RuntimeException(
                'Посилання недійсне або вже використане.'
            );
        }

        if (empty($row['is_active'])) {
            throw new RuntimeException('Акаунт користувача недоступний.');
        }

        $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));

        if ($expiresAt === false || $expiresAt < time()) {
            throw new RuntimeException(
                'Термін дії посилання завершився. Створіть новий запит.'
            );
        }
    }


    private static function normalizeToken($token)
    {
        $token = trim((string) $token);

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new RuntimeException('Посилання відновлення недійсне.');
        }

        return $token;
    }


    private static function normalizeEmail($email)
    {
        $email = strtolower(trim((string) $email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Вкажіть коректний email.');
        }

        return $email;
    }
}
