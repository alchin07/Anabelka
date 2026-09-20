import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => {
    assert.equal(fs.existsSync(path), true, `${path} must exist`);
    return fs.readFileSync(path, 'utf8');
};

const routes = read('routes/AdminSecurity.php');
const access = read('app/Models/AdminAccess.php');
const header = read('views/admin/partials/header.php');
const model = read('app/Models/VipPriceViewLog.php');
const controller = read('app/Controllers/AdminVipPriceViewController.php');
const view = read('views/admin/security/vip-price-views.php');
const css = read('css/admin-vip-price-views.css');

assert.match(routes, /\/admin\/vip-price-views/);
assert.match(routes, /AdminVipPriceViewController@index/);
assert.match(routes, /Models\/VipPriceViewLog\.php/);
assert.match(routes, /Controllers\/AdminVipPriceViewController\.php/);

assert.match(access, /vip_prices\.view/);
assert.match(access, /\/admin\/vip-price-views/);
assert.match(access, /Перегляд журналу VIP-цін/);

assert.match(header, /\$canVipPriceViews/);
assert.match(header, /href="\/Anabelka\/admin\/vip-price-views"/);
assert.match(header, />VIP-ціни</);

assert.match(controller, /VipPriceViewLog::normalizeFilters\s*\(/);
assert.match(controller, /\$perPage\s*=\s*50/);
assert.match(controller, /VipPriceViewLog::count\s*\(/);
assert.match(controller, /VipPriceViewLog::page\s*\(/);

for (const filter of [
    'date_from',
    'date_to',
    'user',
    'product',
    'rank_id',
    'surface',
    'view_code'
]) {
    assert.match(model, new RegExp(`['"]${filter}['"]`));
    assert.match(view, new RegExp(`name=["']${filter}["']`));
}

assert.match(model, /LEFT\s+JOIN\s+users\s+AS\s+u/i);
assert.match(model, /LEFT\s+JOIN\s+products\s+AS\s+p/i);
assert.match(model, /LEFT\s+JOIN\s+user_ranks\s+AS\s+r/i);
assert.match(model, /ORDER\s+BY\s+v\.viewed_at\s+DESC/i);
assert.match(model, /LIMIT\s+:limit\s+OFFSET\s+:offset/i);
assert.match(model, /v\.view_code\s*=\s*:view_code/i);
assert.match(model, /DATE_ADD\s*\(\s*:date_to\s*,\s*INTERVAL\s+1\s+DAY\s*\)/i);
assert.match(model, /:user_query_name/);
assert.match(model, /:user_query_email/);
assert.match(model, /:product_query_name/);
assert.match(model, /:product_query_sku/);
assert.match(model, /:product_query_slug/);
assert.doesNotMatch(model, /u\.name LIKE :user_query(?:\s|['"])/);
assert.doesNotMatch(model, /u\.email LIKE :user_query(?:\s|['"])/);
assert.doesNotMatch(model, /p\.name LIKE :product_query(?:\s|['"])/);
assert.doesNotMatch(model, /p\.sku LIKE :product_query(?:\s|['"])/);
assert.doesNotMatch(model, /p\.slug LIKE :product_query(?:\s|['"])/);

assert.match(view, /Watermark/);
assert.match(view, /По 50 записів на сторінку|\$perPage/);
assert.match(view, /Попередня/);
assert.match(view, /Наступна/);
assert.match(view, /htmlspecialchars/);

assert.match(css, /@media\s*\(max-width:\s*640px\)/);
assert.match(css, /content:\s*attr\(data-label\)/);
assert.match(css, /min-height:\s*44px/);

process.stdout.write('VIP price admin journal contract passed\n');
