import assert from 'node:assert/strict';
import fs from 'node:fs';

const required = [
    'routes/StorefrontPages.php',
    'app/Controllers/StorefrontPageController.php',
    'views/storefront/discounts.php',
    'views/storefront/new.php',
    'views/storefront/partials/product-card.php',
    'views/storefront/partials/pagination.php',
    'css/storefront-pages.css'
];

for (const file of required) {
    assert.ok(
        fs.existsSync(file),
        `${file} must exist before storefront pages can pass`
    );
}

const routes = fs.readFileSync('routes/StorefrontPages.php', 'utf8');
assert.match(
    routes,
    /\$router->get\('\/discounts',\s*'StorefrontPageController@discounts'\)/
);
assert.match(
    routes,
    /\$router->get\('\/new',\s*'StorefrontPageController@newArrivals'\)/
);

const controller = fs.readFileSync(
    'app/Controllers/StorefrontPageController.php',
    'utf8'
);
assert.match(controller, /StorefrontProductCollection::page\(\s*'discounts'/);
assert.match(controller, /StorefrontProductCollection::page\(\s*'new'/);
assert.match(controller, /ContentInterfaceTranslator::seed\(\)/);

const discounts = fs.readFileSync('views/storefront/discounts.php', 'utf8');
const newest = fs.readFileSync('views/storefront/new.php', 'utf8');
const card = fs.readFileSync('views/storefront/partials/product-card.php', 'utf8');
const pagination = fs.readFileSync('views/storefront/partials/pagination.php', 'utf8');
const css = fs.readFileSync('css/storefront-pages.css', 'utf8');

for (const view of [discounts, newest]) {
    assert.match(view, /partials\/product-card\.php/);
    assert.match(view, /partials\/pagination\.php/);
    assert.doesNotMatch(view, /Database::connect/);
}

assert.match(card, /current_price/);
assert.match(card, /color_variants/);
assert.match(card, /old_price/);
assert.match(card, /display_discount_percent/);
assert.match(card, /htmlspecialchars\(/);
assert.match(card, /\^#\[0-9a-f\]\{6\}\$/i);
assert.doesNotMatch(card, /Product::getCurrentPrice|Database::connect/);

assert.match(pagination, /aria-current="page"/);
assert.match(pagination, /collectionPath/);
assert.match(css, /\.storefront-page/);
assert.match(css, /repeat\(2,\s*minmax\(0,\s*1fr\)\)/);

process.stdout.write('storefront pages contract passed\n');
