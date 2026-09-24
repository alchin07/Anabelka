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
const routes = read('routes/Web.php');
const header = read('views/admin/partials/header.php');
const view = read('views/admin/dashboard-builder/index.php');
const css = read('css/admin-dashboard-builder.css');
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
    'deleteLink'
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
    /AdminDashboardServiceRegistry::available\s*\(/
);
assert.match(
    controller,
    /Сама служба та її дані залишилися без змін/
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
assert.match(view, /admin-dashboard-builder\.css\?v=1/);
assert.match(css, /@media\s*\(max-width:\s*700px\)/);
assert.match(css, /min-height:\s*44px/);

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
    registry,
    /public\s+static\s+function\s+canUse\s*\(/
);

process.stdout.write(
    'admin dashboard builder contract passed\n'
);
