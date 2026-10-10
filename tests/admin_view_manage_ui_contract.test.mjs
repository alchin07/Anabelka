import assert from 'node:assert/strict';
import fs from 'node:fs';

const products = fs.readFileSync(
    'views/admin/products/index.php',
    'utf8'
);
const categories = fs.readFileSync(
    'views/admin/categories/index.php',
    'utf8'
);
const orders = fs.readFileSync(
    'views/admin/orders/index.php',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);

assert.match(
    products,
    /AdminAccess::can\('products\.manage'\)/
);
assert.match(
    products,
    /if \(\$canManage\):[\s\S]*?data-product-create/
);
assert.match(
    products,
    /if \(\$canManage\):[\s\S]*?class="admin-product-actions"/
);

assert.match(
    categories,
    /AdminAccess::can\('categories\.manage'\)/
);
assert.match(
    categories,
    /if \(\$canManage\):[\s\S]*?data-category-reorder="up"/
);
assert.match(
    categories,
    /if \(\$canManage\):[\s\S]*?class="category-admin-actions"/
);
assert.match(
    categories,
    /if \(\$canManage\):[\s\S]*?data-category-create/
);

assert.match(
    orders,
    /AdminAccess::can\('orders\.manage'\)/
);
assert.match(
    orders,
    /if \(\$canManage\):[\s\S]*?class="admin-order-actions"/
);

assert.match(
    router,
    /\$isWrite\s*=\s*\$method !== 'GET' && \$method !== 'HEAD'/
);
assert.match(
    router,
    /'\/admin\/products'\s*=>\s*\['products\.view', 'products\.manage'\]/
);
assert.match(
    router,
    /'\/admin\/categories'\s*=>\s*\['categories\.view', 'categories\.manage'\]/
);
assert.match(
    router,
    /'\/admin\/orders'\s*=>\s*\['orders\.view', 'orders\.manage'\]/
);

process.stdout.write(
    'admin view manage UI contract passed\n'
);
