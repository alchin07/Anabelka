import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => {
    assert.equal(fs.existsSync(path), true, path + ' must exist');
    return fs.readFileSync(path, 'utf8');
};

const app = read('app/Core/App.php');
const access = read('app/Models/AdminAccess.php');
const registry = read('app/Models/AdminDashboardServiceRegistry.php');
const layout = read('app/Models/AdminDashboardLayout.php');
const controller = read('app/Controllers/AdminDashboardBuilderController.php');
const dashboardController = read('app/Controllers/AdminDashboardController.php');
const dashboardView = read('views/admin/index.php');
const dashboardCss = read('css/admin-dashboard.css');
const routes = read('routes/Web.php');
const header = read('views/admin/partials/header.php');
const view = read('views/admin/dashboard-builder/index.php');
const css = read('css/admin-dashboard-builder.css');
const js = read('js/admin-dashboard-builder.js');
const migration = read(
    'database/migrations/2026-09-24_admin_dashboard_builder.sql'
);

assert.match(app, /Models\/AdminDashboardLayout\.php/);
assert.match(app, /Controllers\/AdminDashboardBuilderController\.php/);

assert.match(
    access,
    /'dashboard\.manage',\s*'Керування конструктором головної адмін-панелі'/
);
assert.match(
    access,
    /'\/admin\/dashboard-builder'\s*=>\s*\['dashboard\.manage',\s*'dashboard\.manage'\]/
);
assert.match(
    access,
    /\['administrators\.manage',\s*'dashboard\.manage'\]/
);

for (const action of [
    'index',
    'createBlock',
    'updateBlock',
    'toggleBlock',
    'deleteBlock',
    'createLink',
    'updateLink',
    'toggleLink',
    'deleteLink',
    'reorderBlocks',
    'reorderLinks'
]) {
    assert.match(
        routes,
        new RegExp('AdminDashboardBuilderController@' + action)
    );
}

for (const method of [
    'ensureSchema',
    'allForAdmin',
    'activeForCurrentAdmin',
    'createBlock',
    'updateBlock',
    'toggleBlock',
    'deleteBlock',
    'createLink',
    'updateLink',
    'toggleLink',
    'deleteLink',
    'reorderBlocks',
    'reorderLinks',
    'usedServiceKeys'
]) {
    assert.match(
        layout,
        new RegExp(
            'public\\s+static\\s+function\\s+'
            + method
            + '\\s*\\('
        )
    );
}

