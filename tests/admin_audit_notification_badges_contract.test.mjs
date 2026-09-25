import assert from 'node:assert/strict';
import fs from 'node:fs';

const notifications = fs.readFileSync(
    'app/Models/AdminNotificationCenter.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminAdministratorController.php',
    'utf8'
);
const adminHeader = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);
const publicHeader = fs.readFileSync(
    'views/partials/header.php',
    'utf8'
);
const audit = fs.readFileSync(
    'views/admin/administrators/audit.php',
    'utf8'
);

assert.match(
    notifications,
    /'audit' => 'Нові дії в журналі'/
);
assert.match(
    notifications,
    /AdminAccess::can\('audit\.view'\)[\s\S]*?countNewAuditActions/
);
assert.match(
    notifications,
    /public static function auditUnreadState\s*\(/
);
assert.match(
    notifications,
    /WHERE l\.id > :baseline[\s\S]*?AND l\.id <= :max_id[\s\S]*?rs\.audit_log_id IS NULL[\s\S]*?GROUP BY[\s\S]*?l\.admin_user_id/
);
assert.match(
    notifications,
    /public static function auditUnreadEntryIds\s*\(/
);
assert.match(
    notifications,
    /public static function markAuditEntrySeen\s*\(/
);
assert.match(
    notifications,
    /public static function markAuditAllSeen\s*\(/
);

assert.match(
    controller,
    /auditUnreadState\([\s\S]*?auditUnreadEntryIds/
);
assert.doesNotMatch(
    controller,
    /\$isFullJournal[\s\S]*?markAuditSeen/
);

assert.match(
    adminHeader,
    /\$notificationByKey\['audit'\]/
);
assert.match(
    adminHeader,
    /Журнал дій[\s\S]*?\$auditBadge > 0[\s\S]*?admin-nav-badge/
);

assert.match(
    publicHeader,
    /\$adminNotificationSummary\['total'\]/
);

assert.match(audit, /\$auditUnreadByActor/);
assert.match(audit, /\$auditUnreadEntryLookup/);
assert.match(audit, /data-audit-entry-id/);
assert.match(audit, /data-audit-entry-new-badge/);
assert.match(audit, /admin-audit-new-badge/);
assert.match(
    audit,
    /\$groupUnread[\s\S]*?\$auditUnreadByActor\[\$groupKey\]/
);

process.stdout.write(
    'audit notification badges contract passed\n'
);
