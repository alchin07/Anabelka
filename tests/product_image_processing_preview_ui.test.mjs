import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createRequire} from 'node:module';
import test from 'node:test';

const require = createRequire(import.meta.url);
let JSDOM;
try {
    ({JSDOM} = require('jsdom'));
} catch {
    // Keep the application dependency free; CI can supply its DOM test runtime.
    const dependencyRoot = process.env.ANABELKA_UI_TEST_DEPS
        || new URL('../../test-deps/', import.meta.url).pathname;
    ({JSDOM} = createRequire(dependencyRoot + '/package.json')('jsdom'));
}

const source = fs.readFileSync(new URL('../js/admin-products.js', import.meta.url), 'utf8');
const pause = () => new Promise(resolve => setTimeout(resolve, 45));
const response = data => ({ok: true, text: async () => JSON.stringify(data)});
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return {promise, resolve, reject};
};
const accepted = {
    status: 'ready', master_path: 'uploads/products/processed/accepted.jpg',
    master_width: 600, master_height: 900,
    normalization: {background_profile_requested: 'original-canvas'}
};
const candidate = {
    status: 'ready', master_width: 1200, master_height: 1800,
    normalization: {
        method: 'standard-canvas-fallback', background_profile_requested: 'studio-light', mask_mode_requested: 'modnet',
        mask_method: 'opencv-grabcut', subject_mask_applied: true
    }
};
const preview = (id = 'preview-1', imageId = 17) => ({
    success: true, image_id: imageId, preview_id: id,
    expires_at: '2099-01-01T00:00:00Z', processing: candidate,
    preview_url: '/Anabelka/admin/products/image-process-preview-file?image_id=' + imageId + '&preview_id=' + id
});

async function harness(t, images = [{id: 17, path: 'uploads/products/original.jpg', processing: accepted}], initialHistory = null) {
    const products = [{id: 1, name: 'Product', stock_mode: 'total', images}];
    const encoded = JSON.stringify(products);
    const ids = ['id', 'category', 'name', 'slug', 'sku', 'description', 'price', 'old-price',
        'stock', 'show-stock', 'material', 'brand', 'country', 'active'];
    const dom = new JSDOM(`<!doctype html><html><body>
        <script type="application/json" id="admin-products-data">${encoded}</script>
        <button data-product-edit data-product-id="1">Edit</button>
        <div id="site-message"></div>
        <section id="product-editor" hidden><button data-product-close>Close</button>
        <strong id="product-editor-title"></strong><span id="product-editor-kicker"></span>
        <form id="product-editor-form"><input name="_csrf" value="csrf-token">
        ${ids.map(id => `<input id="product-edit-${id}">`).join('')}
        <select id="product-edit-stock-mode"><option value="total">Total</option></select>
        <div id="product-size-list"></div><template id="product-size-template">
        <div class="product-size-row"><input data-size-id><input data-size-name>
        <input data-size-stock><button data-size-remove></button></div></template>
        <button type="button" data-size-add>Add size</button><div id="product-image-list"></div>
        <input type="file" id="product-image-input"><div id="product-upload-preview"></div>
        <button class="product-editor-save">Save</button></form></section>
        </body></html>`, {url: 'https://example.test/Anabelka/admin/products', runScripts: 'outside-only'});
    t.after(() => dom.window.close());
    const {window} = dom;
    const calls = [];
    const queued = [];
    const historyMoves = [];
    const originalGo = window.history.go.bind(window.history);
    const originalBack = window.history.back.bind(window.history);
    window.history.go = depth => {historyMoves.push(depth); originalGo(depth);};
    window.history.back = () => {historyMoves.push(-1); originalBack();};
    let liveProducts;
    const parse = window.JSON.parse;
    window.JSON.parse = function (text) {
        const result = parse(text);
        if (text === encoded) liveProducts = result;
        return result;
    };
    window.fetch = (url, options) => {
        calls.push({url, body: Object.fromEntries(options.body.entries()), options});
        if (url.endsWith('image-process-cancel')) return Promise.resolve(response({success: true}));
        const next = queued.shift();
        if (!next) return Promise.reject(new Error('Unexpected request: ' + url));
        return Promise.resolve(next);
    };
    if (initialHistory) window.history.replaceState(initialHistory, '', window.location.href);
    window.eval(source);
    const q = selector => window.document.querySelector(selector);
    const click = selector => {
        const node = typeof selector === 'string' ? q(selector) : selector;
        assert.ok(node, 'Missing control: ' + selector);
        node.click();
    };
    const open = async () => {click('[data-product-edit]'); await pause();};
    await open();
    return {
        window, q, click, calls, queued, open, historyMoves,
        image: () => liveProducts[0].images[0],
        snapshot: () => JSON.stringify(liveProducts[0].images[0].processing),
        back: async () => {window.history.back(); await pause();},
        start: async (data = preview(), card = q('.product-image-manage')) => {
            queued.push(response(data));
            click(card.querySelector('[data-product-image-process]'));
            await pause();
        }
    };
}