assert.match(
    layout,
    /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+admin_dashboard_blocks/i
);
assert.match(
    layout,
    /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+admin_dashboard_links/i
);
assert.match(layout, /service_key\s+VARCHAR\(80\)\s+NOT\s+NULL/i);
assert.match(layout, /label_override\s+VARCHAR\(120\)\s+NULL/i);
assert.match(
    layout,
    /FOREIGN\s+KEY\s*\(block_id\)[\s\S]*?REFERENCES\s+admin_dashboard_blocks\(id\)[\s\S]*?ON\s+DELETE\s+CASCADE/i
);
assert.doesNotMatch(
    layout,
    /admin_dashboard_links[\s\S]*?\burl\s+VARCHAR/i
);
assert.match(
    layout,
    /AdminDashboardServiceRegistry::requireService\s*\(/
);
assert.match(
    layout,
    /AdminDashboardServiceRegistry::canUse\s*\(/
);
assert.match(
    layout,
    /Цю службу вже додано на головну адмін-панель/
);
assert.match(
    layout,
    /DELETE\s+FROM\s+admin_dashboard_links\s+WHERE\s+id\s*=\s*:id/i
);
assert.doesNotMatch(
    layout,
    /DELETE\s+FROM\s+(orders|products|categories|users|languages)/i
);
assert.match(
    layout,
    /public\s+static\s+function\s+reorderBlocks\s*\(/
);
assert.match(
    layout,
    /public\s+static\s+function\s+reorderLinks\s*\(/
);
assert.match(
    layout,
    /Склад блоків змінився/
);
assert.match(
    layout,
    /Склад ярликів змінився/
);
assert.match(
    layout,
    /UPDATE\s+admin_dashboard_links[\s\S]*?block_id\s*=\s*:block_id[\s\S]*?sort_order\s*=\s*:sort_order/i
);

assert.match(
    controller,
    /AdminDashboardLayout::createBlock\s*\(/
);
assert.match(
    controller,
    /AdminDashboardLayout::createLink\s*\(/
);
assert.match(
    controller,
    /AdminDashboardLayout::deleteLink\s*\(/
);
assert.match(
    controller,
    /AdminDashboardServiceRegistry::availableWithBadges\s*\(/
);
assert.match(
    controller,
    /Сама служба та її дані залишилися без змін/
);
assert.match(
    controller,
    /AdminDashboardLayout::reorderBlocks\s*\(/
);
assert.match(
    controller,
    /AdminDashboardLayout::reorderLinks\s*\(/
);
assert.match(
    controller,
    /Content-Type:\s*application\/json/
);

assert.match(
    dashboardController,
    /AdminDashboardLayout::activeForCurrentAdmin\s*\(/
);
assert.match(
    dashboardController,
    /'dashboardBuilderBlocks'\s*=>\s*\$dashboardBuilderBlocks/
);
assert.match(
    dashboardView,
    /\$dashboardBuilderBlocks/
);
assert.match(
    dashboardView,
    /dashboard-builder-layout/
);
assert.match(
    dashboardView,
    /dashboard-builder-service-link/
);
assert.match(
    dashboardView,
    /dashboard-builder-service-badge/
);
assert.match(
    dashboardView,
    /\$dashboardBadgeTone\s*===\s*'error'/
);
assert.match(
    dashboardView,
    /admin-dashboard\.css\?v=3/
);
assert.match(
    dashboardCss,
    /dashboard-builder-service-grid/
);
assert.match(
    dashboardCss,
    /dashboard-builder-service-badge[\s\S]*?background:\s*#8A2BE2/
);
assert.match(
    dashboardCss,
    /dashboard-builder-service-badge\.is-error[\s\S]*?background:\s*#b63e48/
);

assert.match(header, /\$canDashboardBuilder/);
assert.match(header, /href="\/Anabelka\/admin\/dashboard-builder"/);
assert.match(header, /Конструктор адмін-главної/);

assert.match(view, /Конструктор головної адмін-панелі/);
assert.match(view, /name="service_key"/);
assert.match(view, /name="label_override"/);
assert.match(view, /name="block_id"/);
assert.match(view, /name="link_id"/);
assert.match(view, /service_key/);
assert.match(view, /Служба та її дані залишаться без змін/);
assert.match(view, /badge_count/);
assert.match(view, /dashboard-builder-live-badge/);
assert.match(view, /\$badgeTone\s*===\s*'error'/);
assert.match(view, /99\+/);
assert.match(view, /admin-dashboard-builder\.css\?v=5/);
assert.match(view, /data-dashboard-builder-root/);
assert.match(view, /data-dashboard-builder-block-list/);
assert.match(view, /data-dashboard-builder-block-handle/);
assert.match(view, /data-dashboard-builder-block-title/);
assert.match(view, /data-dashboard-builder-link-list/);
assert.match(view, /data-dashboard-builder-link-handle/);
assert.match(view, /data-dashboard-builder-links-empty/);
assert.match(view, /admin-dashboard-builder\.js\?v=1/);
assert.match(css, /@media\s*\(max-width:\s*700px\)/);
assert.match(css, /min-height:\s*44px/);
assert.match(css, /dashboard-builder-live-badge/);
assert.match(css, /background:\s*#8A2BE2/);
assert.match(css, /dashboard-builder-live-badge\.is-error[\s\S]*?background:\s*#b63e48/);
assert.match(css, /dashboard-builder-drag/);
assert.match(css, /touch-action:\s*none/);
assert.match(css, /is-link-drop-target/);
assert.match(
    css,
    /@media\s*\(max-width:\s*700px\)[\s\S]*?\.dashboard-builder-title-form\s*\{[\s\S]*?flex:\s*0\s+0\s+auto/
);
assert.match(
    css,
    /\.dashboard-builder-block-actions,[\s\S]*?\.dashboard-builder-link-actions[\s\S]*?grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/
);
assert.match(css, /dashboard-builder-links-empty\[hidden\]/);
assert.match(
    css,
    /dashboard-builder-block\.is-dragging[\s\S]*?min-height:\s*68px/
);
assert.match(
    css,
    /dashboard-builder-block\.is-dragging::after[\s\S]*?content:\s*attr\(data-dashboard-builder-block-title\)/
);
assert.match(
    css,
    /dashboard-builder-block\.is-dragging[\s\S]*?>\s*\.dashboard-builder-links[\s\S]*?display:\s*none\s*!important/
);

assert.match(js, /PointerEvent/);
assert.match(js, /setPointerCapture/);
assert.match(js, /reorder-blocks/);
assert.match(js, /reorder-links/);
assert.match(js, /block_ids\[\]/);
assert.match(js, /JSON\.stringify\(layout\)/);
assert.match(js, /restoreBlockOrder/);
assert.match(js, /restoreLinkLayout/);
assert.match(js, /blockForPoint/);
assert.match(js, /syncEmptyStates/);

assert.match(
    migration,
    /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+admin_dashboard_blocks/i
);
assert.match(
    migration,
    /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+admin_dashboard_links/i
);
assert.match(
    migration,
    /ON\s+DELETE\s+CASCADE/i
);
assert.doesNotMatch(
    migration,
    /\burl\s+VARCHAR/i
);

assert.match(
    routes,
    /\/admin\/dashboard-builder\/reorder-blocks/
);
assert.match(
    routes,
    /\/admin\/dashboard-builder\/reorder-links/
);

assert.match(
    registry,
    /public\s+static\s+function\s+canUse\s*\(/
);
assert.match(
    registry,
    /public\s+static\s+function\s+availableWithBadges\s*\(/
);
assert.match(
    registry,
    /SystemErrorNotification::unreadCount\s*\(/
);

process.stdout.write(
    'admin dashboard builder contract passed\n'
);
