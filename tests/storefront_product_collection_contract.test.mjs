import assert from 'node:assert/strict';
import fs from 'node:fs';
import {phpJson} from './helpers/php-probe.mjs';

const modelPath = 'app/Models/StorefrontProductCollection.php';

assert.ok(
    fs.existsSync(modelPath),
    'StorefrontProductCollection model must exist before collection rules can pass'
);

const modelSource = fs.readFileSync(modelPath, 'utf8');

assert.match(
    modelSource,
    /public\s+static\s+function\s+page\s*\(/,
    'collection model must expose the paginated page() API'
);
assert.match(modelSource, /Category::visibleCategoryIds\s*\(/);
assert.match(modelSource, /Category::adultCategoryIds\s*\(/);
assert.match(modelSource, /Product::getCurrentRankSlug\s*\(/);
assert.match(modelSource, /ProductTranslator::localizeList\s*\(/);
assert.match(modelSource, /ProductImage::colorVariantsForProducts\s*\(/);
assert.match(modelSource, /ORDER\s+BY\s+q\.id\s+DESC/i);
assert.match(modelSource, /LIMIT\s+:limit\s+OFFSET\s+:offset/i);
assert.match(
    modelSource,
    /NOT\s*\(\s*q\.active_discount_percent\s*>\s*0\s*OR\s*q\.old_price\s*>\s*q\.current_price\s*\)/i,
    'new arrivals must exclude every product that qualifies for Discounts'
);
assert.doesNotMatch(
    modelSource,
    /Product::getCurrentPrice\s*\(/,
    'pagination must not use per-product current-price queries'
);

const results = phpJson(`
require 'app/Models/StorefrontProductCollection.php';
echo json_encode([
    StorefrontProductCollection::pageMeta('2', 25),
    StorefrontProductCollection::pageMeta(['2'], 25),
    StorefrontProductCollection::pageMeta('999', 25),
    StorefrontProductCollection::discountPercent(100, 80, 0),
    StorefrontProductCollection::discountPercent(100, 80, 15),
    StorefrontProductCollection::discountPercent(0, 0, 0)
]);
`);

assert.equal(results[0].page, 2);
assert.equal(results[0].per_page, 24);
assert.equal(results[0].total, 25);
assert.equal(results[0].total_pages, 2);
assert.equal(results[0].has_previous, true);
assert.equal(results[0].has_next, false);

assert.equal(results[1].page, 1);
assert.equal(results[2].page, 2);
assert.equal(results[3], 20);
assert.equal(results[4], 15);
assert.equal(results[5], null);

process.stdout.write('storefront product collection contract passed\n');
