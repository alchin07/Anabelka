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
    const mask = {opened: null, callbacks: null, auxiliary: false, open(config, callbacks) { this.opened = config; this.callbacks = callbacks; }, isOpen() { return Boolean(this.opened); }, hasAuxiliary() { return this.auxiliary; }, closeAuxiliary() { this.auxiliary = false; this.callbacks.onAuxiliary(false); }, requestClose() { this.close({discarded:true}); }, close(result={}) { this.opened=null; this.callbacks.onClose(result); }, handleBack() { if(this.auxiliary) this.closeAuxiliary(); else this.requestClose(); }};
    window.AnabelkaMaskEditor = mask;
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
        window, mask, q, click, calls, queued, open, historyMoves,
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


const reviewData = () => ({success:true,state:'needs_review',image_id:17,preview_id:'review-1',review_available:true,review:{revision:0,width:2500,height:3250,regions:[{id:1,bbox:[100,200,80,160]}],reasons:['source_local_material_loss'],source_url:'/Anabelka/admin/products/image-process-mask-file?asset=source',alpha_url:'/Anabelka/admin/products/image-process-mask-file?asset=alpha'}});

test('quality refusal opens the mask editor without replacing the live photo or permitting confirmation', async t => {
    const h = await harness(t);
    const before = h.snapshot();
    await h.start(reviewData());
    assert.ok(h.mask.isOpen(), 'needs_review must enter local review');
    assert.equal(h.mask.opened.imageId,17);
    assert.equal(h.mask.opened.width,2500);
    assert.equal(h.snapshot(),before);
    assert.equal(h.q('[data-product-image-preview-confirm]'),null);
    h.mask.close({discarded:true});
    await pause();
    assert.ok(h.calls.some(x=>x.url.endsWith('image-process-cancel')));
    assert.equal(h.snapshot(),before);
});

test('ready preview exposes mask correction and hands validated revised result to existing compare',async t=>{
    const h=await harness(t);
    const before=h.snapshot();
    await h.start({...preview('review-1'),state:'ready',review_available:true,revision:0});
    h.queued.push(response(reviewData()));
    h.click('[data-product-mask-edit]');
    await pause();
    assert.ok(h.mask.isOpen());
    assert.ok(h.q('.product-image-compare-modal'),'original compare must remain underneath');
    h.queued.push(response({...reviewData(),review:{...reviewData().review,revision:1,status:'accepted'}}));
    h.queued.push(response({...preview('review-1'),state:'ready',revision:1}));
    const result=await h.mask.callbacks.onValidate({revision:0,actions:[{kind:'foreground',tool:'point',radius:5,hardness:1,points:[[102,208]]}]},{});
    assert.equal(result.state,'ready');
    h.mask.close({discarded:false,reason:'compare'});
    h.mask.callbacks.onCompare(result);
    await pause();
    assert.equal(h.snapshot(),before,'validation alone never publishes');
    assert.ok(h.q('[data-product-image-preview-confirm]'));
    assert.equal(h.calls.filter(x=>x.url.endsWith('image-process-cancel')).length,0,'revised token must remain live');
});

test('Back closes mask before preview and product editor',async t=>{
    const h=await harness(t);
    await h.start(reviewData());
    assert.ok(h.mask.isOpen());
    await h.back();
    assert.equal(h.mask.isOpen(),false);
    assert.equal(h.q('#product-editor').hidden,false);
    await h.back();
    assert.equal(h.q('#product-editor').hidden,true);
});
