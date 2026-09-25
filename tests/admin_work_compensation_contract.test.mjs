import assert from 'node:assert/strict';
import fs from 'node:fs';

const model = fs.readFileSync(
    'app/Models/AdminWorkTime.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminWorkTimeController.php',
    'utf8'
);
const routes = fs.readFileSync(
    'routes/AdminSecurity.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/administrators/work-time.php',
    'utf8'
);
const script = fs.readFileSync(
    'js/admin-work-time-compensation.js',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);
const auditView = fs.readFileSync(
    'views/admin/administrators/audit.php',
    'utf8'
);

assert.match(
    model,
    /CREATE TABLE IF NOT EXISTS admin_work_compensation/
);
assert.match(
    model,
    /hourly_rate_minor BIGINT UNSIGNED NOT NULL DEFAULT 0/
);
assert.match(
    model,
    /payout_type VARCHAR\(20\) NOT NULL DEFAULT 'monthly'/
);
assert.match(
    model,
    /one_time_from DATE NULL[\s\S]*?one_time_to DATE NULL/
);
assert.match(
    model,
    /public static function saveCompensation\s*\(/
);
assert.match(
    model,
    /\['one_time', 'weekly', 'monthly'\]/
);
assert.match(
    model,
    /\['UAH', 'EUR', 'USD', 'PLN'\]/
);
assert.match(
    model,
    /private static function moneyToMinor/
);
assert.match(
    model,
    /\(\$seconds \* \$rateMinor\) \+ 1800/
);
assert.match(
    model,
    /strtotime\('monday this week'\)/
);
assert.match(
    model,
    /date\('Y-m-01'\)/
);
assert.match(
    model,
    /SUM\(admin_seconds \+ public_seconds\)/
);

assert.match(
    controller,
    /public function saveCompensation\(\)/
);
assert.match(
    controller,
    /AdminWorkTime::canViewReport/
);
assert.match(
    controller,
    /AdminWorkTime::saveCompensation/
);
assert.match(
    controller,
    /AdminAccess::audit\(\s*'admin\.compensation_updated'[\s\S]*?'target_admin_id' => \$targetAdminId/
);

const auditCallStart = controller.indexOf(
    "AdminAccess::audit(\n                'admin.compensation_updated'"
);
const auditCallEnd = controller.indexOf(
    ');',
    auditCallStart
);
assert.ok(auditCallStart >= 0 && auditCallEnd > auditCallStart);
const auditCall = controller.slice(auditCallStart, auditCallEnd);

assert.doesNotMatch(auditCall, /hourly_rate|currency|amount|rate_minor/);

assert.match(
    routes,
    /post\(\s*['"]\/admin\/work-time\/compensation['"][\s\S]*?saveCompensation/
);

assert.match(view, /Робочий час і нарахування/);
assert.match(view, /Нараховано/);
assert.match(view, /Ставка за годину/);
assert.match(view, /Разова виплата/);
assert.match(view, /Щотижнева виплата/);
assert.match(view, /Щомісячна виплата/);
assert.match(view, /data-work-payout-type/);
assert.match(view, /data-work-one-time-fields/);
assert.match(view, /admin-work-time-compensation\.js\?v=1/);
assert.match(view, /admin-work-time\.css\?v=2/);

assert.match(
    script,
    /payout\.value === 'one_time'/
);
assert.match(
    script,
    /input\.required = isOneTime/
);

assert.match(
    management,
    /DELETE FROM admin_work_compensation/
);
assert.match(
    auditView,
    /'admin\.compensation_updated' => 'Оновлено умови оплати адміністратора'/
);

process.stdout.write(
    'administrator work compensation contract passed\n'
);
