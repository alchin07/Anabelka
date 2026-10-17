import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import {URL} from 'node:url';

const editor = fs.readFileSync('js/admin-mobile-navigation.js', 'utf8');
const view = fs.readFileSync('views/admin/mobile-navigation/index.php', 'utf8');

function harness({stored = true, core = true} = {}) {
    const events = {};
    const calls = [];
    const flash = [];
    const toasts = [];
    const reloads = [];
    const replies = [];
    const itemArray = [11, 22, 33].map(id => ({
        dataset: {itemId: String(id)},
        classList: {add() {}, remove() {}},
        querySelector(selector) {
            return selector === '.mobile-navigation-item-copy strong'
                ? {textContent: 'Назва ' + id} : null;
        },
        get previousElementSibling() {
            return list.children[list.children.indexOf(this) - 1] || null;
        },
        get nextElementSibling() {
            return list.children[list.children.indexOf(this) + 1] || null;
        },
        getBoundingClientRect() {
            return {top: 50 * list.children.indexOf(this), height: 50};
        }
    }));
    const list = {
        children: [...itemArray],
        querySelectorAll() {return [...this.children];},
        appendChild(item) {
            this.children = this.children.filter(node => node !== item);
            this.children.push(item);
        },
        insertBefore(item, anchor) {
            this.children = this.children.filter(node => node !== item);
            const index = this.children.indexOf(anchor);
            assert.ok(index >= 0);
            this.children.splice(index, 0, item);
        },
        get lastElementChild() {return this.children.at(-1);}
    };
    let latestForm = null;
    const createPanel = {
        hidden: true,
        querySelector(query) {
            return query === 'form[data-mobile-navigation-form="create"]'
                ? latestForm : null;
        }
    };
    const createCancel = {
        addEventListener(name, handler) {events['create-cancel-' + name] = handler;}
    };
    const root = {
        dataset: {revision: 'first-revision', csrf: 'form-csrf'},
        querySelector(selector) {
            if (selector === '[data-mobile-navigation-list]') return list;
            if (selector === '[data-mobile-navigation-create]') return createPanel;
            if (selector === '[data-mobile-navigation-create-cancel]') return createCancel;
            return null;
        },
        addEventListener(name, callback) {events[name] = callback;},
        contains() {return true;}
    };
    const document = {
        querySelector(selector) {
            return selector === '[data-mobile-navigation-root]' ? root : null;
        }
    };
    class HTMLFormElement {
        constructor(action) {
            this.action = action;
            this.dataset = {pending: '0', mobileNavigationForm: action.split('/').pop()};
            this.button = {disabled: false};
            this.fields = {};
            this.errors = {};
            for (const name of ['name_uk', 'url']) {
                this.fields[name] = {
                    name,
                    value: name === 'url' ? '/Anabelka/test' : 'Тест',
                    form: this,
                    attributes: {},
                    setAttribute(key, value) {this.attributes[key] = String(value);},
                    removeAttribute(key) {delete this.attributes[key];},
                    focus() {this.focused = true;}
                };
                this.errors[name] = {hidden: true, textContent: ''};
            }
        }
        matches(selector) {return selector === '[data-mobile-navigation-form]';}
        querySelectorAll(selector) {
            return selector === 'button[type="submit"]' ? [this.button] : [];
        }
        querySelector(selector) {
            for (const name of ['name_uk', 'url']) {
                if (selector === 'input[name="' + name + '"]') {
                    return this.fields[name];
                }
                if (selector === '[data-mobile-navigation-error="' + name + '"]') {
                    return this.errors[name];
                }
            }
            return null;
        }
    }
    class FormData {
        constructor(form) {this.form = form || null;this.data = {};}
        append(k, value) {(this.data[k] ||= []).push(value);}
        get(k) {return this.data[k]?.[0] ?? null;}
        getAll(k) {return this.data[k] || [];}
    }
    let confirmed = false;
    const window = {
        location: {reload() {reloads.push(true);}},
        AnabelkaNotify: core ? {
            show(type, message) {toasts.push({type, message});return true;},
            store(type, message) {flash.push({type, message});return stored;}
        } : null,
        AnabelkaDialog: {confirm() {return Promise.resolve(confirmed);}},
        confirm() {throw new Error('Use the branded confirmation dialog');},
        async fetch(url, options) {
            calls.push({url, options});
            const result = replies.shift() || {ok: true, message: 'Збережено.'};
            return {
                ok: result.ok,
                status: result.ok ? 200 : result.status || 422,
                async json() {return {ok: result.ok, message: result.message, revision: result.revision};}
            };
        }
    };
    vm.runInNewContext(editor, {document, window, FormData, HTMLFormElement, URL});

    async function settle() {for (let i = 0; i < 20; i++) await Promise.resolve();}
    function queue(message, {ok = true, status = 200, revision = ''} = {}) {
        replies.push({message, ok, status, revision});
    }
    function control(kind, id, attrs = {}) {
        const item = itemArray.find(node => Number(node.dataset.itemId) === id);
        const button = {
            dataset: {nextActive: '1', mobileNavigationMove: 'up', ...attrs},
            disabled: false,
            closest(selector) {return selector === '[data-mobile-navigation-item]' ? item : null;},
            hasAttribute(name) {return name === 'data-mobile-navigation-' + kind;}
        };
        return button;
    }
    async function click(button) {
        events.click({target: {closest(query) {return query === 'button' ? button : null;}}});
        await settle();
    }
    async function submit(action, overrides = {}) {
        const form = new HTMLFormElement('/Anabelka/admin/mobile-navigation/' + action);
        for (const [name, value] of Object.entries(overrides)) {
            if (form.fields[name]) form.fields[name].value = value;
        }
        latestForm = form;
        let prevented = false;
        events.submit({target: form, preventDefault() {prevented = true;}});
        await settle();
        assert.equal(prevented, true);
        return form;
    }
    return {
        window, events, root, list, itemArray,
        calls, flash, toasts, reloads, queue, control, click, submit, settle,
        input(form, name, value) {
            const field = form.fields[name];
            field.value = value;
            events.input({target: field});
        },
        cancel() {events['create-cancel-click']();},
        confirm(value) {confirmed = value;}
    };
}

