import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import test, {before, after} from 'node:test';

// Keep browser dependencies outside the PHP application's dependency tree.
// npm install --prefix ../test-deps playwright
// ../test-deps/node_modules/.bin/playwright install chromium
// ANABELKA_UI_TEST_DEPS=../test-deps node --test tests/product_image_processing_mobile_browser.test.mjs
// ANABELKA_UI_TEST_BROWSER can point to an already installed Chromium executable.
const require = createRequire(import.meta.url);
const dependencyRoots = [
    process.env.ANABELKA_UI_TEST_DEPS,
    fileURLToPath(new URL('../../test-deps/', import.meta.url)),
    process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES
].filter(Boolean);
let playwright;
try {
    playwright = require('playwright');
} catch {
    for (const root of dependencyRoots) {
        try {
            playwright = createRequire(path.resolve(root, 'package.json'))('playwright');
            break;
        } catch {}
    }
}
assert.ok(playwright, 'Install Playwright in ANABELKA_UI_TEST_DEPS; browser tests must run, not skip.');

const repo = process.env.ANABELKA_UI_TEST_SOURCE_DIR
    ? path.resolve(process.env.ANABELKA_UI_TEST_SOURCE_DIR)
    : fileURLToPath(new URL('../', import.meta.url));
const cssFiles = [
    'style.css', 'catalog.css', 'admin-products.css', 'admin-layout.css',
    'admin-access.css', 'admin-product-editor-fixes.css', 'anabelka-dialog.css', 'anabelka-select.css'
];
const accepted = {
    status: 'ready', master_path: 'uploads/products/processed/accepted.jpg',
    source_width: 600, source_height: 900, master_width: 600, master_height: 900,
    processor_version: 'accepted-processor',
    normalization: {method: 'standard-canvas-fallback', background_profile_requested: 'original-canvas'}
};
const candidate = {
    status: 'ready', master_width: 1200, master_height: 1800,
    source_width: 600, source_height: 900, processor_version: 'candidate-processor',
    normalization: {
        method: 'standard-canvas-fallback', background_profile_requested: 'studio-light',
        mask_mode_requested: 'modnet', mask_method: 'opencv-grabcut', subject_mask_applied: true
    }
};
const saved = {...candidate, master_path: 'uploads/products/processed/applied.jpg'};
const imageSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900" viewBox="0 0 600 900"><rect width="600" height="900" fill="#e9dcf3"/><circle cx="300" cy="270" r="130" fill="#87618d"/><rect x="160" y="430" width="280" height="430" fill="#87618d"/></svg>';

