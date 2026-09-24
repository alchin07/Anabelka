import assert from 'node:assert/strict';
import fs from 'node:fs';

const notifications = fs.readFileSync(
    'app/Models/AdminNotificationCenter.php',
    'utf8'
);
const header = fs.readFileSync(
    'views/partials/header.php',
    'utf8'
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('orders\\.manage'\)\)[\s\S]*?self::item\([\s\S]*?'orders'/
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('users\\.manage'\)\)[\s\S]*?self::item\([\s\S]*?'new_users'[\s\S]*?self::item\([\s\S]*?'rank_requests'/
);

assert.match(
    notifications,
    /if\s*\(AdminAccess::can\('translations\\.manage'\)\)[\s\S]*?self::item\([\s\S]*?'translations'/
);

assert.match(
    notifications,
    /foreach\s*\(\$items as \$item\)[\s\S]*?\$badgeTotal \+= \$count/
);

assert.match(
    header,
    /\$adminNotificationCount\s*=\s*max\([\s\S]*?\$adminNotificationSummary\['total'\]/
);

assert.doesNotMatch(
    header,
    /\$adminNotificationSummary\['all_total'\][\s\S]*?public-header-admin-message-badge/
);

process.stdout.write(
    'public admin badge permission filter contract passed\n'
);