function selectProcessing(h, profile = 'studio-light', mode = 'modnet', card = h.q('.product-image-manage')) {
    const profileSelect = card.querySelector('[data-product-image-background-profile]');
    const modeSelect = card.querySelector('[data-product-image-mask-mode]');
    profileSelect.value = profile;
    profileSelect.dispatchEvent(new h.window.Event('change'));
    modeSelect.value = mode;
    modeSelect.dispatchEvent(new h.window.Event('change'));
}

function assertProcessingSelection(h, profile = 'studio-light', mode = 'modnet', card = h.q('.product-image-manage')) {
    assert.equal(card.querySelector('[data-product-image-background-profile]').value, profile,
        'Selected background profile must survive processing state changes');
    assert.equal(card.querySelector('[data-product-image-mask-mode]').value, mode,
        'Selected mask mode must survive processing state changes');
}

function pressKey(h, key, target = h.window.document.activeElement, options = {}) {
    const event = new h.window.KeyboardEvent('keydown', {
        key, bubbles: true, cancelable: true, ...options
    });
    target.dispatchEvent(event);
    return event;
}

test('preview keeps selected profile and mask mode while busy and after receiving the candidate', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    selectProcessing(h);
    const profile = h.q('[data-product-image-background-profile]');
    profile.value = 'original-canvas';
    profile.dispatchEvent(new h.window.Event('change'));
    assertProcessingSelection(h, 'original-canvas', 'modnet');
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, true);
    profile.value = 'studio-light';
    profile.dispatchEvent(new h.window.Event('change'));
    assertProcessingSelection(h);
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-process]');
    assert.deepEqual(h.calls[0].body, {
        _csrf: 'csrf-token', image_id: '17', background_profile: 'studio-light', mask_mode: 'modnet'
    });
    assertProcessingSelection(h);
    assert.equal(h.q('[data-product-image-background-profile]').disabled, true);
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, true);
    pending.resolve(response(preview()));
    await pause();
    assertProcessingSelection(h);
    assert.equal(h.q('[data-product-image-background-profile]').disabled, false);
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
    assert.equal(h.snapshot(), before);
    assert.ok(h.q('.product-image-compare-modal'));
});

test('preview and confirm errors preserve selected profile and mask mode for retry', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    selectProcessing(h);
    h.queued.push(response({success: false, message: 'Preview failed'}));
    h.click('[data-product-image-process]');
    await pause();
    assertProcessingSelection(h);
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
    await h.start();
    assert.deepEqual(h.calls.filter(call => call.url.endsWith('image-process-preview')).at(-1).body, {
        _csrf: 'csrf-token', image_id: '17', background_profile: 'studio-light', mask_mode: 'modnet'
    });
    h.queued.push(response({success: false, message: 'Confirm failed'}));
    h.click('[data-product-image-preview-confirm]');
    await pause();
    assertProcessingSelection(h);
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
    assert.equal(h.snapshot(), before);
    assert.ok(h.q('.product-image-compare-modal'));
});

