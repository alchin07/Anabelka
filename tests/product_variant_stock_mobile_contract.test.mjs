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

test('variant stock editor uses vertical mobile cards instead of a horizontal table', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /product-variant-stock-cards/);
    assert.match(js, /product-variant-stock-card/);
    assert.match(js, /product-variant-stock-color-row/);
    assert.doesNotMatch(js, /product-variant-stock-table-wrap/);
    assert.doesNotMatch(js, /product-variant-stock-table/);
    assert.doesNotMatch(js, /overflow-x:\s*auto/);
});

test('variant stock editor exposes touch-friendly minus input plus controls', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /data-variant-decrease/);
    assert.match(js, /data-variant-increase/);
    assert.match(js, /product-variant-stock-stepper/);
    assert.match(js, /inputMode\s*=\s*['"]numeric['"]/);
    assert.match(js, /Math\.max\(0/);
});

test('variant stock editor calculates per-size and grand totals and mirrors size summaries', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /data-variant-size-total/);
    assert.match(js, /function\s+updateTotals\s*\(/);
    assert.match(js, /syncLegacySizeTotals/);
    assert.match(js, /data-size-stock/);
    assert.match(js, /readOnly\s*=\s*true/);
    assert.match(js, /Підсумок за розміром/);
});

test('mobile manual input preparation targets the new card matrix with legacy fallback', () => {
    const fixes = read('js/admin-product-editor-fixes.js');

    assert.match(
        fixes,
        /\[data-variant-cards\][^\n]*\[data-variant-table\]|\[data-variant-cards\][\s\S]{0,120}\[data-variant-table\]/
    );
    assert.match(fixes, /input\.type\s*=\s*['"]text['"]/);
    assert.match(fixes, /input\.inputMode\s*=\s*['"]numeric['"]/);
    assert.match(fixes, /input\.pattern\s*=\s*['"]\[0-9\]\*['"]/);
    assert.ok(fixes.includes("replace(/[^0-9]/g, '')"));
    assert.match(fixes, /MutationObserver/);
});

test('typing stock does not rebuild the matrix through the size-list observer', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /const\s+sizeObserver\s*=\s*new MutationObserver/);
    assert.match(js, /sizeObserver\.observe\(sizeList,\s*\{\s*childList:\s*true\s*\}\s*\)/);
    assert.doesNotMatch(
        js,
        /observe\(sizeList,\s*\{[^}]*subtree:\s*true[^}]*\}\s*\)/
    );
    assert.match(js, /const\s+sourceObserver\s*=\s*new MutationObserver/);
});

test('summary mirroring avoids rewriting unchanged DOM while the matrix input is focused', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /if\s*\(stock\.value\s*!==\s*nextValue\)/);
    assert.match(js, /if\s*\(labelText\.textContent\s*!==\s*['"]Підсумок['"]\)/);
    assert.match(js, /if\s*\(sizeHint\.textContent\s*!==\s*matrixHint\)/);
    assert.match(js, /if\s*\(totalNode\.textContent\s*!==\s*nextText\)/);
});

test('admin header cache-busts the mobile variant stock and input fix scripts', () => {
    const header = read('views/admin/partials/header.php');

    assert.match(header, /admin-product-variant-stock\.js\?v=4/);
    assert.match(header, /admin-product-editor-fixes\.js\?v=2/);
});
