import assert from 'node:assert/strict';
import fs from 'node:fs';

const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);

const deleteStart = management.indexOf(
    'public static function deleteCustomRole'
);
const deleteEnd = management.indexOf(
    'public static function updateCustomRolePermissions',
    deleteStart
);

assert.ok(deleteStart >= 0 && deleteEnd > deleteStart);

const block = management.slice(deleteStart, deleteEnd);

assert.match(
    block,
    /SELECT COUNT\(\*\)[\s\S]*?FROM admin_users[\s\S]*?WHERE role_id = :role_id[\s\S]*?FOR UPDATE/
);

assert.match(
    block,
    /\$assignedCount > 0/
);

assert.match(
    block,
    /DELETE FROM admin_roles[\s\S]*?AND NOT EXISTS \([\s\S]*?SELECT 1[\s\S]*?FROM admin_users[\s\S]*?WHERE role_id = :assigned_role_id/
);

assert.match(
    block,
    /if \(\$delete->rowCount\(\) !== 1\)[\s\S]*?SELECT COUNT\(\*\)[\s\S]*?FROM admin_users/
);

process.stdout.write(
    'custom admin role atomic delete guard contract passed\n'
);
