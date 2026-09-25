import assert from 'node:assert/strict';
import fs from 'node:fs';

const app = fs.readFileSync(
    'app/Core/App.php',
    'utf8'
);
const activity = fs.readFileSync(
    'app/Models/AdminWorkActivity.php',
    'utf8'
);
const workTime = fs.readFileSync(
    'app/Models/AdminWorkTime.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminWorkTimeController.php',
    'utf8'
);
const script = fs.readFileSync(
    'js/admin-work-time.js',
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
const report = fs.readFileSync(
    'views/admin/administrators/work-time.php',
    'utf8'
);
const profile = fs.readFileSync(
    'views/admin/administrators/profile.php',
    'utf8'
);
const management = fs.readFileSync(
    'app/Models/AdminManagement.php',
    'utf8'
);

assert.match(app, /Models\/AdminWorkActivity\.php/);

assert.match(
    activity,
    /CREATE TABLE IF NOT EXISTS admin_work_activity_sessions/
);
assert.match(
    activity,
    /CREATE TABLE IF NOT EXISTS admin_work_activity_segments/
);
assert.match(
    activity,
    /CREATE TABLE IF NOT EXISTS admin_work_time_source_daily/
);
assert.match(
    activity,
    /'web_admin'[\s\S]*?'web_public'[\s\S]*?'android'[\s\S]*?'ios'/
);
assert.match(
    activity,
    /SELECT DATE_FORMAT\(NOW\(\), '%Y-%m-%d %H:%i:%s'\)/
);
assert.match(
    activity,
    /SELECT id, is_active[\s\S]*?FOR UPDATE/
);
assert.match(
    activity,
    /claimUniqueInterval/
);
assert.match(
    activity,
    /started_at < :slice_end[\s\S]*?ended_at > :slice_start/
);
assert.match(
    activity,
    /active_seconds =\s*active_seconds \+ VALUES\(active_seconds\)/
);
assert.match(
    activity,
    /MAX_CREDIT_GAP_SECONDS = 90/
);
assert.match(
    activity,
    /migrateLegacyDaily/
);
assert.match(
    activity,
    /FROM admin_work_time_daily/
);

assert.match(
    workTime,
    /return AdminWorkActivity::heartbeat/
);
assert.match(
    workTime,
    /AdminWorkActivity::rangeSummary/
);
assert.match(
    workTime,
    /'android_seconds'/
);
assert.match(
    workTime,
    /'ios_seconds'/
);

assert.match(controller, /\$_POST\['source'\]/);
assert.match(controller, /\$_POST\['session_id'\]/);
assert.match(controller, /\$_POST\['active'\]/);
assert.match(
    controller,
    /legacy-web-[\s\S]*?session_id\(\)/
);

assert.match(script, /anabelka-admin-work-session-v2/);
assert.match(script, /crypto\.randomUUID/);
assert.match(script, /body\.set\('source'/);
assert.match(script, /body\.set\('session_id'/);
assert.match(script, /body\.set\('active'/);
assert.doesNotMatch(script, /active_seconds/);
assert.match(script, /navigator\.sendBeacon/);

assert.match(
    adminHeader,
    /data-work-source=["']web_admin["']/
);
assert.match(
    publicHeader,
    /data-work-source=["']web_public["']/
);
assert.match(adminHeader, /admin-work-time\.js\?v=2/);
assert.match(publicHeader, /admin-work-time\.js\?v=2/);

assert.match(report, />Android</);
assert.match(report, />iPhone</);
assert.match(profile, />Android</);
assert.match(profile, />iPhone</);

assert.match(
    management,
    /DELETE FROM admin_work_activity_sessions/
);

process.stdout.write(
    'cross-platform administrator work activity contract passed\n'
);
