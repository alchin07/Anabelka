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
                token_hash CHAR(64) NOT NULL DEFAULT '',
                expires_at DATETIME NULL,
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

        $columns = $db->query("SHOW COLUMNS FROM user_invitations")
            ->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(
            function ($column) {
                return strtolower((string) ($column['Field'] ?? ''));
            },
            $columns
        );

        if (!in_array('token_hash', $columnNames, true)) {
            $db->exec("
                ALTER TABLE user_invitations
                ADD COLUMN token_hash CHAR(64) NOT NULL DEFAULT '' AFTER status
            ");
        }

        if (!in_array('expires_at', $columnNames, true)) {
            $db->exec("
                ALTER TABLE user_invitations
                ADD COLUMN expires_at DATETIME NULL AFTER token_hash
            ");
        }

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

        $inviteToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $inviteToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 7 * 86400);
        $unusablePassword = bin2hex(random_bytes(32));
        $passwordHash = password_hash(
            $unusablePassword,
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
                    token_hash,
                    expires_at,
                    created_by_user_id
                )
                VALUES
                (
                    :user_id,
                    :contact,
                    :channel,
                    'created',
                    :token_hash,
                    :expires_at,
                    :created_by_user_id
                )
            ");
            $inviteStmt->execute([
                'user_id' => $userId,
                'contact' => $contact,
                'channel' => $channel,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by_user_id' => $createdByUserId > 0
                    ? $createdByUserId
                    : null
            ]);

            $db->commit();

            return [
                'user_id' => $userId,
                'name' => $name,
                'email' => $email,
                'invite_token' => $inviteToken,
                'expires_at' => $expiresAt,
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


    public static function findByToken($token)
    {
        self::ensureSchema();
        $token = trim((string) $token);

        if ($token === '') {
            return null;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT
                ui.user_id,
                ui.contact,
                ui.channel,
                ui.status,
                ui.expires_at,
                u.name,
                u.email,
                u.rank_id,
                ur.name AS rank_name,
                ur.slug AS rank_slug
            FROM user_invitations ui
            INNER JOIN users u ON u.id = ui.user_id
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            WHERE ui.token_hash = :token_hash
              AND ui.status IN ('created', 'sent')
              AND ui.expires_at IS NOT NULL
              AND ui.expires_at >= NOW()
            LIMIT 1
        ");
        $stmt->execute([
            'token_hash' => hash('sha256', $token)
        ]);

        $invite = $stmt->fetch(PDO::FETCH_ASSOC);

        return $invite ?: null;
    }


    public static function accept($token, $password)
    {
        self::ensureSchema();
        $token = trim((string) $token);
        $password = (string) $password;

        if ($token === '') {
            throw new InvalidArgumentException('Некоректне запрошення.');
        }

        PasswordPolicy::validate($password);

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    ui.user_id,
                    u.name,
                    u.email,
                    u.rank_id,
                    ur.slug AS rank_slug
                FROM user_invitations ui
                INNER JOIN users u ON u.id = ui.user_id
                INNER JOIN user_ranks ur ON ur.id = u.rank_id
                WHERE ui.token_hash = :token_hash
                  AND ui.status IN ('created', 'sent')
                  AND ui.expires_at IS NOT NULL
                  AND ui.expires_at >= NOW()
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                'token_hash' => hash('sha256', $token)
            ]);
            $invite = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException(
                    'Запрошення недійсне або строк його дії закінчився.'
                );
            }

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $db->prepare("
                UPDATE users
                SET password = :password
                WHERE id = :user_id
            ")->execute([
                'password' => $passwordHash,
                'user_id' => (int) $invite['user_id']
            ]);

            $db->prepare("
                UPDATE user_invitations
                SET
                    status = 'accepted',
                    accepted_at = COALESCE(accepted_at, NOW()),
                    token_hash = ''
                WHERE user_id = :user_id
            ")->execute([
                'user_id' => (int) $invite['user_id']
            ]);

            $db->commit();

            return $invite;
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
                accepted_at = COALESCE(accepted_at, NOW()),
                token_hash = ''
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
}
