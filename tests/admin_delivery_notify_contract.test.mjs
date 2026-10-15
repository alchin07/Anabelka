import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');

test('delivery includes one accessible host and notification assets before its handlers', () => {
    const html = read('views/admin/delivery/index.php');
    assert.equal((html.match(/id="site-message"/g) || []).length, 1);
    assert.equal((html.match(/src="\/Anabelka\/js\/anabelka-notify\.js\?v=/g) || []).length, 1);
    assert.equal((html.match(/href="\/Anabelka\/css\/anabelka-notify\.css\?v=/g) || []).length, 1);
    assert.ok(html.indexOf('js/anabelka-notify.js') < html.indexOf('js/admin-delivery/common.js'));
    assert.match(html, /aria-live="polite"/);
    assert.doesNotMatch(html, /src="\/Anabelka\/js\/admin-delivery\.js/);
});

test('delivery uses shared queue and stores reload flashes instead of custom timers', () => {
    const calls = [];
    const window = {AnabelkaNotify: {
        show(type, message) { calls.push(['show', type, message]); return true; },
        store(type, message) { calls.push(['store', type, message]); return true; }
    }};
    vm.runInNewContext(read('js/admin-delivery/common.js'), {window}, {filename: 'common.js'});
    assert.equal(window.showMessage('Збережено', 'success'), true);
    assert.equal(window.showMessage('Після оновлення', 'success', true), true);
    assert.equal(window.showMessage('Часткове збереження', 'warning', true), true);
    assert.equal(window.showMessage('Інфо', 'unknown'), true);
    assert.deepEqual(calls, [
        ['show', 'success', 'Збережено'],
        ['store', 'success', 'Після оновлення'],
        ['store', 'warning', 'Часткове збереження'],
        ['show', 'info', 'Інфо']
    ]);

    window.AnabelkaNotify.store = () => false;
    assert.equal(window.showMessage('Storage закритий', 'error', true), true);
    assert.deepEqual(calls.at(-1), ['show', 'error', 'Storage закритий']);
    delete window.AnabelkaNotify;
    assert.equal(window.showMessage('API недоступний', 'error'), false);
    assert.doesNotMatch(read('js/admin-delivery/common.js'), /siteMessageTimer|classList\.add\('show'\)/);
});

test('delivery AJAX outcomes distinguish success, failure and partial success', () => {
    const cases = [
        ['toggle.js', 2, /'success'\s*\)/, /'error'\s*\)/],
        ['edit.js', 3, /'success'\s*\)/, /'error'\s*\)/],
        ['delete.js', 2, /'success',\s*true\s*\)/, /'error'\s*\)/],
        ['add.js', 2, /'success',\s*true\s*\)/, /'error'\s*\)/],
        ['add-service.js', 2, /'success',\s*true\s*\)/, /'error'\s*\)/],
        ['add-option.js', 2, /settingsSaved \? 'success' : 'warning',\s*true/, /'error'\s*\)/]
    ];
    for (const [name, expectedCalls, successPattern, errorPattern] of cases) {
        const js = read('js/admin-delivery/' + name);
        assert.equal((js.match(/(?:window\.)?showMessage\(/g) || []).length, expectedCalls, name);
        assert.match(js, successPattern, name + ' success');
        assert.match(js, errorPattern, name + ' error');
    }
});
