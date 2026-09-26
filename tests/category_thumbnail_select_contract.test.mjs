import assert from 'node:assert/strict';
import fs from 'node:fs';

const routes = fs.readFileSync('routes/Web.php', 'utf8');
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
assert.doesNotMatch(productView, /data-anabelka-thumbnail-edit/);
assert.doesNotMatch(productView, /data-category-thumbnail-select/);
assert.doesNotMatch(productView, /category-thumbnail-data/);
assert.doesNotMatch(productView, /admin-product-category-thumbnails\.js/);
assert.match(
    productView,
    /id="product-filter-category"[\s\S]*?data-anabelka-select/
);
assert.match(
    productView,
    /id="product-filter-category"[\s\S]*?data-anabelka-rich="1"[\s\S]*?data-anabelka-depth=/
);
assert.doesNotMatch(
    productView,
    /id="product-filter-category"[\s\S]*?data-anabelka-thumbnail-edit/
);

assert.match(selectJs, /function renderPresentation\s*\(/);
assert.match(selectJs, /anabelka-select-thumbnail/);
assert.match(selectJs, /dataset\.anabelkaDepth/);
assert.match(selectCss, /\.anabelka-select-option\.is-rich/);
assert.match(selectCss, /\.anabelka-select-thumbnail\.is-empty/);
assert.doesNotMatch(selectJs, /anabelka:thumbnail-edit|anabelkaThumbnailEdit/);
assert.doesNotMatch(selectCss, /\.anabelka-select-thumbnail\.is-editable/);
assert.doesNotMatch(selectCss, /\.product-category-thumbnail-editor/);

assert.match(categoryView, /name="image"\s+id="category-edit-image"/s);
assert.match(categoryView, /data-category-thumbnail-options/);
assert.match(categoryView, /data-category-thumbnail-auto/);
assert.match(categoryJs, /function renderThumbnailEditor\s*\(/);
assert.match(categoryJs, /function chooseThumbnail\s*\(/);
assert.match(categoryJs, /category\.thumbnail_candidates/);
assert.match(categoryJs, /function validateThumbnailFile\s*\(/);
assert.match(categoryJs, /function uploadCategoryThumbnail\s*\(/);
assert.match(
    categoryJs,
    /\/Anabelka\/admin\/categories\/thumbnail/
);
assert.match(categoryJs, /thumbnail_file/);
assert.doesNotMatch(categoryJs, /AnabelkaCategoryThumbnail/);
assert.doesNotMatch(categoryJs, /anabelkaThumbnailEdit/);
assert.doesNotMatch(categoryView, /data-category-thumbnail-select/);
assert.doesNotMatch(
    categoryView,
    /admin-product-category-thumbnails\.js/
);
assert.match(categoryView, /admin-categories\.js\?v=10/);

assert.match(
    routes,
    /post\(\s*['"]\/admin\/categories\/thumbnail['"][\s\S]*?AdminCategoryController@thumbnail/
);
assert.match(controller, /\$_FILES\['thumbnail_file'\]/);

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


assert.doesNotMatch(
    selectJs,
    /style\.paddingLeft\s*=/
);
process.stdout.write('rich category rows keep one left alignment\n');
