import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

function read(path) {
    return fs.readFileSync(path, 'utf8');
}

const cartController = read('app/Controllers/CartController.php');
const productView = read('views/product/show.php');

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
