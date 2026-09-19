import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = (relativePath) => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

test('variant stock collapses legacy duplicate rows by logical color name', () => {
    const model = read('app/Models/ProductVariantStock.php');

    assert.match(model, /function\s+logicalColorKey\s*\(/);
    assert.match(model, /\$logicalKey\s*=\s*self::logicalColorKey\(/);
    assert.match(model, /\$aggregated/);
    assert.match(model, /logicalKeyForRequestedColor/);
});

test('public variant colors prefer matrix key and dedupe by normalized name', () => {
    const controller = read('app/Controllers/ProductController.php');

    assert.match(controller, /\$matrixColorsByName/);
    assert.match(controller, /\$normalizedName/);
    assert.match(controller, /\$matrixColor\s*=\s*\$matrixColorsByName\[/);
    assert.match(controller, /\$seenColors\[\$normalizedName\]/);
});


test('product colors are independent from product photos', () => {
    const app = read('app/Core/App.php');
    const colorModel = read('app/Models/ProductColor.php');
    const adminProduct = read('app/Models/AdminProduct.php');
    const controller = read('app/Controllers/AdminProductController.php');
    const view = read('views/admin/products/index.php');
    const colorEditor = read('js/admin-product-colors.js');
    const picker = read('js/admin-product-color-picker.js');

    assert.match(app, /Models\/ProductColor\.php/);
    assert.match(
        colorModel,
        /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+product_colors/i
    );
    assert.match(colorModel, /public static function syncForProduct\s*\(/);
    assert.match(colorModel, /public static function editorColorsForProducts\s*\(/);
    assert.match(colorModel, /public static function variantsForProducts\s*\(/);

    assert.match(
        adminProduct,
        /ProductColor::editorColorsForProducts\(\$ids\)/
    );
    assert.match(
        adminProduct,
        /\$product\['colors'\]\s*=\s*\$colorMap/
    );
    assert.match(
        controller,
        /ProductColor::ensureTable\(\);/
    );
    assert.match(
        controller,
        /ProductColor::syncForProduct\([\s\S]*?mergeProductColors/
    );
    assert.match(
        controller,
        /\$_POST\['product_color_name'\]/
    );

    assert.match(view, /id="product-color-list"/);
    assert.match(view, /data-product-color-add/);
    assert.match(view, /admin-product-colors\.js\?v=1/);

    assert.match(colorEditor, /name\s*=\s*['"]product_color_name\[\]['"]/);
    assert.match(colorEditor, /name\s*=\s*['"]product_color_hex\[\]['"]/);
    assert.match(colorEditor, /dataImageColorOpen|dataset\.imageColorOpen|data-image-color-open/);
    assert.match(
        picker,
        /picker\.photoButton\.disabled\s*=\s*!sourceImage/
    );
    assert.match(
        picker,
        /anabelka:product-color-change/
    );
});

test('catalog and public variants include colors that have no photo', () => {
    const product = read('app/Models/Product.php');
    const collection = read('app/Models/StorefrontProductCollection.php');
    const controller = read('app/Controllers/ProductController.php');
    const colorModel = read('app/Models/ProductColor.php');

    assert.match(product, /ProductColor::variantsForProducts/);
    assert.match(collection, /ProductColor::variantsForProducts/);
    assert.match(controller, /ProductColor::variantsForProducts/);
    assert.match(
        colorModel,
        /'path'\s*=>\s*\$path/
    );
    assert.match(
        colorModel,
        /trim\(\(string\) \(\$existing\['path'\] \?\? ''\)\) === ''/
    );
});
