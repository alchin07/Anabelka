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

test('variant stock matrix owns its stable manual inputs', () => {
    const matrix = read('js/admin-product-variant-stock.js');
    const fixes = read('js/admin-product-editor-fixes.js');

    assert.match(matrix, /input\.type\s*=\s*['"]text['"]/);
    assert.match(matrix, /input\.inputMode\s*=\s*['"]numeric['"]/);
    assert.match(matrix, /input\.pattern\s*=\s*['"]\[0-9\]\*['"]/);
    assert.match(matrix, /input\.select\(\)/);

    assert.doesNotMatch(fixes, /data-variant-stock-input/);
    assert.doesNotMatch(fixes, /variantRoot/);
    assert.doesNotMatch(fixes, /activeVariantKey/);
    assert.doesNotMatch(fixes, /restoreVariantFocus/);
});

test('typing stock updates values and totals without rendering the matrix', () => {
    const matrix = read('js/admin-product-variant-stock.js');
    const inputHandler = matrix.match(
        /input\.addEventListener\(['"]input['"],\s*function\s*\(\)\s*\{[\s\S]*?\n\s*\}\);/
    )?.[0] || '';

    assert.notEqual(inputHandler, '');
    assert.doesNotMatch(inputHandler, /\brender\s*\(/);
    assert.match(inputHandler, /updateTotals\s*\(/);
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
    assert.match(js, /data-variant-size-summary/);
    assert.match(js, /Усього:/);
    assert.match(js, /data-variant-stock-hidden/);
    assert.match(js, /grid-template-columns:minmax\(0,1fr\) auto 34px/);
});

test('duplicate color names collapse into one matrix color', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /const\s+seenNames\s*=\s*new Set\(\)/);
    assert.match(js, /const\s+key\s*=\s*textKey\(name\)/);
    assert.match(js, /seenNames\.has\(key\)/);
    assert.match(js, /seenNames\.add\(key\)/);
});

test('matrix rebuilds only when product dimensions change', () => {
    const matrix = read('js/admin-product-variant-stock.js');

    assert.match(matrix, /let\s+lastDimensionSignature\s*=\s*['"]["']/);
    assert.match(matrix, /function\s+dimensionSignature\s*\(/);
    assert.match(matrix, /function\s+rebuildIfDimensionsChanged\s*\(/);
    assert.match(matrix, /nextSignature\s*===\s*lastDimensionSignature/);
    assert.match(matrix, /rebuildIfDimensionsChanged\(false\)/);
    assert.match(matrix, /rebuildIfDimensionsChanged\(true\)/);
    assert.doesNotMatch(
        matrix,
        /new MutationObserver\(function\s*\(\)\s*\{\s*window\.setTimeout\(render,\s*0\)/
    );
});

test('size observer is structural and totals are updated in place', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /const\s+sizeObserver\s*=\s*new MutationObserver/);
    assert.match(js, /sizeObserver\.observe\(sizeList,\s*\{\s*childList:\s*true\s*\}\s*\)/);
    assert.doesNotMatch(
        js,
        /observe\(sizeList,\s*\{[^}]*subtree:\s*true[^}]*\}\s*\)/
    );
    assert.match(js, /const\s+sourceObserver\s*=\s*new MutationObserver/);

    const totals = js.match(
        /function\s+updateTotals\s*\(\)\s*\{[\s\S]*?\n\s*\}/
    )?.[0] || '';
    assert.notEqual(totals, '');
    assert.doesNotMatch(totals, /\brender\s*\(/);
    assert.doesNotMatch(totals, /rebuildIfDimensionsChanged\s*\(/);
});

test('summary mirroring avoids rewriting unchanged DOM while the matrix input is focused', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /if\s*\(stock\.value\s*!==\s*nextValue\)/);
    assert.match(js, /if\s*\(sizeHint\.textContent\s*!==\s*matrixHint\)/);
    assert.match(js, /if\s*\(totalNode\s*&&\s*totalNode\.textContent\s*!==\s*nextText\)/);
});

test('variant save failure is propagated to the product editor instead of swallowed', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /function\s+variantSaveError\s*\(/);
    assert.match(js, /throw\s+variantSaveError\s*\(/);
    assert.match(js, /productIdField\.value\s*=\s*String\(productId\)/);
});

test('existing matrix can be cleared by saving an empty row list', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /const\s+shouldSaveMatrix\s*=\s*hasStoredMatrix\s*\|\|\s*matrixTouched/);
    assert.doesNotMatch(js, /&&\s*rows\.length\s*>\s*0\s*&&\s*shouldSaveMatrix/);
    assert.match(js, /hasStoredMatrix\s*=\s*rows\.length\s*>\s*0/);
});

test('dimension rename preserves stock through stable tokens and releases the old size id', () => {
    const js = read('js/admin-product-variant-stock.js');

    assert.match(js, /const\s+tokenCache\s*=\s*new Map\(\)/);
    assert.match(js, /function\s+stableDimensionToken\s*\(/);
    assert.match(js, /function\s+dimensionState\s*\(/);
    assert.match(js, /function\s+rekeyCacheForDimensionChanges\s*\(/);
    assert.match(js, /rekeyCacheForDimensionChanges\(lastDimensionState,\s*nextState\)/);
    assert.match(js, /input\.dataset\.variantTokenKey\s*=\s*tokenKey/);
    assert.match(js, /idInput\.value\s*=\s*['"]0['"]/);
});

test('admin header cache-busts verified stable matrix v11', () => {
    const header = read('views/admin/partials/header.php');

    assert.match(header, /admin-product-variant-stock\.js\?v=11/);
    assert.match(header, /admin-product-editor-fixes\.js\?v=3/);
});