for (const action of ['cancel', 'close', 'escape', 'back', 'editor-close', 'editor-back']) {
    test(action + ' preserves draft processing selections through editor reopen', async t => {
        const h = await harness(t);
        const before = h.snapshot();
        selectProcessing(h);
        await h.start();
        if (action === 'back' || action === 'editor-back') {
            await h.back();
            if (action === 'editor-back') await h.back();
        } else if (action === 'escape') {
            h.window.document.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        } else {
            h.click(action === 'cancel' ? '[data-product-image-preview-cancel]'
                : action === 'close' ? '.product-image-compare-close' : '[data-product-close]');
        }
        await pause();
        assert.equal(h.q('.product-image-compare-modal'), null);
        assertProcessingSelection(h);
        assert.equal(h.snapshot(), before);
        if (!h.q('#product-editor').hidden) {
            h.click('[data-product-close]');
            await pause();
        }
        await h.open();
        assertProcessingSelection(h);
        assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
        await h.start(preview('retry-after-reopen'));
        assert.deepEqual(h.calls.filter(call => call.url.endsWith('image-process-preview')).at(-1).body, {
            _csrf: 'csrf-token', image_id: '17', background_profile: 'studio-light', mask_mode: 'modnet'
        });
    });
}

test('confirmation replaces draft selections with the applied processing settings', async t => {
    const h = await harness(t);
    selectProcessing(h);
    await h.start();
    const saved = {...candidate, master_path: 'uploads/products/processed/applied.jpg', normalization: {
        ...candidate.normalization, background_profile_requested: 'anabelka-brand',
        background_profile: 'anabelka-brand', mask_mode_requested: 'grabcut'
    }};
    h.queued.push(response({success: true, image_id: 17, processing: saved}));
    h.click('[data-product-image-preview-confirm]');
    await pause();
    assertProcessingSelection(h, 'anabelka-brand', 'grabcut');
    h.click('[data-product-image-compare]');
    assert.match(h.q('.product-image-compare-diagnostics').textContent, /Фон: Anabelka Brand/);
    assert.match(h.q('.product-image-compare-diagnostics').textContent, /Маска: GrabCut/);
    h.click('[data-product-close]');
    await pause();
    await h.open();
    assertProcessingSelection(h, 'anabelka-brand', 'grabcut');
    const profile = h.q('[data-product-image-background-profile]');
    profile.value = 'original-canvas';
    profile.dispatchEvent(new h.window.Event('change'));
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, true);
    profile.value = 'studio-light';
    profile.dispatchEvent(new h.window.Event('change'));
    assert.equal(h.q('[data-product-image-mask-mode]').disabled, false);
    assert.equal(h.q('[data-product-image-mask-mode]').value, 'grabcut');
});

test('profile enables three mask modes and older accepted photos default to auto', async t => {
    const h = await harness(t);
    const mode = h.q('[data-product-image-mask-mode]');
    assert.ok(mode, 'Mask method select must exist');
    assert.equal(mode.value, 'auto');
    assert.equal(mode.disabled, true);
    assert.deepEqual(Array.from(mode.options, option => [option.value, option.textContent]), [
        ['auto', 'Автоматично (рекомендовано)'], ['grabcut', 'GrabCut'], ['modnet', 'MODNet']
    ]);
    const profile = h.q('[data-product-image-background-profile]');
    profile.value = 'studio-light';
    profile.dispatchEvent(new h.window.Event('change'));
    assert.equal(mode.disabled, false);
    profile.value = 'original-canvas';
    profile.dispatchEvent(new h.window.Event('change'));
    assert.equal(mode.disabled, true);
    assert.equal(h.q('[data-product-image-process]').textContent, 'Попередній перегляд');
});

