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
});
