import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => fs.readFileSync(path, 'utf8');
const script = read('js/admin-mobile-navigation-ai-translation.js');
const view = read('views/admin/mobile-navigation/index.php');
const loader = read('js/admin-nav.js');
const header = read('views/admin/partials/header.php');

function harness({creating = false, editorOpen = false, name = 'Новий пункт',
    provider = 'success', permission = true} = {}) {
    const actions = {};
    const requests = [];
    const notices = [];
    const focusLog = [];
    const callbacks = [];
    const createPanel = {hidden: !creating};
    const editor = {
        open: editorOpen,
        addEventListener(type, listener) {actions['editor-' + type] = listener;}
    };
    const sourceName = {
        value: name,
        focus() {focusLog.push('source');}
    };
    const translated = {
        value: '',
        focus() {focusLog.push('translation');},
        addEventListener(type, cb) {actions['translation-' + type] = cb;}
    };
    const origin = {type: 'hidden', value: 'manual'};
    const status = {
        value: 'approved',
        options: [{value: 'approved'}, {value: 'draft'}, {value: 'review'}]
    };
    const button = {
        dataset: {targetLanguage: 'en'},
        disabled: false,
        textContent: 'Перекласти через ШІ',
        addEventListener(type, cb) {actions['button-' + type] = cb;}
    };
    const form = {
        querySelector(selector) {
            return selector === 'input[name="name_uk"]' ? sourceName : null;
        }
    };
    const fieldset = {
        dataset: {mobileNavigationLanguage: 'en'},
        closest(selector) {return selector === 'form' ? form : null;},
        querySelector(selector) {
            return ({
                '[data-mobile-navigation-ai-translate]': button,
                '[data-mobile-navigation-translation-name]': translated,
                '[data-mobile-navigation-translation-source]': origin,
                '[data-mobile-navigation-translation-status]': status
            })[selector] || null;
        }
    };
    const classes = {};
    const aria = {};
    const floatingRoot = {
        classList: {
            toggle(name, enabled) {classes[name] = enabled;}
        },
        setAttribute(name, value) {aria[name] = value;}
    };
    class MutationObserver {
        constructor(fn) {this.fn = fn;}
        observe(node, options) {
            assert.equal(node, createPanel, 'Observe the actual creation panel');
            assert.equal(options.attributes, true);
            assert.equal(options.attributeFilter.includes('hidden'), true);
            callbacks.push(this.fn);
        }
    }
    const document = {
        readyState: 'complete',
        querySelector(selector) {
            if (selector === '[data-mobile-navigation-create]') return createPanel;
            if (selector === '.admin-drawer-ai') return permission ? {} : null;
            return null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-mobile-navigation-language]') return [fieldset];
            if (selector === '.mobile-navigation-editor') return [editor];
            return [];
        },
        getElementById(id) {
            return id === 'admin-ai-top-slot' ? floatingRoot : null;
        }
    };
    const window = {
        AnabelkaNotify: {
            show(type, message) {notices.push([type, message]);return true;}
        }
    };
    if (provider !== 'missing') {
        window.AnabelkaAITranslation = {
            async suggest(options) {
                requests.push(JSON.parse(JSON.stringify(options)));
                if (provider === 'error') {
                    throw new Error('Groq тимчасово недоступний');
                }
                return {name: 'New item'};
            }
        };
    }
    vm.runInNewContext(script, {document, window, MutationObserver}, {
        filename: 'admin-mobile-navigation-ai-translation.js'
    });
    return {
        actions, requests, notices, focusLog, origin, status,
        translated, sourceName, button, createPanel, editor, classes, aria,
        observerCount: callbacks.length,
        async translate() {await actions['button-click']();},
        typeManually() {actions['translation-input']();},
        setCreateOpen(open) {
            createPanel.hidden = !open;
            callbacks.forEach(cb => cb());
        },
        setEditorOpen(open) {
            editor.open = open;
            actions['editor-toggle']();
        }
    };
}

test('new item form includes buttons, localized inputs, AI sources and editable status', () => {
    const createStart = view.indexOf('data-mobile-navigation-form="create"');
    const createEnd = view.indexOf('<div class="mobile-navigation-form-actions">', createStart);
    assert.ok(createStart > 0 && createEnd > createStart);
    const createMarkup = view.slice(createStart, createEnd);

    assert.match(createMarkup, /data-mobile-navigation-language/);
    assert.match(createMarkup, /data-mobile-navigation-ai-translate/);
    assert.match(createMarkup, /data-target-language/);
    assert.match(createMarkup, /data-mobile-navigation-translation-name/);
    assert.match(createMarkup, /data-mobile-navigation-translation-source/);
    assert.match(createMarkup, /data-mobile-navigation-translation-status/);
    assert.match(createMarkup, /TranslationWorkflow::statusOptions/);
    assert.match(createMarkup, /\$statusValue === 'approved'/);
    assert.match(createMarkup, /<select[\s\S]*?\[status\]/);
    assert.match(createMarkup, /\$canManage && !\$migrationRequired/);
    assert.match(createMarkup, /Перекласти через ШІ/);
    assert.doesNotMatch(createMarkup, /type="hidden"\s+name="translations\[[^\n]+\[status\]"/);
});