for (const mode of ['auto', 'grabcut', 'modnet']) {
    test('preview sends ' + mode + ' and leaves accepted data unchanged through cancellation', async t => {
        const h = await harness(t);
        const before = h.snapshot();
        const profile = h.q('[data-product-image-background-profile]');
        profile.value = 'studio-light';
        profile.dispatchEvent(new h.window.Event('change'));
        const select = h.q('[data-product-image-mask-mode]');
        assert.ok(select, 'Mask method select must exist');
        select.value = mode;
        const pending = deferred();
        h.queued.push(pending.promise);
        h.click('[data-product-image-process]');
        assert.equal(h.snapshot(), before);
        assert.equal(h.calls[0].url, '/Anabelka/admin/products/image-process-preview');
        assert.deepEqual(h.calls[0].body, {
            _csrf: 'csrf-token', image_id: '17', background_profile: 'studio-light', mask_mode: mode
        });
        pending.resolve(response(preview()));
        await pause();
        assert.equal(h.snapshot(), before);
        assert.ok(h.q('.product-image-compare-processed').src.endsWith(preview().preview_url));
        assert.match(h.q('.product-image-compare-diagnostics').textContent, /Маска: GrabCut/);
        h.click('[data-product-image-preview-cancel]');
        await pause();
        assert.equal(h.q('.product-image-compare-modal'), null);
        assert.equal(h.snapshot(), before);
        assert.equal(h.calls.filter(call => call.url.endsWith('image-process-cancel')).length, 1);
        assert.deepEqual(h.calls.at(-1).body, {_csrf: 'csrf-token', image_id: '17', preview_id: 'preview-1'});
        h.click('[data-product-image-compare]');
        assert.ok(h.q('.product-image-compare-processed').src.endsWith('/Anabelka/uploads/products/processed/accepted.jpg'));
        assert.equal(h.q('[data-product-image-preview-confirm]'), null);
    });
}

test('preview and confirm failures leave the accepted processing intact', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    h.queued.push(response({success: false, message: 'Preview failed'}));
    h.click('[data-product-image-process]');
    await pause();
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.q('.product-image-processing').dataset.processingStatus, 'ready');
    await h.start();
    h.queued.push(response({success: false, message: 'Confirm failed'}));
    h.click('[data-product-image-preview-confirm]');
    await pause();
    assert.equal(h.snapshot(), before);
    assert.ok(h.q('.product-image-compare-modal'));
    assert.equal(h.q('[data-product-image-preview-confirm]').disabled, false);
    assert.match(h.q('#site-message').textContent, /Confirm failed/);
});

test('double clicks issue one preview and one confirm and only confirm updates accepted data', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    const pending = deferred();
    h.queued.push(pending.promise);
    const button = h.q('[data-product-image-process]');
    button.dispatchEvent(new h.window.MouseEvent('click'));
    button.dispatchEvent(new h.window.MouseEvent('click'));
    assert.equal(h.calls.length, 1);
    pending.resolve(response(preview()));
    await pause();
    const commit = deferred();
    h.queued.push(commit.promise);
    const apply = h.q('[data-product-image-preview-confirm]');
    assert.ok(apply, 'Apply action must exist');
    apply.dispatchEvent(new h.window.MouseEvent('click'));
    apply.dispatchEvent(new h.window.MouseEvent('click'));
    assert.equal(h.calls.filter(call => call.url.endsWith('image-process-confirm')).length, 1);
    assert.equal(h.snapshot(), before);
    const saved = {...candidate, master_path: 'uploads/products/processed/new.jpg'};
    commit.resolve(response({success: true, image_id: 17, processing: saved}));
    await pause();
    assert.equal(h.snapshot(), JSON.stringify(saved));
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.match(h.q('[data-product-image-processing-status]').textContent, /1200×1800/);
    assert.equal(h.q('[data-product-image-mask-mode]').value, 'modnet');
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
});

