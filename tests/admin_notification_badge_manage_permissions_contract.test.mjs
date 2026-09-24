import assert from 'node:assert/strict';
import fs from 'node:fs';

const notifications = fs.readFileSync(
    'app/Models/AdminNotificationCenter.php',
    'utf8'
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('orders\.manage'\)\)[\s\S]*?'orders'/
);
assert.doesNotMatch(
    notifications,
    /if\s*\(AdminAccess::can\('orders\.view'\)\)[\s\S]*?'orders'/
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('users\.manage'\)\)[\s\S]*?'new_users'[\s\S]*?'rank_requests'/
);
assert.doesNotMatch(
    notifications,
    /AdminAccess::can\('users\.view'\)/
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('translations\.manage'\)\)[\s\S]*?'translations'/
);

assert.match(
    notifications,
    /case 'orders':[\s\S]*?orders\.manage/
);
assert.match(
    notifications,
    /case 'new_users':[\s\S]*?case 'rank_requests':[\s\S]*?users\.manage/
);
assert.match(
    notifications,
    /case 'translations':[\s\S]*?translations\.manage/
);

assert.match(
    notifications,
    /\$path !== '\/admin\/users'[\s\S]*?AdminAccess::can\('users\.manage'\)/
);

assert.match(
    notifications,
    /countTranslationAttention\(\)[\s\S]*?products\.manage[\s\S]*?categories\.manage[\s\S]*?delivery\.manage/
);

process.stdout.write(
    'admin notification badge manage-permission contract passed\n'
);