// This shell retains the actual editor/form/photo-section nesting from the PHP view.
// Application JS creates every image card and modal; no generated UI is duplicated here.
function fixture() {
    const products = [{id: 1, name: 'Product', stock_mode: 'total', images: [
        {id: 17, path: '/Anabelka/uploads/products/original.jpg', is_main: 1, processing: accepted},
        {id: 18, path: '/Anabelka/uploads/products/second.jpg', processing: accepted}
    ]}];
    const ids = ['id', 'category', 'name', 'slug', 'sku', 'description', 'price', 'old-price',
        'stock', 'show-stock', 'material', 'brand', 'country', 'active'];
    return `<!doctype html><html lang="uk"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        ${cssFiles.map(name => `<link rel="stylesheet" href="/Anabelka/css/${name}">`).join('')}
        </head><body><main class="admin-products">
        <button data-product-edit data-product-id="1">Редагувати товар</button></main>
        <div id="product-editor" class="product-editor" hidden>
        <button class="product-editor-backdrop" data-product-close aria-label="Закрити редактор"></button>
        <section class="product-editor-window" role="dialog" aria-modal="true" aria-labelledby="product-editor-title">
        <header class="product-editor-head"><div><span id="product-editor-kicker">Товар</span>
        <h2 id="product-editor-title">Товар</h2></div><button class="product-editor-close" data-product-close>×</button></header>
        <form id="product-editor-form"><input type="hidden" name="_csrf" value="csrf-token">
        ${ids.map(id => `<input type="hidden" id="product-edit-${id}">`).join('')}
        <select hidden id="product-edit-stock-mode"><option value="total">Total</option></select>
        <div class="product-editor-body"><details class="product-form-section" open><summary>
        <span>Фотографії</span><small>Головне фото та перемикання кольорів</small></summary>
        <div class="product-details-content"><div id="product-image-list" class="product-image-list"></div>
        <label class="product-upload-field"><span>Додати фотографії</span>
        <input type="file" id="product-image-input" multiple></label>
        <div id="product-upload-preview" class="product-upload-preview"></div></div></details>
        <div hidden id="product-size-list"></div><button hidden type="button" data-size-add>Додати розмір</button></div>
        <footer class="product-editor-actions"><button type="button" data-product-close>Скасувати</button>
        <button type="submit" class="product-editor-save">Зберегти</button></footer></form></section></div>
        <template id="product-size-template"><div class="product-size-row"><input data-size-id>
        <input data-size-name><input data-size-stock><button data-size-remove></button></div></template>
        <script type="application/json" id="admin-products-data">${JSON.stringify(products)}</script>
        <div id="site-message" class="site-message" role="status"></div>
        <script src="/Anabelka/js/admin-products.js"></script></body></html>`;
}

