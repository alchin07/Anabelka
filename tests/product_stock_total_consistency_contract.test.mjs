import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

function read(path) {
    return fs.readFileSync(path, 'utf8');
}

const controller = read('app/Controllers/AdminProductController.php');
const editor = read('js/admin-products.js');
const matrix = read('js/admin-product-variant-stock.js');
const view = read('views/admin/products/index.php');
const header = read('views/admin/partials/header.php');

test('duplicate product sizes are rejected instead of silently merged', () => {
    assert.match(
        controller,
        /if \(isset\(\$seen\[\$key\]\)\)\s*\{[\s\S]*?throw new InvalidArgumentException/
    );
    assert.match(
        controller,
        /Розмір «.*додано двічі/
    );
    assert.match(
        editor,
        /function validateSizeUniqueness\(\)/
    );
    assert.match(
        editor,
        /setCustomValidity\([\s\S]*Цей розмір уже додано/
    );
    assert.match(
        editor,
        /const duplicateSize = validateSizeUniqueness\(\)/
    );
    assert.match(
        editor,
        /focusDuplicateSize\(duplicateSize\)/
    );
});

test('by-size total is automatic and matrix grand total owns it', () => {
    assert.match(
        editor,
        /totalField\.hidden = false/
    );
    assert.match(
        editor,
        /fields\.stock\.readOnly = bySize/
    );
    assert.match(
        editor,
        /Загальний залишок, шт\. \(автоматично\)/
    );
    assert.match(
        editor,
        /function syncBySizeTotalFromRows\(\)/
    );
    assert.match(
        matrix,
        /const totalStockField = document\.getElementById\('product-edit-stock'\)/
    );
    assert.match(
        matrix,
        /stockModeField\.value === 'by_size'[\s\S]*?totalStockField\.value = String\(total\)/
    );
});

test('stock consistency scripts are cache-busted', () => {
    assert.match(
        view,
        /admin-products\.js\?v=10/
    );
    assert.match(
        header,
        /admin-product-variant-stock\.js\?v=18/
    );
});
