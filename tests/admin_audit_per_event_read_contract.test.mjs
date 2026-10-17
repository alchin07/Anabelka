import assert from 'node:assert/strict';
import fs from 'node:fs';

const access = fs.readFileSync(
    'app/Models/AdminAccess.php',
    'utf8'
);
const notifications = fs.readFileSync(
    'app/Models/AdminNotificationCenter.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const routes = fs.readFileSync(
    'routes/AdminSecurity.php',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);
const audit = fs.readFileSync(
    'views/admin/administrators/audit.php',
    'utf8'
);
const script = fs.readFileSync(
    'js/admin-audit-read-state.js',
    'utf8'
);

assert.match(
    access,
    /in_array\(\$action, \['admin\.login', 'admin\.logout'\], true\)[\s\S]*?isSeniorSessionAudit/
);
assert.match(
    access,
    /private static function isSeniorSessionAudit\(\$adminUserId\)[\s\S]*?\['owner', 'store_owner'\]/
);

assert.match(
    notifications,
    /CREATE TABLE IF NOT EXISTS admin_audit_read_state/
);
assert.match(
    notifications,
    /PRIMARY KEY \(viewer_admin_user_id, audit_log_id\)/
);
assert.match(
    notifications,
    /LEFT JOIN admin_audit_read_state rs[\s\S]*?rs\.audit_log_id IS NULL/
);
assert.match(
    notifications,
    /public static function markAuditEntrySeen\s*\(/
);
assert.match(
    notifications,
    /INSERT IGNORE INTO admin_audit_read_state/
);
assert.match(
    notifications,
    /public static function markAuditAllSeen\s*\(/
);
assert.match(
    notifications,
    /public static function canClearAuditUnread\s*\([\s\S]*?\['owner', 'store_owner'\]/
);

assert.match(
    controller,
    /public function markAuditEntrySeen\(\)/
);
assert.match(
    controller,
    /public function clearAuditUnread\(\)/
);
assert.doesNotMatch(
    controller,
    /\$isFullJournal[\s\S]*?markAuditSeen/
);

assert.match(
    routes,
    /post\(\s*['"]\/admin\/audit\/seen['"][\s\S]*?markAuditEntrySeen/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/audit\/seen-all['"][\s\S]*?clearAuditUnread/
);
assert.match(
    router,
    /\$path === '\/admin\/audit'[\s\S]*?strpos\(\$path, '\/admin\/audit\/'\)[\s\S]*?audit\.view/
);

assert.match(audit, /data-audit-entry-id/);
assert.match(audit, /data-audit-entry-new-badge/);
assert.match(audit, /data-audit-group-badge/);
assert.match(audit, /\/admin\/audit\/seen-all/);
assert.match(audit, /\$canClearAuditUnread/);
assert.match(audit, /Позначити всі дії прочитаними/);

assert.match(
    script,
    /details\.addEventListener\('toggle'[\s\S]*?details\.open[\s\S]*?markSeen\(details\)/
);
assert.match(
    script,
    /data-audit-entry-new-badge[\s\S]*?entryBadge\.remove\(\)/
);
assert.match(
    script,
    /updateGroupBadge[\s\S]*?actor_remaining/
);
assert.match(
    script,
    /markInitiallyOpenItems/
);

process.stdout.write(
    'per-event audit read-state contract passed\n'
);