let browser;
let server;
let origin;
const requests = [];
before(async () => {
    browser = await playwright.chromium.launch({
        headless: true,
        executablePath: process.env.ANABELKA_UI_TEST_BROWSER || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']
    });
    server = http.createServer(async (req, res) => {
        const pathname = new URL(req.url, 'http://localhost').pathname;
        if (pathname === '/Anabelka/admin/products') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(fixture());
        } else if (pathname.startsWith('/Anabelka/css/') || pathname === '/Anabelka/js/admin-products.js') {
            const relative = pathname.slice('/Anabelka/'.length);
            res.setHeader('Content-Type', relative.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(path.join(repo, relative)));
        } else if (/image-process-(preview|confirm|cancel)$/.test(pathname)) {
            for await (const _chunk of req) {} // Consume the real browser's multipart request.
            requests.push(pathname);
            res.setHeader('Content-Type', 'application/json');
            const result = pathname.endsWith('-preview') ? {
                success: true, image_id: 17, preview_id: 'preview-browser',
                expires_at: '2099-01-01T00:00:00Z', processing: candidate,
                preview_url: '/Anabelka/admin/products/image-process-preview-file?image_id=17&preview_id=preview-browser'
            } : pathname.endsWith('-confirm') ? {success: true, image_id: 17, processing: saved} : {success: true};
            res.end(JSON.stringify(result));
        } else if (pathname.includes('/uploads/products/') || pathname.endsWith('image-process-preview-file')) {
            res.setHeader('Content-Type', 'image/svg+xml');
            const processed = pathname.includes('/processed/') || pathname.endsWith('image-process-preview-file');
            res.end(processed ? imageSvg.replace('#e9dcf3', '#edf1f5').replaceAll('#87618d', '#557084') : imageSvg);
        } else {
            res.writeHead(404);
            res.end();
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
});
after(async () => {
    if (browser) await browser.close();
    if (server) await new Promise(resolve => server.close(resolve));
});

async function harness(t, width = 390, height = 640) {
    const context = await browser.newContext({viewport: {width, height}, hasTouch: width <= 650});
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const requestStart = requests.length;
    t.after(async () => {
        await context.close();
        assert.deepEqual(errors, [], 'Application JS must not throw in Chromium.');
    });
    await page.goto(origin + '/Anabelka/admin/products');
    await page.locator('[data-product-edit]').click();
    await page.locator('.product-image-manage').first().waitFor();
    const first = selector => page.locator('.product-image-manage').first().locator(selector);
    return {
        page, first,
        calls: action => requests.slice(requestStart).filter(url => url.endsWith('-' + action)),
        start: async () => {
            await first('[data-product-image-background-profile]').selectOption('studio-light');
            await first('[data-product-image-mask-mode]').selectOption('modnet');
            await first('[data-product-image-process]').click();
            await page.locator('.product-image-compare-modal').waitFor();
            await page.waitForFunction(() => [...document.querySelectorAll('.product-image-compare-stage img')]
                .every(img => img.complete && img.naturalWidth > 0));
            if (process.env.ANABELKA_UI_TEST_SCREENSHOTS) {
                fs.mkdirSync(process.env.ANABELKA_UI_TEST_SCREENSHOTS, {recursive: true});
                await page.screenshot({path: path.join(process.env.ANABELKA_UI_TEST_SCREENSHOTS,
                    `preview-${width}x${height}.png`)});
                const bounds = await page.evaluate(() => Object.fromEntries([
                    '.product-image-compare-dialog', '.product-image-compare-header', '.product-image-compare-stage',
                    '.product-image-compare-label.is-original', '.product-image-compare-label.is-processed',
                    '.product-image-compare-details-button', '.product-image-compare-close',
                    '[data-product-image-preview-confirm]', '[data-product-image-preview-cancel]'
                ].map(selector => {
                    const rect = document.querySelector(selector)?.getBoundingClientRect();
                    return [selector, rect ? {x: rect.x, y: rect.y, width: rect.width, height: rect.height} : null];
                })));
                fs.writeFileSync(path.join(process.env.ANABELKA_UI_TEST_SCREENSHOTS,
                    `preview-${width}x${height}.json`), JSON.stringify({viewport: {width, height}, bounds}, null, 2));
            }
        }
    };
}

async function box(locator, message) {
    assert.ok(await locator.count(), message || 'Control must exist.');
    const bounds = await locator.boundingBox();
    assert.ok(bounds, message || 'Control must be rendered.');
    return bounds;
}

async function assertTouchTarget(locator, label) {
    const bounds = await box(locator, label + ' must be rendered.');
    assert.ok(bounds.width >= 43.99 && bounds.height >= 43.99,
        `${label} must be at least 44×44 CSS pixels; was ${bounds.width}×${bounds.height}.`);
}

async function assertInsideViewport(page, selector) {
    const bounds = await box(page.locator(selector), selector + ' must be rendered.');
    const {width, height} = page.viewportSize();
    assert.ok(bounds.x >= -1 && bounds.y >= -1 && bounds.x + bounds.width <= width + 1
        && bounds.y + bounds.height <= height + 1, selector + ' must fit in the viewport without scrolling.');
}

async function assertNoHorizontalOverflow(page, selectors) {
    const overflow = await page.evaluate(selectors => selectors.flatMap(selector =>
        [...document.querySelectorAll(selector)].filter(node => node.scrollWidth > node.clientWidth + 1)
            .map(node => ({selector, clientWidth: node.clientWidth, scrollWidth: node.scrollWidth,
                overflowingChildren: [...node.querySelectorAll('*')].filter(child => {
                    const rect = child.getBoundingClientRect();
                    const parent = node.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0
                        && (rect.right > parent.right + 1 || rect.left < parent.left - 1);
                }).map(child => ({tag: child.tagName, class: child.className,
                    width: child.getBoundingClientRect().width, minWidth: getComputedStyle(child).minWidth})).slice(0, 8)
            }))), selectors);
    assert.deepEqual(overflow, [], 'Editor/photo cards must not overflow horizontally.');
    const width = await page.evaluate(() => document.documentElement.scrollWidth);
    assert.ok(width <= page.viewportSize().width + 1, 'Page must not overflow horizontally.');
}

async function assertActionIcon(button, mobile) {
    await assertTouchTarget(button, 'Photo action');
    const name = await button.getAttribute('aria-label');
    assert.ok(name && name.trim(), 'Photo action needs an accessible name.');
    const icon = await box(button.locator('svg'), 'Photo action must use a visible SVG icon.');
    assert.ok(icon.width >= 16 && icon.height >= 16, 'Action icon must be visibly sized.');
    const text = button.locator('.product-image-action-text');
    assert.equal(await text.count(), 1, 'Photo action keeps its text label in the DOM.');
    const rendered = await text.evaluate(node => {
        const rect = node.getBoundingClientRect();
        const style = getComputedStyle(node);
        return {width: rect.width, height: rect.height, display: style.display, visibility: style.visibility};
    });
    if (mobile) {
        assert.ok(rendered.display === 'none' || rendered.visibility === 'hidden'
            || (rendered.width <= 1.1 && rendered.height <= 1.1), 'Mobile action must display only its icon.');
    } else {
        assert.ok(rendered.width > 4 && rendered.height > 4 && rendered.display !== 'none'
            && rendered.visibility === 'visible', 'Desktop action must display its text beside the icon.');
    }
}

async function assertOverlayClipping(page, leftPercent) {
    const clipPath = await page.locator('.product-image-compare-processed').evaluate(node =>
        getComputedStyle(node).clipPath);
    assert.match(clipPath, /^inset\(/, 'Processed overlay must use the rendered CSS comparison clip.');
    const values = clipPath.slice(clipPath.indexOf('(') + 1, clipPath.lastIndexOf(')')).trim().split(/\s+/);
    assert.equal(values.length, 4, 'Computed comparison clip defines top, right, bottom and left insets.');
    assert.equal(values[3], leftPercent + '%', 'Pointer/keyboard split must change the rendered left inset.');
}

for (const width of [320, 360, 390, 412]) {
    test(`mobile photo actions at ${width}px are 44px icon buttons without overflow`, async t => {
        const h = await harness(t, width);
        await assertActionIcon(h.first('[data-product-image-process]'), true);
        await assertActionIcon(h.first('[data-product-image-compare]'), true);
        await assertNoHorizontalOverflow(h.page, [
            '.product-editor-window', '.product-editor-body', '.product-form-section',
            '.product-image-manage', '.product-image-processing', '.product-image-tools'
        ]);
        const left = await box(h.first('[data-product-image-process]'));
        const right = await box(h.first('[data-product-image-compare]'));
        assert.ok(left.x + left.width <= right.x + 1 || right.x + right.width <= left.x + 1
            || left.y + left.height <= right.y + 1 || right.y + right.height <= left.y + 1,
        'Both photo actions must have distinct, nonoverlapping touch targets.');
    });

    for (const height of [740, 360]) {
        test(`preview at ${width}×${height} fits header, image and apply/cancel without a lower range`, async t => {
            const h = await harness(t, width, height);
            await h.start();
            const header = h.page.locator('.product-image-compare-header');
            assert.equal(await header.locator('.product-image-compare-details-button').count(), 1,
                'Processing details must be an icon action in the preview header.');
            await assertTouchTarget(header.locator('.product-image-compare-details-button'), 'Processing details');
            await assertTouchTarget(header.locator('.product-image-compare-close'), 'Close comparison');
            await box(header.locator('.product-image-compare-details-button svg'), 'Details icon must be rendered.');
            assert.ok(await header.locator('.product-image-compare-details-button').getAttribute('aria-label'),
                'Details icon needs an accessible name.');
            assert.equal(await h.page.locator('.product-image-compare-modal input[type="range"]').count(), 0,
                'The divider is the sole comparison slider; remove the lower range input.');
            for (const selector of ['.product-image-compare-header', '.product-image-compare-stage',
                '[data-product-image-preview-confirm]', '[data-product-image-preview-cancel]']) {
                await assertInsideViewport(h.page, selector);
            }
            await assertTouchTarget(h.page.locator('[data-product-image-preview-confirm]'), 'Apply preview');
            await assertTouchTarget(h.page.locator('[data-product-image-preview-cancel]'), 'Cancel preview');
            const original = await box(h.page.locator('.product-image-compare-label.is-original'));
            const processed = await box(h.page.locator('.product-image-compare-label.is-processed'));
            assert.ok(original.x + original.width <= processed.x + 1
                || processed.x + processed.width <= original.x + 1
                || original.y + original.height <= processed.y + 1
                || processed.y + processed.height <= original.y + 1,
            'Original and processed captions must remain readable without overlapping at short heights.');
            const scroll = await h.page.locator('.product-image-compare-dialog').evaluate(node => ({
                clientHeight: node.clientHeight, scrollHeight: node.scrollHeight
            }));
            assert.ok(scroll.scrollHeight <= scroll.clientHeight + 1,
                'Preview header and actions must remain visible at short viewport heights.');
            await assertNoHorizontalOverflow(h.page, ['.product-image-compare-dialog']);
        });
    }
}

test('desktop photo actions retain both SVG icons and visible labels', async t => {
    const h = await harness(t, 1100, 900);
    await assertActionIcon(h.first('[data-product-image-process]'), false);
    await assertActionIcon(h.first('[data-product-image-compare]'), false);
    await assertNoHorizontalOverflow(h.page, ['.product-image-manage', '.product-image-processing']);
});

test('divider uses a centered SVG marker with real pointer drag and keyboard control', async t => {
    const h = await harness(t, 390, 740);
    await h.start();
    const divider = h.page.locator('.product-image-compare-divider');
    assert.equal(await divider.getAttribute('role'), 'slider');
    assert.equal(await divider.getAttribute('aria-valuemin'), '0');
    assert.equal(await divider.getAttribute('aria-valuemax'), '100');
    assert.ok(await divider.getAttribute('aria-label'));
    const dividerBox = await box(divider);
    assert.ok(dividerBox.width >= 44, 'Divider must retain a 44px pointer target.');
    const stage = await box(h.page.locator('.product-image-compare-stage'));
    const handle = await box(divider.locator('.product-image-compare-handle'), 'Divider must render its marker.');
    const icon = await box(divider.locator('.product-image-compare-handle svg'), 'Marker must use SVG arrows.');
    assert.ok(Math.abs(handle.width - 38) < 0.1 && Math.abs(handle.height - 38) < 0.1,
        'Divider marker retains its 38×38px circle.');
    for (const [label, bounds] of [['Marker', handle], ['SVG arrows', icon]]) {
        assert.ok(Math.abs(bounds.x + bounds.width / 2 - (stage.x + stage.width / 2)) <= 1,
            label + ' must be horizontally centered on the divider.');
        assert.ok(Math.abs(bounds.y + bounds.height / 2 - (stage.y + stage.height / 2)) <= 1,
            label + ' must be vertically centered in the stage.');
    }
    await h.page.mouse.move(handle.x + handle.width / 2, handle.y + handle.height / 2);
    await h.page.mouse.down();
    await h.page.mouse.move(stage.x - 1, stage.y + stage.height / 2, {steps: 8});
    assert.equal(await divider.getAttribute('aria-valuenow'), '0', 'Pointer drag clamps to the left endpoint.');
    await assertOverlayClipping(h.page, 0);
    await h.page.mouse.move(stage.x + stage.width + 1, stage.y + stage.height / 2, {steps: 8});
    assert.equal(await divider.getAttribute('aria-valuenow'), '100', 'Captured drag reaches the right endpoint.');
    await assertOverlayClipping(h.page, 100);
    await h.page.mouse.up();
    await divider.focus();
    await h.page.keyboard.press('Home');
    assert.equal(await divider.getAttribute('aria-valuenow'), '0');
    await assertOverlayClipping(h.page, 0);
    await h.page.keyboard.press('ArrowRight');
    const increased = Number(await divider.getAttribute('aria-valuenow'));
    assert.ok(increased > 0 && increased < 100, 'Right arrow moves the accessible slider.');
    await h.page.keyboard.press('ArrowLeft');
    assert.equal(await divider.getAttribute('aria-valuenow'), '0');
    await h.page.keyboard.press('End');
    assert.equal(await divider.getAttribute('aria-valuenow'), '100');
    await assertOverlayClipping(h.page, 100);
    await h.page.keyboard.press('ArrowRight');
    assert.equal(await divider.getAttribute('aria-valuenow'), '100', 'Keyboard stays within the right endpoint.');
    assert.equal(await h.page.locator('.product-image-compare-stage').evaluate(node =>
        node.style.getPropertyValue('--compare-split')), '100%');
});

test('touch drag stays captured through both divider endpoints without scrolling the mobile preview', async t => {
    const h = await harness(t, 390, 640);
    await h.start();
    const divider = h.page.locator('.product-image-compare-divider');
    const stage = await box(h.page.locator('.product-image-compare-stage'));
    const client = await h.page.context().newCDPSession(h.page);
    const y = stage.y + stage.height / 2;
    const sendTouch = async (type, x) => {
        await client.send('Input.dispatchTouchEvent', {
            type, touchPoints: type === 'touchEnd' ? [] : [{x, y, id: 1, radiusX: 5, radiusY: 5, force: 1}]
        });
        // Chromium coalesces touch moves; dispatch acknowledgement precedes renderer delivery.
        await h.page.evaluate(() => new Promise(resolve => requestAnimationFrame(resolve)));
    };
    const initialScroll = await h.page.evaluate(() => ({
        document: document.scrollingElement.scrollTop,
        dialog: document.querySelector('.product-image-compare-dialog').scrollTop
    }));
    await sendTouch('touchStart', stage.x + stage.width / 2);
    for (let step = 1; step <= 12; step++) {
        await sendTouch('touchMove', stage.x + stage.width / 2 - (stage.width / 2 + 1) * step / 12);
    }
    assert.equal(await divider.getAttribute('aria-valuenow'), '0', 'Touch reaches the left endpoint.');
    let previous = 0;
    for (let step = 1; step <= 12; step++) {
        await sendTouch('touchMove', stage.x - 1 + (stage.width + 2) * step / 12);
        const current = Number(await divider.getAttribute('aria-valuenow'));
        assert.ok(current >= previous, 'Captured touch updates the divider throughout the drag.');
        previous = current;
    }
    assert.equal(previous, 100, 'Captured touch reaches the right endpoint.');
    await sendTouch('touchEnd');
    // At an endpoint half of the 44px target remains within the clipped stage.
    await sendTouch('touchStart', stage.x + stage.width - 4);
    await sendTouch('touchMove', stage.x - 1);
    assert.equal(await divider.getAttribute('aria-valuenow'), '0', 'The visible endpoint remains draggable.');
    await sendTouch('touchEnd');
    const finalScroll = await h.page.evaluate(() => ({
        document: document.scrollingElement.scrollTop,
        dialog: document.querySelector('.product-image-compare-dialog').scrollTop
    }));
    assert.deepEqual(finalScroll, initialScroll, 'Dragging the divider must not scroll the mobile preview.');
    await client.detach();
});

async function assertDetails(page, expectedVersion) {
    const trigger = page.locator('.product-image-compare-header .product-image-compare-details-button');
    await box(trigger, 'Processing details action must be in the header.');
    await trigger.click();
    const modal = page.locator('.product-image-compare-details-modal');
    await modal.waitFor({state: 'visible'});
    const dialog = modal.locator('.product-image-compare-details-dialog');
    assert.equal(await dialog.getAttribute('role'), 'dialog', 'Processing details is an accessible dialog.');
    assert.ok(await dialog.getAttribute('aria-label') || await dialog.getAttribute('aria-labelledby'),
        'Processing details dialog needs an accessible title.');
    assert.match(await modal.locator('.product-image-compare-meta').innerText(), new RegExp(expectedVersion));
    assert.ok((await modal.locator('.product-image-compare-diagnostic').count()) > 0,
        'Processing diagnostics remain available in the details dialog.');
    await assertTouchTarget(modal.locator('.product-image-compare-details-close'), 'Close processing details');
    await modal.locator('.product-image-compare-details-close').click();
    await modal.waitFor({state: 'hidden'});
}

test('header processing details stay accessible before confirmation and after reopening applied comparison', async t => {
    const h = await harness(t, 320, 360);
    await h.start();
    await assertDetails(h.page, 'candidate-processor');
    await h.page.locator('[data-product-image-preview-confirm]').click();
    await h.page.locator('.product-image-compare-modal').waitFor({state: 'detached'});
    assert.equal(h.calls('confirm').length, 1, 'Only explicit Apply sends a confirmation.');
    assert.equal(h.calls('cancel').length, 0, 'An applied preview is not canceled.');
    await h.first('[data-product-image-compare]').click();
    await h.page.locator('.product-image-compare-modal').waitFor();
    await assertDetails(h.page, 'candidate-processor');
    assert.equal(await h.page.locator('[data-product-image-preview-confirm]').count(), 0,
        'An applied comparison no longer has preview confirmation controls.');
});

test('real browser Back closes processing details, then preview, then the product editor', async t => {
    const h = await harness(t);
    await h.start();
    await h.page.locator('.product-image-compare-details-button').click();
    await h.page.locator('.product-image-compare-details-modal').waitFor({state: 'visible'});
    await h.page.goBack();
    await h.page.locator('.product-image-compare-details-modal').waitFor({state: 'hidden'});
    assert.equal(await h.page.locator('.product-image-compare-modal').isVisible(), true);
    assert.equal(await h.page.locator('#product-editor').isVisible(), true);
    assert.equal(h.calls('cancel').length, 0, 'Closing only details leaves the preview pending.');
    await h.page.goBack();
    await h.page.locator('.product-image-compare-modal').waitFor({state: 'detached'});
    assert.equal(await h.page.locator('#product-editor').isVisible(), true);
    await h.page.waitForFunction(() => !history.state?.__anabelkaProductImageCompare);
    assert.equal(h.calls('confirm').length, 0, 'Back must not confirm a preview.');
    assert.equal(h.calls('cancel').length, 1, 'Leaving preview sends exactly one cancellation.');
    await h.page.goBack();
    await h.page.locator('#product-editor').waitFor({state: 'hidden'});
});

for (const action of ['cancel', 'close', 'escape', 'back']) {
    test(`${action} dismisses a real browser preview without confirming`, async t => {
        const h = await harness(t, 360, 640);
        await h.start();
        if (action === 'escape') await h.page.keyboard.press('Escape');
        else if (action === 'back') await h.page.goBack();
        else await h.page.locator(action === 'cancel' ? '[data-product-image-preview-cancel]'
            : '.product-image-compare-close').click();
        await h.page.locator('.product-image-compare-modal').waitFor({state: 'detached'});
        assert.equal(h.calls('confirm').length, 0, 'Dismissal must never confirm.');
        assert.equal(h.calls('cancel').length, 1, 'Dismissal cancels its pending preview once.');
        assert.equal(await h.first('[data-product-image-background-profile]').inputValue(), 'studio-light');
        assert.equal(await h.first('[data-product-image-mask-mode]').inputValue(), 'modnet');
        await h.first('[data-product-image-compare]').click();
        await h.page.locator('.product-image-compare-modal').waitFor();
        assert.match(await h.page.locator('.product-image-compare-meta').textContent(), /accepted-processor/,
            'Dismissal keeps the previously accepted processing result.');
    });
}
