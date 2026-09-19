import assert from 'node:assert/strict';
import fs from 'node:fs';

const category = fs.readFileSync('app/Models/Category.php', 'utf8');
const manager = fs.readFileSync('app/Services/CategoryManager.php', 'utf8');
const controller = fs.readFileSync(
    'app/Controllers/AdminCategoryController.php',
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
const selectJs = fs.readFileSync('js/anabelka-select.js', 'utf8');
const selectCss = fs.readFileSync('css/anabelka-select.css', 'utf8');
const categoryJs = fs.readFileSync('js/admin-categories.js', 'utf8');

assert.match(
    category,
    /COALESCE\([\s\S]*?NULLIF\(TRIM\(c\.image\), ''\)[\s\S]*?SELECT preview\.main_image[\s\S]*?preview\.category_id = c\.id[\s\S]*?ORDER BY preview\.id DESC[\s\S]*?AS thumbnail_image/
);
assert.match(
    category,
    /public static function thumbnailCandidatesForAdmin\s*\(/
);
assert.match(
    category,
    /WHERE category_id IN \(\{\$placeholders\}\)[\s\S]*?main_image IS NOT NULL/
);
assert.match(
    controller,
    /Category::thumbnailCandidatesForAdmin\([\s\S]*?thumbnail_candidates/
);

assert.match(
    productView,
    /id="product-edit-category"[\s\S]*?data-anabelka-select/
);
assert.match(productView, /data-anabelka-rich="1"/);
assert.match(productView, /data-anabelka-thumbnail=/);
assert.match(productView, /data-anabelka-depth=/);

assert.match(selectJs, /function renderPresentation\s*\(/);
assert.match(selectJs, /anabelka-select-thumbnail/);
assert.match(selectJs, /dataset\.anabelkaDepth/);
assert.match(selectCss, /\.anabelka-select-option\.is-rich/);
assert.match(selectCss, /\.anabelka-select-thumbnail\.is-empty/);

assert.match(categoryView, /name="image"\s+id="category-edit-image"/s);
assert.match(categoryView, /data-category-thumbnail-options/);
assert.match(categoryView, /data-category-thumbnail-auto/);
assert.match(categoryJs, /function renderThumbnailEditor\s*\(/);
assert.match(categoryJs, /function chooseThumbnail\s*\(/);
assert.match(categoryJs, /category\.thumbnail_candidates/);

assert.match(
    manager,
    /image = :image/
);
assert.match(
    manager,
    /private static function resolveCategoryImage\s*\(/
);
assert.match(
    manager,
    /FROM products[\s\S]*?category_id = :category_id[\s\S]*?main_image = :image/
);
assert.match(
    manager,
    /Оберіть мініатюру з фотографій товарів цієї категорії/
);

process.stdout.write('category thumbnail select contract passed\n');
