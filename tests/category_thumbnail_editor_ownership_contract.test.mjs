import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

function read(path) {
    return fs.readFileSync(path, 'utf8');
}

const productModel = read('app/Models/AdminProduct.php');
const productController = read('app/Controllers/AdminProductController.php');
const productView = read('views/admin/products/index.php');
const productCss = read('css/admin-products.css');
const categoryView = read('views/admin/categories/index.php');
const categoryJs = read('js/admin-categories.js');
const categoryCss = read('css/admin-categories.css');
const selectJs = read('js/anabelka-select.js');
const selectCss = read('css/anabelka-select.css');
const adminHeader = read('views/admin/partials/header.php');

test('product category selector keeps thumbnail previews but has no thumbnail editor', () => {
    assert.match(
        productView,
        /id="product-edit-category"[\s\S]*?data-anabelka-select/
    );
    assert.match(productView, /data-anabelka-thumbnail=/);
    assert.doesNotMatch(productView, /data-anabelka-thumbnail-edit/);
    assert.doesNotMatch(productView, /data-category-thumbnail-select/);
    assert.doesNotMatch(productView, /category-thumbnail-data/);
    assert.doesNotMatch(productView, /category-thumbnail-csrf/);
    assert.doesNotMatch(
        productView,
        /admin-product-category-thumbnails\.js/
    );
    assert.doesNotMatch(
        productController,
        /categoryCsrfToken/
    );
    assert.doesNotMatch(
        productModel,
        /thumbnailCandidatesForAdmin/
    );
    assert.doesNotMatch(
        productCss,
        /\.product-category-thumbnail-editor/
    );
});

test('category editor remains the only thumbnail editor owner', () => {
    assert.match(categoryView, /data-category-thumbnail-editor/);
    assert.match(categoryView, /id="category-edit-thumbnail-upload"/);
    assert.match(categoryView, /data-category-thumbnail-options/);
    assert.match(categoryView, /data-category-thumbnail-auto/);
    assert.match(categoryCss, /\.category-thumbnail-editor/);

    assert.match(categoryJs, /function validateThumbnailFile\s*\(/);
    assert.match(categoryJs, /function uploadCategoryThumbnail\s*\(/);
    assert.match(
        categoryJs,
        /\/Anabelka\/admin\/categories\/thumbnail/
    );
    assert.match(categoryJs, /thumbnail_file/);
    assert.doesNotMatch(categoryJs, /AnabelkaCategoryThumbnail/);
    assert.doesNotMatch(categoryJs, /anabelkaThumbnailEdit/);
    assert.doesNotMatch(
        categoryView,
        /admin-product-category-thumbnails\.js/
    );
    assert.doesNotMatch(categoryView, /data-category-thumbnail-select/);
});

test('shared select is presentation-only and uses generic Android Back', () => {
    assert.match(selectJs, /anabelka-select-thumbnail/);
    assert.doesNotMatch(
        selectJs,
        /anabelka:thumbnail-edit|anabelkaThumbnailEdit|categorySelectHistoryKey|armCategorySelectHistory/
    );
    assert.doesNotMatch(
        selectCss,
        /\.anabelka-select-thumbnail\.is-editable|\.product-category-thumbnail-editor/
    );
    assert.match(
        selectJs,
        /key:\s*['"]anabelka-select['"][\s\S]*?openInstance[\s\S]*?aria-expanded/
    );
    assert.match(adminHeader, /anabelka-select\.css\?v=5/);
    assert.match(adminHeader, /anabelka-select\.js\?v=11/);
});

test('obsolete inline editor files are removed', () => {
    assert.equal(
        fs.existsSync('js/admin-product-category-thumbnails.js'),
        false
    );
    assert.equal(
        fs.existsSync('tests/product_category_inline_thumbnail_contract.test.mjs'),
        false
    );
});

process.stdout.write(
    'category thumbnail editor ownership cleanup passed\n'
);
