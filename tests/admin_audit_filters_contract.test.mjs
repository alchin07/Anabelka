import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);
const audit = fs.readFileSync(
    'views/admin/administrators/audit.php',
    'utf8'
);
const index = fs.readFileSync(
    'views/admin/administrators/index.php',
    'utf8'
);

assert.match(
    management,
    /public static function normalizeAuditFilters\(array \$filters\)/
);
assert.match(
    management,
    /'admin_id' => \$adminId[\s\S]*?'action' => \$action[\s\S]*?'date_from' => \$dateFrom[\s\S]*?'date_to' => \$dateTo/
);
assert.match(
    management,
    /public static function auditLog\(array \$filters = \[\], \$limit = 200\)/
);
assert.match(
    management,
    /l\.admin_user_id = :admin_id/
);
assert.match(
    management,
    /l\.action = :action/
);
assert.match(
    management,
    /l\.created_at >= :date_from/
);
assert.match(
    management,
    /l\.created_at < :date_to_exclusive/
);
assert.match(
    management,
    /public static function auditAdministrators\(\)/
);
assert.match(
    management,
    /public static function auditActions\(\)/
);

assert.match(
    controller,
    /public function audit\(\)[\s\S]*?normalizeAuditFilters[\s\S]*?auditLog\(\$filters, 250\)[\s\S]*?auditAdministrators\(\)[\s\S]*?auditActions\(\)/
);

assert.match(audit, /class="admin-audit-filters"/);
assert.match(audit, /name="admin_id"/);
assert.match(audit, /name="action"/);
assert.match(audit, /name="date_from"/);
assert.match(audit, /name="date_to"/);
assert.match(audit, /href="\/Anabelka\/admin\/audit">Скинути/);

assert.match(
    index,
    /\/admin\/audit\?admin_id=<\?= \$adminId \?>/
);
assert.match(index, /Дії адміністратора/);

process.stdout.write(
    'admin audit filters and quick link contract passed\n'
);
