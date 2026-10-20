import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

test('product card has a secondary quick-order submit below add to cart', () => {
    const page = read('views/product/show.php');
    const cart = page.indexOf('Добавить выбранное в корзину');
    const quick = page.indexOf('data-product-quick-order');
    assert.ok(cart !== -1 && quick > cart);
    assert.match(page, /formmethod="GET"\s+formaction="\/Anabelka\/quick-order\/product"/);
    assert.match(page, /Translator::t\('quick.title', 'Швидке замовлення'\)/);
});

test('both product form listeners allow quick submit without AJAX cart mutation', () => {
    const page = read('views/product/show.php');
    const variants = read('js/product-color-variants.js');
    assert.match(page, /event\.submitter[\s\S]*?data-product-quick-order/);
    assert.match(variants, /event\.submitter[\s\S]*?data-product-quick-order/);
    assert.match(variants, /event\.preventDefault\(\);\s*event\.stopImmediatePropagation\(\);/);
    assert.match(variants, /const formData = new FormData\(cartForm\);/);
    assert.match(variants, /if \(state\.colors\.length > 0 && !state\.selectedColor\)/);
});

test('direct product route is separate from original cart-based quick ordering', () => {
    const routes = read('routes/Web.php');
    assert.match(routes, /'\/quick-order',\s*'QuickOrderController@form'/);
    assert.match(routes, /'\/quick-order',\s*'QuickOrderController@store'/);
    assert.match(routes, /'\/quick-order\/product',\s*'QuickOrderController@productForm'/);
    assert.match(routes, /'\/quick-order\/product',\s*'QuickOrderController@productStore'/);
    const template = read('views/order/quick.php');
    assert.match(template, /name="draft_token"/);
    assert.match(template, /!empty\(\$productDraftToken\)[\s\S]*'\/Anabelka\/quick-order\/product'/);
});

test('product quick-order is one-use, reprices and rechecks size, color and stock', () => {
    const source = read('app/Controllers/QuickOrderController.php');
    assert.match(source, /bin2hex\(random_bytes\(16\)\)/);
    assert.match(source, /time\(\) - \(int\) \(\$selection\['created_at'\] \?\? 0\) > 1800/);
    assert.match(source, /Product::findById\(/);
    assert.match(source, /Product::hasSize\(/);
    assert.match(source, /ProductVariantStock::hasMatrix\(/);
    assert.match(source, /ProductVariantStock::colorInfo\(/);
    assert.match(source, /ProductVariantStock::stockFor\(/);
    assert.match(source, /AdultAccess::isConfirmed\(\)/);
    assert.match(source, /Product::getCurrentPrice\(/);
    assert.match(source, /QuickOrder::create\(/);
    assert.match(source, /unset\(\$drafts\[\$token\]\);[\s\S]*header\(/);
    assert.equal((source.match(/Cart::clearByUserId\(/g) || []).length, 1,
        'cart clearing remains limited to the original cart quick-order flow');
});
