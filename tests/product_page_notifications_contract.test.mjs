import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = relative => fs.readFileSync(relative, 'utf8');
const variant = read('js/product-color-variants.js');
const view = read('views/product/show.php');
const i18n = read('views/partials/product-i18n.php');
const translator = read('app/Models/ProductInterfaceTranslator.php');
const header = read('views/partials/header.php');
const bootstrap = read('app/Core/NotificationBootstrap.php');

function extractNotifier(source, startNeedle, endNeedle, name, window) {
    const start = source.indexOf(startNeedle);
    assert.ok(start >= 0, name + ' definition exists');
    const end = source.indexOf(endNeedle, start + startNeedle.length);
    assert.ok(end > start, name + ' definition ends at expected boundary');
    return vm.runInNewContext(
        '(function () {\n' + source.slice(start, end) + '\nreturn ' + name + ';\n})()',
        {window}
    );
}

test('public product page has one shared accessible notification host and relies on bootstrap', () => {
    assert.match(view, /id="site-message"[\s\S]*?class="site-message anabelka-notify"/);
    assert.match(view, /role="status"[\s\S]*?aria-live="polite"[\s\S]*?aria-atomic="true"/);
    assert.equal((view.match(/id="site-message"/g) || []).length, 1);
    assert.match(bootstrap, /js\/anabelka-notify\.js/);
    assert.match(bootstrap, /css\/anabelka-notify\.css/);
    assert.match(header, /product-color-variants\.js\?v=6/);
});

test('both product cart notification helpers delegate severity and text to the central queue', () => {
    const received = [];
    const window = {
        AnabelkaNotify: {
            show(type, message) {
                received.push([type, message]);
                return true;
            }
        }
    };
    const variantNotice = extractNotifier(
        variant, '        function showMessage(text, type =',
        '        function currentProductSlug()', 'showMessage', window
    );
    const fallbackNotice = extractNotifier(
        view, '    function showSiteMessage(text, type =',
        '    </script>', 'showSiteMessage', window
    );

    assert.equal(variantNotice('Оберіть колір.', 'warning'), true);
    assert.equal(variantNotice('Недостатньо товару.'), true);
    assert.equal(variantNotice('Додано.', 'success'), true);
    assert.equal(fallbackNotice('Оберіть розмір.', 'warning'), true);
    assert.equal(fallbackNotice('Сервер не відповідає.'), true);
    assert.equal(fallbackNotice('Додано.', 'success'), true);
    assert.deepEqual(received, [
        ['warning', 'Оберіть колір.'],
        ['error', 'Недостатньо товару.'],
        ['success', 'Додано.'],
        ['warning', 'Оберіть розмір.'],
        ['error', 'Сервер не відповідає.'],
        ['success', 'Додано.']
    ]);

    delete window.AnabelkaNotify;
    assert.equal(variantNotice('Повідомлення.'), false);
    assert.equal(fallbackNotice('Повідомлення.'), false);
});

test('validation, server errors, and success use correct levels in both handlers', () => {
    assert.match(variant, /showMessage\(stockUi\.select_color \|\| 'Оберіть колір\.', 'warning'\)/);
    assert.match(variant, /showMessage\(stockUi\.select_size \|\| 'Оберіть хоча б один розмір\.', 'warning'\)/);
    assert.match(variant, /data\.message \|\| stockUi\.add_error/);
    assert.match(variant, /showMessage\(stockUi\.added \|\| '✓ Товар додано до кошика', 'success'\)/);
    assert.match(view, /cartMessages\.select_size \|\| 'Оберіть хоча б один розмір\.'/);
    assert.match(view, /cartMessages\.added \|\| '✓ Товар додано до кошика'/);
    assert.match(view, /cartMessages\.add_error/);
    assert.match(view, /showSiteMessage\([\s\S]*?'warning'\s*\)/);
    assert.match(view, /showSiteMessage\([\s\S]*?'success'\s*\)/);
    assert.match(view, /showSiteMessage\([\s\S]*?'error'\s*\)/);
});

test('messages use seeded UA, RU and EN translations, without changing stock or form behavior', () => {
    assert.match(i18n, /'select_color' => Translator::t\('product\.select_color'/);
    for (const translation of ['Оберіть колір.', 'Выберите цвет.', 'Choose a color.']) {
        assert.ok(translator.includes(translation), translation);
    }
    assert.match(variant, /event\.stopImmediatePropagation\(\)/);
    assert.match(variant, /cartForm\.addEventListener\('submit', submitColorCart, true\)/);
    assert.match(variant, /applyLegacyAvailability\(data\.availability\)/);
    assert.match(view, /applyLegacyAvailability\(\s*data\.availability\s*\)/);
    assert.match(view, /new FormData\(cartForm\)/);
    assert.match(view, /cartForm\.addEventListener\(/);
});

test('legacy browser alerts and custom message timers are absent in both handlers', () => {
    for (const source of [variant, view]) {
        assert.doesNotMatch(source, /\b(?:window\.)?alert\s*\(/);
        assert.doesNotMatch(source, /productColorVariantMessageTimer/);
        assert.doesNotMatch(source, /message\.classList\.add\('show'\)/);
        assert.doesNotMatch(source, /message\.classList\.remove\('show'\)/);
    }
    assert.doesNotMatch(view, /function showSiteMessage[\s\S]*?setTimeout/);
    assert.doesNotMatch(variant, /function showMessage\(text, type = 'error'\)[\s\S]*?clearTimeout/);
});
