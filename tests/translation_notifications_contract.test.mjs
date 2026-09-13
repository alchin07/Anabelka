import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const read = (path) => readFileSync(new URL('../' + path, import.meta.url), 'utf8');
const flashKey = 'anabelka.notifications.flash.v1';
const defaultReturn = '/Anabelka/admin/translations/missing?section=interface';

// Only browser/network boundaries are simulated. Every test runs the production
// editor/provider script together with the real AnabelkaNotify implementation.
function runtime(storage = new Map()) {
    const timers = new Map();
    const alerts = [];
    const navigations = [];
    const requests = [];
    let nextTimer = 0;
    let href = '';
    let reply = async () => ({success: true});
    let fetchError = null;
    let responseOk = true;
    const document = {};

    class Element {
        constructor(tag = 'div') {
            Object.assign(this, {tag, children: [], dataset: {}, attributes: {},
                listeners: {}, value: '', disabled: false, hidden: false, parentNode: null});
            const names = new Set();
            this.classList = {
                add: (...items) => items.forEach((item) => names.add(item)),
                remove: (...items) => items.forEach((item) => names.delete(item)),
                contains: (item) => names.has(item),
                toggle: (item, enabled = !names.has(item)) => {
                    enabled ? names.add(item) : names.delete(item);
                    return enabled;
                }
            };
            Object.defineProperty(this, 'className', {
                get: () => [...names].join(' '),
                set: (value) => {names.clear(); String(value).split(/\s+/).filter(Boolean)
                    .forEach((item) => names.add(item));}
            });
        }
        set textContent(value) {this.text = String(value); this.children = [];}
        get textContent() {return (this.text || '') + this.children.map((x) => x.textContent).join('');}
        set innerHTML(value) {assert.equal(value, ''); this.textContent = '';}
        setAttribute(name, value) {this.attributes[name] = String(value);}
        getAttribute(name) {return this.attributes[name] ?? null;}
        appendChild(child) {child.parentNode = this; this.children.push(child); return child;}
        remove() {this.parentNode.children = this.parentNode.children.filter((x) => x !== this);}
        addEventListener(name, listener) {(this.listeners[name] ||= []).push(listener);}
        async fire(name) {
            for (const listener of this.listeners[name] || []) {
                await listener.call(this, {currentTarget: this, preventDefault() {}});
            }
        }
        matches(selector) {
            if (selector.startsWith('.')) return this.classList.contains(selector.slice(1));
            if (selector.startsWith('#')) return this.id === selector.slice(1);
            const data = selector.match(/^\[data-([\w-]+)\]$/);
            if (data) return Object.hasOwn(this.dataset,
                data[1].replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()));
            return this.tag === selector;
        }
        querySelectorAll(selector) {
            const children = this.children.flatMap((child) => [child, ...child.querySelectorAll('*')]);
            return selector === '*' ? children : children.filter((child) => child.matches(selector));
        }
        querySelector(selector) {return this.querySelectorAll(selector)[0] || null;}
        closest(selector) {return this.matches(selector) ? this : this.parentNode?.closest(selector) || null;}
        focus() {document.activeElement = this;}
        select() {this.selectedText = true;}
        scrollIntoView() {this.scrolled = true;}
    }
    document.body = new Element('body');
    document.activeElement = document.body;
    document.createElement = (tag) => new Element(tag);
    document.querySelectorAll = (selector) => document.body.querySelectorAll(selector);
    document.getElementById = (id) => document.body.querySelector('#' + id);
    const node = (tag, properties = {}, parent = document.body) => {
        return parent.appendChild(Object.assign(new Element(tag), properties));
    };
    const container = node('div', {id: 'anabelka-notifications'});
    const location = {
        get href() {return href;},
        set href(value) {href = value; navigations.push({url: value, flash: storage.get(flashKey)});}
    };
    const window = {
        location,
        sessionStorage: {
            getItem: (key) => storage.get(key) ?? null,
            setItem: (key, value) => storage.set(key, String(value)),
            removeItem: (key) => storage.delete(key)
        },
        setTimeout: (fn, delay) => {const id = ++nextTimer; timers.set(id, {fn, delay}); return id;},
        clearTimeout: (id) => timers.delete(id),
        alert: (message) => alerts.push(message)
    };
    class BrowserFormData extends Map {
        constructor(form) {
            super();
            for (const field of form?.querySelectorAll('*') || []) {
                if (field.name && !field.disabled) this.set(field.name, field.value);
            }
        }
        append(name, value) {this.set(name, value);}
    }
    const context = vm.createContext({window, document, Error, FormData: BrowserFormData,
        fetch: async (url, options) => {
            requests.push({url, options});
            if (fetchError) throw fetchError;
            return {ok: responseOk, json: async () => reply(url, options)};
        }});
    const load = (file) => vm.runInContext(read(file), context, {filename: file});
    load('js/anabelka-notifications.js');
    const notice = () => {
        const element = container.children[0];
        assert.ok(element, 'expected a shared notification, not alert() or a local message');
        return {type: element.className.split('anabelka-notification--')[1],
            message: element.querySelector('.anabelka-notification__message').textContent};
    };
    return {window, document, storage, node, load, notice, container, alerts, timers,
        navigations, requests, setReply: (fn) => {reply = fn;},
        setFetchError: (error) => {fetchError = error;},
        setHttpOk: (ok) => {responseOk = ok;}};
}

