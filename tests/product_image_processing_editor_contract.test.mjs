import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path, 'utf8');

const adminProduct = read('app/Models/AdminProduct.php');
const view = read('views/admin/products/index.php');
const script = read('js/admin-products.js');
const css = read('css/admin-products.css');

assert.match(
    adminProduct,
    /ProductImageProcessing::forImageIds\(\$imageIds\)/
);
assert.match(
    adminProduct,
    /\$image\['processing'\]\s*=/
);

assert.match(view, /admin-products\.css\?v=7/);
assert.match(view, /admin-products\.js\?v=11/);

assert.match(script, /data-product-image-process/);
assert.match(
    script,
    /\/Anabelka\/admin\/products\/image-process/
);
assert.match(script, /payload\.append\('_csrf'/);
assert.match(script, /payload\.append\('image_id'/);
assert.match(script, /Готово ·/);
assert.match(script, /Не оброблено/);
assert.match(script, /Повторити/);
assert.match(script, /image\.processing\s*=\s*data\.processing/);

assert.match(css, /\.product-image-processing/);
assert.match(
    css,
    /data-processing-status="ready"/
);
assert.match(
    css,
    /data-processing-status="error"/
);
assert.match(
    css,
    /data-processing-status="processing"/
);

process.stdout.write(
    'product image processing editor contract passed\n'
);