test('Android Back closes details then cancels preview then closes editor', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    await h.start();
    h.click('.product-image-compare-details-button');
    await h.back();
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true);
    assert.ok(h.q('.product-image-compare-modal'));
    assert.equal(h.q('#product-editor').hidden, false);
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
    await h.back();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('#product-editor').hidden, false);
    assert.equal(h.calls.filter(call => call.url.endsWith('image-process-cancel')).length, 1);
    await h.back();
    assert.equal(h.q('#product-editor').hidden, true);
    await h.open();
    await h.back();
    assert.equal(h.q('#product-editor').hidden, true, 'Reopened editor owns a fresh Back entry');
});

for (const action of ['close', 'cancel']) {
    test(action + ' unwinds preview and details history without closing editor', async t => {
        const h = await harness(t);
        await h.start();
        h.click('.product-image-compare-details-button');
        h.click(action === 'close' ? '.product-image-compare-close' : '[data-product-image-preview-cancel]');
        await pause();
        assert.equal(h.q('.product-image-compare-modal'), null);
        assert.equal(h.q('#product-editor').hidden, false);
        assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompare), false);
        assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompareDetails), false);
        assert.equal(h.calls.filter(call => call.url.endsWith('image-process-cancel')).length, 1);
        await h.back();
        assert.equal(h.q('#product-editor').hidden, true);
    });
}

for (const comparison of ['preview', 'accepted', 'confirmed']) {
    test(comparison + ' comparison exposes an accessible details icon in its header', async t => {
        const h = await harness(t);
        if (comparison === 'accepted') {
            h.click('[data-product-image-compare]');
        } else {
            await h.start();
            if (comparison === 'confirmed') {
                const saved = {...candidate, master_path: 'uploads/products/processed/confirmed.jpg'};
                h.queued.push(response({success: true, image_id: 17, processing: saved}));
                h.click('[data-product-image-preview-confirm]');
                await pause();
                h.click('[data-product-image-compare]');
            }
        }
        const button = h.q('.product-image-compare-details-button');
        assert.ok(h.q('.product-image-compare-header').contains(button),
            'Details must remain reachable in the header beside the comparison close control');
        assert.equal(button.getAttribute('aria-label'), 'Деталі обробки');
        assert.equal(button.title, 'Деталі обробки');
        assert.equal(button.querySelector('svg')?.getAttribute('aria-hidden'), 'true');
        assert.equal(button.querySelector('.visually-hidden')?.textContent, 'Деталі обробки');
        h.click(button);
        const dialog = h.q('.product-image-compare-details-dialog');
        assert.equal(dialog.getAttribute('role'), 'dialog');
        assert.equal(dialog.getAttribute('aria-modal'), 'true');
        assert.equal(dialog.getAttribute('aria-label'), 'Деталі обробки');
        assert.equal(h.q('.product-image-compare-details-close').getAttribute('aria-label'),
            'Закрити деталі обробки');
        if (comparison === 'confirmed') {
            assert.match(dialog.textContent, /1200×1800/,
                'Accepted details must describe the newly confirmed result');
        }
    });
}

test('details focus moves inside and Back returns focus to its header icon', async t => {
    const h = await harness(t);
    await h.start();
    const button = h.q('.product-image-compare-details-button');
    button.focus();
    h.click(button);
    assert.equal(h.window.document.activeElement, h.q('.product-image-compare-details-close'));
    await h.back();
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true);
    assert.equal(h.window.document.activeElement, button);
    assert.ok(h.q('.product-image-compare-modal'));
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
});

test('Escape closes details before cancelling preview and preserves the editor Back entry', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    await h.start();
    const button = h.q('.product-image-compare-details-button');
    button.focus();
    h.click(button);
    pressKey(h, 'Escape');
    await pause();
    assert.ok(h.q('.product-image-compare-modal'), 'First Escape closes only processing details');
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true);
    assert.equal(h.window.document.activeElement, button);
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompareDetails), false);
    pressKey(h, 'Escape');
    await pause();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('#product-editor').hidden, false);
    assert.equal(h.calls.filter(call => call.url.endsWith('image-process-cancel')).length, 1);
    await h.back();
    assert.equal(h.q('#product-editor').hidden, true);
});

