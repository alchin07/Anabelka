<?php

class AdminInvitation
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        AdminAccess::ensureSchema();

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_invitations
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_user_id INT UNSIGNED NOT NULL,
                channel VARCHAR(30) NOT NULL DEFAULT 'other',
                contact VARCHAR(160) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'created',
                token_hash CHAR(64) NULL,
                expires_at DATETIME NOT NULL,
                created_by_admin_id INT UNSIGNED NULL,
                sent_at DATETIME NULL,
                accepted_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_invitations_admin (admin_user_id),
                UNIQUE KEY uq_admin_invitations_token (token_hash),
                KEY idx_admin_invitations_status_expiry (status, expires_at),
                CONSTRAINT fk_admin_invitations_admin
                    FOREIGN KEY (admin_user_id)
                    REFERENCES admin_users(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_admin_invitations_creator
                    FOREIGN KEY (created_by_admin_id)
                    REFERENCES admin_users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function create(
        $name,
        $email,
        $roleId,
        $channel,
        $contact,
        $createdByAdminId
    ) {
        self::ensureSchema();

        $name = self::normalizeName($name);
        $email = self::normalizeEmail($email);
        $channel = self::normalizeChannel($channel);
        $contact = self::normalizeContact($contact);
        $roleId = (int) $roleId;
        $createdByAdminId = (int) $createdByAdminId;

        if ($roleId <= 0) {
            throw new InvalidArgumentException(
                'Оберіть роль адміністратора.'
            );
        }

        $db = Database::connect();
        $roleStmt = $db->prepare("
            SELECT id, name, slug, is_system
            FROM admin_roles
            WHERE id = :id
              AND slug <> 'owner'
            LIMIT 1
        ");
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException(
                'Цю роль не можна призначити через запрошення.'
            );
        }

        $existing = $db->prepare("
            SELECT id
            FROM admin_users
            WHERE email = :email
            LIMIT 1
        ");
        $existing->execute(['email' => $email]);

        if ($existing->fetchColumn()) {
            throw new RuntimeException(
                'Адміністратор із таким email уже існує.'
            );
        }

        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $temporaryPassword = bin2hex(random_bytes(32));
        $expiresAt = (string) $db->query("
            SELECT DATE_FORMAT(
                DATE_ADD(NOW(), INTERVAL 7 DAY),
                '%Y-%m-%d %H:%i:%s'
            )
        ")->fetchColumn();

        $db->beginTransaction();

        try {
            $adminStmt = $db->prepare("
                INSERT INTO admin_users
                    (role_id, name, email, password_hash, is_active)
                VALUES
                    (:role_id, :name, :email, :password_hash, 0)
            ");
            $adminStmt->execute([
                'role_id' => (int) $role['id'],
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash(
                    $temporaryPassword,
                    PASSWORD_DEFAULT
                )
            ]);

            $adminId = (int) $db->lastInsertId();

            $inviteStmt = $db->prepare("
                INSERT INTO admin_invitations
                    (
                        admin_user_id,
                        channel,
                        contact,
                        status,
                        token_hash,
                        expires_at,
                        created_by_admin_id
                    )
                VALUES
                    (
                        :admin_user_id,
                        :channel,
                        :contact,
                        'created',
                        :token_hash,
                        :expires_at,
                        :created_by_admin_id
                    )
            ");
            $inviteStmt->execute([
                'admin_user_id' => $adminId,
                'channel' => $channel,
                'contact' => $contact,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by_admin_id' => $createdByAdminId > 0
                    ? $createdByAdminId
                    : null
            ]);

            $db->commit();

            return [
                'admin_id' => $adminId,
                'name' => $name,
                'email' => $email,
                'role_id' => (int) $role['id'],
                'role_name' => (string) $role['name'],
                'role_slug' => (string) $role['slug'],
                'channel' => $channel,
                'contact' => $contact,
                'expires_at' => $expiresAt,
                'invite_token' => $token
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

        $stmt = Database::connect()->prepare("
            SELECT
                ai.id,
                ai.admin_user_id,
                ai.channel,
                ai.contact,
                ai.status,
                ai.expires_at,
                au.name,
                au.email,
                ar.name AS role_name,
                ar.slug AS role_slug
            FROM admin_invitations ai
            INNER JOIN admin_users au
                ON au.id = ai.admin_user_id
            INNER JOIN admin_roles ar
                ON ar.id = au.role_id
            WHERE ai.token_hash = :token_hash
              AND ai.status IN ('created', 'sent')
              AND ai.expires_at > NOW()
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

        if ($token === '') {
            throw new InvalidArgumentException(
                'Запрошення недійсне.'
            );
        }

        self::validatePassword($password);

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    ai.id,
                    ai.admin_user_id,
                    ai.status,
                    ai.expires_at,
                    au.name,
                    au.email,
                    ar.name AS role_name,
                    ar.slug AS role_slug
                FROM admin_invitations ai
                INNER JOIN admin_users au
                    ON au.id = ai.admin_user_id
                INNER JOIN admin_roles ar
                    ON ar.id = au.role_id
                WHERE ai.token_hash = :token_hash
                  AND ai.status IN ('created', 'sent')
                  AND ai.expires_at > NOW()
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

            $adminId = (int) $invite['admin_user_id'];

            $activate = $db->prepare("
                UPDATE admin_users
                SET
                    password_hash = :password_hash,
                    is_active = 1
                WHERE id = :id
            ");
            $activate->execute([
                'password_hash' => password_hash(
                    (string) $password,
                    PASSWORD_DEFAULT
                ),
                'id' => $adminId
            ]);

            $finish = $db->prepare("
                UPDATE admin_invitations
                SET
                    status = 'accepted',
                    token_hash = NULL,
                    accepted_at = NOW()
                WHERE id = :id
            ");
            $finish->execute([
                'id' => (int) $invite['id']
            ]);

            $db->commit();

            return [
                'id' => $adminId,
                'name' => (string) $invite['name'],
                'email' => (string) $invite['email'],
                'role_name' => (string) $invite['role_name'],
                'role_slug' => (string) $invite['role_slug']
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function reissue($adminId, $createdByAdminId)
    {
        self::ensureSchema();

        $adminId = (int) $adminId;
        $createdByAdminId = (int) $createdByAdminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    ai.id,
                    ai.status,
                    ai.channel,
                    ai.contact,
                    au.id AS admin_id,
                    au.name,
                    au.email,
                    au.is_active,
                    ar.id AS role_id,
                    ar.name AS role_name,
                    ar.slug AS role_slug
                FROM admin_invitations ai
                INNER JOIN admin_users au
                    ON au.id = ai.admin_user_id
                INNER JOIN admin_roles ar
                    ON ar.id = au.role_id
                WHERE ai.admin_user_id = :admin_user_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                'admin_user_id' => $adminId
            ]);
            $invite = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException(
                    'Запрошення цього адміністратора не знайдено.'
                );
            }

            if (($invite['role_slug'] ?? '') === 'owner') {
                throw new RuntimeException(
                    'Запрошення розробника змінювати не можна.'
                );
            }

            if (
                ($invite['status'] ?? '') === 'accepted'
                || !empty($invite['is_active'])
            ) {
                throw new RuntimeException(
                    'Адміністратор уже активував свій акаунт.'
                );
            }

            $token = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $token);
            $expiresAt = (string) $db->query("
                SELECT DATE_FORMAT(
                    DATE_ADD(NOW(), INTERVAL 7 DAY),
                    '%Y-%m-%d %H:%i:%s'
                )
            ")->fetchColumn();

            $update = $db->prepare("
                UPDATE admin_invitations
                SET
                    status = 'created',
                    token_hash = :token_hash,
                    expires_at = :expires_at,
                    created_by_admin_id = :created_by_admin_id,
                    sent_at = NULL,
                    accepted_at = NULL
                WHERE id = :id
            ");
            $update->execute([
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'created_by_admin_id' => $createdByAdminId > 0
                    ? $createdByAdminId
                    : null,
                'id' => (int) $invite['id']
            ]);

            $disable = $db->prepare("
                UPDATE admin_users
                SET is_active = 0
                WHERE id = :id
            ");
            $disable->execute([
                'id' => $adminId
            ]);

            $db->commit();

            return [
                'admin_id' => $adminId,
                'name' => (string) $invite['name'],
                'email' => (string) $invite['email'],
                'role_id' => (int) $invite['role_id'],
                'role_name' => (string) $invite['role_name'],
                'role_slug' => (string) $invite['role_slug'],
                'channel' => (string) $invite['channel'],
                'contact' => (string) $invite['contact'],
                'expires_at' => $expiresAt,
                'invite_token' => $token
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function revoke($adminId, $createdByAdminId)
    {
        self::ensureSchema();

        $adminId = (int) $adminId;
        $createdByAdminId = (int) $createdByAdminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    ai.id,
                    ai.status,
                    au.id AS admin_id,
                    au.name,
                    au.email,
                    au.is_active,
                    ar.name AS role_name,
                    ar.slug AS role_slug
                FROM admin_invitations ai
                INNER JOIN admin_users au
                    ON au.id = ai.admin_user_id
                INNER JOIN admin_roles ar
                    ON ar.id = au.role_id
                WHERE ai.admin_user_id = :admin_user_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                'admin_user_id' => $adminId
            ]);
            $invite = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invite) {
                throw new RuntimeException(
                    'Запрошення цього адміністратора не знайдено.'
                );
            }

            if (($invite['role_slug'] ?? '') === 'owner') {
                throw new RuntimeException(
                    'Запрошення розробника змінювати не можна.'
                );
            }

            if (
                ($invite['status'] ?? '') === 'accepted'
                || !empty($invite['is_active'])
            ) {
                throw new RuntimeException(
                    'Активований акаунт не можна відкликати як запрошення.'
                );
            }

            if (($invite['status'] ?? '') === 'revoked') {
                throw new RuntimeException(
                    'Запрошення вже відкликано.'
                );
            }

            $update = $db->prepare("
                UPDATE admin_invitations
                SET
                    status = 'revoked',
                    token_hash = NULL,
                    created_by_admin_id = :created_by_admin_id,
                    sent_at = NULL,
                    accepted_at = NULL
                WHERE id = :id
            ");
            $update->execute([
                'created_by_admin_id' => $createdByAdminId > 0
                    ? $createdByAdminId
                    : null,
                'id' => (int) $invite['id']
            ]);

            $disable = $db->prepare("
                UPDATE admin_users
                SET is_active = 0
                WHERE id = :id
            ");
            $disable->execute([
                'id' => $adminId
            ]);

            $db->commit();

            return [
                'admin_id' => $adminId,
                'name' => (string) $invite['name'],
                'email' => (string) $invite['email'],
                'role_name' => (string) $invite['role_name'],
                'role_slug' => (string) $invite['role_slug']
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'Вкажіть ім’я адміністратора.'
            );
        }

        if (mb_strlen($name, 'UTF-8') > 120) {
            throw new InvalidArgumentException(
                'Ім’я адміністратора занадто довге.'
            );
        }

        return $name;
    }


    private static function normalizeEmail($email)
    {
        $email = strtolower(trim((string) $email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(
                'Вкажіть коректний email.'
            );
        }

        return $email;
    }


    private static function normalizeChannel($channel)
    {
        $channel = strtolower(trim((string) $channel));
        $allowed = ['viber', 'whatsapp', 'telegram', 'other'];

        return in_array($channel, $allowed, true)
            ? $channel
            : 'other';
    }


    private static function normalizeContact($contact)
    {
        $contact = trim((string) $contact);

        if ($contact === '') {
            throw new InvalidArgumentException(
                'Вкажіть контакт для надсилання запрошення.'
            );
        }

        if (mb_strlen($contact, 'UTF-8') > 160) {
            throw new InvalidArgumentException(
                'Контакт для запрошення занадто довгий.'
            );
        }

        return $contact;
    }


    private static function validatePassword($password)
    {
        $password = (string) $password;

        if (class_exists('PasswordPolicy')) {
            PasswordPolicy::validate($password);
            return;
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);

        if ($length < 10) {
            throw new InvalidArgumentException(
                'Пароль адміністратора має містити щонайменше 10 символів.'
            );
        }
    }
}
