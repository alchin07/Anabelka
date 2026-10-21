import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const filePath = relativePath => path.join(projectRoot, relativePath);
const read = relativePath => {
    assert.equal(fs.existsSync(filePath(relativePath)), true, `${relativePath} must exist`);
    return fs.readFileSync(filePath(relativePath), 'utf8');
};

const routes = read('routes/Content.php');
const publicController = read('app/Controllers/NewsController.php');
const adminController = read('app/Controllers/AdminNewsController.php');
const listView = read('views/news/index.php');
const showView = read('views/news/show.php');
const adminView = read('views/admin/news/index.php');
const app = read('app/Core/App.php');
const adminHeader = read('views/admin/partials/header.php');

assert.match(routes, /\$router->get\(\s*['"]\/news['"]\s*,\s*['"]NewsController@index['"]\s*\)/);
assert.match(routes, /\$router->get\(\s*['"]\/news\/\{slug\}['"]\s*,\s*['"]NewsController@show['"]\s*\)/);
for (const action of ['create', 'update', 'publish', 'delete']) {
    assert.match(routes, new RegExp(`\\$router->post\\(\\s*['"]\\/admin\\/news\\/${action}['"]\\s*,\\s*['"]AdminNewsController@${action}['"]`));
}
assert.match(routes, /\$router->get\(\s*['"]\/admin\/news['"]\s*,\s*['"]AdminNewsController@index['"]\s*\)/);

assert.match(publicController, /SiteNews::publishedPage\s*\(/);
assert.match(publicController, /SiteNews::findPublishedBySlug\s*\(/);
assert.match(publicController, /Translator::currentLanguage\s*\(/);

for (const action of ['create', 'update', 'publish', 'delete']) {
    const methodPattern = new RegExp(`public\\s+function\\s+${action}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)(?=\\n\\s*public\\s+function|\\n\\s*private\\s+function|\\n})`);
    const match = adminController.match(methodPattern);
    assert.ok(match, `${action} admin method must exist`);
    assert.match(match[1], /verifyCsrf\s*\(/, `${action} must verify CSRF`);
}
assert.match(adminController, /SiteNewsTranslator::save\s*\(/);
assert.match(adminView, /name="_csrf"/);
assert.match(adminView, /translations\[/);

assert.match(listView, /htmlspecialchars|\$escape/);
assert.match(showView, /nl2br\s*\(\s*(?:htmlspecialchars|\$escape)/);
assert.doesNotMatch(showView, /<\?=\s*\$news\[['"]body['"]\]\s*\?>/);

assert.match(app, /Controllers\/NewsController\.php/);
assert.match(app, /Controllers\/AdminNewsController\.php/);
assert.match(app, /routes\/Content\.php/);
assert.match(adminHeader, /AdminAccess::can\(\s*['"]news\.view['"]\s*\)|\$adminCan\(\s*['"]news\.view['"]\s*\)/);
assert.match(adminHeader, /href="\/Anabelka\/admin\/news"/);

process.stdout.write('news module contract passed\n');
