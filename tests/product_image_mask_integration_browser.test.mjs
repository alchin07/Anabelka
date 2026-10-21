import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import test, {before, after} from 'node:test';
import {productEditorFixture} from './helpers/product-editor-fixture.mjs';

const require = createRequire(import.meta.url);
const dependencyRoots = [process.env.ANABELKA_UI_TEST_DEPS, process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,
    fileURLToPath(new URL('../../test-deps/', import.meta.url))].filter(Boolean);
let playwright;
try { playwright = require('playwright'); } catch {
    for (const root of dependencyRoots) {
        try { playwright = createRequire(path.resolve(root, 'package.json'))('playwright'); break; } catch {}
    }
}
assert.ok(playwright, 'Playwright is required; integration browser tests must run.');
const repo = fileURLToPath(new URL('../', import.meta.url));
const width = Number(process.env.ANABELKA_MASK_SOURCE_WIDTH || 2500);
const height = Number(process.env.ANABELKA_MASK_SOURCE_HEIGHT || 3250);
const token = 'browser-review-token';
const processing = {status: 'ready', master_width: 1200, master_height: 1800,
    normalization: {mask_mode_requested: 'modnet', background_profile_requested: 'studio-light'}};
const actualReview = process.env.ANABELKA_MASK_REVIEW_FILE
    ? JSON.parse(fs.readFileSync(process.env.ANABELKA_MASK_REVIEW_FILE, 'utf8')).initial_review : null;
const assetUrl = (asset, revision = 0) => `/Anabelka/admin/products/image-process-mask-file?image_id=17&preview_id=${token}&revision=${revision}&asset=${asset}`;
const review = (revision = 0, status = 'needs_review', actual = false, actions = []) => ({success: true, state: 'needs_review', image_id: 17,
    preview_id: token, review_available: true, revision, review: {revision, status, width, height,
        regions: actual && actualReview ? actualReview.regions : [{id: 1, bbox: [330, 2460, 140, 140]}],
        reasons: actual && actualReview ? actualReview.reasons : ['source_background_retained'],
        gates: actual && actualReview ? actualReview.gates : {component: {accepted: true}, background: {accepted: false}},
        actions, initial_region_count: actual && actualReview ? actualReview.regions.length : 1,
        action_results: actions.map((_,index)=>({index,status:status === 'accepted'?'accepted':'rejected',
            reasons:status === 'accepted'?[]:['unsafe_background_annotation']})),
        source_url: assetUrl('source', revision), alpha_url: assetUrl('alpha', revision), raw_alpha_url: assetUrl('raw-alpha', revision)}});
const ready = (revision = 0) => ({success: true, state: 'ready', image_id: 17, preview_id: token,
    review_available: true, revision, processing,
    preview_url: `/Anabelka/admin/products/image-process-preview-file?image_id=17&preview_id=${token}&revision=${revision}`});
