<?php

class CustomerEmailVerification
{
    private static $schemaReady = false;

    private const TOKEN_TTL_SECONDS = 86400;
    private const RESEND_COOLDOWN_SECONDS = 60;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS customer_email_verifications
            (
                user_id INT UNSIGNED NOT NULL,
                email VARCHAR(190) NOT NULL,
                token_hash CHAR(64) NULL,
                expires_at DATETIME NULL,
                verified_at DATETIME NULL,
                last_sent_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                KEY idx_customer_email_verification_token (token_hash),
                KEY idx_customer_email_verification_email (email)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function statusForUser($userId, $email)
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $email = self::normalizeEmail($email);

        if ($userId <= 0) {
            return self::emptyStatus($email);
        }

        $stmt = Database::connect()->prepare("
            SELECT
                user_id,
                email,
                verified_at,
                expires_at,
                last_sent_at
            FROM customer_email_verifications
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || strtolower((string) ($row['email'] ?? '')) !== $email) {
            return self::emptyStatus($email);
        }

        $lastSentAt = (string) ($row['last_sent_at'] ?? '');
        $retryAfter = 0;

        if ($lastSentAt !== '') {
            $sentAt = strtotime($lastSentAt);
            if ($sentAt !== false) {
                $retryAfter = max(
                    0,
                    self::RESEND_COOLDOWN_SECONDS - (time() - $sentAt)
                );
            }
        }

        return [
            'email' => $email,
            'verified' => !empty($row['verified_at']),
            'verified_at' => $row['verified_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'last_sent_at' => $row['last_sent_at'] ?? null,
            'can_resend' => $retryAfter === 0,
            'retry_after' => $retryAfter
        ];
    }


    public static function issueForUser($userId, $email, $ignoreCooldown = false)
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $email = self::normalizeEmail($email);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $status = self::statusForUser($userId, $email);

        if (!empty($status['verified'])) {
            throw new RuntimeException('Email уже підтверджено.');
        }

        if (!$ignoreCooldown && empty($status['can_resend'])) {
            throw new RuntimeException(
                'Зачекайте приблизно хвилину перед повторним надсиланням.'
            );
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECONDS);
        $now = date('Y-m-d H:i:s');

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_email_verifications
            (
                user_id,
                email,
                token_hash,
                expires_at,
                verified_at,
                last_sent_at
            )
            VALUES
            (
                :user_id,
                :email,
                :token_hash,
                :expires_at,
                NULL,
                :last_sent_at
            )
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                token_hash = VALUES(token_hash),
                expires_at = VALUES(expires_at),
                verified_at = NULL,
                last_sent_at = VALUES(last_sent_at),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'user_id' => $userId,
            'email' => $email,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'last_sent_at' => $now
        ]);

        return [
            'token' => $token,
            'email' => $email,
            'expires_at' => $expiresAt
        ];
    }


    public static function verifyToken($token)
    {
        self::ensureSchema();

        $token = trim((string) $token);

        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            throw new RuntimeException('Посилання підтвердження недійсне.');
        }

        $tokenHash = hash('sha256', $token);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT user_id, email, expires_at, verified_at
                FROM customer_email_verifications
                WHERE token_hash = :token_hash
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['token_hash' => $tokenHash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Посилання підтвердження недійсне або вже використане.');
            }

            if (!empty($row['verified_at'])) {
                throw new RuntimeException('Email уже підтверджено.');
            }

            $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));

            if ($expiresAt === false || $expiresAt < time()) {
                throw new RuntimeException('Термін дії посилання завершився. Надішліть нове.');
            }

            $userStmt = $db->prepare("
                SELECT id, email, is_active
                FROM users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute(['id' => (int) $row['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['is_active'])) {
                throw new RuntimeException('Акаунт користувача недоступний.');
            }

            if (
                strtolower(trim((string) $user['email']))
                !== strtolower(trim((string) $row['email']))
            ) {
                throw new RuntimeException('Email акаунта було змінено. Надішліть нове підтвердження.');
            }

            $update = $db->prepare("
                UPDATE customer_email_verifications
                SET verified_at = NOW(),
                    token_hash = NULL,
                    expires_at = NULL
                WHERE user_id = :user_id
            ");
            $update->execute(['user_id' => (int) $row['user_id']]);

            $db->commit();

            return [
                'user_id' => (int) $row['user_id'],
                'email' => (string) $row['email'],
                'verified' => true
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function invalidateForEmailChange($userId, $oldEmail, $newEmail)
    {
        self::ensureSchema();

        $userId = (int) $userId;
        $oldEmail = self::normalizeEmail($oldEmail);
        $newEmail = self::normalizeEmail($newEmail);

        if ($userId <= 0 || $oldEmail === $newEmail) {
            return false;
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_email_verifications
            (user_id, email, token_hash, expires_at, verified_at, last_sent_at)
            VALUES (:user_id, :email, NULL, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                token_hash = NULL,
                expires_at = NULL,
                verified_at = NULL,
                last_sent_at = NULL,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'user_id' => $userId,
            'email' => $newEmail
        ]);

        return true;
    }


    public static function deleteForUser($userId)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            DELETE FROM customer_email_verifications
            WHERE user_id = :user_id
        ");

        return $stmt->execute(['user_id' => (int) $userId]);
    }


    private static function emptyStatus($email)
    {
        return [
            'email' => (string) $email,
            'verified' => false,
            'verified_at' => null,
            'expires_at' => null,
            'last_sent_at' => null,
            'can_resend' => true,
            'retry_after' => 0
        ];
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
