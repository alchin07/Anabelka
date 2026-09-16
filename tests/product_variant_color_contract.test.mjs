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
