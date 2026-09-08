<?php

class AdminProfile
{
    public static function current()
    {
        $adminId = AdminAccess::currentId();

        if ($adminId <= 0) {
            throw new RuntimeException('Сесію адміністратора не знайдено.');
        }

        $stmt = Database::connect()->prepare("
            SELECT
                au.id,
                au.name,
                au.email,
                au.is_active,
                au.last_login_at,
                au.created_at,
                ar.id AS role_id,
                ar.name AS role_name,
                ar.slug AS role_slug
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            WHERE au.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || empty($admin['is_active'])) {
            throw new RuntimeException('Обліковий запис адміністратора недоступний.');
        }

        return $admin;
    }


    public static function updateIdentity($name, $email, $currentPassword)
    {
        $admin = self::currentWithPassword();
        $name = self::normalizeName($name);
        $email = self::normalizeEmail($email);

        if (!password_verify((string) $currentPassword, (string) $admin['password_hash'])) {
            throw new RuntimeException('Поточний пароль введено неправильно.');
        }

        $db = Database::connect();
        $duplicate = $db->prepare("
            SELECT id
            FROM admin_users
            WHERE email = :email
              AND id <> :id
            LIMIT 1
        ");
        $duplicate->execute([
            'email' => $email,
            'id' => (int) $admin['id']
        ]);

        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('Адміністратор із таким email уже існує.');
        }

        $stmt = $db->prepare("
            UPDATE admin_users
            SET name = :name,
                email = :email
            WHERE id = :id
        ");
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'id' => (int) $admin['id']
        ]);

        $_SESSION['admin_user_name'] = $name;

        AdminAccess::audit(
            'admin.profile_updated',
            [
                'old_email' => (string) $admin['email'],
                'new_email' => $email,
                'name_changed' => (string) $admin['name'] !== $name
            ],
            (int) $admin['id']
        );

        return self::current();
    }


    public static function changePassword($currentPassword, $newPassword, $confirmation)
    {
        $admin = self::currentWithPassword();

        if (!password_verify((string) $currentPassword, (string) $admin['password_hash'])) {
            throw new RuntimeException('Поточний пароль введено неправильно.');
        }

        $newPassword = (string) $newPassword;
        $confirmation = (string) $confirmation;
        self::validatePassword($newPassword);

        if (!hash_equals($newPassword, $confirmation)) {
            throw new RuntimeException('Новий пароль і підтвердження не збігаються.');
        }

        if (password_verify($newPassword, (string) $admin['password_hash'])) {
            throw new RuntimeException('Новий пароль має відрізнятися від поточного.');
        }

        $stmt = Database::connect()->prepare("
            UPDATE admin_users
            SET password_hash = :password_hash
            WHERE id = :id
        ");
        $stmt->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $admin['id']
        ]);

        session_regenerate_id(true);

        AdminAccess::audit(
            'admin.password_changed',
            [],
            (int) $admin['id']
        );
    }


    private static function currentWithPassword()
    {
        $adminId = AdminAccess::currentId();

        if ($adminId <= 0) {
            throw new RuntimeException('Сесію адміністратора не знайдено.');
        }

        $stmt = Database::connect()->prepare("
            SELECT id, name, email, password_hash, is_active
            FROM admin_users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || empty($admin['is_active'])) {
            throw new RuntimeException('Обліковий запис адміністратора недоступний.');
        }

        return $admin;
    }


    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть ім’я адміністратора.');
        }

        if (mb_strlen($name, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Ім’я адміністратора занадто довге.');
        }

        return $name;
    }


    private static function normalizeEmail($email)
    {
        $email = strtolower(trim((string) $email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Вкажіть коректний email.');
        }

        return $email;
    }


    private static function validatePassword($password)
    {
        $length = function_exists('mb_strlen')
            ? mb_strlen((string) $password, 'UTF-8')
            : strlen((string) $password);

        if ($length < 10) {
            throw new InvalidArgumentException(
                'Новий пароль має містити щонайменше 10 символів.'
            );
        }
    }
}