test('Tab stays inside the active comparison and details dialogs', async t => {
    const h = await harness(t);
    await h.start();
    const dialog = h.q('.product-image-compare-dialog');
    const controls = [...dialog.querySelectorAll('button:not(:disabled), [tabindex="0"]')];
    const first = controls[0];
    const last = controls.at(-1);
    last.focus();
    assert.equal(pressKey(h, 'Tab').defaultPrevented, true,
        'Tab at the last comparison control must not reach the underlying product editor');
    assert.equal(h.window.document.activeElement, first);
    assert.equal(pressKey(h, 'Tab', first, {shiftKey: true}).defaultPrevented, true);
    assert.equal(h.window.document.activeElement, last);
    h.click('.product-image-compare-details-button');
    const close = h.q('.product-image-compare-details-close');
    assert.equal(h.window.document.activeElement, close);
    assert.equal(pressKey(h, 'Tab', close).defaultPrevented, true,
        'Tab inside details must not reach the underlying comparison controls');
    assert.equal(h.window.document.activeElement, close);
    assert.equal(pressKey(h, 'Tab', close, {shiftKey: true}).defaultPrevented, true);
    assert.equal(h.window.document.activeElement, close);
});

test('a preview response after editor close is cancelled and never reopens comparison', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-process]');
    h.click('[data-product-close]');
    await pause();
    pending.resolve(response(preview('late')));
    await pause();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.q('#product-editor').hidden, true);
    assert.equal(h.snapshot(), before);
    assert.ok(h.calls.some(call => call.url.endsWith('image-process-cancel') && call.body.preview_id === 'late'));
});

test('a newer preview keeps its comparison when an earlier request completes late', async t => {
    const h = await harness(t, [
        {id: 17, path: 'uploads/products/one.jpg', processing: accepted},
        {id: 18, path: 'uploads/products/two.jpg', processing: accepted}
    ]);
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-process]');
    const second = h.window.document.querySelectorAll('.product-image-manage')[1];
    await h.start(preview('newer', 18), second);
    pending.resolve(response(preview('older')));
    await pause();
    assert.ok(h.q('.product-image-compare-processed').src.endsWith(preview('newer', 18).preview_url));
    assert.ok(h.calls.some(call => call.url.endsWith('image-process-cancel') && call.body.preview_id === 'older'));
});

test('Back during confirm does not race cancel and committed data is retained after dialog closes', async t => {
    const h = await harness(t);
    await h.start();
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-preview-confirm]');
    await h.back();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.q('#product-editor').hidden, false);
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
    const saved = {...candidate, master_path: 'uploads/products/processed/committed.jpg'};
    pending.resolve(response({success: true, image_id: 17, processing: saved}));
    await pause();
    assert.equal(h.snapshot(), JSON.stringify(saved));
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
});

test('comparison preserves keyboard and pointer slider interactions', async t => {
    const h = await harness(t);
    await h.start();
    const divider = h.q('.product-image-compare-divider');
    const stage = h.q('.product-image-compare-stage');
    assert.equal(h.q('.product-image-compare-slider'), null,
        'The comparison must not render a second lower slider');
    assert.equal(h.q('.product-image-compare-dialog input[type="range"]'), null);
    const assertSplit = value => {
        assert.equal(divider.getAttribute('aria-valuenow'), String(value));
        assert.equal(divider.getAttribute('aria-valuetext'), value + '% ширини оригіналу');
        assert.equal(stage.style.getPropertyValue('--compare-split'), value + '%');
    };
    divider.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'ArrowRight'}));
    assertSplit(52);
    divider.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'Home'}));
    assertSplit(0);
    divider.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'ArrowRight'}));
    assertSplit(2);
    divider.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'End'}));
    assertSplit(100);
    divider.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'ArrowLeft'}));
    assertSplit(98);
    stage.getBoundingClientRect = () => ({left: 10, width: 100});
    let captured = false;
    divider.setPointerCapture = () => {captured = true;};
    divider.hasPointerCapture = () => captured;
    divider.releasePointerCapture = () => {captured = false;};
    const pointer = (type, clientX) => {
        const event = new h.window.MouseEvent(type, {clientX, cancelable: true});
        Object.defineProperty(event, 'pointerId', {value: 1});
        divider.dispatchEvent(event);
    };
    pointer('pointerdown', 35);
    assertSplit(25);
    pointer('pointermove', 90);
    assertSplit(80);
    pointer('pointerup', 90);
    assert.equal(captured, false);
    pointer('pointerdown', -100);
    assertSplit(0);
    pointer('pointermove', 200);
    assertSplit(100);
    pointer('pointercancel', 200);
    assert.equal(captured, false);
    pointer('pointermove', 60);
    assertSplit(100);
});

