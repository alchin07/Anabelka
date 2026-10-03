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

assert.match(view, /admin-products\.css\?v=10/);
assert.match(view, /admin-products\.js\?v=19/);

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
assert.match(script, /data-product-image-compare/);
assert.match(script, /Порівняти/);
assert.match(script, /product-image-compare-modal/);
assert.match(script, /type = 'range'/);
assert.match(script, /processedMasterUrl/);
assert.match(script, /imageCompareHistoryKey/);
assert.match(script, /closeImageComparison/);
assert.match(script, /openImageComparison/);
assert.match(script, /setPointerCapture/);
assert.match(script, /hasPointerCapture/);
assert.match(script, /setCompareSplit/);
assert.match(script, /ArrowLeft/);
assert.match(script, /ArrowRight/);

assert.match(script, /product-image-compare-diagnostics/);
assert.match(script, /Модель:/);
assert.match(script, /Кадрування:/);
assert.match(script, /Метод:/);
assert.match(script, /opencv-haar-face-subject/);
assert.match(script, /mediapipe-persondet/);
assert.match(script, /Впевненість:/);
assert.match(script, /Стратегія:/);
assert.match(script, /aspect-fill/);
assert.match(script, /subject-bbox/);
assert.match(script, /torso-normalize/);
assert.match(script, /torso-zoom-out/);
assert.match(script, /zoom_out_applied/);
assert.match(script, /масштаб по торсу/);
assert.match(script, /віддалення моделі/);
assert.match(script, /Масштаб фото:/);
assert.match(script, /Торс:/);






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
assert.match(css, /\.product-image-compare-modal/);
assert.match(css, /\.product-image-compare-stage/);
assert.match(css, /\.product-image-compare-divider/);
assert.match(css, /touch-action: none/);
assert.match(css, /::-webkit-slider-thumb/);
assert.match(css, /width: 32px/);

assert.match(css, /\.product-image-compare-slider/);
assert.match(css, /\.product-image-compare-diagnostics/);
assert.match(css, /\.product-image-compare-diagnostic/);



process.stdout.write(
    'product image processing editor contract passed\n'
);
