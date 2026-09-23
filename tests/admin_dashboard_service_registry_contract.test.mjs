import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => {
    assert.equal(fs.existsSync(path), true, path + ' must exist');
    return fs.readFileSync(path, 'utf8');
};

const escapeRegex = value => value.replace(
    /[.*+?^$(){}|[\]\\]/g,
    '\\$&'
);

const app = read('app/Core/App.php');
const registry = read('app/Models/AdminDashboardServiceRegistry.php');
const controller = read('app/Controllers/AdminDashboardController.php');
const access = read('app/Models/AdminAccess.php');
const notifications = read('app/Models/AdminNotificationCenter.php');
const systemErrors = read('app/Models/SystemErrorNotification.php');
const routes = [
    read('routes/Web.php'),
    read('routes/Content.php'),
    read('routes/MobileNavigation.php'),
    read('routes/AdminSecurity.php')
].join('\n');

assert.match(
    app,
    /Models\/AdminDashboardServiceRegistry\.php/
);
assert.match(
    controller,
    /AdminDashboardServiceRegistry::available\s*\(/
);
assert.match(
    controller,
    /AdminDashboardServiceRegistry::groups\s*\(/
);
assert.doesNotMatch(
    controller,
    /AdminDashboardServiceRegistry::availableWithBadges\s*\(/
);

for (const method of [
    'groups',
    'catalog',
    'find',
    'requireService',
    'available',
    'availableWithBadges',
    'canUse'
]) {
    assert.match(
        registry,
        new RegExp(
            'public\\s+static\\s+function\\s+'
            + method
            + '\\s*\\('
        )
    );
}

const serviceKeys = [
    'orders',
    'search',
    'users',
    'ranks',
    'products',
    'categories',
    'delivery',
    'languages',
    'translations',
    'ai_translation',
    'news',
    'reviews',
    'mobile_navigation',
    'home_page',
    'administrators',
    'audit',
    'vip_price_views',
    'backup',
    'system_errors',
    'system_error_notifications'
];

for (const serviceKey of serviceKeys) {
    assert.match(
        registry,
        new RegExp(
            '[\\\'"]'
            + serviceKey
            + '[\\\'"]\\s*=>\\s*\\['
        )
    );
}

const serviceRoutes = [
    '/admin/orders',
    '/admin/search',
    '/admin/users',
    '/admin/ranks',
    '/admin/products',
    '/admin/categories',
    '/admin/delivery',
    '/admin/languages',
    '/admin/translations',
    '/admin/ai-translation',
    '/admin/news',
    '/admin/reviews',
    '/admin/mobile-navigation',
    '/admin/home-page',
    '/admin/administrators',
    '/admin/audit',
    '/admin/vip-price-views',
    '/admin/system/backup',
    '/admin/system/errors',
    '/admin/system/error-external-notifications'
];

for (const route of serviceRoutes) {
    assert.match(
        registry,
        new RegExp(
            '[\\\'"]path[\\\'"]\\s*=>\\s*[\\\'"]'
            + escapeRegex(route)
            + '[\\\'"]'
        )
    );
    assert.equal(
        routes.includes("'" + route + "'")
            || routes.includes('"' + route + '"'),
        true,
        route + ' must be backed by a registered route'
    );
}

const permissionKeys = [
    'admin.access',
    'orders.view',
    'search.view',
    'users.view',
    'ranks.view',
    'products.view',
    'categories.view',
    'delivery.view',
    'languages.view',
    'translations.view',
    'ai_translation.view',
    'news.view',
    'reviews.view',
    'mobile_navigation.view',
    'home_page.view',
    'administrators.view',
    'audit.view',
    'vip_prices.view'
];

for (const permissionKey of permissionKeys) {
    assert.match(
        access,
        new RegExp(
            '[\\\'"]'
            + escapeRegex(permissionKey)
            + '[\\\'"]'
        )
    );
    assert.match(
        registry,
        new RegExp(
            '[\\\'"]permission[\\\'"]\\s*=>\\s*[\\\'"]'
            + escapeRegex(permissionKey)
            + '[\\\'"]'
        )
    );
}

assert.match(
    registry,
    /'orders'[\s\S]*?'channels'\s*=>\s*\['orders'\]/
);
assert.match(
    registry,
    /'users'[\s\S]*?'channels'\s*=>\s*\['new_users',\s*'rank_requests'\]/
);
assert.match(
    registry,
    /'translations'[\s\S]*?'channels'\s*=>\s*\['translations'\]/
);
assert.match(
    registry,
    /'system_errors'[\s\S]*?'source'\s*=>\s*'system_errors'/
);
assert.match(
    registry,
    /AdminNotificationCenter::summary\s*\(/
);
assert.match(
    registry,
    /SystemErrorNotification::unreadCount\s*\(/
);
assert.match(
    notifications,
    /'orders'\s*=>\s*'Нові замовлення'/
);
assert.match(
    notifications,
    /'new_users'\s*=>/
);
assert.match(
    notifications,
    /'rank_requests'\s*=>/
);
assert.match(
    notifications,
    /'translations'\s*=>/
);
assert.match(
    systemErrors,
    /public\s+static\s+function\s+unreadCount\s*\(/
);

assert.match(
    registry,
    /'backup'[\s\S]*?'roles'\s*=>\s*\['owner'\]/
);
assert.match(
    registry,
    /'system_errors'[\s\S]*?'roles'\s*=>\s*\['owner'\]/
);
assert.match(
    registry,
    /'system_error_notifications'[\s\S]*?'roles'\s*=>\s*\['owner'\]/
);

assert.doesNotMatch(
    registry,
    /https?:\/\//
);
assert.doesNotMatch(
    registry,
    /'allow_multiple'\s*=>\s*true/
);
assert.match(
    registry,
    /'url'\s*=>\s*'\/Anabelka'\s*\.\s*\$path/
);
assert.match(
    registry,
    /AdminAccess::can\s*\(\$permission\)/
);

process.stdout.write(
    'admin dashboard service registry contract passed\n'
);
