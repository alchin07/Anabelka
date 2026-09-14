import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');

function read(relativePath) {
    return fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');
}

function test(name, callback) {
    try {
        callback();
        process.stdout.write(`ok - ${name}\n`);
    } catch (error) {
        process.stderr.write(`not ok - ${name}\n`);
        throw error;
    }
}

test('catalog placeholder is hidden unless promoted to the authenticated admin action', function () {
    const script = read('js/favorites.js');
    const css = read('css/public-header-notifications.css');

    assert.match(css, /\.public-header-catalog\s*\{[^{}]*display:\s*none\s*!important/si);
    assert.match(script, /document\.querySelector\(['"]\.public-header-catalog['"]\)/);
    assert.match(script, /document\.querySelector\(['"]\.public-header-profile\s+\.public-header-admin['"]\)/);
    assert.match(script, /if\s*\(\s*!adminPopoverLink\s*\)\s*\{[\s\S]*?catalogAction\.remove\(\)/);
    assert.match(script, /catalogAction\.classList\.remove\(['"]public-header-catalog['"]\)/);
    assert.match(script, /catalogAction\.classList\.add\(['"]public-header-admin-action['"]\)/);
    assert.match(script, /catalogAction\.setAttribute\(['"]href['"],\s*adminPopoverLink\.getAttribute\(['"]href['"]\)\s*\|\|\s*['"]\/Anabelka\/admin['"]\)/);
});

test('promoted admin action keeps a single direct admin entry and removes the popover duplicate', function () {
    const script = read('js/favorites.js');

    assert.match(script, /catalogAction\.setAttribute\(['"]aria-label['"],\s*adminLabel\)/);
    assert.match(script, /catalogAction\.setAttribute\(['"]title['"],\s*adminLabel\)/);
    assert.match(script, /adminPopoverLink\.remove\(\)/);
});

test('admin action has independent message and system-error badges', function () {
    const script = read('js/favorites.js');

    assert.match(script, /public-header-admin-message-badge/);
    assert.match(script, /public-header-admin-system-badge/);
    assert.match(script, /admin-notification-count/);
    assert.match(script, /\/Anabelka\/admin\/system\/error-notifications/);
    assert.match(script, /systemBadge\.hidden\s*=\s*count\s*<=\s*0/);
});

test('admin badge colors preserve blue notifications and the established system-error color', function () {
    const css = read('css/public-header-notifications.css');

    assert.match(
        css,
        /\.public-header-admin-message-badge\s*\{[^{}]*background:\s*#2f80ed/si
    );
    assert.match(
        css,
        /\.public-header-admin-system-badge\s*\{[^{}]*background:\s*#b63e48/si
    );
});

test('system errors do not replace the regular admin notification badge', function () {
    const script = read('js/favorites.js');

    assert.doesNotMatch(script, /messageBadge\.textContent\s*=\s*formatHeaderCount\(count\)/);
    assert.match(script, /systemBadge\.textContent\s*=\s*formatHeaderCount\(count\)/);
});
