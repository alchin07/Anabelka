import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const filePath = relativePath => path.join(projectRoot, relativePath);
const read = relativePath => {
    assert.equal(fs.existsSync(filePath(relativePath)), true, `${relativePath} must exist`);
    return fs.readFileSync(filePath(relativePath), 'utf8');
};

const routes = read('routes/Content.php');
const publicController = read('app/Controllers/ProductReviewController.php');
const adminController = read('app/Controllers/AdminReviewController.php');
const productController = read('app/Controllers/ProductController.php');
const productView = read('views/product/show.php');
const reviewsView = read('views/reviews/index.php');
const adminView = read('views/admin/reviews/index.php');
const reviewModel = read('app/Models/ProductReview.php');
const app = read('app/Core/App.php');
const adminHeader = read('views/admin/partials/header.php');

assert.match(routes, /\$router->get\(\s*['"]\/reviews['"]\s*,\s*['"]ProductReviewController@index['"]\s*\)/);
assert.match(routes, /\$router->post\(\s*['"]\/product\/\{slug\}\/reviews['"]\s*,\s*['"]ProductReviewController@store['"]\s*\)/);
assert.match(routes, /\$router->get\(\s*['"]\/admin\/reviews['"]\s*,\s*['"]AdminReviewController@index['"]\s*\)/);
for (const action of ['approve', 'reject', 'delete']) {
    assert.match(routes, new RegExp(`\\$router->post\\(\\s*['"]\\/admin\\/reviews\\/${action}['"]\\s*,\\s*['"]AdminReviewController@${action}['"]`));
}

assert.match(publicController, /CustomerAccount::currentId\s*\(/);
assert.match(publicController, /CustomerAccount::verifyCsrf\s*\(/);
assert.match(publicController, /Product::findBySlug\s*\(/);
assert.match(publicController, /ProductReview::submit\s*\(/);
assert.doesNotMatch(publicController, /\$_POST\[['"](?:product_id|user_id|status)['"]\]/);
assert.match(publicController, /ProductReview::latestApprovedStandard\s*\(\s*100\s*\)/);

assert.match(reviewModel, /Category::visibleCategoryIds\s*\(/);
assert.match(reviewModel, /Category::adultCategoryIds\s*\(/);
assert.match(reviewModel, /pr\.status\s*=\s*'approved'/);
assert.match(reviewModel, /'pending'/);
assert.match(reviewModel, /UNIQUE|hasReview|1062/i);

assert.match(productController, /ProductReview::approvedForProduct\s*\(/);
assert.match(productController, /ProductReview::hasReview\s*\(/);
assert.match(productController, /CustomerAccount::csrfToken\s*\(/);
assert.match(productController, /catch\s*\(Throwable\s+\$e\)[\s\S]*?reviews/i);

assert.match(productView, /id="product-reviews"/);
assert.match(productView, /action="\/Anabelka\/product\/[\s\S]*?\/reviews"/);
assert.match(productView, /name="_csrf"/);
assert.match(productView, /name="rating"/);
assert.match(productView, /name="body"/);
assert.match(productView, /htmlspecialchars|\$escape/);
assert.doesNotMatch(productView, /<\?=\s*\$review\[['"]body['"]\]\s*\?>/);

assert.match(reviewsView, /htmlspecialchars|\$escape/);
assert.match(adminView, /name="_csrf"/);
for (const action of ['approve', 'reject', 'delete']) {
    assert.match(adminView, new RegExp(`/Anabelka/admin/reviews/${action}`));
}

for (const action of ['approve', 'reject', 'delete']) {
    const methodPattern = new RegExp(`public\\s+function\\s+${action}\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)(?=\\n\\s*public\\s+function|\\n\\s*private\\s+function|\\n})`);
    const match = adminController.match(methodPattern);
    assert.ok(match, `${action} admin review method must exist`);
    assert.match(match[1], /verifyCsrf\s*\(/, `${action} must verify admin CSRF`);
}
assert.match(adminController, /ProductReview::moderate\s*\(/);
assert.match(adminController, /AdminAccess::currentId\s*\(/);

assert.match(app, /Controllers\/ProductReviewController\.php/);
assert.match(app, /Controllers\/AdminReviewController\.php/);
assert.match(adminHeader, /\$adminCan\(\s*['"]reviews\.view['"]\s*\)/);
assert.match(adminHeader, /href="\/Anabelka\/admin\/reviews"/);

process.stdout.write('product reviews contract passed\n');
