import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const activity = fs.readFileSync(
    'app/Models/AdminWorkActivity.php',
    'utf8'
);
const workTime = fs.readFileSync(
    'app/Models/AdminWorkTime.php',
    'utf8'
);
const profile = fs.readFileSync(
    'views/admin/administrators/profile.php',
    'utf8'
);

test('legacy activity migration qualifies duplicate-key target columns', () => {
    const qualifiedFirst = activity.match(
        /admin_work_time_source_daily\.first_activity_at/g
    ) || [];
    const qualifiedLast = activity.match(
        /admin_work_time_source_daily\.last_activity_at/g
    ) || [];
    const qualifiedSeconds = activity.match(
        /admin_work_time_source_daily\.active_seconds/g
    ) || [];

    assert.ok(qualifiedFirst.length >= 6);
    assert.ok(qualifiedLast.length >= 6);
    assert.ok(qualifiedSeconds.length >= 2);

    assert.doesNotMatch(
        activity,
        /ON DUPLICATE KEY UPDATE[\s\S]*?WHEN first_activity_at IS NULL/
    );
});

test('missing payout type normalizes to monthly without undefined key access', () => {
    const normalizeStart = workTime.indexOf(
        'private static function normalizeCompensationRow'
    );
    const normalizeEnd = workTime.indexOf(
        'private static function calculateCompensation',
        normalizeStart
    );

    assert.ok(normalizeStart >= 0 && normalizeEnd > normalizeStart);

    const block = workTime.slice(normalizeStart, normalizeEnd);

    assert.match(
        block,
        /\$payoutType = trim\([\s\S]*?\$row\['payout_type'\] \?\? 'monthly'/
    );
    assert.match(
        block,
        /'payout_type' => \$payoutType/
    );
    assert.doesNotMatch(
        block,
        /\? \(string\) \$row\['payout_type'\]/
    );
});

test('personal profile keeps contract status visible', () => {
    assert.match(profile, /Мій робочий контракт/);
    assert.match(
        profile,
        /Умови контракту тимчасово не вдалося завантажити/
    );
});

process.stdout.write(
    'administrator work time regression contract passed\n'
);
