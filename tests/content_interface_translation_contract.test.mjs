import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relativePath => {
    const full = path.join(root, relativePath);
    assert.equal(fs.existsSync(full), true, `${relativePath} must exist`);
    return fs.readFileSync(full, 'utf8');
};

const translator = read('app/Models/ContentInterfaceTranslator.php');
const app = read('app/Core/App.php');
const newsController = read('app/Controllers/NewsController.php');
const reviewController = read('app/Controllers/ProductReviewController.php');
const productReviewPartial = read('views/product/partials/reviews.php');

for (const language of ['uk', 'ru', 'en']) {
    assert.match(translator, new RegExp(`['"]${language}['"]\\s*=>\\s*\\[`));
}

for (const key of [
    'news.title',
    'news.intro',
    'news.empty',
    'news.read_more',
    'news.back',
    'reviews.title',
    'reviews.intro',
    'reviews.empty',
    'reviews.product_title',
    'reviews.product_intro',
    'reviews.product_empty',
    'reviews.rating',
    'reviews.body',
    'reviews.submit',
    'reviews.login_to_review'
]) {
    assert.match(translator, new RegExp(key.replace('.', '\\.')));
}

assert.match(app, /Models\/ContentInterfaceTranslator\.php/);
assert.match(newsController, /ContentInterfaceTranslator::seed\s*\(/);
assert.match(reviewController, /ContentInterfaceTranslator::seed\s*\(/);
assert.match(productReviewPartial, /ContentInterfaceTranslator::seed\s*\(/);

process.stdout.write('content interface translation contract passed\n');