let browser;
let server;
let origin;
before(async () => {
    const html = productEditorFixture();
    browser = await playwright.chromium.launch({headless: true, executablePath: process.env.ANABELKA_UI_TEST_BROWSER || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
    server = http.createServer((req, res) => {
        const url = new URL(req.url, 'http://localhost');
        const pathname = url.pathname;
        if (pathname === '/Anabelka/admin/products') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8'); res.end(html);
        } else if (/^\/Anabelka\/(css|js)\//.test(pathname)) {
            res.setHeader('Content-Type', pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(path.join(repo, pathname.slice('/Anabelka/'.length))));
        } else if (pathname.endsWith('/variant-stock')) {
            res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({success: true, rows: []}));
        } else if (pathname.endsWith('image-process-mask-file') || pathname.endsWith('image-process-preview-file')
            || pathname.includes('/uploads/products/')) {
            const isAlpha = ['alpha','raw-alpha'].includes(url.searchParams.get('asset'));
            const fixturePath = isAlpha ? process.env.ANABELKA_MASK_ALPHA_FILE : process.env.ANABELKA_MASK_SOURCE_FILE;
            if (fixturePath) {
                res.setHeader('Content-Type', /\.jpe?g$/i.test(fixturePath) ? 'image/jpeg' : 'image/png');
                res.end(fs.readFileSync(fixturePath));
            } else {
                res.setHeader('Content-Type', 'image/svg+xml');
                res.end(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="${isAlpha ? '#fff' : '#e4d4ef'}"/></svg>`);
            }
        } else {res.writeHead(404); res.end();}
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
});
after(async () => {if (browser) await browser.close(); if (server) await new Promise(resolve => server.close(resolve));});

async function harness(t, options = {}) {
    const context = await browser.newContext({viewport: {width: options.width || 390, height: options.height || 844}, hasTouch: true});
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const calls = [];
    let revision = 0;
    let journal = [];
    let reviewStatus = 'needs_review';
    let editGate;
    t.after(async () => {if (editGate) editGate(); await context.close(); assert.deepEqual(errors, [], 'Application JS must not throw.');});
    await page.route('**/image-process-*', async route => {
        const action = new URL(route.request().url()).pathname.split('image-process-')[1];
        if (action.endsWith('-file')) {await route.continue(); return;}
        const body = route.request().postData() || '';
        calls.push({action, body});
        let result;
        if (action === 'preview') result = options.refused ? review(0, 'needs_review', options.actualReview) : ready();
        else if (action === 'mask-start') result = review(revision, reviewStatus, options.actualReview, journal);
        else if (action === 'mask-edit') {
            if (options.delayEdit) await new Promise(resolve => {editGate = resolve;});
            journal = JSON.parse(body.match(/name="actions"\r\n\r\n([^\r]+)/)[1]);
            reviewStatus = options.rejectEdit ? 'needs_review' : 'accepted';
            result = review(++revision, reviewStatus, false, journal);
        } else if (action === 'mask-result') result = options.resultFailsOnce
            && calls.filter(call => call.action === 'mask-result').length === 1
            ? {success: false, message: 'Проблема результату перевірки.'} : ready(revision);
        else if (action === 'confirm') result = {success: true, image_id: 17,
            processing: {...processing, master_path: 'uploads/products/processed/accepted-mask.jpg'}};
        else result = {success: true};
        try {await route.fulfill({contentType: 'application/json', body: JSON.stringify(result)});} catch {}
    });
    await page.goto(origin + '/Anabelka/admin/products');
    await page.locator('[data-product-edit][data-product-id="1"]').click();
    await page.locator('details').filter({has: page.locator('#product-image-list')}).locator('summary').click();
    await page.locator('[data-product-image-process]').click();
    if (options.refused) await page.locator('.mask-editor').waitFor();
    else {
        await page.locator('.product-image-compare-modal').waitFor();
        await page.locator('[data-product-mask-edit]').click();
        await page.locator('.mask-editor').waitFor();
    }
    await page.waitForFunction(() => document.querySelector('.mask-editor')?.dataset.ready === 'true');
    const snapshot = () => page.locator('#product-image-list').evaluate(node => node.innerHTML);
    return {page, calls, snapshot, releaseEdit: () => editGate?.(),
        back: async () => {await page.evaluate(() => history.back()); await page.waitForTimeout(90);},
        mark: async () => {
            const box = await page.locator('[data-mask-stage]').boundingBox();
            await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
            await page.waitForFunction(() => window.AnabelkaMaskEditor.getActions().length > 0);
        }};
}

test('quality refusal enters actual mask review and cancellation preserves the live photo', async t => {
    const h = await harness(t, {refused: true});
    const before = await h.snapshot();
    assert.ok((await h.page.locator('[data-mask-status]').innerText()).includes('залишився фон'), 'Initial refusal has a useful reason');
    await h.page.locator('[data-mask-help]').click();
    assert.ok((await h.page.locator('[data-mask-gate="component"]').innerText()).includes('збережено'));
    assert.ok((await h.page.locator('[data-mask-gate="background"]').innerText()).includes('потребує уточнення'));
    await h.page.locator('[data-mask-helper-close]').click();
    await h.page.waitForTimeout(100);
    assert.equal(await h.page.locator('[data-product-image-preview-confirm]').count(), 0);
    await h.page.locator('[data-mask-cancel]').click();
    await h.page.waitForFunction(() => !window.AnabelkaMaskEditor.isOpen());
    assert.equal(await h.snapshot(), before);
    assert.equal(h.calls.some(call => call.action === 'cancel'), true);
    assert.equal(await h.page.locator('#product-editor').isVisible(), true);
});

test('dirty helper Back preserves mask history before preview and product editor', async t => {
    const h = await harness(t);
    const initialLength = await h.page.evaluate(() => history.length);
    await h.mark();
    assert.equal(await h.page.evaluate(() => history.length), initialLength, 'A point adds no history entry');
    await h.page.locator('[data-mask-help]').click();
    assert.equal(await h.page.evaluate(() => history.length), initialLength + 1, 'Helper owns one entry');
    await h.back();
    assert.equal(await h.page.locator('.mask-editor-aux').count(), 0);
    assert.equal(await h.page.locator('.mask-editor').count(), 1);
    await h.back();
    await h.page.locator('[data-mask-keep]').waitFor();
    await h.page.locator('[data-mask-keep]').click();
    await h.page.waitForFunction(() => !window.AnabelkaMaskEditor.hasAuxiliary());
    assert.equal(await h.page.locator('.mask-editor').count(), 1);
    await h.back();
    await h.page.locator('[data-mask-discard]').click();
    await h.page.waitForFunction(() => !window.AnabelkaMaskEditor.isOpen());
    await h.page.waitForTimeout(100);
    assert.equal(await h.page.locator('.product-image-compare-modal').count(), 1);
    assert.equal(await h.page.locator('[data-product-image-preview-confirm]').isDisabled(), true);
    await h.back();
    assert.equal(await h.page.locator('.product-image-compare-modal').count(), 0);
    assert.equal(await h.page.locator('#product-editor').isVisible(), true);
    await h.back();
    assert.equal(await h.page.locator('#product-editor').isVisible(), false);
});

test('validated mask returns to comparison and only explicit confirmation changes processing', async t => {
    const h = await harness(t);
    const before = await h.snapshot();
    await h.mark();
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => !window.AnabelkaMaskEditor.isOpen());
    await h.page.waitForTimeout(100);
    assert.equal(await h.snapshot(), before);
    assert.equal(h.calls.filter(call => call.action === 'mask-edit').length, 1);
    assert.equal(h.calls.filter(call => call.action === 'mask-result').length, 1);
    assert.equal(h.calls.filter(call => call.action === 'cancel').length, 0);
    await h.page.locator('[data-product-image-preview-confirm]').click();
    await h.page.waitForFunction(() => !document.querySelector('.product-image-compare-modal'));
    assert.ok(h.calls.find(call => call.action === 'confirm').body.includes('name="revision"\r\n\r\n1'));
    await h.page.locator('[data-product-image-compare]').click();
    await h.page.locator('.product-image-compare-modal').waitFor();
    assert.ok((await h.page.locator('.product-image-compare-processed').getAttribute('src')).endsWith('accepted-mask.jpg'));
});

test('refused local edits stay in review and never request a result or confirmation', async t => {
    const h = await harness(t, {rejectEdit: true});
    await h.mark();
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => document.querySelector('[data-mask-status]').textContent.includes('потребує уточнення'));
    assert.equal(await h.page.locator('.mask-editor').count(), 1);
    assert.equal(h.calls.some(call => ['mask-result', 'confirm'].includes(call.action)), false);
});

test('a result failure keeps the current mask revision available for retry', async t => {
    const h = await harness(t, {resultFailsOnce: true});
    await h.mark();
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => !document.querySelector('[data-mask-validate]').disabled);
    assert.equal(await h.page.locator('.mask-editor').count(), 1);
    assert.equal(await h.page.locator('[data-product-image-preview-confirm]').isDisabled(), true);
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => !window.AnabelkaMaskEditor.isOpen());
    assert.equal(h.calls.filter(call => call.action === 'mask-result').length, 2);
    assert.equal(h.calls.filter(call => call.action === 'mask-edit').length, 1, 'Rendering retry must preserve the accepted revision');
});

test('Escape handles a dirty mask helper without closing the underlying comparison', async t => {
    const h = await harness(t);
    await h.mark();
    await h.page.keyboard.press('Escape');
    await h.page.locator('[data-mask-keep]').waitFor();
    assert.equal(await h.page.locator('.product-image-compare-modal').count(), 1);
    await h.page.keyboard.press('Escape');
    assert.equal(await h.page.locator('.mask-editor-aux').count(), 0);
    assert.equal(await h.page.locator('.mask-editor').count(), 1);
    assert.equal(await h.page.locator('#product-editor').isVisible(), true);
});

test('reset sends an empty cumulative action log using the current review revision', async t => {
    const h = await harness(t, {rejectEdit: true});
    await h.mark();
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => document.querySelector('.mask-editor').getAttribute('aria-busy') === 'false' && document.querySelector('.mask-editor').dataset.ready === 'true');
    await h.page.locator('[data-mask-reset]').click();
    await h.page.locator('[data-mask-confirm-reset]').click();
    await h.page.waitForTimeout(100);
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForFunction(() => document.querySelector('.mask-editor').getAttribute('aria-busy') === 'false' && document.querySelector('.mask-editor').dataset.ready === 'true');
    const edit = h.calls.filter(call => call.action === 'mask-edit').at(-1);
    assert.ok(edit.body.includes('name="actions"\r\n\r\n[]'));
    assert.ok(edit.body.includes('name="revision"\r\n\r\n1'));
    assert.equal(h.calls.some(call => call.action === 'confirm'), false);
});

test('a late edit completion after forced editor closure cannot reopen a layer', async t => {
    const h = await harness(t, {delayEdit: true});
    await h.mark();
    await h.page.locator('[data-mask-validate]').click();
    await h.page.waitForTimeout(100);
    await h.page.evaluate(() => document.querySelector('[data-product-close]').click());
    h.releaseEdit();
    await h.page.waitForTimeout(150);
    assert.equal(await h.page.locator('.mask-editor').count(), 0);
    assert.equal(await h.page.locator('.product-image-compare-modal').count(), 0);
    assert.equal(await h.page.locator('#product-editor').isVisible(), false);
    assert.equal(h.calls.some(call => call.action === 'mask-result'), false);
});

for (const [screenWidth, screenHeight] of [[320, 640], [360, 800], [390, 844], [412, 915], [768, 1024],
    [640, 320], [800, 360], [844, 390], [915, 412], [1024, 768]]) {
    test(`integrated mask fits ${screenWidth}x${screenHeight} with accessible controls`, async t => {
        const h = await harness(t, {width: screenWidth, height: screenHeight, actualReview: true});
        for (const selector of ['[data-mask-close]', '[data-mask-previous]', '[data-mask-next]', '[data-mask-help]',
            '[data-mask-cancel]', '[data-mask-validate]']) {
            const box = await h.page.locator(selector).boundingBox();
            assert.ok(box.width >= 44 && box.height >= 44, selector + ' has a 44px target');
            assert.ok(box.x >= 0 && box.y >= 0 && box.x + box.width <= screenWidth + 1
                && box.y + box.height <= screenHeight + 1, selector + ' fits the viewport: ' + JSON.stringify(box));
        }
        assert.ok(await h.page.locator('[data-mask-stage]').evaluate(node => node.clientHeight) >= 70);
        if (process.env.ANABELKA_UI_TEST_SCREENSHOTS) {
            fs.mkdirSync(process.env.ANABELKA_UI_TEST_SCREENSHOTS, {recursive: true});
            await h.page.screenshot({path: path.join(process.env.ANABELKA_UI_TEST_SCREENSHOTS,
                `integrated-mask-${screenWidth}x${screenHeight}.png`)});
        }
    });
}