test('late superseded completion does not unlock a newer request on the same image', async t => {
    const h = await harness(t, [
        {id: 17, path: 'uploads/products/one.jpg', processing: accepted},
        {id: 18, path: 'uploads/products/two.jpg', processing: accepted}
    ]);
    const first = deferred();
    h.queued.push(first.promise);
    h.click('[data-product-image-process]');
    const secondCard = h.window.document.querySelectorAll('.product-image-manage')[1];
    await h.start(preview('second', 18), secondCard);
    const retry = deferred();
    h.queued.push(retry.promise);
    h.click('[data-product-image-process]');
    await pause();
    first.resolve(response(preview('superseded')));
    await pause();
    assert.equal(h.q('[data-product-image-process]').disabled, true,
        'Older response must not unlock the active request');
    retry.resolve(response(preview('retry')));
    await pause();
    assert.ok(h.q('.product-image-compare-processed').src.endsWith(preview('retry').preview_url));
});

test('preview invalid JSON and unsafe URL fail without changing accepted data', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    h.queued.push({ok: true, text: async () => '<html>Sign in</html>'});
    h.click('[data-product-image-process]');
    await pause();
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('.product-image-compare-modal'), null);
    await h.start({...preview('invalid-url'), preview_url: 'https://other.test/master.jpg'});
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.ok(h.calls.some(call => call.url.endsWith('image-process-cancel')
        && call.body.preview_id === 'invalid-url'));
});

test('closing editor during confirm still saves committed data in a reopened editor', async t => {
    const h = await harness(t);
    await h.start();
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-preview-confirm]');
    h.click('[data-product-close]');
    await pause();
    await h.open();
    const saved = {...candidate, master_path: 'uploads/products/processed/after-close.jpg'};
    pending.resolve(response({success: true, image_id: 17, processing: saved}));
    await pause();
    assert.equal(h.snapshot(), JSON.stringify(saved));
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.calls.some(call => call.url.endsWith('image-process-cancel')), false);
    h.click('[data-product-image-compare]');
    assert.ok(h.q('.product-image-compare-processed').src.endsWith('/Anabelka/' + saved.master_path));
});

test('repeated details clicks add one Back entry and Forward clears abandoned preview flags', async t => {
    const h = await harness(t);
    await h.start();
    h.click('.product-image-compare-details-button');
    h.click('.product-image-compare-details-button');
    await h.back();
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true);
    await h.back();
    assert.equal(h.q('.product-image-compare-modal'), null);
    h.window.history.forward();
    await pause();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompare), false);
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompareDetails), false);
});

test('cancel cleanup failure is best effort and preserves accepted data', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    await h.start();
    const fetch = h.window.fetch;
    h.window.fetch = (url, options) => {
        if (url.endsWith('image-process-cancel')) {
            h.calls.push({url, body: Object.fromEntries(options.body.entries()), options});
            return Promise.reject(new Error('Offline'));
        }
        return fetch(url, options);
    };
    h.click('[data-product-image-preview-cancel]');
    await pause();
    assert.equal(h.snapshot(), before);
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.q('#product-editor').hidden, false);
});

