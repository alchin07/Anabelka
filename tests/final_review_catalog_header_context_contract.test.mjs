import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const source = fs.readFileSync(
    path.join(projectRoot, 'views/catalog/index.php'),
    'utf8'
);

const headerMarker = "<?php require __DIR__ . '/../partials/header.php'; ?>";
const headerIndex = source.indexOf(headerMarker);
assert.ok(headerIndex >= 0, 'catalog root must include the shared public header');

const beforeHeader = source.slice(0, headerIndex);

assert.doesNotMatch(
    beforeHeader,
    /\$category\b/,
    'catalog root must not leak a $category variable into header.php before the real category page context exists'
);
assert.match(
    beforeHeader,
    /foreach\s*\(\$categories\s+as\s+\$catalogCategory\)/,
    'catalog root partition loop must use a non-context variable name'
);

process.stdout.write('catalog root header context contract passed\n');
