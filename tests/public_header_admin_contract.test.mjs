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

test('admin header action exposes independent notification and system-error badges', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /public-header-admin-message-badge/);
    assert.match(header, /public-header-admin-system-badge/);
    assert.match(
        header,
        /public-header-admin-message-badge[\s\S]*?\$adminNotificationCount/
    );
    assert.match(
        header,
        /id="admin-system-error-count"[\s\S]*?hidden/
    );
});

test('public header system-error script updates only the system badge', function () {
    const script = read('js/public-header-admin-badges.js');

    assert.match(script, /\/Anabelka\/admin\/system\/error-notifications/);
    assert.match(script, /getElementById\(['"]admin-system-error-count['"]\)/);
    assert.match(script, /systemBadge\.textContent\s*=\s*formatCount\(count\)/);
    assert.match(script, /systemBadge\.hidden\s*=\s*count\s*<=\s*0/);
    assert.doesNotMatch(script, /public-header-admin-message-badge[\s\S]*?textContent\s*=/);
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

test('favorites logic no longer controls admin-header visibility', function () {
    const script = read('js/favorites.js');

    assert.doesNotMatch(script, /initPublicAdminHeaderAction/);
    assert.doesNotMatch(script, /\.public-header-catalog/);
    assert.doesNotMatch(script, /admin-system-error-notifications/);
});

test('header cache-busts the restored admin badge assets', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /css\/public-header-notifications\.css\?v=3/);
    assert.match(header, /js\/public-header-admin-badges\.js\?v=1/);
    assert.match(header, /js\/favorites\.js\?v=3/);
});
