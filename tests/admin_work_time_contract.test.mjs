import assert from 'node:assert/strict';
import fs from 'node:fs';

const app = fs.readFileSync(
    'app/Core/App.php',
    'utf8'
);
const model = fs.readFileSync(
    'app/Models/AdminWorkTime.php',
    'utf8'
);
const activity = fs.readFileSync(
    'app/Models/AdminWorkActivity.php',
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
const adminHeader = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);
const publicHeader = fs.readFileSync(
    'views/partials/header.php',
    'utf8'
);
const script = fs.readFileSync(
    'js/admin-work-time.js',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/administrators/work-time.php',
    'utf8'
);

assert.match(app, /Models\/AdminWorkTime\.php/);
assert.match(app, /Controllers\/AdminWorkTimeController\.php/);

assert.match(
    model,
    /CREATE TABLE IF NOT EXISTS admin_work_time_daily/
);
assert.match(
    model,
    /admin_seconds INT UNSIGNED NOT NULL DEFAULT 0/
);
assert.match(
    model,
    /public_seconds INT UNSIGNED NOT NULL DEFAULT 0/
);
assert.match(
    model,
    /session_count INT UNSIGNED NOT NULL DEFAULT 0/
);
assert.match(
    app,
    /Models\/AdminWorkActivity\.php/
);
assert.match(
    activity,
    /private const MAX_CREDIT_GAP_SECONDS = 90/
);
assert.match(
    activity,
    /private const IDLE_SESSION_SECONDS = 300/
);
assert.match(
    activity,
    /'web_admin'[\s\S]*?'web_public'[\s\S]*?'android'[\s\S]*?'ios'/
);
assert.match(
    activity,
    /public static function heartbeat\s*\(/
);
assert.match(
    activity,
    /claimUniqueInterval/
);
assert.match(
    activity,
    /admin_work_activity_segments/
);
assert.match(
    activity,
    /admin_work_time_source_daily/
);
assert.match(
    model,
    /\['owner', 'store_owner'\]/
);
assert.match(
    model,
    /'period' => 'today'/
);
assert.match(
    model,
    /'period' => 'week'/
);
assert.match(
    model,
    /'period' => 'month'/
);

assert.match(
    routes,
    /get\(\s*['"]\/admin\/work-time['"][\s\S]*?AdminWorkTimeController@index/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/work-time\/heartbeat['"][\s\S]*?AdminWorkTimeController@heartbeat/
);

assert.match(controller, /AdminWorkTime::heartbeat/);
assert.match(controller, /AdminWorkTime::canViewReport/);
assert.match(controller, /AdminWorkTime::report/);

assert.match(
    adminHeader,
    /data-work-source=["']web_admin["']/
);
assert.match(
    publicHeader,
    /data-work-source=["']web_public["']/
);
assert.match(
    adminHeader,
    /data-work-endpoint=["']\/Anabelka\/admin\/work-time\/heartbeat["']/
);
assert.match(
    publicHeader,
    /data-work-endpoint=["']\/Anabelka\/admin\/work-time\/heartbeat["']/
);
assert.match(adminHeader, /Робочий час/);

assert.match(script, /IDLE_LIMIT_MS = 5 \* 60 \* 1000/);
assert.match(script, /document\.visibilityState === 'visible'/);
assert.match(script, /lastActivityAt/);
assert.match(script, /session_id/);
assert.match(script, /body\.set\('active'/);
assert.doesNotMatch(script, /active_seconds/);
assert.match(script, /navigator\.sendBeacon/);

assert.match(view, /Адмін-панелі та на публічній частині сайту/);
assert.match(view, /Адмін-панель/);
assert.match(view, />Сайт</);
assert.match(view, />Android</);
assert.match(view, />iPhone</);
assert.match(view, /Сесій/);
assert.match(view, /Сьогодні/);
assert.match(view, /7 днів/);
assert.match(view, /Цей місяць/);

process.stdout.write(
    'administrator active work time contract passed\n'
);
