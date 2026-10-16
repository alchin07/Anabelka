import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const script = fs.readFileSync('js/admin-mobile-navigation-ai-translation.js', 'utf8');
const view = fs.readFileSync('views/admin/mobile-navigation/index.php', 'utf8');
const loader = fs.readFileSync('js/admin-nav.js', 'utf8');
const header = fs.readFileSync('views/admin/partials/header.php', 'utf8');
const menuEditor = fs.readFileSync('js/admin-mobile-navigation.js', 'utf8');

function harness({sourceName = 'Самовивіз', targetLanguage = 'en',
    provider = 'success', permission = true, notifications = true, floating = false} = {}) {
    const notices = [];
    const calls = [];
    const handlers = {};
    const controls = {};
    const sourceInput = {
        value: sourceName,
        focus() {calls.push('source-focus');}
    };
    const translated = {
        value: 'Old translation',
        addEventListener(type, listener) {handlers['translation-' + type] = listener;},
        focus() {calls.push('translation-focus');}
    };
    const origin = {value: 'manual'};
    const state = {
        value: 'approved',
        options: [{value: 'approved'}, {value: 'draft'}, {value: 'review'}]
    };
    const button = {
        dataset: {targetLanguage},
        textContent: 'Перекласти через ШІ',
        disabled: false,
        addEventListener(type, listener) {handlers['button-' + type] = listener;}
    };
    const form = {
        querySelector(selector) {
            return selector === 'input[name="name_uk"]' ? sourceInput : null;
        }
    };
    const fieldset = {
        dataset: {mobileNavigationLanguage: targetLanguage},
        closest(selector) {return selector === 'form' ? form : null;},
        querySelector(selector) {
            return ({
                '[data-mobile-navigation-ai-translate]': button,
                '[data-mobile-navigation-translation-name]': translated,
                '[data-mobile-navigation-translation-source]': origin,
                '[data-mobile-navigation-translation-status]': state
            })[selector] || null;
        }
    };
    const editor = {
        open: true,
        addEventListener(type, listener) {handlers['editor-' + type] = listener;}
    };
    const floatingRoot = {
        classes: {},
        hiddenForAi: false,
        classList: {
            toggle(name, enabled) {floatingRoot.classes[name] = enabled;}
        },
        setAttribute(key, value) {floatingRoot[key] = value;}
    };
    const document = {
        readyState: 'complete',
        querySelector(selector) {
            return selector === '.admin-drawer-ai' && permission ? {} : null;
        },
        getElementById(id) {
            return id === 'admin-ai-top-slot' && floating ? floatingRoot : null;
        },
        querySelectorAll(selector) {
            if (selector === '[data-mobile-navigation-language]') return [fieldset];
            if (selector === '.mobile-navigation-editor') return [editor];
            return [];
        }
    };
    const window = {};
    if (notifications) {
        window.AnabelkaNotify = {
            show(type, message) {notices.push([type, message]); return true;}
        };
    }
    if (provider === 'success') {
        window.AnabelkaAITranslation = {
            async suggest(options) {
                calls.push(JSON.parse(JSON.stringify(options)));
                return {name: 'Self pickup'};
            }
        };
    } else if (provider === 'failure') {
        window.AnabelkaAITranslation = {
            async suggest(options) {
                calls.push(JSON.parse(JSON.stringify(options)));
                throw new Error('Groq тимчасово недоступний');
            }
        };
    }

    vm.runInNewContext(script, {document, window}, {
        filename: 'js/admin-mobile-navigation-ai-translation.js'
    });
    return {
        notices, calls, sourceInput, translated, origin, state, button,
        floatingRoot, editor,
        click: () => handlers['button-click'](),
        manuallyEdit: () => handlers['translation-input'](),
        toggle: () => handlers['editor-toggle']()
    };
}

test('AI draft uses central informational notice and keeps translation unsaved', async () => {
    const h = harness();
    await h.click();
    assert.equal(h.translated.value, 'Self pickup');
    assert.equal(h.origin.value, 'ai');
    assert.equal(h.state.value, 'draft');
    assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Перекласти через ШІ');
    assert.deepEqual(h.notices, [
        ['info', 'ШІ-переклад отримано. Перевірте текст і статус перед збереженням.']
    ]);
    assert.deepEqual(h.calls[0], {
        targetLanguage: 'en',
        name: 'Самовивіз',
        description: '',
        context: 'mobile_navigation'
    });
    assert.equal(h.calls[1], 'translation-focus');
});

