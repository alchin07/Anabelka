import assert from 'node:assert/strict';
import fs from 'node:fs';

const modelPath = 'app/Models/StorefrontProductCollection.php';

assert.ok(
    fs.existsSync(modelPath),
    'StorefrontProductCollection model must exist before collection rules can pass'
);

const modelSource = fs.readFileSync(modelPath, 'utf8');

assert.match(
    modelSource,
    /private\s+const\s+PER_PAGE\s*=\s*24\s*;/,
    'storefront collections must paginate by 24 products'
);
assert.match(
    modelSource,
    /public\s+static\s+function\s+page\s*\(/,
    'collection model must expose the paginated page() API'
);
assert.match(
    modelSource,
    /public\s+static\s+function\s+pageMeta\s*\(/,
    'collection model must expose page metadata logic'
);
assert.match(
    modelSource,
    /public\s+static\s+function\s+discountPercent\s*\(/,
    'collection model must expose display discount calculation'
);
assert.match(modelSource, /Category::visibleCategoryIds\s*\(/);
assert.match(modelSource, /Category::adultCategoryIds\s*\(/);
assert.match(modelSource, /Product::getCurrentRankSlug\s*\(/);
assert.match(modelSource, /ProductTranslator::localizeList\s*\(/);
assert.match(modelSource, /ProductColor::variantsForProducts\s*\(/);
assert.match(modelSource, /ORDER\s+BY\s+q\.id\s+DESC/i);
assert.match(modelSource, /LIMIT\s+:limit\s+OFFSET\s+:offset/i);
assert.match(
    modelSource,
    /NOT\s*\(\s*q\.active_discount_percent\s*>\s*0\s*OR\s*q\.old_price\s*>\s*q\.current_price\s*\)/i,
    'new arrivals must exclude every product that qualifies for Discounts'
);
assert.match(
    modelSource,
    /COALESCE\(r\.old_price,\s*0\)\s+AS\s+old_price/i,
    'null old_price must not exclude a normal product from New Arrivals'
);
assert.doesNotMatch(
    modelSource,
    /Product::getCurrentPrice\s*\(/,
    'pagination must not use per-product current-price queries'
);

process.stdout.write('storefront product collection contract passed\n');
