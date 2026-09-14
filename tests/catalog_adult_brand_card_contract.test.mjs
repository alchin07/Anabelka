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
const categoryView = read('views/catalog/category.php');
const productController = read('app/Controllers/ProductController.php');
const header = read('views/partials/header.php');
const strawberryIcon = read('views/partials/anabelka-strawberry-icon.php');
const css = read('css/catalog.css');

assert.match(view, /AdultAccess::gateUrl\(\$category\)/);
assert.match(view, /class="catalog-adult-brand-name"[\s\S]*?\$category\['name'\]/);
assert.match(view, /class="catalog-adult-strawberry"/);
assert.match(view, /anabelka-strawberry-icon\.php/);
assert.match(view, /class="catalog-adult-root-meta"\s+hidden/);
assert.match(view, /class="catalog-adult-tree"[\s\S]*?hidden/);
assert.match(view, /css\/catalog\.css\?v=11/);

assert.match(
    categoryView,
    /\$isAdultCatalogContext\s*=\s*!empty\(\$category\['effective_adult'\]\)/
);
assert.match(
    productController,
    /'isAdultCatalogContext'\s*=>\s*!empty\(\$productCategory\['effective_adult'\]\)/
);
assert.match(
    header,
    /\$isAdultCatalogContext\s*=\s*!empty\(\$isAdultCatalogContext\)/
);
assert.match(
    header,
    /if\s*\(\$isAdultCatalogContext\)[\s\S]*?anabelka-strawberry-icon\.php[\s\S]*?else[\s\S]*?<svg viewBox="0 0 24 24"/
);

const seedDots = strawberryIcon.match(/<circle\b/g) ?? [];
assert.equal(seedDots.length, 6);
assert.match(strawberryIcon, /viewBox="0 0 24 24"/);
assert.match(strawberryIcon, /stroke="currentColor"/);
assert.match(strawberryIcon, /fill="currentColor"/);

assert.match(
    css,
    /\.catalog-adult-root-meta\[hidden\],\s*\.catalog-adult-tree\[hidden\]\s*\{[^{}]*display:\s*none\s*!important/si
);
assert.match(
    css,
    /\.catalog-adult-entry\s*\{[^{}]*padding:\s*0(?:px)?\s*;/si
);
assert.match(
    css,
    /\.catalog-adult-root\s*\{[^{}]*box-sizing:\s*border-box[^{}]*width:\s*100%[^{}]*padding:\s*22px[^{}]*text-decoration:\s*none/si
);
assert.match(
    css,
    /@media\s*\(max-width:\s*600px\)[\s\S]*?\.catalog-adult-root\s*\{[^{}]*padding:\s*18px\s+16px/si
);

process.stdout.write('catalog adult brand card contract passed\n');
