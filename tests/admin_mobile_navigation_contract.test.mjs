import assert from 'node:assert/strict';
import fs from 'node:fs';

const required = [
  'app/Controllers/AdminMobileNavigationController.php',
  'routes/MobileNavigation.php',
  'views/admin/mobile-navigation/index.php',
  'css/admin-mobile-navigation.css',
  'js/admin-mobile-navigation.js',
  'js/admin-mobile-navigation-ai-translation.js'
];

for (const file of required) {
  assert.ok(
    fs.existsSync(file),
    `${file} must exist before the mobile-menu editor can pass`
  );
}

const routes = fs.readFileSync('routes/MobileNavigation.php', 'utf8');
const controller = fs.readFileSync(
  'app/Controllers/AdminMobileNavigationController.php',
  'utf8'
);
const access = fs.readFileSync('app/Models/AdminAccess.php', 'utf8');
const app = fs.readFileSync('app/Core/App.php', 'utf8');
const adminHeader = fs.readFileSync('views/admin/partials/header.php', 'utf8');
const view = fs.readFileSync('views/admin/mobile-navigation/index.php', 'utf8');
const css = fs.readFileSync('css/admin-mobile-navigation.css', 'utf8');
const js = fs.readFileSync('js/admin-mobile-navigation.js', 'utf8');
const aiJs = fs.readFileSync(
  'js/admin-mobile-navigation-ai-translation.js',
  'utf8'
);
const adminNav = fs.readFileSync('js/admin-nav.js', 'utf8');

assert.match(
  routes,
  /\$router->get\(\s*['"]\/admin\/mobile-navigation['"]\s*,\s*['"]AdminMobileNavigationController@index['"]\s*\)/
);
for (const action of ['create', 'update', 'toggle', 'move', 'delete']) {
  assert.match(
    routes,
    new RegExp(
      `\\$router->post\\(\\s*['\"]\\/admin\\/mobile-navigation\\/${action}['\"]\\s*,\\s*['\"]AdminMobileNavigationController@${action}['\"]\\s*\\)`
    ),
    `route ${action} must exist`
  );
}

for (const method of ['index', 'create', 'update', 'toggle', 'move', 'delete']) {
  assert.match(
    controller,
    new RegExp(`public\\s+function\\s+${method}\\s*\\(`),
    `controller must expose ${method}()`
  );
}
assert.match(controller, /AdminAccess::verifyCsrf\s*\(/);
assert.match(controller, /AdminAccess::currentId\s*\(/);
assert.match(controller, /AdminAccess::audit\s*\(/);
assert.match(controller, /mobile_navigation\.view/);
assert.match(controller, /mobile_navigation\.manage/);
assert.match(controller, /http_response_code\s*\(\s*419\s*\)/);
assert.match(controller, /http_response_code\s*\(\s*422\s*\)/);
assert.match(controller, /http_response_code\s*\(\s*409\s*\)/);
assert.match(controller, /['"]ok['"]\s*=>\s*true/);
assert.match(controller, /['"]ok['"]\s*=>\s*false/);
assert.doesNotMatch(
  controller,
  /echo\s+.*(?:getTraceAsString|PDOException|SQLSTATE)/is,
  'JSON errors must not expose stack traces or SQL details'
);

assert.match(
  access,
  /['"]\/admin\/mobile-navigation['"]\s*=>\s*\[\s*['"]mobile_navigation\.view['"]\s*,\s*['"]mobile_navigation\.manage['"]\s*\]/
);
assert.match(access, /['"]mobile_navigation\.view['"]/);
assert.match(access, /['"]mobile_navigation\.manage['"]/);

const orderStart = access.indexOf("'order_manager' => [");
const contentStart = access.indexOf("'content_manager' => [");
assert.ok(orderStart >= 0 && contentStart > orderStart, 'role permission blocks must exist');
const orderBlock = access.slice(orderStart, contentStart);
const contentBlock = access.slice(contentStart, access.indexOf('];', contentStart) + 2);
assert.doesNotMatch(orderBlock, /mobile_navigation\.(?:view|manage)/);
assert.match(contentBlock, /mobile_navigation\.view/);
assert.match(contentBlock, /mobile_navigation\.manage/);

assert.match(app, /AdminMobileNavigationController\.php/);
assert.match(app, /routes\/MobileNavigation\.php/);

assert.match(adminHeader, /mobile_navigation\.view/);
assert.match(adminHeader, /\/Anabelka\/admin\/mobile-navigation/);
assert.match(adminHeader, />\s*Мобільне меню\s*</);

assert.match(view, /data-mobile-navigation-add/);
assert.match(view, /data-mobile-navigation-list/);
assert.match(view, /name_uk/);
assert.match(view, /name="url"/);
assert.match(view, /translations\[/);
assert.match(view, /data-mobile-navigation-drag-handle/);
assert.match(view, /data-mobile-navigation-move="up"/);
assert.match(view, /data-mobile-navigation-move="down"/);
assert.match(view, /migrationRequired|migration-required|міграц/i);
assert.match(view, /data-mobile-navigation-ai-translate/);
assert.match(view, /data-mobile-navigation-translation-source/);
assert.match(view, /type="hidden"[\s\S]*\[source\]/);
assert.doesNotMatch(
  view,
  /<span>Джерело<\/span>[\s\S]*?<select[\s\S]*?\[source\]/,
  'source manual/AI picker must not remain visible in the mobile-menu editor'
);
assert.match(adminNav, /\/Anabelka\/admin\/mobile-navigation/);
assert.match(adminNav, /admin-mobile-navigation-ai-translation\.js\?v=2/);
assert.match(aiJs, /AnabelkaAITranslation\.suggest/);
assert.match(aiJs, /context:\s*['"]mobile_navigation['"]/);
assert.match(aiJs, /source\.value\s*=\s*['"]ai['"]/);
assert.match(aiJs, /source\.value\s*=\s*['"]manual['"]/);
assert.match(aiJs, /setWorkflow\(fieldset,\s*['"]ai['"],\s*['"]draft['"]\)/);
assert.match(aiJs, /\.mobile-navigation-editor/);
assert.match(aiJs, /addEventListener\(\s*['"]toggle['"]/);
assert.match(aiJs, /is-mobile-navigation-ai-inactive/);
assert.match(aiJs, /aria-hidden/);
assert.match(css, /#admin-ai-top-slot\.is-mobile-navigation-ai-inactive/);
assert.match(css, /visibility:\s*hidden/);
assert.match(css, /pointer-events:\s*none/);

assert.match(js, /pointerdown/i, 'dragging must start from Pointer Events');
assert.match(js, /data-mobile-navigation-drag-handle/);
assert.match(js, /AnabelkaDialog/);
assert.match(js, /AnabelkaNotify|admin-flash-message|sessionStorage/);
assert.doesNotMatch(
  js,
  /(?:list|container)\.innerHTML\s*=/i,
  'editor must not rebuild the whole list while a form is being edited'
);

assert.match(css, /@media\s*\(max-width:\s*430px\)/);
assert.match(css, /overflow-wrap|word-break/);

process.stdout.write('admin mobile navigation contract passed\n');

assert.match(view, /admin-mobile-navigation\.css\?v=3/);
