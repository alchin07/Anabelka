import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => fs.readFileSync(path, 'utf8');
const ai = read('js/admin-delivery-ai-translation.js');

function extract(start, stop, name, context) {
    const from = ai.indexOf(start);
    const to = ai.indexOf(stop, from + start.length);
    assert.ok(from >= 0 && to > from, name);
    return vm.runInNewContext('(function () {\n' + ai.slice(from, to) +
        '\nreturn ' + name + ';\n})()', context);
}

function setup(kind, responder) {
    const notices = [];
    const workflows = [];
    const requests = [];
    const buttons = [];
    const values = {
        '.delivery-translation-name': {value: ''},
        '.delivery-translation-description': {value: ''},
        '.delivery-option-input-translation-label': {value: ''},
        '.delivery-option-input-translation-placeholder': {value: ''}
    };
    const inputById = {
        'edit-name': {value: 'Нова пошта'},
        'edit-description': {value: 'До відділення'},
        'edit-type': {value: 'method'},
        'edit-option-customer-input-label': {value: 'Номер відділення'},
        'edit-option-customer-input-placeholder': {value: 'Вкажіть номер'}
    };
    const section = {
        dataset: {languageCode: 'en'},
        querySelector: selector => values[selector] || null
    };
    const doc = {
        getElementById: id => inputById[id] || null,
        querySelector: query => query === '.admin-drawer-ai' ? {} : null,
        createElement: type => {
            assert.equal(type, 'button');
            return {
                type: '', className: '', dataset: {},
                disabled: false, textContent: '',
                handlers: {},
                addEventListener(event, callback) {this.handlers[event] = callback;}
            };
        }
    };
    const win = {
        setTimeout() {return 1;},
        clearTimeout() {},
        AnabelkaAITranslation: {
            async suggest(payload) {requests.push(payload); return responder(payload);}
        },
        setDeliveryTranslationWorkflow(node, origin, status) {
            workflows.push([node === section, origin, status]);
        }
    };
    function notice(message, type) {notices.push([type, message]); return true;}
    const waitForAiModule = extract('    async function waitForAiModule()',
        '    function ensureStyles()', 'waitForAiModule', {
            document: doc, window: win, showMessage: notice
        });
    const name = kind === 'entity' ? 'createEntityButton' : 'createOptionInputButton';
    const end = kind === 'entity' ? '    function createOptionInputButton(section)' : '    function init()';
    const create = extract('    function ' + name + '(section)', end, name, {
        document: doc, window: win,
        showMessage: notice, waitForAiModule,
        prepareHead: () => ({appendChild(button) {buttons.push(button);}})
    });
    create(section);
    assert.equal(buttons.length, 1);
    return {button: buttons[0], requests, workflows, notices, values, win, doc};
}

