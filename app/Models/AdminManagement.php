<?php

class AdminManagement
{
    public static function administrators()
    {
        AdminAccess::ensureSchema();

        return Database::connect()->query("
            SELECT
                au.id,
                au.role_id,
                au.name,
                au.email,
                au.is_active,
                au.last_login_at,
                au.created_at,
                ar.name AS role_name,
                ar.slug AS role_slug,
                ar.is_system AS role_is_system
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            ORDER BY
                CASE WHEN ar.slug = 'owner' THEN 0 ELSE 1 END,
                au.is_active DESC,
                au.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function assignableRoles()
    {
        AdminAccess::ensureSchema();

        return Database::connect()->query("
            SELECT id, name, slug, is_system
            FROM admin_roles
            WHERE slug <> 'owner'
            ORDER BY is_system DESC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function roles()
    {
        AdminAccess::ensureSchema();
        $db = Database::connect();

        $roles = $db->query("
            SELECT
                ar.id,
                ar.name,
                ar.slug,
                ar.is_system,
                COUNT(DISTINCT au.id) AS admin_count
            FROM admin_roles ar
            LEFT JOIN admin_users au ON au.role_id = ar.id
            GROUP BY ar.id, ar.name, ar.slug, ar.is_system
            ORDER BY
                CASE ar.slug
                    WHEN 'owner' THEN 0
                    WHEN 'administrator' THEN 1
                    WHEN 'order_manager' THEN 2
                    WHEN 'content_manager' THEN 3
                    ELSE 4
                END,
                ar.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $permissionRows = $db->query("
            SELECT role_id, permission_key
            FROM admin_role_permissions
        ")->fetchAll(PDO::FETCH_ASSOC);
        $permissionMap = [];

        foreach ($permissionRows as $row) {
            $roleId = (int) ($row['role_id'] ?? 0);
            $key = (string) ($row['permission_key'] ?? '');

            if ($roleId > 0 && $key !== '') {
                $permissionMap[$roleId][] = $key;
            }
        }

        $allKeys = array_column(self::permissions(), 'permission_key');

        foreach ($roles as &$role) {
            $roleId = (int) ($role['id'] ?? 0);
            $role['permissions'] = ($role['slug'] ?? '') === 'owner'
                ? $allKeys
                : ($permissionMap[$roleId] ?? []);
        }
        unset($role);

        return $roles;
    }


    public static function permissions()
    {
        AdminAccess::ensureSchema();

        return Database::connect()->query("
            SELECT permission_key, name, group_key, sort_order
            FROM admin_permissions
            ORDER BY sort_order ASC, permission_key ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function createAdministrator($name, $email, $password, $roleId)
    {
        AdminAccess::ensureSchema();
        $name = self::normalizeName($name);
        $email = self::normalizeEmail($email);
        self::validatePassword($password);
        $role = self::assignableRoleById($roleId);
        $db = Database::connect();

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

        $stmt = $db->prepare("
            INSERT INTO admin_users
            (role_id, name, email, password_hash, is_active)
            VALUES
            (:role_id, :name, :email, :password_hash, 1)
        ");
        $stmt->execute([
            'role_id' => (int) $role['id'],
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash(
                (string) $password,
                PASSWORD_DEFAULT
            )
        ]);

        return [
            'id' => (int) $db->lastInsertId(),
            'name' => $name,
            'email' => $email,
            'role' => $role
        ];
    }


    public static function changeAdministratorRole($adminId, $roleId)
    {
        AdminAccess::ensureSchema();
        $adminId = (int) $adminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException('Некоректний адміністратор.');
        }

        if ($adminId === AdminAccess::currentId()) {
            throw new RuntimeException(
                'Не можна змінювати власну роль у поточній сесії.'
            );
        }

        $admin = self::administratorById($adminId);

        if (($admin['role_slug'] ?? '') === 'owner') {
            throw new RuntimeException('Роль власника змінювати не можна.');
        }

        $role = self::assignableRoleById($roleId);

        if ((int) $admin['role_id'] === (int) $role['id']) {
            return [
                'changed' => false,
                'admin' => $admin,
                'role' => $role
            ];
        }

        $stmt = Database::connect()->prepare("
            UPDATE admin_users
            SET role_id = :role_id
            WHERE id = :id
        ");
        $stmt->execute([
            'role_id' => (int) $role['id'],
            'id' => $adminId
        ]);

        return [
            'changed' => true,
            'admin' => $admin,
            'role' => $role
        ];
    }


    public static function toggleAdministrator($adminId)
    {
        AdminAccess::ensureSchema();
        $adminId = (int) $adminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException('Некоректний адміністратор.');
        }

        if ($adminId === AdminAccess::currentId()) {
            throw new RuntimeException(
                'Не можна вимкнути власний доступ у поточній сесії.'
            );
        }

        $admin = self::administratorById($adminId);

        if (($admin['role_slug'] ?? '') === 'owner') {
            throw new RuntimeException('Доступ власника вимкнути не можна.');
        }

        $newState = empty($admin['is_active']) ? 1 : 0;
        $stmt = Database::connect()->prepare("
            UPDATE admin_users
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => $newState,
            'id' => $adminId
        ]);

        return [
            'admin' => $admin,
            'is_active' => $newState
        ];
    }


    public static function resetPassword($adminId, $password)
    {
        AdminAccess::ensureSchema();
        $adminId = (int) $adminId;
        self::validatePassword($password);

        if ($adminId <= 0) {
            throw new InvalidArgumentException('Некоректний адміністратор.');
        }

        $admin = self::administratorById($adminId);

        if (($admin['role_slug'] ?? '') === 'owner') {
            throw new RuntimeException(
                'Пароль власника змінюється окремо в налаштуваннях безпеки.'
            );
        }

        $stmt = Database::connect()->prepare("
            UPDATE admin_users
            SET password_hash = :password_hash
            WHERE id = :id
        ");
        $stmt->execute([
            'password_hash' => password_hash(
                (string) $password,
                PASSWORD_DEFAULT
            ),
            'id' => $adminId
        ]);

        return $admin;
    }


    public static function createCustomRole($name, array $permissionKeys)
    {
        AdminAccess::ensureSchema();
        $name = self::normalizeRoleName($name);
        $db = Database::connect();
        $slug = 'custom-' . substr(
            hash('sha256', $name . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX)),
            0,
            16
        );

        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                INSERT INTO admin_roles
                (name, slug, is_system)
                VALUES
                (:name, :slug, 0)
            ");
            $stmt->execute([
                'name' => $name,
                'slug' => $slug
            ]);
            $roleId = (int) $db->lastInsertId();
            self::replaceRolePermissions(
                $db,
                $roleId,
                self::normalizedPermissionKeys($permissionKeys)
            );
            $db->commit();

            return [
                'id' => $roleId,
                'name' => $name,
                'slug' => $slug
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function updateCustomRolePermissions($roleId, array $permissionKeys)
    {
        AdminAccess::ensureSchema();
        $roleId = (int) $roleId;
        $role = self::roleById($roleId);

        if (!empty($role['is_system'])) {
            throw new RuntimeException(
                'Системні ролі є шаблонами. Для власного набору прав створіть окрему роль.'
            );
        }

        $keys = self::normalizedPermissionKeys($permissionKeys);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            self::replaceRolePermissions($db, $roleId, $keys);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $role;
    }


    public static function auditLog($limit = 200)
    {
        AdminAccess::ensureSchema();
        $limit = max(1, min(500, (int) $limit));

        return Database::connect()->query("
            SELECT
                l.id,
                l.admin_user_id,
                l.action,
                l.details,
                l.created_at,
                au.name AS admin_name,
                au.email AS admin_email
            FROM admin_audit_log l
            LEFT JOIN admin_users au ON au.id = l.admin_user_id
            ORDER BY l.id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function administratorById($adminId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                au.id,
                au.role_id,
                au.name,
                au.email,
                au.is_active,
                ar.name AS role_name,
                ar.slug AS role_slug,
                ar.is_system AS role_is_system
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            WHERE au.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            throw new RuntimeException('Адміністратора не знайдено.');
        }

        return $admin;
    }


    private static function roleById($roleId)
    {
        $stmt = Database::connect()->prepare("
            SELECT id, name, slug, is_system
            FROM admin_roles
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException('Роль адміністратора не знайдено.');
        }

        return $role;
    }


    private static function assignableRoleById($roleId)
    {
        $role = self::roleById((int) $roleId);

        if (($role['slug'] ?? '') === 'owner') {
            throw new RuntimeException(
                'Роль власника не можна призначати через цю форму.'
            );
        }

        return $role;
    }


    private static function normalizedPermissionKeys(array $permissionKeys)
    {
        $requested = [];

        foreach ($permissionKeys as $key) {
            $key = trim((string) $key);

            if ($key !== '') {
                $requested[$key] = true;
            }
        }

        // Кожна власна роль повинна мати базовий вхід і головну сторінку.
        $requested['admin.access'] = true;
        $requested['dashboard.view'] = true;

        $valid = array_column(self::permissions(), 'permission_key');
        $validMap = array_fill_keys($valid, true);

        return array_values(array_filter(
            array_keys($requested),
            function ($key) use ($validMap) {
                return isset($validMap[$key]);
            }
        ));
    }


    private static function replaceRolePermissions(PDO $db, $roleId, array $keys)
    {
        $delete = $db->prepare("
            DELETE FROM admin_role_permissions
            WHERE role_id = :role_id
        ");
        $delete->execute(['role_id' => (int) $roleId]);

        if (empty($keys)) {
            return;
        }

        $insert = $db->prepare("
            INSERT INTO admin_role_permissions
            (role_id, permission_key)
            VALUES
            (:role_id, :permission_key)
        ");

        foreach ($keys as $key) {
            $insert->execute([
                'role_id' => (int) $roleId,
                'permission_key' => $key
            ]);
        }
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


    private static function normalizeRoleName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть назву ролі.');
        }

        if (mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('Назва ролі занадто довга.');
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
        $password = (string) $password;
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
