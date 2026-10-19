import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';

const read = path => fs.readFileSync(path, 'utf8');
const currentPriceViews = [
    'views/admin/products/index.php',
    'views/admin/security/vip-price-views.php',
    'views/cart/index.php',
    'views/catalog/category.php',
    'views/catalog/index.php',
    'views/favorites/index.php',
    'views/home/blocks/product-collection.php',
    'views/order/quick.php',
    'views/product/show.php',
    'views/search/index.php',
    'views/storefront/partials/product-card.php'
];

test('all live product prices display hryvnia without converting stored amounts', () => {
    for (const path of currentPriceViews) {
        const source = read(path);
        assert.ok(source.includes('₴'), path + ' must display hryvnia');
        assert.ok(!source.includes('€'), path + ' still displays euro for live prices');
        assert.match(source, /number_format\(|0 ₴/, path + ' must keep existing numeric formatting');
    }
});

test('new regular and quick orders are stored as UAH', () => {
    const regular = read('app/Models/Order.php');
    const quick = read('app/Models/QuickOrder.php');

    assert.match(regular, /INSERT INTO orders[\s\S]*?'UAH',/);
    assert.doesNotMatch(regular, /'EUR'/);
    assert.match(quick, /currency VARCHAR\(3\) NOT NULL DEFAULT 'UAH'/);
    assert.match(quick, /INSERT INTO quick_orders[\s\S]*?'UAH',/);
    assert.doesNotMatch(quick, /'EUR'/);
});

test('confirmation pages use stored order currency for totals and items', () => {
    for (const path of ['views/order/success.php', 'views/order/quick-success.php']) {
        const view = read(path);
        assert.ok(view.includes("$order['currency'] ?? 'EUR'"), path + ' must read persisted currency');
        assert.ok(view.includes("'EUR' => '€'"), path + ' must retain historic euro');
        assert.ok(view.includes("'UAH' => '₴'"), path + ' must support new hryvnia');
        assert.ok(view.includes('$orderCurrencySymbols[$orderCurrency] ?? $orderCurrency'));
        assert.ok(view.includes("htmlspecialchars($orderCurrencySymbol, ENT_QUOTES, 'UTF-8')"));
        assert.ok(!view.includes(') ?> €'), path + ' total must not always show euro');
    }
});

test('historic customer and administrator orders continue to show stored EUR', () => {
    const history = read('views/order/history.php');
    const items = read('views/order/partials/items.php');
    const adminOrders = read('views/admin/orders/index.php');
    const adminHome = read('views/admin/index.php');

    assert.ok(history.includes("$order['currency'] ?? 'EUR'"));
    assert.ok(history.includes("'EUR' => '€'"));
    assert.ok(items.includes("'EUR' => '€'"));
    assert.ok(items.includes("'UAH' => '₴'"));
    assert.ok(adminOrders.includes("$order['currency'] ?? 'EUR'"));
    assert.ok(adminOrders.includes("'UAH' => '₴'"));
    assert.ok(adminHome.includes("$order['currency'] ?? 'EUR'"));
    assert.ok(adminHome.includes("'EUR' => '€'"));
});

test('unrelated administrator work contracts keep their currencies', () => {
    const work = read('views/admin/administrators/work-time.php');
    assert.ok(work.includes("['UAH', 'EUR', 'USD', 'PLN']"));
    assert.ok(work.includes("'EUR' => '€'"));
});
