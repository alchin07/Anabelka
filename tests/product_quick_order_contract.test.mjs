import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

test('quick-order button cannot submit cart, and keeps its branded placement', () => {
    const page = read('views/product/show.php');
    const cart = page.indexOf('Добавить выбранное в корзину');
    const quick = page.indexOf('data-product-quick-order');
    assert.ok(cart !== -1 && quick > cart);
    assert.match(page, /type="button"\s+data-product-quick-order/);
    assert.doesNotMatch(page, /formmethod="GET"\s+formaction="\/Anabelka\/quick-order\/product"/);
    assert.match(page, /Translator::t\('quick.title', 'Швидке замовлення'\)/);
    const header = read('views/partials/header.php');
    assert.match(header, /\/Anabelka\/js\/product-quick-order\.js\?v=1/);
});

function clickQuick({ sizes = [2], color = '', hasColorChoices = false } = {}) {
    const handlers = {};
    const notifications = [];
    const navigations = [];
    let prevented = 0;
    const quickButton = {
        addEventListener(type, callback) { handlers[type] = callback; }
    };
    const colorOptions = {
        querySelector(selector) {
            return selector === '.product-page-color-swatch' && hasColorChoices
                ? {} : null;
        }
    };
    const fields = {
        'input[name="product_id"]': { value: '38' },
        'input[name="color_key"]': color === null ? null : { value: color },
        '[data-product-color-options]': hasColorChoices ? colorOptions : null,
        '[data-product-quick-order]': quickButton
    };
    const form = {
        querySelector: selector => fields[selector] ?? null,
        querySelectorAll(selector) {
            assert.equal(
                selector,
                'input[name="sizes[]"]:checked:not(:disabled)'
            );
            return sizes.map(value => ({ value: String(value) }));
        }
    };
    const document = {
        readyState: 'complete',
        getElementById: id => id === 'cart-form' ? form : null
    };
    const window = {
        AnabelkaProductI18n: {
            select_size: 'Оберіть розмір.',
            select_color: 'Оберіть колір.'
        },
        AnabelkaNotify: {
            show: (type, message) => notifications.push({ type, message })
        },
        location: {
            assign: url => navigations.push(url)
        }
    };
    vm.runInNewContext(
        read('js/product-quick-order.js'),
        { window, document, URLSearchParams },
        { filename: 'js/product-quick-order.js', timeout: 1000 }
    );
    assert.equal(typeof handlers.click, 'function');
    handlers.click({ preventDefault() { prevented++; } });
    return { notifications, navigations, prevented };
}

test('quick order navigates only with selected product, sizes and color', () => {
    const result = clickQuick({
        sizes: [2, 7],
        color: 'білий|#ffffff',
        hasColorChoices: true
    });
    assert.equal(result.prevented, 1);
    assert.equal(result.notifications.length, 0);
    assert.equal(result.navigations.length, 1);
    const url = new URL(result.navigations[0], 'http://localhost');
    assert.equal(url.pathname, '/Anabelka/quick-order/product');
    assert.equal(url.searchParams.get('product_id'), '38');
    assert.deepEqual(url.searchParams.getAll('sizes[]'), ['2', '7']);
    assert.equal(url.searchParams.get('color_key'), 'білий|#ffffff');
    assert.equal(url.searchParams.get('quantity'), null);
});

test('quick order blocks missing sizes instead of submitting cart', () => {
    const result = clickQuick({ sizes: [] });
    assert.equal(result.prevented, 1);
    assert.equal(result.navigations.length, 0);
    assert.deepEqual(result.notifications, [{
        type: 'warning', message: 'Оберіть розмір.'
    }]);
});

test('quick order blocks missing color when color swatches are available', () => {
    const result = clickQuick({
        sizes: [2],
        color: '',
        hasColorChoices: true
    });
    assert.equal(result.navigations.length, 0);
    assert.deepEqual(result.notifications, [{
        type: 'warning', message: 'Оберіть колір.'
    }]);
});

test('ordinary add-to-cart AJAX stays separate from quick-order click', () => {
    const page = read('views/product/show.php');
    const variants = read('js/product-color-variants.js');
    assert.match(page, /cartForm\.addEventListener\(\s*'submit'/);
    assert.match(page, /event\.preventDefault\(\);[\s\S]*?new FormData\(cartForm\)/);
    assert.match(variants, /cartForm\.addEventListener\('submit', submitColorCart, true\)/);
    assert.match(variants, /event\.preventDefault\(\);\s*event\.stopImmediatePropagation\(\);/);
    assert.doesNotMatch(page, /event\.submitter/);
    assert.doesNotMatch(variants, /event\.submitter/);
});

test('the new entry point reuses existing validated product-only quick order', () => {
    const routes = read('routes/Web.php');
    assert.match(routes, /'\/quick-order',\s*'QuickOrderController@form'/);
    assert.match(routes, /'\/quick-order',\s*'QuickOrderController@store'/);
    assert.match(routes, /'\/quick-order\/product',\s*'QuickOrderController@productForm'/);
    assert.match(routes, /'\/quick-order\/product',\s*'QuickOrderController@productStore'/);
    const template = read('views/order/quick.php');
    assert.match(template, /name="draft_token"/);
    assert.match(template, /!empty\(\$productDraftToken\)[\s\S]*'\/Anabelka\/quick-order\/product'/);
    const source = read('app/Controllers/QuickOrderController.php');
    for (const re of [
        /Product::findById\(/,
        /Product::hasSize\(/,
        /ProductVariantStock::colorInfo\(/,
        /ProductVariantStock::stockFor\(/,
        /AdultAccess::isConfirmed\(\)/,
        /Product::getCurrentPrice\(/,
        /QuickOrder::create\(/
    ]) {
        assert.match(source, re);
    }
    assert.equal((source.match(/Cart::clearByUserId\(/g) || []).length, 1);
});

test('quick order uses one visible heading while preserving browser page title', () => {
    const quick = read('views/order/quick.php');
    const header = read('views/partials/header.php');
    assert.match(quick, /\$pageTitle = Translator::t\('quick.title', 'Швидке замовлення'\)/);
    assert.match(quick, /<title><\?= htmlspecialchars\(\$pageTitle\) \?> — Анабелька<\/title>/);
    assert.match(quick, /\$hidePublicPageHeading = true;/);
    assert.match(quick, /<h2>[\s\S]*?Translator::t\(\s*'quick.title',\s*'Швидке замовлення'/);
    assert.match(header, /if \(!empty\(\$pageTitle\) && empty\(\$hidePublicPageHeading\)\)/);
});
