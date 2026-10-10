import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function filePath(relativePath) {
    return path.join(projectRoot, relativePath);
}

function requireFile(relativePath) {
    assert.equal(
        fs.existsSync(filePath(relativePath)),
        true,
        `${relativePath} must exist`
    );
    return fs.readFileSync(filePath(relativePath), 'utf8');
}

const migration = requireFile('database/migrations/2026-09-16_home_content_modules.sql');
const news = requireFile('app/Models/SiteNews.php');
const newsTranslator = requireFile('app/Models/SiteNewsTranslator.php');
const review = requireFile('app/Models/ProductReview.php');
const app = requireFile('app/Core/App.php');
const adminAccess = requireFile('app/Models/AdminAccess.php');

for (const table of ['site_news', 'site_news_translations', 'product_reviews']) {
    assert.match(migration, new RegExp(`CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?${table}\\b`, 'i'));
}

assert.match(
    migration,
    /UNIQUE\s+KEY\s+uq_product_reviews_product_user\s*\(\s*product_id\s*,\s*user_id\s*\)/i
);
assert.match(migration, /FOREIGN\s+KEY\s*\(\s*product_id\s*\)[\s\S]*?REFERENCES\s+products\s*\(\s*id\s*\)/i);
assert.match(migration, /FOREIGN\s+KEY\s*\(\s*user_id\s*\)[\s\S]*?REFERENCES\s+users\s*\(\s*id\s*\)/i);
assert.doesNotMatch(migration, /ALTER\s+TABLE\s+(?:products|categories|orders)\b/i);

for (const method of [
    'latestPublished',
    'publishedPage',
    'findPublishedBySlug',
    'adminAll',
    'createDraft',
    'update',
    'setPublished',
    'delete'
]) {
    assert.match(news, new RegExp(`public\\s+static\\s+function\\s+${method}\\s*\\(`));
}
assert.match(news, /status\s*=\s*'published'/i);
assert.match(news, /published_at\s*<=\s*NOW\s*\(\s*\)/i);

for (const method of ['localize', 'localizeList', 'save']) {
    assert.match(newsTranslator, new RegExp(`public\\s+static\\s+function\\s+${method}\\s*\\(`));
}
assert.match(newsTranslator, /Language::SOURCE_CODE/);
assert.match(newsTranslator, /approved[\s\S]*outdated|outdated[\s\S]*approved/i);

for (const method of [
    'latestApprovedStandard',
    'approvedForProduct',
    'hasReview',
    'submit',
    'adminList',
    'moderate',
    'delete'
]) {
    assert.match(review, new RegExp(`public\\s+static\\s+function\\s+${method}\\s*\\(`));
}
assert.match(review, /Category::visibleCategoryIds\s*\(/);
assert.match(review, /Category::adultCategoryIds\s*\(/);
assert.match(review, /rating[\s\S]*1[\s\S]*5/i);
assert.match(review, /status[^;]*pending/i);
assert.match(review, /1500/);

for (const model of ['SiteNews.php', 'SiteNewsTranslator.php', 'ProductReview.php']) {
    assert.match(app, new RegExp(`Models\\/${model.replace('.', '\\.')}`));
}

for (const permission of ['news.view', 'news.manage', 'reviews.view', 'reviews.manage']) {
    assert.match(adminAccess, new RegExp(permission.replace('.', '\\.')));
}
assert.match(adminAccess, /['"]\/admin\/news['"]\s*=>\s*\[\s*['"]news\.view['"]\s*,\s*['"]news\.manage['"]\s*\]/);
assert.match(adminAccess, /['"]\/admin\/reviews['"]\s*=>\s*\[\s*['"]reviews\.view['"]\s*,\s*['"]reviews\.manage['"]\s*\]/);
assert.match(
    adminAccess,
    /['"]content_manager['"]\s*=>\s*\[[\s\S]*?['"]news\.view['"][\s\S]*?['"]news\.manage['"][\s\S]*?['"]reviews\.view['"][\s\S]*?['"]reviews\.manage['"]/i
);

process.stdout.write('home content models contract passed\n');
