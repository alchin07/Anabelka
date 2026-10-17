import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const nav = fs.readFileSync('js/admin-nav.js', 'utf8');
const header = fs.readFileSync('views/admin/partials/header.php', 'utf8');
const deliveryAi = fs.readFileSync('js/admin-delivery-ai-translation.js', 'utf8');

function extract(name, stop, context) {
    const from = nav.indexOf('    function ' + name + '(');
    const to = nav.indexOf(stop, from);
    assert.ok(from >= 0 && to > from, name + ' exists');
    return vm.runInNewContext('(function () {\n' + nav.slice(from, to) +
        '\nreturn ' + name + ';\n})()', context);
}

test('delivery buttons render regardless of provider readiness or permission marker', () => {
    for (const state of [null, Promise.resolve(false), Promise.resolve(true)]) {
        const added = [];
        const init = extract('appendDeliveryAiWhenReady', '    function ensurePageAiModules()', {
            aiTranslationModuleReady: state,
            appendScript(src, marker) {added.push([src, marker]);}
        });
        init();
        assert.deepEqual(added, [
            ['/Anabelka/js/admin-delivery-ai-translation.js?v=5', 'data-admin-delivery-ai']
        ]);
    }
});

test('provider script is permission gated, and delivery click must check access', () => {
    assert.match(nav, /!document\.querySelector\('\.admin-drawer-ai'\)/);
    assert.match(deliveryAi, /document\.querySelector\('\.admin-drawer-ai'\)/);
    assert.match(nav, /window\.AnabelkaAITranslationReady\s*=\s*aiTranslationModuleReady/);
    assert.match(deliveryAi, /waitForAiModule\(\)/);
    assert.match(deliveryAi, /Promise\.race\(/);
    assert.match(deliveryAi, /window\.clearTimeout/);
    assert.doesNotMatch(deliveryAi, /deliveryAiMessageTimer|\bwindow\.alert\(/);
});

test('Android cache versions and load event callbacks are intact', () => {
    assert.match(header, /admin-nav\.js\?v=34/);
    assert.match(nav, /admin-ai-translation\.js\?v=16/);
    assert.match(nav, /admin-delivery-ai-translation\.js\?v=5/);
    assert.match(nav, /script\.async\s*=\s*false/);
    assert.match(nav, /script\.addEventListener\('load'/);
    assert.match(nav, /script\.addEventListener\('error'/);
    assert.match(nav, /notify\.error\(/);
});

test('delivery translation target loads independently', () => {
    const actions = [];
    const run = extract('ensurePageAiModules', '    function markActiveSection()', {
        window: {location: {pathname: '/Anabelka/admin/delivery'}},
        appendDeliveryAiWhenReady() {actions.push('buttons');},
        appendScript(path, name) {actions.push([path, name]);}
    });
    run();
    assert.deepEqual(actions, ['buttons',
        ['/Anabelka/js/admin-translation-target.js?v=2', 'data-admin-translation-target']]);
});
