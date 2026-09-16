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
    'public-header-catalog',
    'public-header-menu public-header-language'
].map(className => top.indexOf(className));

controls.forEach((position, index) => {
    assert.ok(position >= 0, `variant A control ${index + 1} is missing`);
});
assert.deepEqual(
    controls,
    [...controls].sort((left, right) => left - right),
    'mobile variant A order must be Logo → Favorites → Profile → Cart → Catalog → Language'
);
assert.match(
    top,
    /href="\/Anabelka\/catalog"[\s\S]*?class="public-header-action public-header-catalog"/,
    'Catalog must be a permanent top-row action for every visitor'
);
assert.doesNotMatch(
    top,
    /class="[^"]*public-header-admin-action[^"]*"/,
    'Admin must not replace Catalog in the six-control top row'
);

const profileStart = header.indexOf('<details class="public-header-menu public-header-profile">');
const profileEnd = header.indexOf('</details>', profileStart);
assert.ok(profileStart >= 0 && profileEnd > profileStart, 'profile menu must exist');
const profile = header.slice(profileStart, profileEnd);
assert.match(
    profile,
    /<\?php\s+if\s*\(\$currentAdmin\)\s*:\s*\?>[\s\S]*?href="\/Anabelka\/admin"[\s\S]*?class="public-header-admin-popover-link"/,
    'admin access must remain available from the profile popover for an active admin session'
);
assert.match(profile, /public-header-admin-message-badge/);
assert.match(profile, /id="admin-system-error-count"/);

assert.match(
    adminBadges,
    /document\.querySelector\(['"]\.public-header-admin-popover-link['"]\)/,
    'admin badge updater must target the profile-popover admin link first'
);

process.stdout.write('final mobile header variant A contract passed\n');
