<?php

class AdminManagement
{
    public static function administrators()
    {
        AdminAccess::ensureSchema();

        if (class_exists('AdminInvitation')) {
            AdminInvitation::ensureSchema();
        }

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
                ar.is_system AS role_is_system,
                CASE
                    WHEN ai.status IN ('created', 'sent')
                         AND ai.expires_at <= NOW()
                    THEN 'expired'
                    ELSE ai.status
                END AS invitation_status,
                ai.channel AS invitation_channel,
                ai.contact AS invitation_contact,
                ai.expires_at AS invitation_expires_at
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            LEFT JOIN admin_invitations ai
                ON ai.admin_user_id = au.id
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
            ORDER BY
                CASE slug
                    WHEN 'store_owner' THEN 0
                    WHEN 'administrator' THEN 1
                    WHEN 'order_manager' THEN 2
                    WHEN 'content_manager' THEN 3
                    ELSE 4
                END,
                is_system DESC,
                id ASC
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
                    WHEN 'store_owner' THEN 1
                    WHEN 'administrator' THEN 2
                    WHEN 'order_manager' THEN 3
                    WHEN 'content_manager' THEN 4
                    ELSE 5
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
            throw new RuntimeException('Роль розробника змінювати не можна.');
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
            throw new RuntimeException('Доступ розробника вимкнути не можна.');
        }

