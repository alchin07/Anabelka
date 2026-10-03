import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = relativePath => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

const sidebar = read('views/partials/public-catalog-sidebar.php');

assert.match(
    sidebar,
    /\$adultAccessConfirmed\s*=\s*AdultAccess::isConfirmed\s*\(\s*\)\s*;/,
    'sidebar must resolve adult access before rendering the recursive tree'
);
assert.match(
    sidebar,
    /if\s*\(\s*\$isAdultRoot\s*&&\s*!\$adultAccessConfirmed\s*\)\s*\{\s*\$children\s*=\s*\[\]\s*;/s,
    'unconfirmed visitors must not receive adult child-category names in the sidebar DOM'
);
assert.match(
    sidebar,
    /\$href\s*=\s*\$isAdultRoot\s*\?\s*AdultAccess::gateUrl\(\$node\)\s*:\s*Category::catalogUrl\(\$node\)/s,
    'adult root itself must continue through the existing gate URL'
);

process.stdout.write('adult sidebar privacy contract passed\n');
