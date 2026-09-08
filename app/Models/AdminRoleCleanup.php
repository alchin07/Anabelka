<?php

class AdminRoleCleanup
{
    private static $done = false;


    public static function run()
    {
        if (self::$done) {
            return;
        }

        self::$done = true;
        $db = Database::connect();

        $canonical = $db->query("
            SELECT id
            FROM admin_roles
            WHERE slug = 'store_owner'
            LIMIT 1
        ")->fetchColumn();

        $canonicalId = (int) $canonical;

        if ($canonicalId <= 0) {
            return;
        }

        $legacyRoles = $db->query("
            SELECT id, slug
            FROM admin_roles
            WHERE name = 'Власник'
              AND is_system = 1
              AND slug NOT IN ('owner', 'store_owner')
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($legacyRoles)) {
            return;
        }

        $db->beginTransaction();

        try {
            $moveAdmins = $db->prepare("
                UPDATE admin_users
                SET role_id = :canonical_role_id
                WHERE role_id = :legacy_role_id
            ");

            $deleteRole = $db->prepare("
                DELETE FROM admin_roles
                WHERE id = :legacy_role_id
                  AND slug NOT IN ('owner', 'store_owner')
            ");

            foreach ($legacyRoles as $legacyRole) {
                $legacyId = (int) ($legacyRole['id'] ?? 0);

                if ($legacyId <= 0 || $legacyId === $canonicalId) {
                    continue;
                }

                // Якщо до старої дубльованої ролі вже був прив'язаний
                // адміністратор, переносимо його на канонічну роль
                // «Власник», а не видаляємо обліковий запис.
                $moveAdmins->execute([
                    'canonical_role_id' => $canonicalId,
                    'legacy_role_id' => $legacyId
                ]);

                // Пов'язані права та збережені перевизначення видаляться
                // каскадно через зовнішні ключі.
                $deleteRole->execute([
                    'legacy_role_id' => $legacyId
                ]);
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('Admin role cleanup error: ' . $e->getMessage());
        }
    }
}
