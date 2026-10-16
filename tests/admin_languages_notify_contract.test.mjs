import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import {spawnSync} from 'node:child_process';

const read = path => fs.readFileSync(path, 'utf8');
const controller = read('app/Controllers/AdminLanguageController.php');
const view = read('views/admin/languages/index.php');
const css = read('css/admin-languages.css');
const bootstrap = read('app/Core/NotificationBootstrap.php');

test('five mutations use the shared one-time success queue', () => {
    assert.match(controller, /private function flashSuccess\(\$message\)/);
    assert.match(controller, /AnabelkaFlash::success\(\(string\) \$message\)/);
    assert.equal((controller.match(/\$this->flashSuccess\(/g) || []).length, 5);
    assert.doesNotMatch(controller, /\$_SESSION\['language_message'\]\s*=/);
    for (const message of [
        'Мову додано.',
        'Назву мови збережено.',
        'Мову увімкнено.',
        'Мову вимкнено.',
        'Основну мову сайту змінено.',
        'Мову та її переклади видалено.'
    ]) {
        assert.ok(controller.includes(message), message);
    }
});

test('old success is migrated and old errors remain persistent, not an expiring toast', () => {
    assert.match(controller, /\$legacyMessage = \$_SESSION\['language_message'\]/);
    assert.match(controller, /AnabelkaFlash::success\(\$legacyMessage\)/);
    assert.match(controller, /unset\(\$_SESSION\['language_message'\], \$_SESSION\['language_error'\]\)/);
    assert.match(controller, /'error' => is_string\(\$error\) \? \$error : ''/);
    assert.equal((controller.match(/\$_SESSION\['language_error'\]\s*=/g) || []).length, 5);
    assert.doesNotMatch(controller, /AnabelkaFlash::error\(/);
    assert.match(view, /languages-notice error" role="alert"/);
    assert.doesNotMatch(view, /if \(!empty\(\$message\)\)/);
    assert.match(bootstrap, /js\/anabelka-notify\.js/);
});

test('language forms and destructive confirmation stay untouched', () => {
    assert.match(view, /\/Anabelka\/admin\/languages\/create/);
    assert.match(view, /\/Anabelka\/admin\/languages\/update/);
    assert.match(view, /\/Anabelka\/admin\/languages\/toggle/);
    assert.match(view, /\/Anabelka\/admin\/languages\/default/);
    assert.match(view, /\/Anabelka\/admin\/languages\/delete/);
    assert.match(view, /return confirm\('Видалити цю мову/);
    assert.match(controller, /Language::createFromCatalog/);
    assert.match(controller, /Language::updateName/);
    assert.match(controller, /Language::setActive/);
    assert.match(controller, /Language::setDefault/);
    assert.match(controller, /Language::delete/);
    assert.match(controller, /Translator::deleteForLanguage/);
});

test('permanent language errors use branded purple rather than obsolete red', () => {
    assert.match(view, /admin-languages\.css\?v=3/);
    assert.match(css, /\.languages-notice\.error/);
    assert.match(css, /border-color: var\(--notify-info-border, #8A2BE2\)|border:\s*1px solid var\(--notify-info-border, #8A2BE2\)/);
    assert.match(css, /background:\s*var\(--notify-info-bg, #f4eaff\)/);
    assert.match(css, /color:\s*var\(--notify-info-text, #6519b9\)/);
    assert.doesNotMatch(css, /#fff0f2|#9b1c31/);
});

test('PHP runtime checks redirect flashes, error visibility, compatibility and safe HTML', () => {
    const run = spawnSync(process.env.PHP_BIN || 'php', [
        '-n', 'tests/admin_languages_notify_runtime.php'
    ], {encoding: 'utf8'});
    assert.equal(run.error, undefined);
    assert.equal(run.status, 0, run.stderr || run.stdout);
    assert.match(run.stdout, /admin languages notification runtime passed/);
});