function editor() {
    const env = runtime();
    const {node} = env;
    const form = node('form', {id: 'interface-translation-form',
        action: '/Anabelka/admin/translations/interface/save', dataset: {focusLanguage: 'ru'}});
    const source = node('textarea', {id: 'interface-source-value', name: 'source_value',
        value: 'Вітаємо, {name}!'}, form);
    const key = node('input', {name: 'translation_key', value: 'header.welcome'}, form);
    node('input', {name: 'return_url', value: defaultReturn}, form);
    form.elements = {translation_key: key};
    const section = node('section', {dataset: {interfaceLanguage: 'ru'}}, form);
    const field = node('textarea', {className: 'interface-translation-value',
        name: 'translation_value[ru]', value: 'Привет, {name}!'}, section);
    const origin = node('input', {className: 'translation-workflow-source',
        name: 'translation_source[ru]', value: 'manual'}, section);
    node('span', {dataset: {translationSourceLabel: ''}}, section);
    const status = node('select', {dataset: {translationStatus: ''},
        name: 'translation_status[ru]', value: 'approved'}, section);
    const ai = node('button', {dataset: {interfaceAiTranslate: '', targetLanguage: 'ru'},
        textContent: 'Перекласти через ШІ'}, section);
    const save = node('button', {className: 'interface-editor-save', textContent: 'Зберегти'}, form);
    env.load('js/admin-interface-translations.js');
    return {...env, form, source, section, field, origin, status, ai, save};
}

