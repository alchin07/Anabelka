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

test('admin action keeps regular and system counters mutually exclusive', function () {
    const header = read('views/partials/header.php');
    const script = read('js/public-header-admin-badges.js');

    assert.match(header, /public-header-admin-message-badge[\s\S]*?\$adminNotificationCount/);
    assert.match(header, /public-header-admin-system-badge[\s\S]*?id="admin-system-error-count"/);
    assert.match(script, /const\s+regularHidden\s*=\s*messageBadge\.hidden/);
    assert.match(
        script,
        /if\s*\(count\s*>\s*0\)\s*\{[\s\S]*?messageBadge\.hidden\s*=\s*true[\s\S]*?systemBadge\.textContent\s*=\s*formatCount\(count\)[\s\S]*?systemBadge\.hidden\s*=\s*false/
    );
    assert.match(
        script,
        /systemBadge\.hidden\s*=\s*true[\s\S]*?messageBadge\.hidden\s*=\s*regularHidden/
    );
});

test('system-error check preserves the regular admin count when errors are absent or unavailable', function () {
    const script = read('js/public-header-admin-badges.js');

    assert.match(script, /\/Anabelka\/admin\/system\/error-notifications/);
    assert.match(script, /const\s+regularText\s*=\s*\(messageBadge\.textContent\s*\|\|\s*['"]['"]\)\.trim\(\)/);
    assert.match(script, /messageBadge\.textContent\s*=\s*regularText/);
    assert.match(script, /catch\s*\(function\s*\(\)\s*\{[\s\S]*?systemBadge\.hidden\s*=\s*true[\s\S]*?messageBadge\.hidden\s*=\s*regularHidden/);
});

test('admin badge reuses the cart badge geometry and placement exactly', function () {
    const header = read('views/partials/header.php');
    const baseCss = read('css/public-header.css');
    const notificationCss = read('css/public-header-notifications.css');

    assert.match(
        header,
        /class="public-header-count public-header-admin-count public-header-admin-message-badge"/
    );
    assert.match(
        header,
        /class="public-header-count public-header-admin-count public-header-admin-system-badge"/
    );
    assert.doesNotMatch(header, /class="public-header-admin-badges"/);

    assert.match(
        baseCss,
        /\.public-header-count\s*\{[^{}]*position:\s*absolute[^{}]*top:\s*0[^{}]*right:\s*0[^{}]*min-width:\s*18px[^{}]*height:\s*18px[^{}]*padding:\s*0\s+4px[^{}]*border:\s*2px\s+solid\s+#fff[^{}]*font-size:\s*10px/si
    );
    assert.doesNotMatch(
        notificationCss,
        /\.public-header-admin-(?:badge|badges)\s*\{[^{}]*(?:position|top|right|min-width|width|height|padding|font-size)\s*:/si
    );
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

test('header cache-busts restored badge CSS and priority script', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /css\/public-header-notifications\.css\?v=4/);
    assert.match(header, /js\/public-header-admin-badges\.js\?v=2/);
});

test('favorites logic no longer controls admin-header visibility', function () {
    const script = read('js/favorites.js');

    assert.doesNotMatch(script, /initPublicAdminHeaderAction/);
    assert.doesNotMatch(script, /\.public-header-catalog/);
    assert.doesNotMatch(script, /admin-system-error-notifications/);
});