function requestMatches(call, action, expected) {
    assert.equal(call.url, '/Anabelka/admin/mobile-navigation/' + action);
    assert.equal(call.options.method, 'POST');
    assert.equal(call.options.credentials, 'same-origin');
    assert.equal(call.options.headers['X-Requested-With'], 'XMLHttpRequest');
    for (const [key, value] of Object.entries(expected)) {
        assert.equal(call.options.body.get(key), value, key);
    }
}

test('single shared notification queue; no legacy flash key, direct host, or timers', () => {
    assert.match(editor, /shared\.store\('success', message\)/);
    assert.match(editor, /shared\.show\(type, message\)/);
    assert.doesNotMatch(editor, /flashKey|anabelka-mobile-navigation-flash|storeFlash|consumeStoredFlash/);
    assert.doesNotMatch(editor, /sessionStorage|getElementById\('site-message'\)/);
    assert.doesNotMatch(editor, /classList\.add\('show'\)/);
    assert.match(view, /admin-mobile-navigation\.js\?v=3/);
    assert.equal((view.match(/id="site-message"/g) || []).length, 1);
    assert.ok(view.indexOf('js/anabelka-notify.js') < view.indexOf('js/admin-mobile-navigation.js'));
});

test('create and update store exactly one success before refreshing the list', async () => {
    for (const action of ['create', 'update']) {
        const h = harness();
        h.queue('Пункт збережено.');
        const form = await h.submit(action);
        assert.equal(h.calls.length, 1);
        requestMatches(h.calls[0], action, {});
        assert.equal(h.calls[0].options.body.form, form);
        assert.deepEqual(h.flash, [{type: 'success', message: 'Пункт збережено.'}]);
        assert.deepEqual(h.toasts, []);
        assert.deepEqual(h.reloads, [true]);
        assert.equal(form.button.disabled, true);
    }
});