test('missing Ukrainian name warns without requesting AI or changing translation', async () => {
    const h = harness({sourceName: ' '});
    await h.click();
    assert.deepEqual(h.notices, [['warning', 'Спочатку вкажіть українську назву пункту.']]);
    assert.deepEqual(h.calls, ['source-focus']);
    assert.equal(h.translated.value, 'Old translation');
    assert.equal(h.origin.value, 'manual');
    assert.equal(h.state.value, 'approved');
});

test('unavailable provider shows loading warning without changing fields', async () => {
    const h = harness({provider: 'missing'});
    await h.click();
    assert.equal(h.notices.length, 1);
    assert.equal(h.notices[0][0], 'warning');
    assert.match(h.notices[0][1], /завантажується/);
    assert.equal(h.translated.value, 'Old translation');
    assert.equal(h.state.value, 'approved');
    assert.equal(h.button.disabled, false);
});

test('roles without translator permission receive a meaningful warning', async () => {
    const h = harness({provider: 'missing', permission: false});
    await h.click();
    assert.deepEqual(h.notices, [
        ['warning', 'ШІ-переклад недоступний для поточної ролі адміністратора.']
    ]);
    assert.equal(h.translated.value, 'Old translation');
    assert.equal(h.state.value, 'approved');
});

test('missing target language reports error before changing workflow', async () => {
    const h = harness({targetLanguage: ''});
    await h.click();
    assert.deepEqual(h.notices, [['error', 'Не вдалося визначити мову перекладу.']]);
    assert.equal(h.translated.value, 'Old translation');
    assert.equal(h.state.value, 'approved');
});

test('failed AI call emits an error and restores button without altering input', async () => {
    const h = harness({provider: 'failure'});
    await h.click();
    assert.deepEqual(h.notices, [['error', 'Groq тимчасово недоступний']]);
    assert.equal(h.translated.value, 'Old translation');
    assert.equal(h.origin.value, 'manual');
    assert.equal(h.state.value, 'approved');
    assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Перекласти через ШІ');
});

test('manual editing after AI draft updates origin but not approval or saves', async () => {
    const h = harness();
    await h.click();
    h.translated.value = 'Own manual text';
    h.manuallyEdit();
    assert.equal(h.origin.value, 'manual');
    assert.equal(h.state.value, 'draft');
    assert.equal(h.notices.length, 1);
});

test('the missing notification API does not show alert or overwrite other messages', async () => {
    const h = harness({notifications: false, sourceName: ''});
    await h.click();
    assert.deepEqual(h.notices, []);
    assert.deepEqual(h.calls, ['source-focus']);
    assert.doesNotMatch(script, /\b(?:window\.)?alert\s*\(/);
    assert.doesNotMatch(script, /getElementById\('site-message'\)/);
    assert.doesNotMatch(script, /classList\.add\('show'\)/);
    assert.doesNotMatch(script, /setTimeout/);
    assert.match(script, /shared\.show\(type, message\)/);
});

test('floating Groq visibility still follows the editor expand/collapse', () => {
    const h = harness({floating: true});
    assert.equal(h.floatingRoot.classes['is-mobile-navigation-ai-inactive'], false);
    assert.equal(h.floatingRoot['aria-hidden'], 'false');
    h.editor.open = false;
    h.toggle();
    assert.equal(h.floatingRoot.classes['is-mobile-navigation-ai-inactive'], true);
    assert.equal(h.floatingRoot['aria-hidden'], 'true');
});

test('main mobile menu actions stay separate from the AI notification migration', () => {
    assert.doesNotMatch(menuEditor, /anabelka-mobile-navigation-flash|consumeStoredFlash|storeFlash/);
    assert.match(menuEditor, /shared\.store\('success', message\)/);
    assert.match(menuEditor, /AnabelkaNotify/);
    assert.match(view, /id="site-message"/);
    assert.equal((view.match(/id="site-message"/g) || []).length, 1);
    assert.match(view, /data-mobile-navigation-ai-translate/);
    assert.match(loader, /admin-mobile-navigation-ai-translation\.js\?v=3/);
    assert.match(header, /admin-nav\.js\?v=33/);
});
