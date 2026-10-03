import assert from 'node:assert/strict';
import fs from 'node:fs';

const model = fs.readFileSync(
    'app/Models/CatalogSearch.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/SearchController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/search/index.php',
    'utf8'
);
const pagination = fs.readFileSync(
    'views/partials/pagination.php',
    'utf8'
);
const css = fs.readFileSync('css/catalog.css', 'utf8');

assert.match(
    model,
    /public static function page\s*\(/
);
assert.match(
    model,
    /self::run\([\s\S]*?\$query[\s\S]*?\$languageCode[\s\S]*?0[\s\S]*?50/
);
assert.match(
    model,
    /array_slice\(\s*\$products,\s*\$offset,\s*\$perPage/
);
assert.match(
    model,
    /'per_page'\s*=>\s*\$perPage/
);
assert.match(
    model,
    /'total_products'\s*=>\s*\$totalProducts/
);
assert.match(
    model,
    /'total_pages'\s*=>\s*\$totalPages/
);
assert.match(
    model,
    /'has_previous'\s*=>\s*\$page\s*>\s*1/
);
assert.match(
    model,
    /'has_next'\s*=>\s*\$page\s*<\s*\$totalPages/
);
assert.match(
    model,
    /ProductColor::variantsForProducts\(\$productIds\)/
);
assert.match(
    model,
    /private static function normalizePageNumber\s*\(/
);

assert.match(
    controller,
    /CatalogSearch::page\([\s\S]*?\$_GET\['page'\]\s*\?\?\s*1[\s\S]*?24/
);
assert.match(
    controller,
    /'searchPagination'\s*=>\s*\$searchPagination/
);
assert.match(
    controller,
    /\(int\) \(\$results\['page'\] \?\? 1\) === 1[\s\S]*?SearchQueryLog::record/
);

assert.match(
    view,
    /\$searchPagination\['total'\]/
);
assert.match(
    view,
    /\$paginationPath\s*=\s*['"]\/Anabelka\/search['"]/
);
assert.match(
    view,
    /\$paginationQuery\s*=\s*\['q'\s*=>\s*\$query\]/
);
assert.match(view, /partials\/pagination\.php/);
assert.match(view, /catalog\.css\?v=7/);

assert.match(pagination, /http_build_query/);
assert.match(pagination, /aria-current="page"/);
assert.match(pagination, /rel="prev"/);
assert.match(pagination, /rel="next"/);

assert.match(
    css,
    /\.anabelka-pagination-link\.is-current\s*\{[^{}]*background:\s*#8A2BE2[^{}]*color:\s*#fff/si
);

process.stdout.write('search pagination contract passed\n');