        self::assertInvitationActivatedForAccountActions($adminId);

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
                'Пароль розробника змінюється окремо в налаштуваннях безпеки.'
            );
        }

        self::assertInvitationActivatedForAccountActions($adminId);

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


    public static function deleteAdministrator($adminId)
    {
        AdminAccess::ensureSchema();
        $adminId = (int) $adminId;

        if ($adminId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        if ($adminId === AdminAccess::currentId()) {
            throw new RuntimeException(
                'Не можна видалити власний активний обліковий запис.'
            );
        }

        // Any helper that may CREATE TABLE must run before the transaction.
        if (class_exists('AdminInvitation')) {
            AdminInvitation::ensureSchema();
        }
        if (class_exists('AdminNotificationCenter')) {
            AdminNotificationCenter::ensureSchema();
        }
        if (class_exists('SystemErrorNotification')) {
            SystemErrorNotification::ensureSchema();
        }
        if (class_exists('SystemErrorNote')) {
            SystemErrorNote::ensureSchema();
        }
        if (class_exists('SystemErrorStatus')) {
            SystemErrorStatus::ensureSchema();
        }
        if (class_exists('CustomerRankRequest')) {
            CustomerRankRequest::ensureSchema();
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    au.id,
                    au.name,
                    au.email,
                    au.role_id,
                    au.is_active,
                    ar.name AS role_name,
                    ar.slug AS role_slug
                FROM admin_users au
                INNER JOIN admin_roles ar ON ar.id = au.role_id
                WHERE au.id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['id' => $adminId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$admin) {
                throw new RuntimeException(
                    'Адміністратора не знайдено.'
                );
            }

            if (($admin['role_slug'] ?? '') === 'owner') {
                throw new RuntimeException(
                    'Обліковий запис Розробника видалити не можна.'
                );
            }

            if (class_exists('CustomerRankRequest')) {
                $clearRankRequests = $db->prepare("
                    UPDATE customer_rank_requests
                    SET admin_user_id = NULL
                    WHERE admin_user_id = :admin_user_id
                ");
                $clearRankRequests->execute([
                    'admin_user_id' => $adminId
                ]);
            }

            if (class_exists('SystemErrorNote')) {
                $clearErrorNotes = $db->prepare("
                    UPDATE admin_system_error_notes
                    SET updated_by_admin_id = NULL
                    WHERE updated_by_admin_id = :admin_user_id
                ");
                $clearErrorNotes->execute([
                    'admin_user_id' => $adminId
                ]);
            }

            if (class_exists('SystemErrorStatus')) {
                $clearErrorStatus = $db->prepare("
                    UPDATE admin_system_error_status
                    SET updated_by_admin_id = NULL
                    WHERE updated_by_admin_id = :admin_user_id
                ");
                $clearErrorStatus->execute([
                    'admin_user_id' => $adminId
                ]);
            }

            if (class_exists('SystemErrorNotification')) {
                $deleteErrorState = $db->prepare("
                    DELETE FROM admin_system_error_notification_state
                    WHERE admin_user_id = :admin_user_id
                ");
                $deleteErrorState->execute([
                    'admin_user_id' => $adminId
                ]);
            }

            if (class_exists('AdminNotificationCenter')) {
                $deleteNotificationState = $db->prepare("
                    DELETE FROM admin_notification_state
                    WHERE admin_user_id = :admin_user_id
                ");
                $deleteNotificationState->execute([
                    'admin_user_id' => $adminId
                ]);

                $deleteNotificationPreferences = $db->prepare("
                    DELETE FROM admin_notification_preferences
                    WHERE admin_user_id = :admin_user_id
                ");
                $deleteNotificationPreferences->execute([
                    'admin_user_id' => $adminId
                ]);
            }

            // admin_invitations is ON DELETE CASCADE, while admin_audit_log
            // keeps history through ON DELETE SET NULL.
            $delete = $db->prepare("
                DELETE FROM admin_users
                WHERE id = :id
            ");
            $delete->execute(['id' => $adminId]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'Не вдалося видалити адміністратора.'
                );
            }

            $db->commit();

            return $admin;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function createCustomRole($name, array $permissionKeys)
    {
        AdminAccess::ensureSchema();
        $name = self::normalizeRoleName($name);
        $db = Database::connect();

        $duplicate = $db->prepare("
            SELECT id
            FROM admin_roles
            WHERE name = :name
            LIMIT 1
        ");
        $duplicate->execute([
            'name' => $name
        ]);

        if ($duplicate->fetchColumn()) {
            throw new RuntimeException(
                'Роль із такою назвою вже існує.'
            );
        }

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


    public static function renameCustomRole($roleId, $name)
    {
        AdminAccess::ensureSchema();
        $roleId = (int) $roleId;
        $name = self::normalizeRoleName($name);

        if ($roleId <= 0) {
            throw new InvalidArgumentException(
                'Некоректна роль адміністратора.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT id, name, slug, is_system
                FROM admin_roles
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['id' => $roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                throw new RuntimeException(
                    'Роль адміністратора не знайдено.'
                );
            }

            if (!empty($role['is_system'])) {
                throw new RuntimeException(
                    'Назву системної ролі змінювати не можна.'
                );
            }

            $duplicate = $db->prepare("
                SELECT id
                FROM admin_roles
                WHERE name = :name
                  AND id <> :id
                LIMIT 1
            ");
            $duplicate->execute([
                'name' => $name,
                'id' => $roleId
            ]);

            if ($duplicate->fetchColumn()) {
                throw new RuntimeException(
                    'Роль із такою назвою вже існує.'
                );
            }

            $update = $db->prepare("
                UPDATE admin_roles
                SET name = :name
                WHERE id = :id
            ");
            $update->execute([
                'name' => $name,
                'id' => $roleId
            ]);

            $db->commit();

            $role['old_name'] = (string) ($role['name'] ?? '');
            $role['name'] = $name;

            return $role;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function deleteCustomRole($roleId)
    {
        AdminAccess::ensureSchema();
        $roleId = (int) $roleId;

        if ($roleId <= 0) {
            throw new InvalidArgumentException(
                'Некоректна роль адміністратора.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT id, name, slug, is_system
                FROM admin_roles
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['id' => $roleId]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                throw new RuntimeException(
                    'Роль адміністратора не знайдено.'
                );
            }

            if (!empty($role['is_system'])) {
                throw new RuntimeException(
                    'Системну роль видалити не можна.'
                );
            }

            $assigned = $db->prepare("
                SELECT COUNT(*)
                FROM admin_users
                WHERE role_id = :role_id
                FOR UPDATE
            ");
            $assigned->execute([
                'role_id' => $roleId
            ]);
            $assignedCount = max(
                0,
                (int) $assigned->fetchColumn()
            );

            if ($assignedCount > 0) {
                throw new RuntimeException(
                    'Спочатку призначте адміністраторам іншу роль.'
                );
            }

            // The NOT EXISTS condition is the final database-level guard.
            // It protects existing installations even when an old
            // admin_users table does not have the expected foreign key,
            // and it also closes the gap between the check and DELETE.
            $delete = $db->prepare("
                DELETE FROM admin_roles
                WHERE id = :id
                  AND is_system = 0
                  AND NOT EXISTS (
                      SELECT 1
                      FROM admin_users
                      WHERE role_id = :assigned_role_id
                  )
            ");
            $delete->execute([
                'id' => $roleId,
                'assigned_role_id' => $roleId
            ]);

            if ($delete->rowCount() !== 1) {
                $recheck = $db->prepare("
                    SELECT COUNT(*)
                    FROM admin_users
                    WHERE role_id = :role_id
                ");
                $recheck->execute([
                    'role_id' => $roleId
                ]);

                if ((int) $recheck->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'Спочатку призначте адміністраторам іншу роль.'
                    );
                }

                throw new RuntimeException(
                    'Не вдалося видалити роль.'
                );
            }

            // Role permissions and saved overrides are removed by FK CASCADE.
            $db->commit();

            return $role;
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


    public static function normalizeAuditFilters(array $filters)
    {
        $adminId = max(0, (int) ($filters['admin_id'] ?? 0));
        $action = trim((string) ($filters['action'] ?? ''));

        if (
            $action !== ''
            && preg_match('/^[a-z0-9_.-]{1,120}$/i', $action) !== 1
        ) {
            $action = '';
        }

        $dateFrom = self::normalizeAuditDate(
            $filters['date_from'] ?? ''
        );
        $dateTo = self::normalizeAuditDate(
            $filters['date_to'] ?? ''
        );

        if (
            $dateFrom !== ''
            && $dateTo !== ''
            && strcmp($dateFrom, $dateTo) > 0
        ) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'admin_id' => $adminId,
            'action' => $action,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];
    }


    public static function auditLog(array $filters = [], $limit = 200)
    {
        AdminAccess::ensureSchema();
        $filters = self::normalizeAuditFilters($filters);
        $limit = max(1, min(500, (int) $limit));
        $where = [];
        $params = [];

        if ($filters['admin_id'] > 0) {
            $where[] = 'l.admin_user_id = :admin_id';
            $params['admin_id'] = $filters['admin_id'];
        }

        if ($filters['action'] !== '') {
            $where[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }

        if ($filters['date_from'] !== '') {
            $where[] = 'l.created_at >= :date_from';
            $params['date_from'] = $filters['date_from'] . ' 00:00:00';
        }

        if ($filters['date_to'] !== '') {
            $dateToExclusive = date(
                'Y-m-d',
                strtotime($filters['date_to'] . ' +1 day')
            );
            $where[] = 'l.created_at < :date_to_exclusive';
            $params['date_to_exclusive'] =
                $dateToExclusive . ' 00:00:00';
        }

        $sql = "
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
        ";

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= " ORDER BY l.id DESC LIMIT {$limit}";

        $stmt = Database::connect()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function auditAdministrators()
    {
        AdminAccess::ensureSchema();

        return Database::connect()->query("
            SELECT id, name, email, is_active
            FROM admin_users
            ORDER BY name ASC, email ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function auditActions()
    {
        AdminAccess::ensureSchema();

        return Database::connect()->query("
            SELECT DISTINCT action
            FROM admin_audit_log
            WHERE TRIM(action) <> ''
            ORDER BY action ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    }


    private static function assertInvitationActivatedForAccountActions($adminId)
    {
        if (!class_exists('AdminInvitation')) {
            return;
        }

        AdminInvitation::ensureSchema();

        $stmt = Database::connect()->prepare("
            SELECT status
            FROM admin_invitations
            WHERE admin_user_id = :admin_user_id
            LIMIT 1
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminId
        ]);
        $status = trim((string) ($stmt->fetchColumn() ?: ''));

        if ($status !== '' && $status !== 'accepted') {
            throw new RuntimeException(
                'Спочатку адміністратор має прийняти запрошення та встановити власний пароль.'
            );
        }
    }


    private static function normalizeAuditDate($value)
    {
        $value = trim((string) $value);

        if (
            $value === ''
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1
        ) {
            return '';
        }

        [$year, $month, $day] = array_map(
            'intval',
            explode('-', $value)
        );

        return checkdate($month, $day, $year)
            ? $value
            : '';
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
                'Роль розробника не можна призначати через цю форму.'
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

        foreach (array_keys($requested) as $key) {
            if (substr($key, -7) !== '.manage') {
                continue;
            }

            $viewKey = substr($key, 0, -7) . '.view';

            if (isset($validMap[$viewKey])) {
                $requested[$viewKey] = true;
            }
        }

        return array_values(array_filter(
            $valid,
            function ($key) use ($requested) {
                return isset($requested[$key]);
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
