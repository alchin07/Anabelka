import assert from 'node:assert/strict';
import fs from 'node:fs';

const view = fs.readFileSync(
    'views/admin/categories/index.php',
    'utf8'
);
const categories = fs.readFileSync(
    'js/admin-categories.js',
    'utf8'
);
const categoryAi = fs.readFileSync(
    'js/admin-category-ai-translation.js',
    'utf8'
);
const header = fs.readFileSync(
    'views/admin/partials/header.php',
    'utf8'
);
const nav = fs.readFileSync(
    'js/admin-nav.js',
    'utf8'
);

assert.match(
    view,
    /name="translation_status\[<\?=\s*\$escape\(\$code\)\s*\?>\]"[\s\S]*?data-anabelka-select[\s\S]*?data-category-translation-status/
);
assert.match(
    categories,
    /statusField\.value\s*=\s*normalizedStatus;[\s\S]*?AnabelkaSelect\.sync\(statusField\)/
);
assert.match(
    categoryAi,
    /statusSelect\.setAttribute\(['"]data-anabelka-select['"],\s*['"]['"]\)/
);
assert.match(
    categoryAi,
    /AnabelkaSelect\.enhance\(statusSelect\)/
);
assert.match(
    categoryAi,
    /AnabelkaSelect\.refresh\(statusSelect\)/
);
assert.match(header, /anabelka-select\.js\?v=7/);
assert.match(header, /admin-nav\.js\?v=25/);
assert.match(nav, /admin-category-ai-translation\.js\?v=5/);
assert.match(view, /admin-categories\.js\?v=6/);

process.stdout.write(
    'category translation status branded-select contract passed\n'
);
