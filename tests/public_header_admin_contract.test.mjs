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

test('public header exposes Admin only in the top row for an active admin session', function () {
    const header = read('views/partials/header.php');
    const topStart = header.indexOf('<div class="public-header-top">');
    const searchStart = header.indexOf('<form\n                class="site-search-form"');

    assert.ok(topStart >= 0 && searchStart > topStart);
    const top = header.slice(topStart, searchStart);

    assert.doesNotMatch(top, /href="\/Anabelka\/catalog"/);
    assert.doesNotMatch(top, /public-header-catalog/);
    assert.match(
        top,
        /<\?php\s+if\s*\(\$currentAdmin\)\s*:\s*\?>[\s\S]*?href="\/Anabelka\/admin"[\s\S]*?class="public-header-action public-header-admin public-header-admin-action"[\s\S]*?<\?php\s+endif;\s*\?>/
    );

    const directAdminLinks = header.match(/href="\/Anabelka\/admin"/g) || [];
    assert.equal(directAdminLinks.length, 1, 'admin entry must exist exactly once');
    assert.doesNotMatch(header, /public-header-admin-popover-link/);
});

test('admin top-row action keeps regular and system counters mutually exclusive', function () {
    const header = read('views/partials/header.php');
    const script = read('js/public-header-admin-badges.js');

    assert.match(header, /public-header-admin-message-badge[\s\S]*?\$adminNotificationCount/);
    assert.match(header, /public-header-admin-system-badge[\s\S]*?id="admin-system-error-count"/);
    assert.match(script, /querySelector\(['"]\.public-header-admin-action['"]\)/);
    assert.doesNotMatch(script, /public-header-admin-popover-link/);
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

test('admin badge keeps the established compact counter geometry', function () {
    const baseCss = read('css/public-header.css');
    const notificationCss = read('css/public-header-notifications.css');

    assert.match(
        baseCss,
        /\.public-header-count\s*\{[^{}]*position:\s*absolute[^{}]*top:\s*0[^{}]*right:\s*0[^{}]*min-width:\s*18px[^{}]*height:\s*18px[^{}]*padding:\s*0\s+4px[^{}]*border:\s*2px\s+solid\s+#fff[^{}]*font-size:\s*10px/si
    );
    assert.match(
        notificationCss,
        /\.public-header-admin-action\s*\{[^{}]*position:\s*relative[^{}]*overflow:\s*visible/si
    );
    assert.match(
        notificationCss,
        /\.public-header-admin-badges\s*\{[^{}]*display:\s*contents[^{}]*pointer-events:\s*none/si
    );
    assert.match(
        notificationCss,
        /\.public-header-admin-badge\s*\{[^{}]*position:\s*absolute[^{}]*top:\s*0[^{}]*right:\s*0[^{}]*min-width:\s*18px[^{}]*height:\s*18px[^{}]*padding:\s*0\s+4px[^{}]*border:\s*2px\s+solid\s+#fff[^{}]*font-size:\s*10px/si
    );
});

test('admin notification badge uses the site primary color and system errors keep their established color', function () {
    const css = read('css/public-header-notifications.css');

    assert.match(
        css,
        /\.public-header-admin-message-badge\s*\{[^{}]*background:\s*var\(--primary-color,\s*#8a2be2\)/si
    );
    assert.match(
        css,
        /\.public-header-admin-system-badge\s*\{[^{}]*background:\s*#b63e48/si
    );
});

test('header cache-busts the admin badge stylesheet after top-row restoration', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /css\/public-header-notifications\.css\?v=6/);
});

test('header cache-busts the admin badge script after top-row restoration', function () {
    const header = read('views/partials/header.php');

    assert.match(header, /js\/public-header-admin-badges\.js\?v=4/);
});

test('favorites logic no longer controls admin-header visibility', function () {
    const script = read('js/favorites.js');

    assert.doesNotMatch(script, /initPublicAdminHeaderAction/);
    assert.doesNotMatch(script, /\.public-header-catalog/);
    assert.doesNotMatch(script, /admin-system-error-notifications/);
});


test('admin action uses the restored staggered dashboard icon, not the old equal-square grid', function () {
    const header = read('views/partials/header.php');
    const icon = read('views/partials/admin-panel-icon.php');

    assert.match(
        header,
        /require __DIR__ \. '\/admin-panel-icon\.php';/
    );
    assert.match(icon, /height="10\.2"/);
    assert.match(icon, /height="5\.2"/);
    assert.match(icon, /width="8"/);
    assert.doesNotMatch(
        icon,
        /x="4" y="4" width="6" height="6" rx="1\.2"/
    );
});
