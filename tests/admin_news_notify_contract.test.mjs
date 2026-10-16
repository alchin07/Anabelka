import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {spawnSync} from 'node:child_process';

const read = path => fs.readFileSync(path, 'utf8');
const controller = read('app/Controllers/AdminNewsController.php');
const view = read('views/admin/news/index.php');
const css = read('css/admin-news.css');
const bootstrap = read('app/Core/NotificationBootstrap.php');
const entrypoint = read('index.php');

test('news success goes through AnabelkaFlash rather than the old session notice', () => {
    assert.match(controller, /private function flash\(\$type, \$message\)/);
    assert.match(controller, /if \(\(string\) \$type === 'success'\)/);
    assert.match(controller, /AnabelkaFlash::success\(\(string\) \$message\)/);
    assert.match(controller, /unset\(\$_SESSION\['admin_news_flash'\]\)/);
    for (const text of [
        'Чернетку новини створено.',
        'Новину та переклади збережено.',
        'Новину опубліковано.',
        'Новину повернуто в чернетки.',
        'Новину видалено.'
    ]) {
        assert.ok(controller.includes(text), text);
    }
});

test('validation/CSRF errors still appear persistently and existing POST security remains', () => {
    assert.match(controller, /\$this->flash\('error', 'Сесію форми завершено\. Оновіть сторінку\.'/);
    assert.match(controller, /AdminAccess::verifyCsrf/);
    assert.match(controller, /AdminAccess::audit\('news\.(?:create|update|publish|delete)'/);
    assert.match(controller, /\$_SESSION\['admin_news_flash'\] = \[/);
    assert.match(controller, /Location: \/Anabelka\/admin\/news/);
    assert.match(view, /if \(\$flash && \(\$flash\['type'\] \?\? ''\) === 'error'\)/);
    assert.match(view, /admin-news-flash is-error" role="alert"/);
    assert.match(view, /admin-news-warning" role="alert"/);
    assert.match(view, /\$loadError !== ''/);
    assert.doesNotMatch(view, /admin-news-flash is-success/);
});

test('existing pending legacy successes migrate exactly once on first page load', () => {
    assert.match(controller, /if \(\(\$flash\['type'\] \?\? ''\) === 'success'\)/);
    assert.match(controller, /AnabelkaFlash::success\(\(string\) \(\$flash\['message'\] \?\? ''\)\)/);
    assert.match(controller, /\$flash = null/);
});

test('news screen retains CSRF, translations and delete confirmation', () => {
    assert.match(view, /action="\/Anabelka\/admin\/news\/create"/);
    assert.match(view, /action="\/Anabelka\/admin\/news\/update"/);
    assert.match(view, /action="\/Anabelka\/admin\/news\/publish"/);
    assert.match(view, /action="\/Anabelka\/admin\/news\/delete"/);
    assert.match(view, /name="_csrf"/);
    assert.match(view, /translations\[/);
    assert.match(view, /onsubmit="return confirm\('Видалити цю новину\?'\);"/);
    assert.match(view, /admin-news\.css\?v=2/);
});

test('success uses branded queue; persistent errors keep their existing visible styling', () => {
    assert.match(bootstrap, /js\/anabelka-notify\.js/);
    assert.match(bootstrap, /site-message.*anabelka-notify/);
    assert.match(entrypoint, /NotificationBootstrap::register\(\)/);
    assert.match(css, /\.admin-news-flash\.is-error/);
    assert.match(css, /\.admin-news-warning/);
    assert.doesNotMatch(css, /\.admin-news-flash\.is-success/);
    assert.doesNotMatch(css, /#f2fbf5|#b9ddc4/);
});

test('PHP runtime verifies redirect flashes, deduplication, persistent errors and safe HTML', () => {
    const result = spawnSync(process.env.PHP_BIN || 'php', [
        '-n', 'tests/admin_news_notify_runtime.php'
    ], {encoding: 'utf8'});
    assert.equal(result.error, undefined);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.match(result.stdout, /admin news notification runtime passed/);
});