test('opening an accepted comparison dismisses a pending preview before its late completion', async t => {
    const h = await harness(t, [
        {id: 17, path: 'uploads/products/one.jpg', processing: accepted},
        {id: 18, path: 'uploads/products/two.jpg', processing: accepted}
    ]);
    const pending = deferred();
    h.queued.push(pending.promise);
    h.click('[data-product-image-process]');
    const secondCard = h.window.document.querySelectorAll('.product-image-manage')[1];
    h.click(secondCard.querySelector('[data-product-image-compare]'));
    const acceptedDialog = h.q('.product-image-compare-modal');
    assert.ok(acceptedDialog);
    pending.resolve(response(preview('late-while-comparing')));
    await pause();
    assert.equal(h.q('.product-image-compare-modal'), acceptedDialog);
    assert.ok(h.q('.product-image-compare-original').src.endsWith('/Anabelka/uploads/products/two.jpg'));
    assert.ok(h.calls.some(call => call.url.endsWith('image-process-cancel')
        && call.body.preview_id === 'late-while-comparing'));
});

for (const outer of ['close', 'escape', 'cancel', 'editor']) {
    test('rapid details close followed by ' + outer + ' serializes history traversal', async t => {
        const h = await harness(t);
        await h.start();
        h.click('.product-image-compare-details-button');
        h.click('.product-image-compare-details-close');
        if (outer === 'escape') {
            h.window.document.dispatchEvent(new h.window.KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        } else {
            h.click(outer === 'close' ? '.product-image-compare-close'
                : outer === 'cancel' ? '[data-product-image-preview-cancel]' : '[data-product-close]');
        }
        await pause();
        assert.equal(h.q('.product-image-compare-modal'), null);
        assert.equal(h.q('#product-editor').hidden, outer === 'editor');
        assert.equal(Boolean(h.window.history.state && h.window.history.state.__anabelkaProductImageCompare), false);
        assert.equal(Boolean(h.window.history.state && h.window.history.state.__anabelkaProductImageCompareDetails), false);
        assert.equal(h.calls.filter(call => call.url.endsWith('image-process-cancel')).length, 1);
        assert.equal(h.historyMoves.reduce((total, depth) => total - depth, 0),
            outer === 'editor' ? 3 : 2, 'Each owned entry is traversed exactly once');
        if (outer !== 'editor') {
            await h.back();
            assert.equal(h.q('#product-editor').hidden, true);
        } else {
            assert.equal(Boolean(h.window.history.state && h.window.history.state.__anabelkaProductEditor), false);
        }
    });
}

test('a page reload with abandoned details history gives the editor a clean new entry', async t => {
    const h = await harness(t, undefined, {
        __anabelkaProductEditor: 55,
        __anabelkaProductImageCompare: true,
        __anabelkaProductImageCompareDetails: true
    });
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompare), false);
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompareDetails), false);
    await h.back();
    assert.equal(h.q('#product-editor').hidden, true);
});

test('details reopen waits for pending close traversal and owns one fresh Back entry', async t => {
    const h = await harness(t);
    await h.start();
    h.click('.product-image-compare-details-button');
    const length = h.window.history.length;
    h.click('.product-image-compare-details-close');
    h.click('.product-image-compare-details-button');
    h.click('.product-image-compare-details-button');
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true,
        'Reopening must wait until the close traversal settles');
    assert.equal(h.window.history.length, length,
        'Pending reopening must not push onto the stale details entry');
    await pause();
    assert.equal(h.q('.product-image-compare-details-modal').hidden, false);
    assert.equal(Boolean(h.window.history.state.__anabelkaProductImageCompareDetails), true);
    await h.back();
    assert.equal(h.q('.product-image-compare-details-modal').hidden, true);
    assert.ok(h.q('.product-image-compare-modal'));
    assert.equal(h.q('#product-editor').hidden, false);
    await h.back();
    assert.equal(h.q('.product-image-compare-modal'), null);
    assert.equal(h.q('#product-editor').hidden, false);
    await h.back();
    assert.equal(h.q('#product-editor').hidden, true);
});
