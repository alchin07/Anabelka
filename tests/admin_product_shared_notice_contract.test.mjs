import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const code = fs.readFileSync('js/admin-products.js', 'utf8');
const view = fs.readFileSync('views/admin/products/index.php', 'utf8');
const css = fs.readFileSync('css/admin-products.css', 'utf8');

function fragment(startText, endText) {
    const start = code.indexOf(startText);
    const end = code.indexOf(endText, start + startText.length);
    assert.ok(start >= 0 && end > start, startText);
    return code.slice(start, end);
}

const messageSource = fragment(
    '    function showMessage(text, type)',
    '    const editorFieldErrors = new Map()'
);
const maskNoticeSource = fragment(
    '    function imageMaskNotice(type, message)',
    '    function liveImagePreview(preview)'
);

function makeNotices(available = true) {
    const sent = [];
    const window = {};
    if (available) {
        window.AnabelkaNotify = {
            show(level, text) {
                sent.push([level, text]);
                return true;
            }
        };
    }
    const helpers = vm.runInNewContext(
        '(function () {\n' + messageSource + '\n'
        + maskNoticeSource
        + '\nreturn {showMessage, imageMaskNotice};\n})()',
        {window}
    );
    return {window, sent, ...helpers};
}

test('the product editor uses only the shared notification queue for all severities', () => {
    const h = makeNotices();
    assert.equal(h.showMessage('Збережено.', 'success'), true);
    assert.equal(h.showMessage('Помилка.', 'error'), true);
    assert.equal(h.showMessage('Перевірте поля.', 'warning'), true);
    assert.equal(h.showMessage('Чернетка перекладу.', 'info'), true);
    assert.equal(h.showMessage('Неозначений тип.', 'unknown'), true);
    assert.equal(h.showMessage('Без типу.'), true);
    assert.deepEqual(h.sent, [
        ['success', 'Збережено.'],
        ['error', 'Помилка.'],
        ['warning', 'Перевірте поля.'],
        ['info', 'Чернетка перекладу.'],
        ['info', 'Неозначений тип.'],
        ['info', 'Без типу.']
    ]);
});

test('mask review uses the same queue exactly once without duplicate toasts', () => {
    const h = makeNotices();
    assert.equal(h.imageMaskNotice('error', 'Маска відхилена.'), true);
    assert.equal(h.imageMaskNotice('warning', 'Потрібна перевірка.'), true);
    assert.equal(h.imageMaskNotice('success', 'Обробку застосовано.'), true);
    assert.deepEqual(h.sent, [
        ['error', 'Маска відхилена.'],
        ['warning', 'Потрібна перевірка.'],
        ['success', 'Обробку застосовано.']
    ]);
});

test('without shared notifications product UI never starts a competing timer or alters the host', () => {
    const h = makeNotices(false);
    assert.equal(h.showMessage('Помилка.', 'error'), false);
    assert.equal(h.imageMaskNotice('warning', 'Перевірка.'), false);
    assert.deepEqual(h.sent, []);
    assert.doesNotMatch(messageSource,
        /setTimeout|clearTimeout|getElementById|classList|adminProductMessageTimer/);
    assert.doesNotMatch(maskNoticeSource,
        /setTimeout|clearTimeout|getElementById|classList|AnabelkaNotify/);
    assert.doesNotMatch(code, /adminProductMessageTimer/);
    assert.match(code, /return showMessage\(message, type\)/);
});

test('product saving, AI drafts, validation, CSRF and persistent errors remain separate', () => {
    assert.match(view, /admin-products\.js\?v=35/);
    assert.equal((view.match(/id="site-message"/g) || []).length, 1);
    assert.ok(view.indexOf('js/anabelka-notify.js') < view.indexOf('js/admin-products.js'));
    assert.match(view, /admin-product-legacy-flash/);
    assert.match(code, /AnabelkaNotify\.store\('success', savedMessage\)/);
    assert.match(code, /setTranslationWorkflow\(section, 'ai', 'draft'\)/);
    assert.match(code, /showEditorFieldError\(/);
    assert.match(code, /new FormData\(form\)/);
    assert.match(code, /window\.confirm\(/);
    assert.match(css, /\.admin-product-flash\.is-error/);
    assert.match(view, /admin-product-flash is-error" role="alert"/);
});

test('photo processing operations and Android Back handlers are still present', () => {
    assert.match(code, /function maskEditor\(\)/);
    assert.match(code, /function imageMaskNotice\(type, message\)/);
    assert.match(code, /imageMaskNotice\('error'/);
    assert.match(code, /function armProductEditorHistory\(\)/);
    assert.match(code, /function disarmProductEditorHistory\(\)/);
    assert.match(code, /function liveImagePreview\(preview\)/);
    assert.match(code, /productImagePreview|image-process-preview/);
});
