<?php

class AdminRolePermission
{
    private static $schemaReady = false;
    private static $applied = false;


    public static function applySavedOverrides()
    {
        if (self::$applied) {
            return;
        }

        self::ensureSchema();
        $db = Database::connect();
        $rows = $db->query("
            SELECT
                o.role_id,
                o.permissions_json,
                r.slug
            FROM admin_role_permission_overrides o
            INNER JOIN admin_roles r ON r.id = o.role_id
            ORDER BY o.role_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (($row['slug'] ?? '') === 'owner') {
                continue;
            }

            $decoded = json_decode(
                (string) ($row['permissions_json'] ?? '[]'),
                true
            );
            $keys = self::normalizePermissionKeys(
                is_array($decoded) ? $decoded : []
            );

            self::replaceLivePermissions(
                $db,
                (int) ($row['role_id'] ?? 0),
                $keys
            );
        }

        self::$applied = true;
    }


    public static function update($roleId, array $permissionKeys)
    {
        AdminAccess::ensureSchema();
        self::ensureSchema();

        $roleId = (int) $roleId;

        if ($roleId <= 0) {
            throw new InvalidArgumentException('Некоректна роль адміністратора.');
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, name, slug, is_system
            FROM admin_roles
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$role) {
            throw new RuntimeException('Роль адміністратора не знайдено.');
        }

        if (($role['slug'] ?? '') === 'owner') {
            throw new RuntimeException(
                'Права ролі «Розробник» змінювати не можна.'
            );
        }

        $keys = self::normalizePermissionKeys($permissionKeys);
        $json = json_encode(
            $keys,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('Не вдалося підготувати права ролі.');
        }

        $db->beginTransaction();

        try {
            $save = $db->prepare("
                INSERT INTO admin_role_permission_overrides
                    (role_id, permissions_json)
                VALUES
                    (:role_id, :permissions_json)
                ON DUPLICATE KEY UPDATE
                    permissions_json = VALUES(permissions_json),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $save->execute([
                'role_id' => $roleId,
                'permissions_json' => $json
            ]);

            self::replaceLivePermissions($db, $roleId, $keys);
            $db->commit();
            self::$applied = true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $role['permissions'] = $keys;

        return $role;
    }


    private static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS admin_role_permission_overrides
            (
                role_id INT UNSIGNED NOT NULL,
                permissions_json TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (role_id),
                CONSTRAINT fk_admin_role_permission_overrides_role
                    FOREIGN KEY (role_id)
                    REFERENCES admin_roles(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    private static function normalizePermissionKeys(array $permissionKeys)
    {
        $db = Database::connect();
        $validRows = $db->query("
            SELECT permission_key
            FROM admin_permissions
            ORDER BY sort_order ASC, permission_key ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
        $validMap = array_fill_keys($validRows, true);
        $requested = [];

        foreach ($permissionKeys as $key) {
            $key = trim((string) $key);

            if ($key !== '' && isset($validMap[$key])) {
                $requested[$key] = true;
            }
        }

        // Без цих двох прав адміністратор не зможе нормально увійти
        // до службової частини та відкрити її головну сторінку.
        $requested['admin.access'] = true;
        $requested['dashboard.view'] = true;

        // Право керування автоматично включає право перегляду
        // відповідного розділу.
        foreach (array_keys($requested) as $key) {
            if (substr($key, -7) !== '.manage') {
                continue;
            }

            $viewKey = substr($key, 0, -7) . '.view';

            if (isset($validMap[$viewKey])) {
                $requested[$viewKey] = true;
            }
        }

        $result = [];

        foreach ($validRows as $key) {
            if (isset($requested[$key])) {
                $result[] = $key;
            }
        }

        return $result;
    }


    private static function replaceLivePermissions(PDO $db, $roleId, array $keys)
    {
        $roleId = (int) $roleId;

        if ($roleId <= 0) {
            return;
        }

        $delete = $db->prepare("
            DELETE FROM admin_role_permissions
            WHERE role_id = :role_id
        ");
        $delete->execute(['role_id' => $roleId]);

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
                'role_id' => $roleId,
                'permission_key' => $key
            ]);
        }
    }
}
