import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const nav = fs.readFileSync('js/admin-nav.js', 'utf8');
const header = fs.readFileSync('views/admin/partials/header.php', 'utf8');
const deliveryAi = fs.readFileSync('js/admin-delivery-ai-translation.js', 'utf8');

function extract(name, endMarker, context) {
    const start = nav.indexOf('    function ' + name + '(');
    const end = nav.indexOf(endMarker, start);
    assert.ok(start >= 0 && end > start, name + ' function exists');
    return vm.runInNewContext(
        '(function () {\n' + nav.slice(start, end) +
        '\nreturn ' + name + ';\n})()',
        context
    );
}

test('delivery AI loader waits for shared provider readiness before adding buttons', async () => {
    const callbacks = {};
    const script = {
        addEventListener(type, fn) {callbacks[type] = fn;}
    };
    const appended = [];
    const window = {};
    const promise = new Promise(resolve => {callbacks.resolve = resolve;});
    const load = extract('appendDeliveryAiWhenReady', '    function ensurePageAiModules()', {
        aiTranslationModuleReady: promise,
        appendScript(src, name) {appended.push([src, name]);}
    });

    load();
    assert.equal(appended.length, 0, 'Button script must not load before shared provider');
    callbacks.resolve(true);
    await promise;
    await Promise.resolve();
    assert.deepEqual(appended, [
        ['/Anabelka/js/admin-delivery-ai-translation.js?v=4', 'data-admin-delivery-ai']
    ]);
});

test('failed or unauthorized AI loader does not create unusable delivery buttons', async () => {
    const calls = [];
    const denied = extract('appendDeliveryAiWhenReady', '    function ensurePageAiModules()', {
        aiTranslationModuleReady: null,
        appendScript() {calls.push('loaded');}
    });
    denied();
    assert.deepEqual(calls, []);

    const failed = extract('appendDeliveryAiWhenReady', '    function ensurePageAiModules()', {
        aiTranslationModuleReady: Promise.resolve(false),
        appendScript() {calls.push('loaded');}
    });
    failed();
    await Promise.resolve();
    assert.deepEqual(calls, []);
});

test('provider script has ordered async=false load handling and an explicit failure notice', () => {
    assert.match(nav, /script\.async\s*=\s*false/);
    assert.match(nav, /aiTranslationModuleReady\s*=\s*new Promise/);
    assert.match(nav, /script\.addEventListener\('load'/);
    assert.match(nav, /script\.addEventListener\('error'/);
    assert.match(nav, /function showAiModuleLoadError\(\)/);
    assert.match(nav, /notify\.error\(/);
    assert.match(nav, /window\.AnabelkaAITranslation\.suggest\s*===\s*'function'/);
    assert.match(nav, /!document\.querySelector\('\.admin-drawer-ai'\)/);
    assert.match(header, /admin-nav\.js\?v=30/);
    assert.match(nav, /admin-ai-translation\.js\?v=14/);
    assert.doesNotMatch(deliveryAi, /deliveryAiMessageTimer|\bwindow\.alert\(/);
});

test('page still attaches independent translation-target navigation without AI access', () => {
    const page = extract('ensurePageAiModules', '    function markActiveSection()', {
        window: {location: {pathname: '/Anabelka/admin/delivery'}},
        appendDeliveryAiWhenReady() {},
        appendScript(src, attribute) {
            assert.equal(src, '/Anabelka/js/admin-translation-target.js?v=2');
            assert.equal(attribute, 'data-admin-translation-target');
        }
    });
    page();
});