test('server validation, CSRF, conflicts and denied permissions display error, not success', async () => {
    for (const status of [403, 409, 419, 422, 500, 503]) {
        const h = harness();
        h.queue('Не вдалося зберегти.', {ok: false, status});
        const form = await h.submit('update');
        assert.equal(form.dataset.pending, '0');
        assert.equal(form.button.disabled, false);
        assert.deepEqual(h.toasts, [{type: 'error', message: 'Не вдалося зберегти.'}]);
        assert.deepEqual(h.flash, []);
        assert.deepEqual(h.reloads, []);
    }
});

test('toggling an item preserves CSRF and toggled state, then queues one success', async () => {
    const h = harness();
    h.queue('Пункт увімкнено.');
    const button = h.control('toggle', 22);
    await h.click(button);
    requestMatches(h.calls[0], 'toggle', {_csrf: 'form-csrf', item_id: '22', is_active: '1'});
    assert.deepEqual(h.flash, [{type: 'success', message: 'Пункт увімкнено.'}]);
    assert.deepEqual(h.reloads, [true]);
    assert.equal(button.disabled, true);
});

test('toggle failure restores the action and keeps the editor open', async () => {
    const h = harness();
    h.queue('Недостатньо прав.', {ok: false, status: 403});
    const button = h.control('toggle', 22);
    await h.click(button);
    assert.equal(button.disabled, false);
    assert.deepEqual(h.toasts, [{type: 'error', message: 'Недостатньо прав.'}]);
    assert.deepEqual(h.flash, []);
    assert.deepEqual(h.reloads, []);
});

test('deletion requires branded confirmation and does not mutate on Cancel', async () => {
    const h = harness();
    h.confirm(false);
    await h.click(h.control('delete', 11));
    assert.deepEqual(h.calls, []);
    assert.deepEqual(h.flash, []);
    h.confirm(true);
    h.queue('Пункт видалено.');
    await h.click(h.control('delete', 11));
    requestMatches(h.calls[0], 'delete', {_csrf: 'form-csrf', item_id: '11'});
    assert.deepEqual(h.flash, [{type: 'success', message: 'Пункт видалено.'}]);
    assert.deepEqual(h.reloads, [true]);
});

test('failed delete does not reload and keeps the delete button available', async () => {
    const h = harness();
    h.confirm(true);
    h.queue('Пункт не знайдено.', {ok: false, status: 422});
    const button = h.control('delete', 11);
    await h.click(button);
    assert.equal(button.disabled, false);
    assert.deepEqual(h.toasts, [{type: 'error', message: 'Пункт не знайдено.'}]);
    assert.deepEqual(h.reloads, []);
});

test('accessible reorder keeps ordering and revision, then replays shared success', async () => {
    const h = harness();
    h.queue('Порядок збережено.', {revision: 'next-revision'});
    await h.click(h.control('move', 22, {mobileNavigationMove: 'up'}));
    requestMatches(h.calls[0], 'move', {_csrf: 'form-csrf', revision: 'first-revision'});
    assert.deepEqual(h.calls[0].options.body.getAll('ids[]'), ['22', '11', '33']);
    assert.equal(h.root.dataset.revision, 'next-revision');
    assert.deepEqual(h.flash, [{type: 'success', message: 'Порядок збережено.'}]);
    assert.deepEqual(h.reloads, [true]);
});

test('failed reorder restores original order and reports error without success', async () => {
    const h = harness();
    h.queue('Порядок меню змінився.', {ok: false, status: 409});
    const control = h.control('move', 22, {mobileNavigationMove: 'up'});
    await h.click(control);
    assert.deepEqual(h.list.children.map(x => Number(x.dataset.itemId)), [11, 22, 33]);
    assert.equal(control.disabled, false);
    assert.deepEqual(h.toasts, [{type: 'error', message: 'Порядок меню змінився.'}]);
    assert.deepEqual(h.flash, []);
    assert.deepEqual(h.reloads, []);
});

