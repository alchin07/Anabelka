import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = relativePath => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

const header = read('views/partials/header.php');
const adminBadges = read('js/public-header-admin-badges.js');

const topStart = header.indexOf('<div class="public-header-top">');
const searchStart = header.indexOf('<form\n                class="site-search-form"');
assert.ok(topStart >= 0, 'shared public-header-top row must exist');
assert.ok(searchStart > topStart, 'search must follow the shared top row');
const top = header.slice(topStart, searchStart);

const controls = [
    'public-header-logo',
    'header-favorites',
    'public-header-profile',
    'header-cart',
    'public-header-admin-action',
    'public-header-menu public-header-language'
].map(className => top.indexOf(className));

controls.forEach((position, index) => {
    assert.ok(position >= 0, `admin variant control ${index + 1} is missing`);
});
assert.deepEqual(
    controls,
    [...controls].sort((left, right) => left - right),
    'admin mobile order must be Logo → Favorites → Profile → Cart → Admin → Language'
);

assert.doesNotMatch(
    top,
    /href="\/Anabelka\/catalog"|public-header-catalog/,
    'Catalog must not occupy the approved admin icon slot'
);
assert.match(
    top,
    /<\?php\s+if\s*\(\$currentAdmin\)\s*:\s*\?>[\s\S]*?href="\/Anabelka\/admin"[\s\S]*?class="public-header-action public-header-admin public-header-admin-action"[\s\S]*?<\?php\s+endif;\s*\?>/,
    'Admin action must render only for an active admin session'
);
assert.match(top, /public-header-admin-message-badge/);
assert.match(top, /id="admin-system-error-count"/);

const profileStart = header.indexOf('<details class="public-header-menu public-header-profile">');
const profileEnd = header.indexOf('</details>', profileStart);
assert.ok(profileStart >= 0 && profileEnd > profileStart, 'profile menu must exist');
const profile = header.slice(profileStart, profileEnd);
assert.doesNotMatch(
    profile,
    /href="\/Anabelka\/admin"|public-header-admin-popover-link/,
    'Admin access must not be duplicated inside the profile popover'
);

assert.match(
    adminBadges,
    /document\.querySelector\(['"]\.public-header-admin-action['"]\)/,
    'admin badge updater must target the conditional top-row admin action'
);

process.stdout.write('final mobile header admin-action contract passed\n');
