import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => {
    assert.equal(fs.existsSync(path), true, `${path} must exist`);
    return fs.readFileSync(path, 'utf8');
};

const app = read('app/Core/App.php');
const routes = read('routes/Content.php');
const access = read('app/Models/AdminAccess.php');
const header = read('views/admin/partials/header.php');
const model = read('app/Models/HomePageBlock.php');
const homeController = read('app/Controllers/HomeController.php');
const adminController = read('app/Controllers/AdminHomePageController.php');
const home = read('views/home.php');
const rail = read('views/home/partials/right-rail.php');
const productBlock = read('views/home/blocks/product-collection.php');
const admin = read('views/admin/home-page/index.php');
const css = read('css/admin-home-page.css');
const js = read('js/admin-home-page.js');
const previewJs = read('js/anabelka-builder-preview.js');
const previewCss = read('css/anabelka-builder-preview.css');
const migration = read('database/migrations/2026-09-21_home_page_builder.sql');

assert.match(app, /Models\/HomePageBlock\.php/);
assert.match(app, /Controllers\/AdminHomePageController\.php/);
assert.match(routes, /\/admin\/home-page/);
for (const action of ['index', 'create', 'delete', 'update', 'toggle', 'move', 'reorder']) {
    assert.match(routes, new RegExp(`AdminHomePageController@${action}`));
}
assert.match(access, /home_page\.view/);
assert.match(access, /home_page\.manage/);
assert.match(header, /\$canHomePage/);
assert.match(header, /href="\/Anabelka\/admin\/home-page"/);

