import assert from 'node:assert/strict';
import fs from 'node:fs';

const product = fs.readFileSync('app/Models/Product.php', 'utf8');
const controller = fs.readFileSync(
    'app/Controllers/CatalogController.php',
    'utf8'
);
const view = fs.readFileSync('views/catalog/category.php', 'utf8');
const partial = fs.readFileSync('views/partials/pagination.php', 'utf8');
const css = fs.readFileSync('css/catalog.css', 'utf8');

assert.match(
    product,
    /public\s+static\s+function\s+pageByCategory\s*\(/
);
assert.match(product, /SELECT\s+COUNT\(\*\)[\s\S]*category_id\s*=\s*:category_id/i);
assert.match(product, /LIMIT\s+:limit\s+OFFSET\s+:offset/i);
assert.match(product, /ProductImage::colorVariantsForProducts/);
assert.match(product, /'per_page'\s*=>\s*\$perPage/);
assert.match(product, /'total_pages'\s*=>\s*\$totalPages/);
assert.match(product, /normalizePageNumber/);

assert.match(
    controller,
    /Product::pageByCategory\([\s\S]*\$_GET\['page'\]\s*\?\?\s*1[\s\S]*24/
);
assert.match(controller, /'productPagination'\s*=>\s*\$productPage/);

assert.match(view, /partials\/pagination\.php/);
assert.match(view, /unset\(\$paginationQuery\['page'\]\)/);
assert.match(view, /catalog\.css\?v=7/);

assert.match(partial, /aria-current="page"/);
assert.match(partial, /rel="prev"/);
assert.match(partial, /rel="next"/);
assert.match(partial, /http_build_query/);
assert.match(partial, /currentPage\s*-\s*2/);
assert.match(partial, /currentPage\s*\+\s*2/);
assert.match(partial, />…<\/span>/);

assert.match(
    css,
    /\.anabelka-pagination-link\s*\{[^{}]*min-width:\s*44px[^{}]*min-height:\s*44px/si
);
assert.match(
    css,
    /\.anabelka-pagination-link\.is-current\s*\{[^{}]*background:\s*#8A2BE2[^{}]*color:\s*#fff/si
);

process.stdout.write('catalog pagination contract passed\n');
