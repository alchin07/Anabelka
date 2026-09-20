import assert from 'node:assert/strict';
import fs from 'node:fs';

const back = fs.readFileSync('js/anabelka-admin-back.js', 'utf8');
const select = fs.readFileSync('js/anabelka-select.js', 'utf8');
const dialog = fs.readFileSync('js/anabelka-dialog.js', 'utf8');
const nav = fs.readFileSync('js/admin-nav.js', 'utf8');
const products = fs.readFileSync('js/admin-products.js', 'utf8');
const categories = fs.readFileSync('js/admin-categories.js', 'utf8');
const colorPicker = fs.readFileSync(
    'js/admin-product-color-picker.js',
    'utf8'
);
const thumbnails = fs.readFileSync(
    'js/admin-product-category-thumbnails.js',
    'utf8'
);
const header = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);
const productView = fs.readFileSync(
    'views/admin/products/index.php',
    'utf8'
);
const categoryView = fs.readFileSync(
    'views/admin/categories/index.php',
    'utf8'
);

assert.match(back, /history\.pushState/);
assert.match(back, /window\.addEventListener\(['"]popstate['"]/);
assert.match(back, /function\s+register\s*\(/);
assert.match(back, /sortedHandlers\(\)/);
assert.match(back, /handler\.close/);
assert.match(back, /history\.back\(\)/);
assert.match(back, /__anabelkaAdminBackGuard/);

assert.match(select, /key:\s*['"]anabelka-select['"]/);
assert.match(select, /priority:\s*90/);
assert.match(dialog, /key:\s*['"]anabelka-dialog['"]/);
assert.match(dialog, /priority:\s*120/);
assert.match(nav, /key:\s*['"]admin-drawer['"]/);

assert.match(products, /key:\s*['"]product-editor['"]/);
assert.match(products, /key:\s*['"]product-translations['"]/);
assert.match(
    products,
    /product-translations[\s\S]*?translationDetails\.open\s*=\s*false/
);

assert.match(categories, /key:\s*['"]category-modal['"]/);
assert.match(
    categories,
    /querySelectorAll\(['"]\.category-modal['"]\)[\s\S]*?!modal\.hidden/
);

assert.match(
    colorPicker,
    /key:\s*['"]product-color-picker['"]/
);
assert.match(colorPicker, /priority:\s*110/);
assert.match(
    thumbnails,
    /key:\s*['"]category-thumbnail-editor['"]/
);
assert.match(thumbnails, /priority:\s*105/);

const backIndex = header.indexOf('anabelka-admin-back.js?v=3');
const selectIndex = header.indexOf('anabelka-select.js?v=9');
const dialogIndex = header.indexOf('anabelka-dialog.js?v=3');

assert.ok(backIndex >= 0);
assert.ok(selectIndex > backIndex);
assert.ok(dialogIndex > backIndex);
assert.match(header, /admin-nav\.js\?v=27/);

assert.match(productView, /admin-products\.js\?v=7/);
assert.match(
    productView,
    /admin-product-color-picker\.js\?v=6/
);
assert.match(
    productView,
    /admin-product-category-thumbnails\.js\?v=4/
);
assert.match(categoryView, /admin-categories\.js\?v=8/);
assert.match(
    categoryView,
    /admin-product-category-thumbnails\.js\?v=4/
);

process.stdout.write('admin Android Back contract passed\n');


assert.match(back, /function\s+anchorNavigationUrl\s*\(/);
assert.match(back, /document\.addEventListener\(\s*['"]click['"]/);
assert.match(back, /event\.preventDefault\(\)/);
assert.match(back, /pendingNavigationUrl\s*=\s*url/);
assert.match(back, /suppressNextPop\s*=\s*true/);
assert.match(
    back,
    /pendingNavigationUrl\s*!==\s*['"]["'][\s\S]*?window\.location\.assign\(url\)/
);

process.stdout.write('admin menu navigation with Back guard passed\n');


assert.match(back, /function\s+syncNow\s*\(/);
assert.match(back, /syncNow:\s*syncNow/);
assert.match(
    products,
    /editor\.hidden\s*=\s*false;[\s\S]*?AnabelkaAdminBack\.syncNow\(\)/
);
assert.match(
    products,
    /editor\.hidden\s*=\s*true;[\s\S]*?AnabelkaAdminBack\.syncNow\(\)/
);
assert.match(
    select,
    /wrapper\.classList\.add\(['"]is-open['"]\)[\s\S]*?AnabelkaAdminBack\.syncNow\(\)/
);

process.stdout.write('syncNow arms product editor immediately\n');