test('AI notification wrapper sends typed messages through one shared queue', () => {
    const calls = [];
    const win = {AnabelkaNotify: {
        show(type, message) {calls.push([type, message]); return true;}
    }};
    const notice = extract("    function showMessage(text, type = 'info')",
        '    function ensureStyles()', 'showMessage', {window: win});
    assert.equal(notice('Чернетка.'), true);
    assert.equal(notice('Помилка.', 'error'), true);
    assert.equal(notice('Спробуйте знову.', 'warning'), true);
    assert.deepEqual(calls, [
        ['info', 'Чернетка.'], ['error', 'Помилка.'],
        ['warning', 'Спробуйте знову.']
    ]);
    delete win.AnabelkaNotify;
    assert.equal(notice('Недоступно.'), false);
    assert.doesNotMatch(ai, /\b(?:window\.)?alert\s*\(/);
    assert.doesNotMatch(ai, /deliveryAiMessageTimer|message\.classList\.add\('show'\)/);
    assert.doesNotMatch(ai, /getElementById\('site-message'\)/);
});

for (const kind of ['entity', 'option']) {
    test(kind + ' translation is an unsaved draft, not a success notification', async () => {
        const draft = {name: 'Post office', description: 'Delivery instructions'};
        const h = setup(kind, async () => draft);
        const original = h.button.textContent;
        await h.button.handlers.click();

        assert.equal(h.requests.length, 1);
        assert.equal(h.requests[0].targetLanguage, 'en');
        assert.equal(h.requests[0].context,
            kind === 'entity' ? 'delivery_method' : 'delivery_option_input');
        assert.deepEqual(h.workflows, [[true, 'ai', 'draft']]);
        const nameKey = kind === 'entity'
            ? '.delivery-translation-name' : '.delivery-option-input-translation-label';
        const descriptionKey = kind === 'entity'
            ? '.delivery-translation-description'
            : '.delivery-option-input-translation-placeholder';
        assert.equal(h.values[nameKey].value, draft.name);
        assert.equal(h.values[descriptionKey].value, draft.description);
        assert.equal(h.notices.length, 1);
        assert.equal(h.notices[0][0], 'info');
        assert.match(h.notices[0][1], /Зберегти/);
        assert.equal(h.button.disabled, false);
        assert.equal(h.button.textContent, original);
    });

    test(kind + ' missing AI provider reports an error without changing data', async () => {
        const h = setup(kind, async () => ({}));
        delete h.win.AnabelkaAITranslation;
        h.win.AnabelkaAITranslationReady = Promise.resolve(false);
        await h.button.handlers.click();
        assert.equal(h.requests.length, 0);
        assert.deepEqual(h.workflows, []);
        assert.equal(h.notices.length, 1);
        assert.equal(h.notices[0][0], 'error');
        assert.equal(h.button.disabled, false);
    });

    test(kind + ' missing permission gives warning without requesting AI', async () => {
        const h = setup(kind, async () => ({}));
        delete h.win.AnabelkaAITranslation;
        h.doc.querySelector = () => null;
        await h.button.handlers.click();
        assert.deepEqual(h.requests, []);
        assert.deepEqual(h.workflows, []);
        assert.equal(h.notices.length, 1);
        assert.equal(h.notices[0][0], 'warning');
    });

    test(kind + ' awaits provider load and preserves draft workflow', async () => {
        const h = setup(kind, async () => ({name: 'Pickup', description: 'Self pickup'}));
        const ai = h.win.AnabelkaAITranslation;
        delete h.win.AnabelkaAITranslation;
        let done;
        h.win.AnabelkaAITranslationReady = new Promise(resolve => {done = resolve;});
        const click = h.button.handlers.click();
        assert.equal(h.requests.length, 0);
        h.win.AnabelkaAITranslation = ai;
        done(true);
        await click;
        assert.equal(h.requests.length, 1);
        assert.deepEqual(h.workflows, [[true, 'ai', 'draft']]);
        assert.equal(h.notices[0][0], 'info');
    });

    test(kind + ' AI error preserves existing form data and reports error', async () => {
        const h = setup(kind, async () => {throw new Error('Groq недоступний');});
        await h.button.handlers.click();
        assert.deepEqual(h.workflows, []);
        assert.deepEqual(h.notices, [['error', 'Groq недоступний']]);
        assert.equal(h.button.disabled, false);
        assert.equal(h.button.textContent, 'Перекласти через ШІ');
    });
}

test('delivery AI loader refreshes Android cache and does not alter forms', () => {
    const nav = read('js/admin-nav.js');
    const view = read('views/admin/delivery/index.php');
    assert.match(nav, /function appendDeliveryAiWhenReady\(\)[\s\S]*?admin-delivery-ai-translation\.js\?v=5/);
    assert.match(nav, /path === '\/Anabelka\/admin\/delivery'[\s\S]*?appendDeliveryAiWhenReady\(\)/);
    assert.match(view, /anabelka-notify\.js\?v=3/);
    assert.match(view, /admin-delivery\/common\.js\?v=4/);
    assert.match(ai, /setDeliveryTranslationWorkflow\(/);
    assert.doesNotMatch(ai, /saveDeliveryOptionInput\(/);
});