test('pointer drag handlers still exist and call the same shared reorder path', () => {
    assert.match(editor, /root\.addEventListener\('pointerdown'/);
    assert.match(editor, /root\.addEventListener\('pointermove'/);
    assert.match(editor, /root\.addEventListener\('pointerup'/);
    assert.match(editor, /root\.addEventListener\('pointercancel'/);
    assert.match(editor, /persistOrder\(state\.previousOrder\)/);
    assert.match(editor, /restoreOrder\(state\.previousOrder\)/);
});

test('when storage is blocked the success is still sent to the shared UI', async () => {
    const h = harness({stored: false});
    h.queue('Збережено.');
    await h.submit('update');
    assert.deepEqual(h.flash, [{type: 'success', message: 'Збережено.'}]);
    assert.deepEqual(h.toasts, [{type: 'success', message: 'Збережено.'}]);
    assert.deepEqual(h.reloads, [true]);
});

test('without shared API, success still refreshes rather than restoring legacy flash', async () => {
    const h = harness({core: false});
    h.queue('Збережено.');
    await h.submit('update');
    assert.deepEqual(h.flash, []);
    assert.deepEqual(h.toasts, []);
    assert.deepEqual(h.reloads, [true]);
});


test('creation shows inline errors for both required fields before any request', async () => {
    const h = harness();
    const form = await h.submit('create', {name_uk: '  ', url: ''});
    assert.deepEqual(h.calls, []);
    assert.deepEqual(h.flash, []);
    assert.deepEqual(h.toasts, []);
    assert.deepEqual(h.reloads, []);
    assert.equal(form.button.disabled, false);
    assert.equal(form.fields.name_uk.attributes['aria-invalid'], 'true');
    assert.equal(form.fields.url.attributes['aria-invalid'], 'true');
    assert.equal(form.errors.name_uk.hidden, false);
    assert.equal(form.errors.url.hidden, false);
    assert.match(form.errors.name_uk.textContent, /українську назву/);
    assert.match(form.errors.url.textContent, /посилання/);
    assert.equal(form.fields.name_uk.focused, true);
});

test('invalid paths, path traversal, double encoding and unsafe schemes are rejected locally', async () => {
    const urls = [
        'Test', '/catalog', '//evil.example',
        'javascript:alert(1)', 'data:text/html,something',
        '/Anabelka/../admin', '/Anabelka/%2e%2e/admin',
        '/Anabelka/%252e%252e/admin', 'https://'
    ];
    for (const url of urls) {
        const h = harness();
        const form = await h.submit('create', {url});
        assert.equal(h.calls.length, 0, 'Invalid: ' + url);
        assert.equal(form.errors.url.hidden, false, url);
        assert.equal(form.fields.url.attributes['aria-invalid'], 'true', url);
        assert.deepEqual(h.toasts, []);
        assert.deepEqual(h.reloads, []);
    }
});

test('internal /Anabelka paths and real HTTPS links remain creatable', async () => {
    for (const url of [
        '/Anabelka', '/Anabelka/test', '/Anabelka/catalog?ref=1',
        'https://example.org/products?q=1'
    ]) {
        const h = harness();
        h.queue('Пункт створено.');
        const form = await h.submit('create', {url});
        assert.equal(h.calls.length, 1, url);
        assert.equal(h.calls[0].options.body.form, form);
        assert.equal(form.errors.url.hidden, true);
        assert.deepEqual(h.flash, [{type: 'success', message: 'Пункт створено.'}]);
        assert.deepEqual(h.reloads, [true]);
    }
});

test('length restrictions follow the existing server rules for name and URL', async () => {
    const name = harness();
    const form = await name.submit('create', {name_uk: 'ж'.repeat(161)});
    assert.match(form.errors.name_uk.textContent, /занадто довга/);
    assert.equal(name.calls.length, 0);
    const url = harness();
    const longForm = await url.submit('create', {url: '/Anabelka/' + 'x'.repeat(1000)});
    assert.match(longForm.errors.url.textContent, /1000/);
    assert.equal(url.calls.length, 0);
});

test('live correction removes invalid markers without deleting values or other errors', async () => {
    const h = harness();
    const form = await h.submit('create', {name_uk: '', url: 'Test'});
    h.input(form, 'name_uk', 'Новий пункт');
    assert.equal(form.errors.name_uk.hidden, true);
    assert.equal(form.fields.name_uk.attributes['aria-invalid'], undefined);
    assert.equal(form.errors.url.hidden, false);
    h.input(form, 'url', '/Anabelka/new');
    assert.equal(form.errors.url.hidden, true);
    assert.equal(form.fields.url.attributes['aria-invalid'], undefined);
    assert.equal(form.fields.name_uk.value, 'Новий пункт');
    assert.equal(form.fields.url.value, '/Anabelka/new');
    assert.deepEqual(h.calls, []);
});

test('server 422 invalid URL is attached beneath URL, not shown as a global toast', async () => {
    const h = harness();
    h.queue('Внутрішнє посилання повинно вести в Анабельку.', {ok: false, status: 422});
    const form = await h.submit('create');
    assert.equal(h.calls.length, 1);
    assert.equal(form.errors.url.hidden, false);
    assert.equal(form.errors.url.textContent, 'Внутрішнє посилання повинно вести в Анабельку.');
    assert.equal(form.fields.url.attributes['aria-invalid'], 'true');
    assert.equal(form.fields.url.focused, true);
    assert.equal(form.button.disabled, false);
    assert.deepEqual(h.toasts, []);
    assert.deepEqual(h.reloads, []);
});

test('server 422 invalid name is attached to the name field', async () => {
    const h = harness();
    h.queue('Назва пункту меню занадто довга.', {ok: false, status: 422});
    const form = await h.submit('create');
    assert.equal(form.errors.name_uk.hidden, false);
    assert.match(form.errors.name_uk.textContent, /Назва/);
    assert.equal(form.fields.name_uk.attributes['aria-invalid'], 'true');
    assert.deepEqual(h.toasts, []);
    assert.deepEqual(h.reloads, []);
});

test('unrelated translation validation, CSRF and denied access still use central queue', async () => {
    for (const [status, message] of [
        [422, 'Некоректний переклад.'],
        [419, 'Сесію завершено. Оновіть сторінку.'],
        [403, 'Недостатньо прав для цієї дії.']
    ]) {
        const h = harness();
        h.queue(message, {ok: false, status});
        const form = await h.submit('create');
        assert.deepEqual(h.toasts, [{type: 'error', message}]);
        assert.equal(form.errors.url.hidden, true);
        assert.equal(form.errors.name_uk.hidden, true);
        assert.deepEqual(h.reloads, []);
    }
});

test('cancel resets validation presentation but does not destroy unsaved text', async () => {
    const h = harness();
    const form = await h.submit('create', {name_uk: '', url: 'Test'});
    h.cancel();
    assert.equal(form.errors.url.hidden, true);
    assert.equal(form.errors.name_uk.hidden, true);
    assert.equal(form.fields.url.attributes['aria-invalid'], undefined);
    assert.equal(form.fields.name_uk.attributes['aria-invalid'], undefined);
    assert.equal(form.fields.url.value, 'Test');
    assert.deepEqual(h.calls, []);
});

test('create form exposes accessible inline branded purple feedback and asset versions', () => {
    const css = fs.readFileSync('css/admin-mobile-navigation.css', 'utf8');
    assert.match(view, /data-mobile-navigation-form="create"\s+novalidate/);
    assert.match(view, /id="mobile-navigation-create-name-error"/);
    assert.match(view, /id="mobile-navigation-create-url-error"/);
    assert.match(view, /aria-describedby="mobile-navigation-create-url-error"/);
    assert.match(view, /data-mobile-navigation-error="name_uk"/);
    assert.match(view, /data-mobile-navigation-error="url"/);
    assert.match(view, /admin-mobile-navigation\.css\?v=4/);
    assert.match(css, /input\[aria-invalid="true"\]/);
    assert.match(css, /border-color:\s*#8A2BE2/);
    assert.match(css, /\.mobile-navigation-field-error\[hidden\]\s*\{[\s\S]*?display:\s*none/);
});
