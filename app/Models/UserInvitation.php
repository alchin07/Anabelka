<?php

class UserInvitation
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_invitations
            (
                user_id INT UNSIGNED NOT NULL,
                contact VARCHAR(160) NOT NULL DEFAULT '',
                channel VARCHAR(30) NOT NULL DEFAULT 'other',
                status VARCHAR(20) NOT NULL DEFAULT 'created',
                created_by_user_id INT UNSIGNED NULL,
                sent_at DATETIME NULL,
                accepted_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                KEY idx_user_invitations_status (status),
                KEY idx_user_invitations_created (created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function createAccount(
        $name,
        $email,
        $rankId,
        $channel,
        $contact,
        $createdByUserId = null
    ) {
        self::ensureSchema();

        $name = trim((string) $name);
        $email = trim((string) $email);
        $rankId = (int) $rankId;
        $channel = self::normalizeChannel($channel);
        $contact = trim((string) $contact);
        $createdByUserId = $createdByUserId !== null
            ? (int) $createdByUserId
            : null;

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть ім’я користувача.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Вкажіть коректний email.');
        }

        if ($contact === '') {
            throw new InvalidArgumentException(
                'Вкажіть телефон або контакт для запрошення.'
            );
        }

        if (strlen($contact) > 160) {
            throw new InvalidArgumentException(
                'Контакт для запрошення занадто довгий.'
            );
        }

        $db = Database::connect();

        $existing = $db->prepare("
            SELECT id
            FROM users
            WHERE email = :email
            LIMIT 1
        ");
        $existing->execute(['email' => $email]);

        if ($existing->fetchColumn()) {
            throw new RuntimeException(
                'Користувач із таким email уже існує.'
            );
        }

        $rankStmt = $db->prepare("
            SELECT id, name, slug, level
            FROM user_ranks
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ");
        $rankStmt->execute(['id' => $rankId]);
        $rank = $rankStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rank || ($rank['slug'] ?? '') === 'guest') {
            throw new RuntimeException(
                'Оберіть активний ранг користувача.'
            );
        }

        $plainPassword = self::generatePassword(12);
        $passwordHash = password_hash(
            $plainPassword,
            PASSWORD_DEFAULT
        );

        $db->beginTransaction();

        try {
            $userStmt = $db->prepare("
                INSERT INTO users
                (
                    rank_id,
                    name,
                    email,
                    password,
                    is_active
                )
                VALUES
                (
                    :rank_id,
                    :name,
                    :email,
                    :password,
                    1
                )
            ");
            $userStmt->execute([
                'rank_id' => $rankId,
                'name' => $name,
                'email' => $email,
                'password' => $passwordHash
            ]);

            $userId = (int) $db->lastInsertId();

            $inviteStmt = $db->prepare("
                INSERT INTO user_invitations
                (
                    user_id,
                    contact,
                    channel,
                    status,
                    created_by_user_id
                )
                VALUES
                (
                    :user_id,
                    :contact,
                    :channel,
                    'created',
                    :created_by_user_id
                )
            ");
            $inviteStmt->execute([
                'user_id' => $userId,
                'contact' => $contact,
                'channel' => $channel,
                'created_by_user_id' => $createdByUserId > 0
                    ? $createdByUserId
                    : null
            ]);

            $db->commit();

            return [
                'user_id' => $userId,
                'name' => $name,
                'email' => $email,
                'password' => $plainPassword,
                'rank' => $rank,
                'channel' => $channel,
                'contact' => $contact
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function markSent($userId)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE user_invitations
            SET
                status = CASE
                    WHEN status = 'accepted' THEN 'accepted'
                    ELSE 'sent'
                END,
                sent_at = COALESCE(sent_at, NOW())
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);

        if ($stmt->rowCount() < 1) {
            $check = $db->prepare("
                SELECT user_id
                FROM user_invitations
                WHERE user_id = :user_id
                LIMIT 1
            ");
            $check->execute(['user_id' => $userId]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException(
                    'Для цього користувача немає запрошення.'
                );
            }
        }
    }


    public static function markAccepted($userId)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE user_invitations
            SET
                status = 'accepted',
                accepted_at = COALESCE(accepted_at, NOW())
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => $userId]);
    }


    private static function normalizeChannel($channel)
    {
        $channel = strtolower(trim((string) $channel));
        $allowed = ['viber', 'whatsapp', 'telegram', 'other'];

        return in_array($channel, $allowed, true)
            ? $channel
            : 'other';
    }


    private static function generatePassword($length)
    {
        $length = max(10, min(32, (int) $length));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        $max = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
