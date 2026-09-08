<?php

class AdminAccess
{
    private static $schemaReady = false;

    private const SESSION_ID = 'admin_user_id';
    private const SESSION_NAME = 'admin_user_name';
    private const SESSION_ROLE = 'admin_role_slug';
    private const CSRF_KEY = 'admin_csrf_token';


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_roles
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_roles_slug (slug)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_permissions
            (
                permission_key VARCHAR(120) NOT NULL,
                name VARCHAR(160) NOT NULL,
                group_key VARCHAR(80) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (permission_key),
                KEY idx_admin_permissions_group (group_key, sort_order)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_role_permissions
            (
                role_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(120) NOT NULL,
                PRIMARY KEY (role_id, permission_key),
                CONSTRAINT fk_admin_role_permissions_role
                    FOREIGN KEY (role_id)
                    REFERENCES admin_roles(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_admin_role_permissions_permission
                    FOREIGN KEY (permission_key)
                    REFERENCES admin_permissions(permission_key)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_users
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id INT UNSIGNED NOT NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_admin_users_email (email),
                KEY idx_admin_users_role (role_id),
                CONSTRAINT fk_admin_users_role
                    FOREIGN KEY (role_id)
                    REFERENCES admin_roles(id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_audit_log
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_user_id INT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_audit_user_date (admin_user_id, created_at),
                KEY idx_admin_audit_action_date (action, created_at),
                CONSTRAINT fk_admin_audit_user
                    FOREIGN KEY (admin_user_id)
                    REFERENCES admin_users(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::seedPermissions($db);
        self::seedRoles($db);

        self::$schemaReady = true;
    }


    public static function hasAdmins()
    {
        self::ensureSchema();

        return (int) Database::connect()
            ->query("SELECT COUNT(*) FROM admin_users")
            ->fetchColumn() > 0;
    }


    public static function createOwner($name, $email, $password)
    {
        self::ensureSchema();

        if (self::hasAdmins()) {
            throw new RuntimeException(
                'Початкове налаштування вже виконано.'
            );
        }

        $name = self::normalizeName($name);
        $email = self::normalizeEmail($email);
        self::validatePassword($password);

        $db = Database::connect();
        $roleId = self::roleIdBySlug($db, 'owner');

        if ($roleId <= 0) {
            throw new RuntimeException('Не вдалося знайти роль власника.');
        }

        $stmt = $db->prepare("
            INSERT INTO admin_users
            (role_id, name, email, password_hash, is_active)
            VALUES
            (:role_id, :name, :email, :password_hash, 1)
        ");
        $stmt->execute([
            'role_id' => $roleId,
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash(
                (string) $password,
                PASSWORD_DEFAULT
            )
        ]);

        $adminId = (int) $db->lastInsertId();
        self::startSessionFor($adminId);
        self::audit('admin.owner_created', [
            'admin_id' => $adminId
        ], $adminId);

        return $adminId;
    }


    public static function authenticate($email, $password)
    {
        self::ensureSchema();

        $email = self::normalizeEmail($email);
        $stmt = Database::connect()->prepare("
            SELECT
                au.id,
                au.name,
                au.email,
                au.password_hash,
                au.is_active,
                ar.slug AS role_slug,
                ar.name AS role_name
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            WHERE au.email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$admin
            || empty($admin['is_active'])
            || !password_verify((string) $password, (string) $admin['password_hash'])
        ) {
            self::audit('admin.login_failed', [
                'email' => $email
            ], null);
            return false;
        }

        $adminId = (int) $admin['id'];
        self::startSessionFor($adminId);

        $db = Database::connect();
        $update = $db->prepare("
            UPDATE admin_users
            SET last_login_at = NOW()
            WHERE id = :id
        ");
        $update->execute(['id' => $adminId]);

        if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $db->prepare("
                UPDATE admin_users
                SET password_hash = :password_hash
                WHERE id = :id
            ");
            $rehash->execute([
                'password_hash' => password_hash(
                    (string) $password,
                    PASSWORD_DEFAULT
                ),
                'id' => $adminId
            ]);
        }

        self::audit('admin.login', [], $adminId);

        return self::current();
    }


    public static function logout()
    {
        $adminId = self::currentId();

        if ($adminId > 0) {
            self::audit('admin.logout', [], $adminId);
        }

        unset(
            $_SESSION[self::SESSION_ID],
            $_SESSION[self::SESSION_NAME],
            $_SESSION[self::SESSION_ROLE]
        );

        session_regenerate_id(true);
    }


    public static function current()
    {
        self::ensureSchema();

        $adminId = self::currentId();

        if ($adminId <= 0) {
            return null;
        }

        $stmt = Database::connect()->prepare("
            SELECT
                au.id,
                au.name,
                au.email,
                au.is_active,
                ar.id AS role_id,
                ar.slug AS role_slug,
                ar.name AS role_name
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            WHERE au.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $adminId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$admin || empty($admin['is_active'])) {
            unset(
                $_SESSION[self::SESSION_ID],
                $_SESSION[self::SESSION_NAME],
                $_SESSION[self::SESSION_ROLE]
            );
            return null;
        }

        $_SESSION[self::SESSION_NAME] = (string) $admin['name'];
        $_SESSION[self::SESSION_ROLE] = (string) $admin['role_slug'];

        return $admin;
    }


    public static function currentId()
    {
        return (int) ($_SESSION[self::SESSION_ID] ?? 0);
    }


    public static function can($permissionKey)
    {
        self::ensureSchema();

        $permissionKey = trim((string) $permissionKey);
        $admin = self::current();

        if (!$admin || $permissionKey === '') {
            return false;
        }

        if (($admin['role_slug'] ?? '') === 'owner') {
            return true;
        }

        $stmt = Database::connect()->prepare("
            SELECT 1
            FROM admin_role_permissions arp
            WHERE arp.role_id = :role_id
              AND arp.permission_key = :permission_key
            LIMIT 1
        ");
        $stmt->execute([
            'role_id' => (int) $admin['role_id'],
            'permission_key' => $permissionKey
        ]);

        return (bool) $stmt->fetchColumn();
    }


    public static function permissionForRequest($method, $path)
    {
        $method = strtoupper((string) $method);
        $path = rtrim((string) $path, '/');

        if ($path === '') {
            $path = '/';
        }

        $isWrite = $method !== 'GET' && $method !== 'HEAD';
        $areas = [
            '/admin/orders' => ['orders.view', 'orders.manage'],
            '/admin/search' => ['search.view', 'search.manage'],
            '/admin/users' => ['users.view', 'users.manage'],
            '/admin/ranks' => ['ranks.view', 'ranks.manage'],
            '/admin/languages' => ['languages.view', 'languages.manage'],
            '/admin/translations' => ['translations.view', 'translations.manage'],
            '/admin/ai-translation' => ['ai_translation.view', 'ai_translation.manage'],
            '/admin/categories' => ['categories.view', 'categories.manage'],
            '/admin/products' => ['products.view', 'products.manage'],
            '/admin/delivery' => ['delivery.view', 'delivery.manage']
        ];

        foreach ($areas as $prefix => $permissions) {
            if ($path === $prefix || strpos($path, $prefix . '/') === 0) {
                return $permissions[$isWrite ? 1 : 0];
            }
        }

        if ($path === '/admin') {
            return 'dashboard.view';
        }

        return 'admin.access';
    }


    public static function csrfToken()
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(24));
        }

        return (string) $_SESSION[self::CSRF_KEY];
    }


    public static function verifyCsrf($token)
    {
        $stored = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        $token = (string) $token;

        return $stored !== ''
            && $token !== ''
            && hash_equals($stored, $token);
    }


    public static function audit($action, array $details = [], $adminUserId = null)
    {
        try {
            self::ensureSchema();
            $action = trim((string) $action);

            if ($action === '') {
                return;
            }

            $encoded = !empty($details)
                ? json_encode(
                    $details,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
                : null;

            $stmt = Database::connect()->prepare("
                INSERT INTO admin_audit_log
                (admin_user_id, action, details)
                VALUES
                (:admin_user_id, :action, :details)
            ");
            $stmt->execute([
                'admin_user_id' => $adminUserId !== null
                    ? (int) $adminUserId
                    : null,
                'action' => $action,
                'details' => $encoded !== false ? $encoded : null
            ]);
        } catch (Throwable $e) {
            error_log('Admin audit error: ' . $e->getMessage());
        }
    }


    private static function startSessionFor($adminId)
    {
        $adminId = (int) $adminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException('Некоректний адміністратор.');
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_ID] = $adminId;

        $admin = self::current();

        if (!$admin) {
            throw new RuntimeException('Не вдалося відкрити сесію адміністратора.');
        }
    }


    private static function seedPermissions(PDO $db)
    {
        $permissions = [
            ['admin.access', 'Доступ до службових розділів', 'system', 10],
            ['dashboard.view', 'Перегляд головної адмін-панелі', 'dashboard', 20],
            ['orders.view', 'Перегляд замовлень', 'orders', 30],
            ['orders.manage', 'Зміна замовлень', 'orders', 31],
            ['search.view', 'Перегляд журналу пошуку', 'search', 40],
            ['search.manage', 'Керування пошуком', 'search', 41],
            ['users.view', 'Перегляд користувачів', 'users', 50],
            ['users.manage', 'Керування користувачами', 'users', 51],
            ['ranks.view', 'Перегляд рангів', 'ranks', 60],
            ['ranks.manage', 'Керування рангами', 'ranks', 61],
            ['products.view', 'Перегляд товарів', 'catalog', 70],
            ['products.manage', 'Керування товарами', 'catalog', 71],
            ['categories.view', 'Перегляд категорій', 'catalog', 80],
            ['categories.manage', 'Керування категоріями', 'catalog', 81],
            ['delivery.view', 'Перегляд доставки', 'delivery', 90],
            ['delivery.manage', 'Керування доставкою', 'delivery', 91],
            ['languages.view', 'Перегляд мов', 'translations', 100],
            ['languages.manage', 'Керування мовами', 'translations', 101],
            ['translations.view', 'Перегляд перекладів', 'translations', 110],
            ['translations.manage', 'Керування перекладами', 'translations', 111],
            ['ai_translation.view', 'Перегляд налаштувань ШІ', 'translations', 120],
            ['ai_translation.manage', 'Керування ШІ-перекладом', 'translations', 121],
            ['administrators.view', 'Перегляд адміністраторів', 'security', 130],
            ['administrators.manage', 'Керування адміністраторами', 'security', 131],
            ['audit.view', 'Перегляд журналу дій', 'security', 140]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO admin_permissions
            (permission_key, name, group_key, sort_order)
            VALUES
            (:permission_key, :name, :group_key, :sort_order)
        ");

        foreach ($permissions as $permission) {
            $stmt->execute([
                'permission_key' => $permission[0],
                'name' => $permission[1],
                'group_key' => $permission[2],
                'sort_order' => $permission[3]
            ]);
        }
    }


    private static function seedRoles(PDO $db)
    {
        $roles = [
            ['Власник', 'owner'],
            ['Адміністратор', 'administrator'],
            ['Менеджер замовлень', 'order_manager'],
            ['Контент-менеджер', 'content_manager']
        ];

        $insertRole = $db->prepare("
            INSERT IGNORE INTO admin_roles
            (name, slug, is_system)
            VALUES
            (:name, :slug, 1)
        ");

        foreach ($roles as $role) {
            $insertRole->execute([
                'name' => $role[0],
                'slug' => $role[1]
            ]);
        }

        $allPermissions = $db->query("
            SELECT permission_key
            FROM admin_permissions
            ORDER BY sort_order ASC, permission_key ASC
        ")->fetchAll(PDO::FETCH_COLUMN);

        $rolePermissions = [
            'owner' => $allPermissions,
            'administrator' => array_values(array_filter(
                $allPermissions,
                function ($key) {
                    return !in_array(
                        $key,
                        ['administrators.manage'],
                        true
                    );
                }
            )),
            'order_manager' => [
                'dashboard.view',
                'orders.view',
                'orders.manage',
                'search.view',
                'users.view'
            ],
            'content_manager' => [
                'dashboard.view',
                'products.view',
                'products.manage',
                'categories.view',
                'categories.manage',
                'languages.view',
                'translations.view',
                'translations.manage',
                'ai_translation.view'
            ]
        ];

        $insertPermission = $db->prepare("
            INSERT IGNORE INTO admin_role_permissions
            (role_id, permission_key)
            VALUES
            (:role_id, :permission_key)
        ");

        foreach ($rolePermissions as $roleSlug => $permissions) {
            $roleId = self::roleIdBySlug($db, $roleSlug);

            if ($roleId <= 0) {
                continue;
            }

            foreach ($permissions as $permissionKey) {
                $insertPermission->execute([
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey
                ]);
            }
        }
    }


    private static function roleIdBySlug(PDO $db, $slug)
    {
        $stmt = $db->prepare("
            SELECT id
            FROM admin_roles
            WHERE slug = :slug
            LIMIT 1
        ");
        $stmt->execute(['slug' => (string) $slug]);

        return (int) $stmt->fetchColumn();
    }


    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть ім’я адміністратора.');
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
