import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const script = read('js/admin-interface-translations.js');

test('interface translation editor loads one shared notification host and assets in order', () => {
    const editor = read('views/admin/translations/interface-edit.php');
    const destination = read('views/admin/translations/missing.php');
    assert.equal((editor.match(/id="site-message"/g) || []).length, 1);
    assert.match(editor, /class="site-message anabelka-notify"/);
    assert.match(editor, /aria-atomic="true"/);
    assert.match(editor, /css\/anabelka-notify\.css\?v=3/);
    assert.match(editor, /js\/anabelka-notify\.js\?v=3/);
    assert.match(editor, /js\/admin-interface-translations\.js\?v=4/);
    assert.ok(editor.indexOf('js/anabelka-notify.js') <
        editor.indexOf('js/admin-interface-translations.js'));
    assert.match(destination, /js\/anabelka-notify\.js\?v=3/);
    assert.match(destination, /id="site-message"/);
});

test('legacy alert and custom toast timer are removed but translation workflow stays intact', () => {
    assert.doesNotMatch(script, /window\.alert\s*\(/);
    assert.doesNotMatch(script, /interfaceTranslationMessageTimer/);
    assert.doesNotMatch(script, /message\.classList\.add\('show'\)/);
    assert.match(script, /notify\.store\(type, text\)/);
    assert.match(script, /notify\.show\(type, text\)/);
    assert.match(script, /showMessage\('success', data\.message \|\| 'Збережено\.', true\)/);
    assert.match(script, /showMessage\(\s*'info',\s*'ШІ-переклад отримано/);
    assert.match(script, /showMessage\(\s*'warning',\s*'Система ШІ-перекладу/);
    assert.match(script, /preservesPlaceholders\(/);
    assert.match(script, /setWorkflow\(section, 'ai', 'draft'\)/);
    assert.match(script, /new FormData\(form\)/);
    assert.match(script, /'X-Requested-With': 'XMLHttpRequest'/);
});

function browserHarness(response, {storeResult = true} = {}) {
    const handlers = {};
    const notifications = [];
    const timeouts = [];
    const requests = [];
    const saveButton = {disabled: false, textContent: 'Зберегти'};
    const form = {
        action: '/Anabelka/admin/translations/interface/save',
        dataset: {focusLanguage: ''},
        elements: {translation_key: {value: 'messages.saved'}},
        querySelector(query) {
            return query === '.interface-editor-save' ? saveButton : null;
        },
        addEventListener(name, callback) {handlers[name] = callback;}
    };
    const sourceField = {value: 'Збережено'};
    const document = {
        getElementById(id) {
            if (id === 'interface-translation-form') return form;
            if (id === 'interface-source-value') return sourceField;
            return null;
        },
        querySelectorAll() {return [];}
    };
    const window = {
        AnabelkaNotify: {
            show(type, message) {
                notifications.push(['show', type, message]);
                return true;
            },
            store(type, message) {
                notifications.push(['store', type, message]);
                return storeResult;
            }
        },
        setTimeout(callback, delay) {
            timeouts.push({callback, delay});
        },
        location: {href: ''}
    };
    class FormDataStub {
        constructor(target) {assert.equal(target, form);}
    }
    const fetch = async (url, options) => {
        requests.push({url, options});
        return response;
    };
    vm.runInNewContext(script, {document, window, fetch, FormData: FormDataStub},
        {filename: 'admin-interface-translations.js'});
    return {handlers, notifications, timeouts, requests, saveButton, window};
}

test('successful save stores one success for the redirect target and keeps original AJAX request', async () => {
    const h = browserHarness({
        ok: true,
        json: async () => ({
            success: true,
            message: 'Переклади інтерфейсу збережено.',
            return_url: '/Anabelka/admin/translations/missing?section=interface'
        })
    });
    let prevented = false;
    await h.handlers.submit({preventDefault() {prevented = true;}});
    assert.equal(prevented, true);
    assert.deepEqual(h.notifications, [['store', 'success', 'Переклади інтерфейсу збережено.']]);
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/Anabelka/admin/translations/interface/save');
    assert.equal(h.requests[0].options.method, 'POST');
    assert.equal(h.requests[0].options.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(h.saveButton.disabled, true);
    const redirect = h.timeouts.find(item => item.delay === 450);
    assert.ok(redirect);
    redirect.callback();
    assert.equal(h.window.location.href, '/Anabelka/admin/translations/missing?section=interface');
});

test('server validation and HTTP errors display error and re-enable Save without redirect', async () => {
    const h = browserHarness({
        ok: false,
        json: async () => ({success: false, message: 'Перевірте службові вставки.'})
    });
    await h.handlers.submit({preventDefault() {}});
    assert.deepEqual(h.notifications, [['show', 'error', 'Перевірте службові вставки.']]);
    assert.equal(h.saveButton.disabled, false);
    assert.equal(h.saveButton.textContent, 'Зберегти');
    assert.equal(h.timeouts.some(item => item.delay === 450), false);
});

test('disabled session storage falls back to direct success toast', async () => {
    const h = browserHarness({
        ok: true,
        json: async () => ({success: true, message: 'Збережено.'})
    }, {storeResult: false});
    await h.handlers.submit({preventDefault() {}});
    assert.deepEqual(h.notifications, [
        ['store', 'success', 'Збережено.'],
        ['show', 'success', 'Збережено.']
    ]);
});