import assert from 'node:assert/strict';
import fs from 'node:fs';

const rolePermission = fs.readFileSync(
    'app/Models/AdminRolePermission.php',
    'utf8'
);

assert.match(
    rolePermission,
    /public static function applySavedOverrides\(\)[\s\S]*?beginTransaction\(\)[\s\S]*?ORDER BY o\.role_id ASC[\s\S]*?FOR UPDATE[\s\S]*?replaceLivePermissions[\s\S]*?commit\(\)/
);

assert.match(
    rolePermission,
    /public static function update\([\s\S]*?beginTransaction\(\)[\s\S]*?FROM admin_roles[\s\S]*?WHERE id = :id[\s\S]*?FOR UPDATE[\s\S]*?replaceLivePermissions/
);

assert.match(
    rolePermission,
    /catch \(Throwable \$e\)[\s\S]*?inTransaction\(\)[\s\S]*?rollBack\(\)/
);

assert.doesNotMatch(
    rolePermission,
    /INSERT IGNORE INTO admin_role_permissions/
);

process.stdout.write(
    'admin role permission concurrency contract passed\n'
);
