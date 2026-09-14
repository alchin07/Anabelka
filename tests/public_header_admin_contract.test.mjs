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

test('public header has no visitor catalog shortcut and renders admin action only from server admin state', function () {
    const header = read('views/partials/header.php');

    assert.doesNotMatch(
        header,
        /href="\/Anabelka\/catalog"[\s\S]*?public-header-(?:catalog|admin-action)/
    );
    assert.match(
        header,
        /<\?php\s+if\s*\(\$currentAdmin\)\s*:\s*\?>[\s\S]*?href="\/Anabelka\/admin"[\s\S]*?class="public-header-action public-header-admin-action"[\s\S]*?<\?php\s+endif;\s*\?>/
    );

    const directAdminLinks = header.match(/href="\/Anabelka\/admin"/g) || [];
    assert.equal(directAdminLinks.length, 1, 'admin entry must exist exactly once');
});

test('admin action renders one priority badge, not two simultaneous badges', function () {
    const header = read('views/partials/header.php');

    assert.match(
        header,
        /class="public-header-count public-header-admin-count"[\s\S]*?id="admin-notification-count"[\s\S]*?\$adminNotificationCount/
    );
    assert.doesNotMatch(header, /public-header-admin-badges/);
    assert.doesNotMatch(header, /public-header-admin-message-badge/);
    assert.doesNotMatch(header, /public-header-admin-system-badge/);
    assert.doesNotMatch(header, /id="admin-system-error-count"/);
});

test('system errors temporarily override the regular admin count and restore it when clear', function () {
    const script = read('js/public-header-admin-badges.js');

    assert.match(script, /\/Anabelka\/admin\/system\/error-notifications/);
    assert.match(script, /getElementById\(['"]admin-notification-count['"]\)/);
    assert.match(script, /const\s+regularText\s*=\s*\(badge\.textContent\s*\|\|\s*['"]['"]\)\.trim\(\)/);
    assert.match(script, /const\s+regularHidden\s*=\s*badge\.hidden/);
    assert.match(
        script,
        /if\s*\(count\s*>\s*0\)\s*\{[\s\S]*?badge\.textContent\s*=\s*formatCount\(count\)[\s\S]*?badge\.hidden\s*=\s*false[\s\S]*?classList\.add\(['"]is-system-error['"]\)/
    );
    assert.match(
        script,
        /badge\.textContent\s*=\s*regularText[\s\S]*?badge\.hidden\s*=\s*regularHidden[\s\S]*?classList\.remove\(['"]is-system-error['"]\)/
    );
});

test('single admin badge restores the original compact size and approved colors', function () {
    const css = read('css/public-header-notifications.css');

    assert.match(
        css,
        /\.public-header-admin-count\s*\{[^{}]*min-width:\s*18px[^{}]*height:\s*18px[^{}]*padding:\s*0\s+4px[^{}]*background:\s*#2f80ed/si
    );
    assert.match(
        css,
        /\.public-header-admin-count\.is-system-error\s*\{[^{}]*background:\s*#b63e48[^{}]*color:\s*#fff/si
    );
    assert.doesNotMatch(css, /\.public-header-admin-badges\s*\{/);
    assert.doesNotMatch(css, /\.public-header-admin-badge\s*\{/);
});

test('favorites logic no longer controls admin-header visibility', function () {
    const script = read('js/favorites.js');

    assert.doesNotMatch(script, /initPublicAdminHeaderAction/);
    assert.doesNotMatch(script, /\.public-header-catalog/);
    assert.doesNotMatch(script, /admin-system-error-notifications/);
});

test('header cache-busts the restored single admin badge assets', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /css\/public-header-notifications\.css\?v=4/);
    assert.match(header, /js\/public-header-admin-badges\.js\?v=2/);
    assert.match(header, /js\/favorites\.js\?v=3/);
});
