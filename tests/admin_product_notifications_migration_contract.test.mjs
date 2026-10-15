import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const picker = read('js/admin-product-color-picker.js');
const editor = read('js/admin-products.js');

test('product color picker relies on the central notification queue and never runs its own timer', () => {
    assert.match(picker, /function showMessage\(text, type = 'info'\)/);
    assert.match(picker, /notify\.show\(type, text\)/);
    assert.doesNotMatch(picker, /adminProductColorMessageTimer|message\.classList\.add\('show'\)/);
    assert.doesNotMatch(picker, /document\.getElementById\('site-message'\)/);
    const first = picker.indexOf("    function showMessage(text, type = 'info')");
    const last = picker.indexOf('    function updatePresetSelection(hex)', first);
    assert.ok(first >= 0 && last > first);
    const fragment = picker.slice(first, last);
    const calls = [];
    const context = {
        window: {AnabelkaNotify: {
            show(type, message) {calls.push([type, message]); return true;}
        }}
    };
    const showMessage = vm.runInNewContext('(function () {\n' + fragment +
        '\nreturn showMessage;\n})()', context);
    assert.equal(showMessage('Очікуйте.', 'warning'), true);
    assert.equal(showMessage('Збережено.', 'success'), true);
    assert.equal(showMessage('Інформація.'), true);
    assert.deepEqual(calls, [
        ['warning', 'Очікуйте.'],
        ['success', 'Збережено.'],
        ['info', 'Інформація.']
    ]);
    delete context.window.AnabelkaNotify;
    assert.equal(showMessage('Повідомлення.', 'error'), false);
});

test('photo color sampling distinguishes warnings, errors and an unsaved choice', () => {
    assert.match(picker, /Фотографія ще завантажується\. Спробуйте ще раз\.', 'warning'\)/);
    assert.match(picker, /Не вдалося визначити колір на цьому телефоні\.', 'error'\)/);
    assert.match(picker, /З цього фото не вдалося взяти колір\. Оберіть готовий\.', 'error'\)/);
    assert.match(picker, /Оберіть непрозору ділянку фотографії\.', 'warning'\)/);
    assert.match(picker, /Не вдалося відкрити фотографію для вибору кольору\.', 'error'\)/);
    assert.match(picker, /Колір обрано з фотографії\. Збережіть товар після підтвердження кольору\.', 'info'\)/);
    assert.match(picker, /Виберіть готовий відтінок або «Інший відтінок»\.',\s*'info'/);
    assert.match(picker, /samplePhotoColor\(event\)/);
    assert.match(picker, /savePickerToGroup\(name, picker\.systemInput\.value\)/);
});

test('AI product translation shows an unsaved draft rather than a save success', () => {
    assert.match(editor, /setTranslationWorkflow\(section, 'ai', 'draft'\)/);
    assert.match(editor, /ШІ-переклад отримано\. Перевірте його перед збереженням\.', 'info'/);
    assert.match(editor, /Система ШІ-перекладу ще завантажується\. Спробуйте ще раз\.', 'warning'/);
    assert.match(editor, /Не вдалося отримати ШІ-переклад\.', 'error'/);
    assert.match(editor, /AnabelkaNotify\.store\('success', savedMessage\)/);
    assert.match(editor, /new FormData\(form\)/);
});

test('product list shows persistent failures in branded colors, not old red/green', () => {
    const css = read('css/admin-products.css');
    const html = read('views/admin/products/index.php');
    assert.match(css, /\.admin-product-flash\.is-error\s*\{[^}]*background: var\(--notify-info-bg, #f4eaff\)/s);
    assert.match(css, /\.admin-product-flash\.is-error\s*\{[^}]*color: var\(--notify-info-text, #6519b9\)/s);
    assert.match(css, /\.admin-product-flash\.is-success\s*\{[^}]*background: var\(--notify-success-bg, #8A2BE2\)/s);
    for (const oldColor of ['#e8bac3', '#fff5f6', '#913748', '#b9decf', '#f1faf6', '#2f6954']) {
        assert.ok(!css.includes(oldColor), oldColor + ' legacy notification color');
    }
    assert.match(html, /productsError !== ''/);
    assert.match(html, /admin-product-flash is-error" role="alert"/);
    assert.equal((html.match(/id="site-message"/g) || []).length, 1);
    assert.match(html, /css\/anabelka-notify\.css\?v=4/);
    assert.match(html, /js\/anabelka-notify\.js\?v=3/);
    assert.match(html, /css\/admin-products\.css\?v=21/);
    assert.match(html, /js\/admin-products\.js\?v=34/);
    assert.match(html, /js\/admin-product-color-picker\.js\?v=9/);
});
