import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(
    'app/Controllers/AdminProductController.php',
    'utf8'
);
const variantController = fs.readFileSync(
    'app/Controllers/AdminProductVariantController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/products/index.php',
    'utf8'
);
const matrix = fs.readFileSync(
    'js/admin-product-variant-stock.js',
    'utf8'
);
const router = fs.readFileSync(
    'app/Core/Router.php',
    'utf8'
);
const access = fs.readFileSync(
    'app/Models/AdminAccess.php',
    'utf8'
);

assert.match(
    controller,
    /'csrfToken' => AdminAccess::csrfToken\(\)/
);
assert.match(
    controller,
    /private function assertCsrf\(\)[\s\S]*Csrf::verify\('admin'\)/
);
assert.doesNotMatch(
    controller,
    /admin_product_csrf/
);
assert.doesNotMatch(
    controller,
    /private function csrfToken\(/
);

assert.match(
    variantController,
    /private function assertCsrf\(\)[\s\S]*Csrf::verify\('admin'\)/
);
assert.doesNotMatch(
    variantController,
    /admin_product_csrf/
);

assert.match(
    view,
    /name="_csrf" value="<\?= \$escape\(\$csrfToken\) \?>"/
);
assert.doesNotMatch(
    view,
    /name="csrf_token"/
);

assert.match(
    matrix,
    /form\.querySelector\('input\[name="_csrf"\]'\)/
);
assert.match(
    matrix,
    /payload\.append\('_csrf', csrf\.value\)/
);
assert.doesNotMatch(
    matrix,
    /payload\.append\('csrf_token'/
);

assert.match(
    router,
    /return in_array\([\s\S]*\['POST', 'PUT', 'PATCH', 'DELETE'\]/
);
assert.match(
    access,
    /'\/admin\/products'\s*=>\s*\['products\.view', 'products\.manage'\]/
);

process.stdout.write(
    'admin product centralized CSRF contract passed\n'
);
