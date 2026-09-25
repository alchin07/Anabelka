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
const css = fs.readFileSync(
    'css/admin-administrators.css',
    'utf8'
);

assert.match(
    management,
    /public static function groupAuditEntriesByAdministrator\(array \$entries\)/
);
assert.match(
    management,
    /\$key = \$adminId > 0[\s\S]*?'admin-' \. \$adminId[\s\S]*?: 'system'/
);
assert.match(
    management,
    /'name' => \$name !== ''[\s\S]*?'Система \/ невідомий адміністратор'/
);
assert.match(
    management,
    /\$group\['count'\] = count\(\$group\['entries'\]\)/
);

assert.match(
    controller,
    /\$recentEntries = array_slice\(\$entries, 0, 3\)/
);
assert.match(
    controller,
    /groupAuditEntriesByAdministrator\([\s\S]*?array_slice\(\$entries, 3\)/
);

assert.match(
    audit,
    /<details[\s\S]*?class="admin-audit-item"/
);
assert.match(audit, /\$renderAuditEntry\(\$entry, true\)/);
assert.match(audit, /\$renderAuditEntry\(\$entry, false\)/);
assert.match(audit, /3 найсвіжіші дії/);
assert.match(audit, /За адміністратором/);
assert.match(audit, /admin-audit-group-head/);
assert.match(audit, /admin-audit-new-badge/);
assert.match(
    audit,
    /admin-administrators\\.css\\?v=9/
);

assert.match(css, /\.admin-audit-summary/);
assert.match(css, /details\.admin-audit-item\[open\]/);
assert.match(css, /\.admin-audit-group/);

process.stdout.write(
    'compact grouped administrator audit journal contract passed\n'
);
