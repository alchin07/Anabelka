import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = file => fs.readFileSync(file, 'utf8');
const editor = read('js/admin-rank-translations.js');
const view = read('views/admin/ranks/index.php');
const bootstrap = read('app/Core/NotificationBootstrap.php');
const front = read('index.php');

function createHarness({sourceName = 'Самовивіз', provider = 'success', notify = true} = {}) {
    const notices = [];
    const requests = [];
    const handlers = {};
    const flash = {textContent: 'Помилка збереження.', classList: {
        remove() {throw new Error('AI may not alter server flash');}
    }};
    const translated = {value: 'Попередній переклад'};
    const translationSource = {value: 'manual'};
    const origin = {textContent: 'Ручний переклад'};
    const status = {value: 'approved'};
    const source = {value: sourceName};
    const block = {
        querySelector(selector) {
            return ({
                '[data-rank-translation-name]': translated,
                '[data-rank-translation-source]': translationSource,
                '[data-rank-translation-origin]': origin,
                '[data-rank-translation-status]': status
            })[selector] || null;
        }
    };
    const card = {
        querySelector(selector) {
            return selector === '.admin-rank-source-name' ? source : null;
        }
    };
    const button = {
        dataset: {targetLanguage: 'en'},
        textContent: 'Перекласти через ШІ',
        disabled: false,
        closest(selector) {
            if (selector === '.admin-rank-card') return card;
            if (selector === '[data-rank-translation]') return block;
            return null;
        }
    };
    const document = {
        querySelector(selector) {
            if (selector === 'link[data-admin-rank-translations]') return {};
            if (selector === '.admin-rank-message') return flash;
            return null;
        },
        addEventListener(event, cb) {handlers[event] = cb;}
    };
    const window = {
        alert() {throw new Error('Native browser alert is forbidden');},
        AnabelkaNotify: notify ? {
            show(type, message) {notices.push([type, message]); return true;}
        } : null
    };
    if (provider === 'success') {
        window.AnabelkaAITranslation = {
            async suggest(input) {
                requests.push(input);
                return {name: 'Self-pickup'};
            }
        };
    } else if (provider === 'failure') {
        window.AnabelkaAITranslation = {
            async suggest(input) {
                requests.push(input);
                throw new Error('Groq тимчасово недоступний');
            }
        };
    }

    vm.runInNewContext(editor, {document, window}, {
        filename: 'js/admin-rank-translations.js'
    });

    const click = () => handlers.click({
        target: {
            closest(selector) {
                return selector === '[data-rank-ai-translate]' ? button : null;
            }
        }
    });
    const typeManually = () => handlers.input({
        target: {
            closest(selector) {
                return selector === '[data-rank-translation-name]' ? {
                    closest() {return block;},
                    value: translated.value
                } : null;
            }
        }
    });
    return {
        window, flash, notices, requests, button, source,
        translated, translationSource, origin, status,
        click, typeManually
    };
}

test('rank AI uses the shared notification queue without a native alert or rewriting server flashes', async () => {
    const h = createHarness();
    await h.click();
    assert.deepEqual(h.notices, [
        ['info', 'ШІ-переклад отримано. Перевірте назву та натисніть «Зберегти».']
    ]);
    assert.equal(h.flash.textContent, 'Помилка збереження.');
    assert.equal(h.translated.value, 'Self-pickup');
    assert.equal(h.translationSource.value, 'ai');
    assert.equal(h.origin.textContent, 'Створено ШІ');
    assert.equal(h.status.value, 'draft');
    assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Перекласти через ШІ');
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].targetLanguage, 'en');
    assert.equal(h.requests[0].name, 'Самовивіз');
    assert.equal(h.requests[0].description, '');
    assert.equal(h.requests[0].context, 'user_rank');
});

test('missing Ukrainian rank name warns without calling AI or modifying server notices', async () => {
    const h = createHarness({sourceName: '  '});
    await h.click();
    assert.deepEqual(h.notices, [
        ['warning', 'Спочатку вкажіть українську назву рангу.']
    ]);
    assert.equal(h.requests.length, 0);
    assert.equal(h.translated.value, 'Попередній переклад');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.flash.textContent, 'Помилка збереження.');
});

test('unavailable AI warns while leaving editable values unchanged', async () => {
    const h = createHarness({provider: 'unavailable'});
    await h.click();
    assert.equal(h.notices.length, 1);
    assert.equal(h.notices[0][0], 'warning');
    assert.equal(h.requests.length, 0);
    assert.equal(h.translated.value, 'Попередній переклад');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.button.disabled, false);
});

test('failed provider shows error and restores the button without changing the rank', async () => {
    const h = createHarness({provider: 'failure'});
    await h.click();
    assert.deepEqual(h.notices, [['error', 'Groq тимчасово недоступний']]);
    assert.equal(h.translated.value, 'Попередній переклад');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.requests.length, 1);
    assert.equal(h.button.disabled, false);
    assert.equal(h.button.textContent, 'Перекласти через ШІ');
    assert.equal(h.flash.textContent, 'Помилка збереження.');
});

test('manual change after an AI draft retains existing workflow behavior', async () => {
    const h = createHarness();
    await h.click();
    h.translated.value = 'Manual pickup';
    h.typeManually();
    assert.equal(h.translationSource.value, 'manual');
    assert.equal(h.origin.textContent, 'Ручний переклад');
    assert.equal(h.status.value, 'approved');
    assert.equal(h.requests.length, 1);
    assert.equal(h.notices.length, 1);
});

test('unavailable notification API never triggers alert or overwrites blocking notices', async () => {
    const h = createHarness({sourceName: '', notify: false});
    await h.click();
    assert.deepEqual(h.notices, []);
    assert.equal(h.flash.textContent, 'Помилка збереження.');
    assert.equal(h.button.disabled, false);
});

test('rank editor uses global notifications and leaves separate server messages intact', () => {
    assert.match(view, /admin-rank-translations\.js\?v=2/);
    assert.match(view, /class="admin-rank-message is-error"/);
    assert.match(view, /class="admin-rank-message"/);
    assert.doesNotMatch(view, /id="site-message"/);
    assert.match(front, /NotificationBootstrap::register\(\)/);
    assert.match(bootstrap, /js\/anabelka-notify\.js/);
    assert.match(bootstrap, /css\/anabelka-notify\.css/);
    assert.doesNotMatch(editor, /\b(?:window\.)?alert\s*\(/);
    assert.doesNotMatch(editor, /querySelector\('\.admin-rank-message'\)/);
    assert.match(editor, /notify\.show\(type, text\)/);
});
