import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

const view = read('views/catalog/index.php');

assert.match(
    view,
    /class="catalog-adult-brand-name"[\s\S]*?\$category\['name'\]/
);
assert.match(view, /class="catalog-adult-strawberry"/);
assert.match(
    view,
    /<span\s+class="catalog-adult-root-meta"\s+hidden>/
);
assert.match(
    view,
    /<ul\s+class="catalog-adult-tree"[^>]*\shidden>/
);
assert.match(view, /AdultAccess::gateUrl\(\$category\)/);
assert.doesNotMatch(
    view,
    /<span\s+class="catalog-adult-brand"[^>]*>[\s\S]*?catalog-adult-badge[\s\S]*?<\/span>/
);

process.stdout.write('catalog adult brand card contract passed\n');
