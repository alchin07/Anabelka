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
const giftController = read('app/Controllers/GiftCertificateController.php');
const giftView = read('views/gift-certificates/index.php');
const homeController = read('app/Controllers/HomeController.php');
const homeView = read('views/home.php');
const rail = read('views/home/partials/right-rail.php');
const railCss = read('css/home-right-rail.css');
const catalogView = read('views/catalog/index.php');
const publicHeader = read('views/partials/header.php');
const homeTranslations = read('app/Models/HomeInterfaceTranslator.php');
const app = read('app/Core/App.php');

assert.match(routes, /\$router->get\(\s*['"]\/gift-certificates['"]\s*,\s*['"]GiftCertificateController@index['"]\s*\)/);
assert.match(giftController, /class\s+GiftCertificateController/);
assert.match(giftController, /gift-certificates\/index/);
assert.doesNotMatch(giftView, /action="[^\"]*(?:checkout|order|activate|redeem)/i);
assert.doesNotMatch(giftView, /QR|активуват|оплатити|придбати зараз/i);

assert.match(homeController, /SiteNews::latestPublished\s*\(\s*3\s*,/);
assert.match(homeController, /ProductReview::latestApprovedStandard\s*\(\s*2\s*\)/);
assert.match(homeController, /catch\s*\(Throwable\s+\$e\)[\s\S]*?\$homeNews\s*=\s*\[\]/i);
assert.match(homeController, /catch\s*\(Throwable\s+\$e\)[\s\S]*?\$homeReviews\s*=\s*\[\]/i);
assert.match(homeController, /'homeNews'\s*=>\s*\$homeNews/);
assert.match(homeController, /'homeReviews'\s*=>\s*\$homeReviews/);

assert.match(homeView, /home-primary-content/);
assert.match(homeView, /home\/partials\/right-rail\.php/);
assert.match(homeView, /home-right-rail\.css/);
assert.match(rail, /home-right-rail/);
assert.match(rail, /\/Anabelka\/news/);
assert.match(rail, /\/Anabelka\/reviews/);
assert.match(rail, /\/Anabelka\/gift-certificates/);
assert.doesNotMatch(rail, /Database::|SiteNews::|ProductReview::/);

assert.match(railCss, /\.home-right-rail\s*\{[^{}]*display:\s*none/si);
assert.match(railCss, /@media\s*\(min-width:\s*1250px\)/i);
assert.match(railCss, /grid-template-columns:\s*minmax\(0,\s*1fr\)\s+280px/i);
assert.match(railCss, /gap:\s*20px/i);
assert.match(railCss, /\.home-right-rail\s*\{[^{}]*display:\s*block[^{}]*position:\s*sticky/si);
assert.doesNotMatch(railCss, /@media\s*\(min-width:\s*1050px\)[\s\S]*?\.home-right-rail\s*\{[^{}]*display:\s*block/si);

for (const href of ['/Anabelka/news', '/Anabelka/reviews', '/Anabelka/gift-certificates']) {
    assert.match(catalogView, new RegExp(`href=["']${href.replaceAll('/', '\\/')}["']`));
}
assert.match(catalogView, /catalog-utility-links/);

for (const token of ['public-header-top', 'public-header-logo', 'header-favorites', 'public-header-profile', 'header-cart', 'public-header-language']) {
    assert.match(publicHeader, new RegExp(token));
}
assert.doesNotMatch(publicHeader, /public-header-(?:news|reviews|certificate)/);
assert.doesNotMatch(publicHeader, /href="\/Anabelka\/(?:news|reviews|gift-certificates)"/);

for (const key of [
    'home.news_title',
    'home.all_news',
    'home.reviews_title',
    'home.all_reviews',
    'home.gift_title',
    'home.gift_text',
    'home.gift_more'
]) {
    assert.match(homeTranslations, new RegExp(key.replace('.', '\\.')));
}

assert.match(app, /Controllers\/GiftCertificateController\.php/);

process.stdout.write('home right rail contract passed\n');