// Regression target: reintroducing a private toast or alert in either caller.
test('translation callers and editor markup have no legacy notification path', () => {
    for (const path of ['js/admin-interface-translations.js', 'js/admin-ai-translation.js',
        'views/admin/translations/interface-edit.php']) {
        assert.doesNotMatch(read(path), /site-message|\bshowMessage\b|\balert\s*\(/, path);
    }
});

// Regression target: shipping new callers behind an unchanged cached loader.
test('all changed caller and loader URLs have new cache versions', () => {
    assert.match(read('views/admin/translations/interface-edit.php'), /admin-interface-translations\.js\?v=4/);
    assert.match(read('js/admin-nav.js'), /admin-ai-translation\.js\?v=7/);
    assert.match(read('js/admin-nav.js'), /admin-delivery-ai-translation\.js\?v=4/);
    assert.match(read('views/admin/partials/header.php'), /admin-nav\.js\?v=19/);
});

test('save stores success before immediate navigation and keeps the filtered return URL', async () => {
    const env = editor();
    const returnUrl = defaultReturn + '&language=ru&translation_key=header.welcome&source_text=%D0%92';
    env.setReply(async () => ({success: true, message: 'Переклади збережено.', return_url: returnUrl}));
    await env.form.fire('submit');
    assert.equal(env.navigations.length, 1, 'navigation must not wait for a toast timer');
    assert.equal(env.navigations[0].url, returnUrl);
    const payload = JSON.parse(env.navigations[0].flash);
    assert.equal(payload.items.length, 1);
    assert.equal(payload.items[0].type, 'success');
    assert.equal(payload.items[0].message, 'Переклади збережено.');
    assert.equal(env.container.children.length, 0, 'success belongs on the destination page');
    assert.equal(env.alerts.length, 0);
    const request = env.requests[0];
    assert.equal(request.options.method, 'POST');
    assert.equal(request.options.body.get('translation_value[ru]'), env.field.value);
    assert.equal(request.options.body.get('translation_status[ru]'), 'approved');
});

test('save flash is displayed by the destination exactly once across two page loads', async () => {
    const env = editor();
    env.setReply(async () => ({success: true, message: 'Збережено.'}));
    await env.form.fire('submit');
    const destination = runtime(env.storage);
    assert.deepEqual(destination.notice(), {type: 'success', message: 'Збережено.'});
    assert.equal(env.storage.has(flashKey), false);
    assert.equal(runtime(env.storage).container.children.length, 0);
});

test('successful save retains the existing default return URL and fallback copy', async () => {
    const env = editor();
    await env.form.fire('submit');
    assert.equal(env.window.location.href, defaultReturn);
    assert.equal(JSON.parse(env.storage.get(flashKey)).items[0].message, 'Збережено.');
});

for (const failure of ['rejected save', 'HTTP error', 'invalid JSON', 'network failure']) {
    test(`${failure} shows an immediate error and preserves editable input`, async () => {
        const env = editor();
        env.field.value = 'Несохранённый текст {name}';
        env.setReply(async () => {
            if (failure === 'invalid JSON') throw new SyntaxError('Unexpected token in JSON');
            return {success: failure === 'HTTP error', message: 'Не вдалося зберегти переклади.'};
        });
        if (failure === 'network failure') env.setFetchError(new Error('Network unavailable'));
        if (failure === 'HTTP error') env.setHttpOk(false);
        await env.form.fire('submit');
        assert.equal(env.notice().type, 'error');
        assert.equal(env.field.value, 'Несохранённый текст {name}');
        assert.equal(env.save.disabled, false);
        assert.equal(env.save.textContent, 'Зберегти');
        assert.equal(env.navigations.length, 0);
        assert.equal(env.storage.has(flashKey), false);
        assert.equal(env.document.activeElement, env.document.body);
        assert.equal(env.alerts.length, 0);
    });
}

test('unavailable AI is informational and does not touch the translation', async () => {
    const env = editor();
    await env.ai.fire('click');
    assert.equal(env.notice().type, 'info');
    assert.equal(env.field.value, 'Привет, {name}!');
    assert.equal(env.status.value, 'approved');
    assert.equal(env.requests.length, 0);
});

test('AI suggestion stays an unsaved draft with an informational notification', async () => {
    const env = editor();
    env.window.AnabelkaAITranslation = {suggest: async () => ({name: 'Здравствуйте, {name}!'})};
    await env.ai.fire('click');
    assert.equal(env.field.value, 'Здравствуйте, {name}!');
    assert.equal(env.origin.value, 'ai');
    assert.equal(env.status.value, 'draft');
    assert.equal(env.notice().type, 'info');
    assert.match(env.notice().message, /«Зберегти»/);
    assert.equal(env.requests.length, 0, 'receiving AI text must not persist it');
    assert.equal(env.navigations.length, 0);
    assert.equal(env.ai.disabled, false);
    assert.equal(env.storage.has(flashKey), false);
});

for (const name of ['', 'Привет без вставки', 'Привет, {other}!']) {
    test(`empty or changed-placeholder AI output is rejected: ${JSON.stringify(name)}`, async () => {
        const env = editor();
        env.window.AnabelkaAITranslation = {suggest: async () => ({name})};
        await env.ai.fire('click');
        assert.equal(env.notice().type, 'error');
        assert.equal(env.field.value, 'Привет, {name}!');
        assert.equal(env.status.value, 'approved');
        assert.equal(env.origin.value, 'manual');
        assert.equal(env.ai.disabled, false);
        assert.equal(env.alerts.length, 0);
    });
}

test('failed AI request shows the original error and restores the button', async () => {
    const env = editor();
    env.window.AnabelkaAITranslation = {suggest: async () => {throw new Error('ШІ недоступний.');}};
    await env.ai.fire('click');
    assert.deepEqual(env.notice(), {type: 'error', message: 'ШІ недоступний.'});
    assert.equal(env.ai.disabled, false);
    assert.equal(env.ai.textContent, 'Перекласти через ШІ');
});

async function providerEditor() {
    const env = runtime();
    const select = env.node('select', {id: 'ai-provider-select', value: 'first'});
    const status = env.node('span', {id: 'ai-provider-status'});
    const providers = {first: {name: 'First', configured: true}, second: {name: 'Second', configured: true}};
    env.setReply(async () => ({success: true, providers, selected_provider: 'first'}));
    env.load('js/admin-ai-translation.js');
    await env.window.AnabelkaAITranslation.loadProviders();
    return {...env, select, status, providers};
}

test('failed provider change restores selection and reports one shared error, never alert', async () => {
    const env = await providerEditor();
    env.setReply(async () => ({success: false, message: 'Не вдалося змінити ШІ.'}));
    env.select.value = 'second';
    await env.select.fire('change');
    assert.equal(env.select.value, 'first');
    assert.equal(env.window.AnabelkaAITranslation.getProvider(), 'first');
    assert.equal(env.status.textContent, 'готово');
    assert.deepEqual(env.notice(), {type: 'error', message: 'Не вдалося змінити ШІ.'});
    assert.equal(env.container.children.length, 1);
    assert.equal(env.alerts.length, 0);
});

test('successful provider change retains its inline ready status without duplicate toast', async () => {
    const env = await providerEditor();
    env.setReply(async () => ({success: true, providers: env.providers, selected_provider: 'second'}));
    env.select.value = 'second';
    await env.select.fire('change');
    assert.equal(env.window.AnabelkaAITranslation.getProvider(), 'second');
    assert.equal(env.status.textContent, 'готово');
    assert.equal(env.container.children.length, 0);
});

test('automatic language targeting still scrolls without opening the keyboard', () => {
    const env = editor();
    const initial = [...env.timers.values()].find((timer) => timer.delay === 80);
    initial.fn();
    [...env.timers.values()].find((timer) => timer.delay === 120).fn();
    assert.equal(env.section.scrolled, true);
    assert.equal(env.document.activeElement, env.document.body);
});

test('persistent page errors and workflow controls remain inline', () => {
    for (const [path, variable] of [['index.php', 'dashboardError'], ['missing.php', 'missingError'],
        ['interface-edit.php', 'editorError']]) {
        const page = read('views/admin/translations/' + path);
        assert.ok(page.includes('$' + variable));
        assert.match(page, /class="translations-error"/);
    }
    assert.match(read('views/admin/translations/interface-edit.php'), /data-translation-status/);
});

test('editor script is inert when the error page has no form', () => {
    const env = runtime();
    env.load('js/admin-interface-translations.js');
    assert.equal(env.container.children.length, 0);
    assert.equal(env.requests.length, 0);
});
