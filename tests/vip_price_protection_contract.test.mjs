import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => {
    assert.equal(fs.existsSync(path), true, `${path} must exist`);
    return fs.readFileSync(path, 'utf8');
};

const app = read('app/Core/App.php');
const product = read('app/Models/Product.php');
const protection = read('app/Models/VipPriceProtection.php');
const controller = read('app/Controllers/ProductController.php');
const view = read('views/product/show.php');
const css = read('css/vip-price-protection.css');
const migration = read('database/migrations/2026-09-20_vip_price_protection.sql');

assert.match(app, /Models\/VipPriceProtection\.php/);

assert.match(product, /FROM\s+users\s+AS\s+u[\s\S]*?INNER\s+JOIN\s+user_ranks\s+AS\s+ur/i);
assert.match(product, /u\.is_active\s*=\s*1/i);
assert.match(product, /ur\.is_active\s*=\s*1/i);
assert.match(product, /\$_SESSION\[['"]user_rank_slug['"]\]\s*=\s*\$rankSlug/);
assert.match(product, /ur\.level\s*<=\s*:current_level/i);

assert.match(controller, /VipPriceProtection::forVisiblePrices\s*\(/);
assert.match(controller, /'vipPriceWatermarks'\s*=>\s*\$vipPriceWatermarks/);

assert.match(protection, /public\s+static\s+function\s+isVipPrice\s*\(/);
assert.match(protection, /\$slug\s*===\s*['"]vip['"]/);
assert.match(protection, /rank_level['"]\]\s*<\s*\$priceLevel/);
assert.match(protection, /random_bytes\s*\(\s*3\s*\)/);
assert.match(protection, /base_convert\s*\(/);
assert.match(protection, /session_id\s*\(\s*\)/);
assert.match(protection, /hash\s*\(\s*['"]sha256['"]/);
assert.match(protection, /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+vip_price_view_log/i);
assert.match(protection, /INSERT\s+INTO\s+vip_price_view_log/i);
for (const field of ['user_id', 'product_id', 'rank_id', 'price_amount', 'surface', 'view_code', 'session_hash', 'viewed_at']) {
    assert.match(protection, new RegExp(field));
}

assert.match(view, /css\/vip-price-protection\.css/);
assert.match(view, /vip-price-protected/);
assert.match(view, /vip-price-watermark/);
assert.match(view, /aria-hidden="true"/);
assert.match(view, /htmlspecialchars\s*\([\s\S]*?vipWatermark/);

assert.match(css, /position:\s*absolute/);
assert.match(css, /pointer-events:\s*none/);
assert.match(css, /@media\s*\(max-width:\s*430px\)/);

assert.match(migration, /CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+vip_price_view_log/i);
assert.match(migration, /session_hash\s+CHAR\(64\)/i);

const variantsMatch = controller.match(
    /public\s+function\s+variants\s*\([^)]*\)\s*\{([\s\S]*?)\n\s*private\s+function\s+json/
);
assert.ok(variantsMatch, 'variants() method must be extractable');
assert.doesNotMatch(variantsMatch[1], /getPricesByRanks|product_prices|vipPriceWatermarks/);

process.stdout.write('VIP price protection contract passed\n');
