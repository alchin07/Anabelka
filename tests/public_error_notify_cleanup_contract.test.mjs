import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const testDirectory = path.dirname(fileURLToPath(import.meta.url));
const projectRoot = path.resolve(testDirectory, '..');
const read = (relativePath) => fs.readFileSync(
    path.join(projectRoot, relativePath),
    'utf8'
);

test('warning uses the Anabelka violet palette', () => {
    const css = read('css/anabelka-notify.css');

    assert.match(css, /#f3e8ff/i);
    assert.match(css, /#6519b9/i);
    assert.match(css, /#8A2BE2/i);
    assert.doesNotMatch(css, /#fff7e6|#6d4c1f|#d4a047/i);
});

test('notification colors use theme tokens with a dark-theme override', () => {
    const css = read('css/anabelka-notify.css');

    assert.match(css, /--notify-success-bg\s*:/);
    assert.match(css, /--notify-error-bg\s*:/);
    assert.match(css, /--notify-info-bg\s*:/);
    assert.match(css, /--notify-warning-bg\s*:/);
    assert.match(css, /\[data-theme=["']dark["']\]/);
    assert.match(css, /background:\s*var\(--notify-success-bg\)/);
    assert.match(css, /background:\s*var\(--notify-error-bg\)/);
    assert.match(css, /background:\s*var\(--notify-info-bg\)/);
    assert.match(css, /background:\s*var\(--notify-warning-bg\)/);
    assert.match(css, /box-shadow:\s*var\(--notify-shadow\)/);
    assert.doesNotMatch(css, /\.site-message\.is-warning\s*\{[^}]*background:\s*#f3e8ff/is);
});

test('shared public and admin headers install one global notification host', () => {
    const publicHeader = read('views/partials/header.php');
    const adminHeader = read('views/admin/partials/header.php');
    const categoryView = read('views/admin/categories/index.php');
    const sharedPath = path.join(
        projectRoot,
        'views/partials/anabelka-notify-global.php'
    );

    assert.match(publicHeader, /anabelka-notify-global\.php/);
    assert.match(adminHeader, /anabelka-notify-global\.php/);
    assert.equal(fs.existsSync(sharedPath), true);

    const shared = fs.readFileSync(sharedPath, 'utf8');
    assert.match(shared, /id=["']site-message["']/);
    assert.match(shared, /anabelka-notify\.css\?v=/);
    assert.match(shared, /anabelka-notify\.js\?v=/);
    assert.match(shared, /aria-atomic=["']true["']/);

    assert.doesNotMatch(categoryView, /id=["']site-message["']/);
    assert.doesNotMatch(categoryView, /anabelka-notify\.js/);
    assert.doesNotMatch(categoryView, /anabelka-notify\.css/);
});

test('notification accessibility escalates errors and keeps other messages polite', () => {
    const js = read('js/anabelka-notify.js');

    assert.match(js, /setAttribute\(["']role["']/);
    assert.match(js, /setAttribute\(["']aria-live["']/);
    assert.match(js, /["']alert["']/);
    assert.match(js, /["']assertive["']/);
    assert.match(js, /["']status["']/);
    assert.match(js, /["']polite["']/);
});

test('category flash storage has no direct notification sessionStorage write', () => {
    const js = read('js/admin-categories.js');

    assert.match(js, /AnabelkaNotify\.store/);
    assert.doesNotMatch(
        js,
        /sessionStorage\.setItem\([\s\S]*anabelka-notify-flash/
    );
});

test('front controller installs the shared public error renderer', () => {
    const php = read('index.php');

    assert.match(php, /PublicErrorPage\.php/);
    assert.match(php, /PublicErrorPage::register/);
});

test('shared error renderer and view keep JSON 404s intact and offer navigation', () => {
    const renderer = read('app/Core/PublicErrorPage.php');
    const view = read('views/errors/public.php');

    assert.match(renderer, /http_response_code\(\) !== 404/);
    assert.match(renderer, /application\/json/);
    assert.match(view, /partials\/header\.php/);
    assert.match(view, /\/Anabelka\/catalog/);
    assert.match(view, /\/Anabelka\//);
    assert.match(view, /public-error\.css\?v=2/);
});

test('mobile public error card starts close to the header instead of vertical centering', () => {
    const css = read('css/public-error.css');
    const mobile = css.match(/@media\s*\(max-width:\s*430px\)\s*\{([\s\S]*)\}\s*$/i);

    assert.ok(mobile, 'mobile public-error media query is missing');
    assert.match(
        mobile[1],
        /\.public-error-page\s*\{[^}]*place-items:\s*start\s+center;/is
    );
    assert.match(
        mobile[1],
        /\.public-error-page\s*\{[^}]*padding:\s*(?:5\d|6\d|7[0-2])px\s+12px\s+(?:2\d|3\d)px;/is
    );
    assert.match(
        mobile[1],
        /\.public-error-card\s*\{[^}]*padding:\s*22px\s+16px;/is
    );
});
