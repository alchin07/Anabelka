import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

function read(path) {
    return fs.readFileSync(path, 'utf8');
}

const cartController = read('app/Controllers/CartController.php');
const productController = read('app/Controllers/ProductController.php');
const productView = read('views/product/show.php');
const productI18n = read('views/partials/product-i18n.php');
const productTranslator = read('app/Models/ProductInterfaceTranslator.php');
const colorVariants = read('js/product-color-variants.js');
const publicHeader = read('views/partials/header.php');

test('cart add returns authoritative legacy availability after the item is added', () => {
    assert.match(
        cartController,
        /\$payload\['availability'\]\s*=\s*\$this->getLegacyAvailability\(\$product\)/
    );
    assert.match(
        cartController,
        /private function getLegacyAvailability\(array \$product\)/
    );
    assert.match(
        cartController,
        /'mode'\s*=>\s*'by_size'/
    );
    assert.match(
        cartController,
        /'mode'\s*=>\s*'total'/
    );
    assert.match(
        cartController,
        /'sizes'\s*=>\s*\$sizeStocks/
    );
});

test('legacy product page refreshes visible stock from the AJAX response', () => {
    assert.match(
        productView,
        /function applyLegacyAvailability\(availability\)/
    );
    assert.match(
        productView,
        /if \(data\.availability\)/
    );
    assert.match(
        productView,
        /applyLegacyAvailability\(\s*data\.availability\s*\)/
    );
    assert.match(
        productView,
        /checkbox\.disabled\s*=\s*!available/
    );
    assert.match(
        productView,
        /button\.dataset\.stock\s*=\s*String\(stock\)/
    );
    assert.match(
        productView,
        /\[data-product-stock-summary\]/
    );
});


test('variant cart handler also applies legacy availability response', () => {
    assert.match(
        colorVariants,
        /function applyLegacyAvailability\(availability\)/
    );
    assert.match(
        colorVariants,
        /applyLegacyAvailability\(data\.availability\)/
    );
    assert.match(
        colorVariants,
        /const available = stock > 0;/
    );
    assert.match(
        colorVariants,
        /button\.dataset\.stock = String\(/
    );
    assert.match(
        publicHeader,
        /product-color-variants\.js\?v=4/
    );
});


test('product card separates warehouse stock, own cart, and addable quantity', () => {
    assert.match(
        productController,
        /\$product\['stock_on_hand'\]\s*=\s*\$productStockOnHand/
    );
    assert.match(
        productController,
        /\$product\['cart_quantity'\]\s*=\s*max\(0, \$cartProductQuantity\)/
    );
    assert.match(
        productController,
        /\$product\['available_stock'\]\s*=\s*\$availableTotal/
    );
    assert.match(
        productController,
        /'stock_on_hand'\s*=>\s*\$stock/
    );
    assert.match(
        productController,
        /'in_cart'\s*=>\s*max\(0, \$inCart\)/
    );
    assert.match(
        productController,
        /'available'\s*=>\s*\$available/
    );

    assert.match(
        cartController,
        /'stock_total'\s*=>\s*\$stockTotal/
    );
    assert.match(
        cartController,
        /'cart_total'\s*=>\s*/
    );
    assert.match(
        cartController,
        /'available_total'\s*=>\s*\$availableTotal/
    );
    assert.match(
        cartController,
        /'stock_sizes'\s*=>\s*\$stockSizes/
    );
    assert.match(
        cartController,
        /'cart_sizes'\s*=>\s*\$cartSizes/
    );

    assert.match(
        productView,
        /data-stock-label="stock_on_hand"/
    );
    assert.match(
        productView,
        /data-stock-label="in_your_cart"/
    );
    assert.match(
        productView,
        /data-stock-label="available_to_add"/
    );

    assert.match(
        colorVariants,
        /stockOnHandMap:\s*new Map\(\)/
    );
    assert.match(
        colorVariants,
        /cartMap:\s*new Map\(\)/
    );
    assert.match(
        colorVariants,
        /state\.cartMap\.set\(key, inCart\)/
    );

    assert.match(
        productI18n,
        /window\.AnabelkaProductI18n/
    );
    assert.match(
        productTranslator,
        /'product\.stock_on_hand'/
    );
    assert.match(
        productTranslator,
        /'product\.in_your_cart'/
    );
    assert.match(
        productTranslator,
        /'product\.available_to_add'/
    );
});
