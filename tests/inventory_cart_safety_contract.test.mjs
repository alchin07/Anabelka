import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {fileURLToPath} from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');

test('inventory resolves hidden products without changing public visibility', () => {
    const stock = read('app/Models/Inventory.php');
    const product = read('app/Models/Product.php');
    assert.match(stock, /self::findInventoryProduct\(\(int\) \(\$item\['product_id'\] \?\? 0\)\)/);
    assert.match(stock, /private static function findInventoryProduct\(\$productId\)/);
    assert.match(stock, /SELECT \*\s+FROM products\s+WHERE id = :id\s+LIMIT 1/);
    assert.doesNotMatch(stock, /:\s*Product::findById\(/);
    assert.match(product, /public static function findById\([\s\S]*?is_active = 1/);
});

test('reservation stays conditional and restoring stock must affect a row', () => {
    const stock = read('app/Models/Inventory.php');
    assert.equal((stock.match(/AND stock >= :quantity_limit/g) || []).length, 3);
    assert.ok((stock.match(/\$stmt->rowCount\(\) !== 1/g) || []).length >= 5);
    assert.match(stock, /Не вдалося повернути залишок товару потрібного розміру/);
    assert.match(stock, /Не вдалося повернути залишок товару на склад/);
    assert.match(stock, /if \(\$productId <= 0 \|\| !\$product\)\s*\{\s*throw new RuntimeException/);
    const admin = read('app/Models/AdminOrder.php');
    assert.match(admin, /LIMIT 1\s+FOR UPDATE/);
    assert.match(admin, /Inventory::releaseItems\(\$items\);\s*\$inventoryReserved = false/);
    assert.match(admin, /Inventory::reserveItems\(\$items\);\s*\$inventoryReserved = true/);
    assert.match(admin, /\$db->rollBack\(\)/);
});

test('all cart and order entry points check ownership of size', () => {
    const product = read('app/Models/Product.php');
    assert.match(product, /public static function hasSize\(\$productId, \$sizeId\)/);
    assert.match(product, /FROM product_attributes AS pa[\s\S]*a.slug = 'size'[\s\S]*LIMIT 1/);
    const cart = read('app/Models/Cart.php');
    const cartController = read('app/Controllers/CartController.php');
    const stock = read('app/Models/Inventory.php');
    assert.match(cart, /if \(!Product::hasSize\(\$productId, \$sizeId\)\)/);
    assert.match(cartController, /foreach \(\$cleanSizes as \$sizeId\)\s*\{\s*if \(!Product::hasSize/);
    assert.match(cartController, /public function increase\(\)[\s\S]*if \(!Product::hasSize\(\$productId, \$sizeId\)\)/);
    assert.match(stock, /\$direction < 0 && !Product::hasSize\(\$productId, \$sizeId\)/);
    assert.match(stock, /\$direction > 0 && !Product::hasSize\(\$productId, \$sizeId\)/);
});

test('quick order stock conflict is a 409 and does not clear the cart', () => {
    const quick = read('app/Controllers/QuickOrderController.php');
    const before = quick.indexOf('try {\n            $result = QuickOrder::create(');
    const caught = quick.indexOf('} catch (RuntimeException $e) {', before);
    const response = quick.indexOf('http_response_code(409);', caught);
    const returnPos = quick.indexOf('return;', response);
    const clear = quick.indexOf('Cart::clearByUserId($userId);', returnPos);
    assert.ok(before > 0 && caught > before && response > caught && returnPos > response && clear > returnPos);
    assert.match(quick, /'backUrl' => '\/Anabelka\/cart'/);
    assert.match(quick, /'public.order.stock_changed_title'/);
    assert.match(quick, /'Не вдалося визначити розмір'/);
});

test('order models reserve only during transaction; cart edits do not touch inventory', () => {
    const regular = read('app/Models/Order.php');
    const quick = read('app/Models/QuickOrder.php');
    const cart = read('app/Models/Cart.php');
    const cartController = read('app/Controllers/CartController.php');
    for (const model of [regular, quick]) {
        assert.match(model, /\$db->beginTransaction\(\)/);
        assert.match(model, /Inventory::reserveItems\(\$inventoryItems\)/);
        assert.match(model, /SET inventory_reserved = 1/);
        assert.match(model, /\$db->commit\(\)/);
        assert.match(model, /\$db->rollBack\(\)/);
    }
    assert.doesNotMatch(cart, /Inventory::reserveItems/);
    assert.doesNotMatch(cartController, /Inventory::reserveItems/);
});

test('variant schema setup precedes stock transactions and cannot implicitly commit them', () => {
    const matrix = read('app/Models/ProductVariantStock.php');
    assert.match(matrix, /CREATE TABLE IF NOT EXISTS product_variant_stock/);

    for (const filename of [
        'app/Models/Order.php',
        'app/Models/QuickOrder.php',
        'app/Models/AdminOrder.php'
    ]) {
        const model = read(filename);
        const schema = model.indexOf('ProductVariantStock::ensureTable();');
        const transaction = model.indexOf('$db->beginTransaction();');
        assert.ok(schema >= 0 && transaction > schema,
            filename + ' must initialize the stock matrix before starting a transaction');
    }
});

test('public by-size availability ignores non-size attributes', () => {
    const product = read('app/Models/Product.php');
    const start = product.indexOf('public static function isAvailable($product)');
    const end = product.indexOf('public static function getBadges(', start);
    assert.ok(start >= 0 && end > start);
    const availability = product.slice(start, end);
    assert.match(availability, /FROM product_attributes AS pa/);
    assert.match(availability, /JOIN attribute_values AS av/);
    assert.match(availability, /JOIN attributes AS a/);
    assert.match(availability, /AND a.slug = 'size'/);
    assert.match(availability, /AND pa.stock > 0/);
});