assert.match(model, /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+home_page_blocks/i);
assert.match(model, /product_collection_latest/);
for (const systemKey of [
    'hero',
    'directions',
    'adult_entry',
    'useful',
    'info_row'
]) {
    assert.match(model, new RegExp(`['"]${systemKey}['"]`));
}
assert.match(model, /activeByZone\s*\(\)[\s\S]*?ensureSchema\s*\(\)/);
assert.match(model, /right_rail/);
assert.match(model, /public\s+static\s+function\s+create\s*\(/);
assert.match(model, /public\s+static\s+function\s+delete\s*\(/);
assert.match(model, /public\s+static\s+function\s+move\s*\(/);
assert.match(model, /public\s+static\s+function\s+reorder\s*\(/);
assert.match(model, /Склад блоків змінився/);
assert.match(model, /public\s+static\s+function\s+toggle\s*\(/);
assert.match(model, /public\s+static\s+function\s+updateSettings\s*\(/);
assert.match(model, /settings_json/);
assert.match(model, /uniqueSystemKey\s*\(/);
assert.match(model, /ORDER\s+BY\s+sort_order\s+DESC,\s*id\s+DESC[\s\S]*?LIMIT\s+1[\s\S]*?FOR\s+UPDATE/i);
assert.doesNotMatch(model, /MAX\(sort_order\)[\s\S]*?FOR\s+UPDATE/i);
assert.match(model, /isSystemKey\s*\(/);
assert.match(model, /defaultSettingsForType\s*\(/);
assert.match(model, /normalizeZoneOrder\s*\(/);
assert.match(model, /Базовий системний блок не можна видалити/);
assert.match(model, /'latest'/);
assert.match(model, /'new'/);
assert.match(model, /'discounts'/);

assert.match(homeController, /HomePageBlock::activeByZone\s*\(/);
assert.match(homeController, /fallbackActiveByZone\s*\(/);
assert.match(homeController, /builder_preview/);
assert.match(homeController, /AdminAccess::can\s*\(\s*['"]home_page\.view['"]\s*\)/);
assert.match(homeController, /X-Robots-Tag:\s*noindex,\s*nofollow/);
assert.match(homeController, /StorefrontProductCollection::page\s*\(/);
assert.match(homeController, /SiteNews::latestPublished\s*\(/);
assert.match(homeController, /ProductReview::latestApprovedStandard\s*\(/);

assert.doesNotMatch(home, /\$latestProducts\s*=/);
assert.match(home, /\$homeBlocks\['main'\]/);
assert.match(home, /home\/blocks\/product-collection\.php/);
for (const partial of [
    'hero',
    'directions',
    'adult-entry',
    'useful',
    'info-row'
]) {
    assert.match(home, new RegExp(`home\\/blocks\\/${partial}\\.php`));
    assert.equal(
        fs.existsSync(`views/home/blocks/${partial}.php`),
        true,
        `${partial} home block partial must exist`
    );
}
assert.doesNotMatch(home, /<section class="home-hero">/);
assert.doesNotMatch(home, /<details class="home-useful-menu">/);
assert.doesNotMatch(home, /<section class="home-info-row"/);
assert.match(home, /data-anabelka-builder-preview="home"/);
assert.match(home, /data-anabelka-builder-block-id/);
assert.match(home, /data-anabelka-builder-zone="main"/);
assert.match(home, /anabelka-builder-preview\.css\?v=1/);
assert.match(home, /anabelka-builder-preview\.js\?v=1/);
assert.match(rail, /\$homeBlocks\['right_rail'\]/);
assert.match(rail, /blocks\/news\.php/);
assert.match(rail, /blocks\/reviews\.php/);
assert.match(rail, /blocks\/gift-certificate\.php/);
assert.match(rail, /data-anabelka-builder-zone="right_rail"/);

assert.match(productBlock, /current_price/);
assert.match(productBlock, /Product::getCurrentPrice/);
assert.match(productBlock, /\/Anabelka\/discounts/);
assert.match(productBlock, /\/Anabelka\/new/);

for (const path of [
    'views/home/blocks/news.php',
    'views/home/blocks/reviews.php',
    'views/home/blocks/gift-certificate.php'
]) {
    assert.equal(fs.existsSync(path), true);
}

assert.match(adminController, /HomePageBlock::allForAdmin\s*\(/);
assert.match(adminController, /HomePageBlock::create\s*\(/);
assert.match(adminController, /HomePageBlock::delete\s*\(/);
assert.match(adminController, /HomePageBlock::updateSettings\s*\(/);
assert.match(adminController, /HomePageBlock::toggle\s*\(/);
assert.match(adminController, /HomePageBlock::move\s*\(/);
assert.match(adminController, /HomePageBlock::reorder\s*\(/);
assert.match(admin, /Джерело товарів/);
assert.match(admin, /Останні додані/);
assert.match(admin, /Нові без акцій/);
assert.match(admin, /Зі знижками/);
assert.match(admin, /Кількість елементів/);
assert.match(admin, /Hero/);
assert.match(admin, /Напрямки магазину/);
assert.match(admin, /Блок 18\+/);
assert.match(admin, /Корисне/);
assert.match(admin, /Інформація магазину/);
assert.match(
    admin,
    /in_array\(\$type, \['product_collection', 'news', 'reviews'\], true\)/
);
assert.match(admin, /name="_csrf"/);
assert.match(admin, /\+ Додати блок/);
assert.match(admin, /name="block_type"/);
assert.match(admin, /name="zone"/);
assert.match(admin, /admin-home-builder-add-grid/);
assert.match(admin, /\$zoneCreatable/);
assert.match(admin, /in_array\([\s\S]*?\$addZone[\s\S]*?\$meta\['zones'\]/);
assert.match(admin, /\/Anabelka\/admin\/home-page\/create/);
assert.match(admin, /\/Anabelka\/admin\/home-page\/delete/);
assert.match(admin, /\$isSystem \? 'Базовий' : 'Доданий'/);
assert.match(admin, /data-home-builder-list/);
assert.match(admin, /data-home-builder-drag-handle/);
assert.match(admin, /data-block-id/);
assert.match(admin, /admin-home-page\.js\?v=2/);
assert.match(admin, /data-home-builder-preview-frame="mobile"/);
assert.match(admin, /data-home-builder-preview-frame="desktop"/);
assert.match(admin, /data-home-builder-preview-tab="mobile"/);
assert.match(admin, /builder_preview=home/);
assert.match(css, /@media\s*\(max-width:\s*640px\)/);
assert.match(css, /min-height:\s*44px/);
assert.match(css, /admin-home-builder-add/);
assert.match(css, /admin-home-builder-delete/);
assert.match(css, /admin-home-builder-drag/);
assert.match(css, /touch-action:\s*none/);
assert.match(css, /\.admin-home-builder-block\.is-dragging/);
assert.match(js, /PointerEvent/);
assert.match(js, /setPointerCapture/);
assert.match(js, /block_ids\[\]/);
assert.match(js, /\/Anabelka\/admin\/home-page\/reorder/);
assert.match(js, /data-home-builder-move/);
assert.match(js, /anabelka-builder-order/);
assert.match(js, /postMessage/);
assert.match(js, /broadcastZoneOrder/);
assert.match(js, /data-home-builder-preview-tab/);
assert.match(js, /data-preview-width/);
assert.match(previewJs, /event\.origin\s*!==\s*allowedOrigin/);
assert.match(previewJs, /event\.source\s*!==\s*window\.parent/);
assert.match(previewJs, /anabelka-builder-preview-ready/);
assert.match(previewJs, /anabelka-builder-preview-select/);
assert.match(previewJs, /anabelka-builder-preview-drag-start/);
assert.match(previewJs, /anabelka-builder-preview-order/);
assert.match(previewJs, /anabelka-builder-preview-drop/);
assert.match(previewJs, /data-anabelka-preview-drag-handle/);
assert.match(previewJs, /setPointerCapture/);
assert.match(js, /applyPreviewSubsetOrder/);
assert.match(js, /sameIdSet/);
assert.match(js, /anabelka-builder-preview-drag-start/);
assert.match(js, /anabelka-builder-preview-save-result/);
assert.match(previewCss, /is-builder-highlighted/);
assert.match(previewCss, /anabelka-builder-preview-drag-handle/);
assert.match(previewCss, /touch-action:\s*none/);
assert.match(css, /admin-home-builder-preview-grid/);
assert.match(css, /max-width:\s*900px/);
assert.match(migration, /home_page_blocks/);
assert.match(migration, /INSERT\s+IGNORE/i);
assert.match(migration, /\('hero', 'hero', 'main'/);
assert.match(migration, /\('directions', 'directions', 'main'/);
assert.match(migration, /\('adult_entry', 'adult_entry', 'main'/);
assert.match(migration, /\('useful', 'useful', 'main'/);
assert.match(migration, /\('info_row', 'info_row', 'main'/);

process.stdout.write('home page builder MVP contract passed\n');
