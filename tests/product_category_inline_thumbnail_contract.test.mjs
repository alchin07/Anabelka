import assert from 'node:assert/strict';
import fs from 'node:fs';

const routes = fs.readFileSync('routes/Web.php', 'utf8');
const manager = fs.readFileSync(
    'app/Services/CategoryManager.php',
    'utf8'
);
const controller = fs.readFileSync(
    'app/Controllers/AdminCategoryController.php',
    'utf8'
);
const adminProduct = fs.readFileSync(
    'app/Models/AdminProduct.php',
    'utf8'
);
const productController = fs.readFileSync(
    'app/Controllers/AdminProductController.php',
    'utf8'
);
const view = fs.readFileSync(
    'views/admin/products/index.php',
    'utf8'
);
const categoryView = fs.readFileSync(
    'views/admin/categories/index.php',
    'utf8'
);
const categoryJs = fs.readFileSync(
    'js/admin-categories.js',
    'utf8'
);
const selectJs = fs.readFileSync('js/anabelka-select.js', 'utf8');
const thumbnailJs = fs.readFileSync(
    'js/admin-product-category-thumbnails.js',
    'utf8'
);
const selectCss = fs.readFileSync('css/anabelka-select.css', 'utf8');
const productsCss = fs.readFileSync('css/admin-products.css', 'utf8');

assert.match(
    routes,
    /post\(\s*['"]\/admin\/categories\/thumbnail['"][\s\S]*?AdminCategoryController@thumbnail/
);
assert.match(
    routes,
    /post\(\s*['"]\/admin\/categories\/thumbnail['"][\s\S]*?AdminCategoryController@thumbnail[\s\S]*?['"]csrf['"]\s*=>\s*true/
);
assert.match(
    controller,
    /public function thumbnail\(\)[\s\S]*?CategoryManager::updateThumbnail/
);
assert.match(manager, /public static function updateThumbnail\s*\(/);
assert.match(
    manager,
    /resolveCategoryImage\([\s\S]*?UPDATE categories[\s\S]*?SET image = :image/
);

assert.match(
    adminProduct,
    /Category::thumbnailCandidatesForAdmin\(\$categoryIds\)/
);
assert.match(
    productController,
    /'categoryCsrfToken'\s*=>\s*AdminAccess::csrfToken\(\)/
);

assert.match(view, /Category::buildAdminForest\(\$categories\)/);
assert.match(
    view,
    /\$appendCategoryOptions\(\$children,\s*\$depth \+ 1\)/
);
assert.match(view, /foreach \(\$categoryOptions as \$category\)/);
assert.match(view, /data-anabelka-thumbnail-edit="1"/);
assert.match(view, /id="category-thumbnail-data"/);
assert.match(view, /id="category-thumbnail-csrf"/);
assert.match(view, /admin-product-category-thumbnails\.js\?v=2/);

assert.match(
    selectJs,
    /data\.anabelkaThumbnailEdit === '1'/
);
assert.match(
    selectJs,
    /anabelka:thumbnail-edit/
);
assert.match(
    selectJs,
    /querySelectorAll\('\.anabelka-select-option'\)/
);
assert.match(selectJs, /sync:\s*function\s*\(select\)/);
assert.match(
    selectCss,
    /\.anabelka-select-thumbnail\.is-editable/
);

assert.match(
    thumbnailJs,
    /\/Anabelka\/admin\/categories\/thumbnail/
);
assert.match(
    thumbnailJs,
    /insertAdjacentElement\('afterend', editor\)/
);
assert.match(
    thumbnailJs,
    /Автоматично — останній товар із фото/
);
assert.match(
    thumbnailJs,
    /data-category-thumbnail-choice/
);
assert.match(
    productsCss,
    /\.product-category-thumbnail-editor/
);
assert.match(
    productsCss,
    /\.product-category-thumbnail-grid/
);



assert.match(
    controller,
    /\$_FILES\['thumbnail_file'\]/
);
assert.match(controller, /imagecopyresampled\s*\(/);
assert.match(controller, /\$targetSize\s*=\s*320/);
assert.match(
    controller,
    /uploads\/categories\/thumbnails/
);
assert.match(
    manager,
    /\$allowUploadedImage/
);
assert.match(
    manager,
    /\/Anabelka\/uploads\/categories\/thumbnails\//
);

assert.match(
    view,
    /id="product-edit-category"[\s\S]*?data-category-thumbnail-select/
);
assert.match(
    thumbnailJs,
    /uploadInput\.type\s*=\s*'file'/
);
assert.match(
    thumbnailJs,
    /thumbnail_file/
);
assert.match(
    thumbnailJs,
    /Фото оброблено до 320×320/
);
assert.match(
    thumbnailJs,
    /anabelka:category-thumbnail-updated/
);

assert.match(
    categoryView,
    /id="category-move-parent"[\s\S]*?data-anabelka-select[\s\S]*?data-category-thumbnail-select/
);
assert.match(
    categoryView,
    /admin-product-category-thumbnails\.js\?v=5/
);
assert.match(
    categoryView,
    /id="category-edit-thumbnail-upload"[\s\S]*?accept="image\/jpeg,image\/png,image\/webp"/
);
assert.match(
    categoryView,
    /＋ Завантажити фото/
);
assert.match(
    thumbnailJs,
    /window\.AnabelkaCategoryThumbnail\s*=/
);
assert.match(
    thumbnailJs,
    /validateFile:\s*validateThumbnailFile/
);
assert.match(
    categoryJs,
    /AnabelkaCategoryThumbnail/
);
assert.match(
    categoryJs,
    /api\.save\(categoryId, '', file\)/
);

assert.match(
    categoryJs,
    /function orderedMoveCandidates\s*\(/
);
assert.match(
    categoryJs,
    /option\.dataset\.anabelkaThumbnailEdit\s*=\s*'1'/
);
assert.match(
    categoryJs,
    /option\.dataset\.anabelkaSubtitle\s*=/
);
assert.match(
    categoryJs,
    /AnabelkaSelect\.refresh\(moveParent\)/
);
assert.match(
    selectJs,
    /dataset\.anabelkaSubtitle/
);
assert.match(
    selectCss,
    /\.product-category-thumbnail-upload/
);

process.stdout.write(
    'product category tree, inline thumbnail upload, and move-list contract passed\n'
);
