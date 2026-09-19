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
    controller,
    /public function thumbnail\(\)[\s\S]*?verifyCsrf\(\)[\s\S]*?CategoryManager::updateThumbnail/
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
assert.match(view, /admin-product-category-thumbnails\.js\?v=1/);

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

process.stdout.write(
    'product category tree and inline thumbnail contract passed\n'
);