test('AI translation of a new item becomes draft and does not save automatically', async () => {
    const h = harness({creating: true});
    await h.translate();
    assert.deepEqual(h.requests, [{
        targetLanguage: 'en',
        name: 'Новий пункт',
        description: '',
        context: 'mobile_navigation'
    }]);
    assert.equal(h.translated.value, 'New item');
    assert.equal(h.origin.value, 'ai');
    assert.equal(h.status.value, 'draft');
    assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Перекласти через ШІ');
    assert.deepEqual(h.notices, [
        ['info', 'ШІ-переклад отримано. Перевірте текст і статус перед збереженням.']
    ]);
    assert.deepEqual(h.focusLog, ['translation']);
});

test('manual editing after AI still changes source to manual but keeps draft for review', async () => {
    const h = harness({creating: true});
    await h.translate();
    h.translated.value = 'Власний переклад';
    h.typeManually();
    assert.equal(h.origin.value, 'manual');
    assert.equal(h.status.value, 'draft');
    assert.equal(h.translated.value, 'Власний переклад');
});

test('creation warns about missing Ukrainian source name and does not alter status', async () => {
    const h = harness({creating: true, name: ''});
    await h.translate();
    assert.deepEqual(h.requests, []);
    assert.equal(h.translated.value, '');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.origin.value, 'manual');
    assert.equal(h.focusLog[0], 'source');
    assert.equal(h.notices[0][0], 'warning');
});

test('new item shows a provider error without destroying an existing translation', async () => {
    const h = harness({creating: true, provider: 'error'});
    h.translated.value = 'Existing draft';
    await h.translate();
    assert.deepEqual(h.notices, [['error', 'Groq тимчасово недоступний']]);
    assert.equal(h.translated.value, 'Existing draft');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.button.disabled, false);
});

test('AI rights remain enforced for new item buttons', async () => {
    const h = harness({creating: true, provider: 'missing', permission: false});
    await h.translate();
    assert.deepEqual(h.requests, []);
    assert.deepEqual(h.notices, [
        ['warning', 'ШІ-переклад недоступний для поточної ролі адміністратора.']
    ]);
    assert.equal(h.origin.value, 'manual');
});

test('floating Groq panel follows new item opening and closing, even without an edit modal', () => {
    const h = harness();
    assert.equal(h.observerCount, 1);
    assert.equal(h.classes['is-mobile-navigation-ai-inactive'], true);
    assert.equal(h.aria['aria-hidden'], 'true');

    h.setCreateOpen(true);
    assert.equal(h.classes['is-mobile-navigation-ai-inactive'], false);
    assert.equal(h.aria['aria-hidden'], 'false');

    h.setCreateOpen(false);
    assert.equal(h.classes['is-mobile-navigation-ai-inactive'], true);
    assert.equal(h.aria['aria-hidden'], 'true');

    h.setEditorOpen(true);
    assert.equal(h.classes['is-mobile-navigation-ai-inactive'], false);
    h.setCreateOpen(true);
    h.setCreateOpen(false);
    assert.equal(h.aria['aria-hidden'], 'false', 'An existing editor keeps Groq visible');

    h.setEditorOpen(false);
    assert.equal(h.aria['aria-hidden'], 'true');
});

test('Groq is visible when the AI script loads after the creation form is opened', () => {
    const h = harness({creating: true});
    assert.equal(h.observerCount, 1);
    assert.equal(h.classes['is-mobile-navigation-ai-inactive'], false);
    assert.equal(h.aria['aria-hidden'], 'false');
});

test('dynamic loader and Android cache use the new module with no double-loading', () => {
    assert.match(loader, /admin-mobile-navigation-ai-translation\.js\?v=4/);
    assert.match(header, /admin-nav\.js\?v=34/);
    assert.match(loader, /appendScript\(/);
    assert.match(script, /new MutationObserver\(syncFloatingPanelVisibility\)/);
    assert.match(script, /attributeFilter:\s*\['hidden'\]/);
    assert.match(script, /hasOpenEditor \|\| isCreating/);
    assert.match(view, /data-mobile-navigation-form="create"\s+novalidate/);
    assert.match(view, /data-mobile-navigation-error="url"/);
    assert.match(view, /admin-mobile-navigation\.js\?v=3/);
});
