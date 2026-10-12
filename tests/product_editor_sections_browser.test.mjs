import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import test, {before, after} from 'node:test';
import {productEditorFixture} from './helpers/product-editor-fixture.mjs';

const require = createRequire(import.meta.url);
const dependencyRoots = [process.env.ANABELKA_UI_TEST_DEPS,
    fileURLToPath(new URL('../../test-deps/', import.meta.url)),
    process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES].filter(Boolean);
let playwright;
try { playwright = require('playwright'); } catch {
    for (const root of dependencyRoots) {
        try { playwright = createRequire(path.resolve(root, 'package.json'))('playwright'); break; } catch {}
    }
}
assert.ok(playwright, 'Playwright is required; browser tests must run, not skip.');
const repo = fileURLToPath(new URL('../', import.meta.url));
const labels = ['Ціни', 'Залишки та розміри', 'Кольори', 'Матеріал', 'Фотографії', 'Виробник', 'Переклади'];
let browser;
let server;
let origin;
let html;
const saves = [];
before(async () => {
    html = productEditorFixture();
    browser = await playwright.chromium.launch({headless: true,
        executablePath: process.env.ANABELKA_UI_TEST_BROWSER || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
    server = http.createServer(async (req, res) => {
        const pathname = new URL(req.url, 'http://localhost').pathname;
        if (pathname === '/Anabelka/admin/products') {
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end(html);
        } else if (/^\/Anabelka\/(css|js)\//.test(pathname)) {
            res.setHeader('Content-Type', pathname.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(path.join(repo, pathname.slice('/Anabelka/'.length))));
        } else if (pathname.endsWith('/variant-stock')) {
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({success: true, rows: []}));
        } else if (pathname.endsWith('/save')) {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            saves.push(Buffer.concat(chunks).toString());
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({success: false, message: 'Перевірка збереження завершена'}));
        } else if (pathname.includes('/uploads/products/')) {
            res.setHeader('Content-Type', 'image/svg+xml');
            res.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="900"><rect width="600" height="900" fill="#f4eaff"/></svg>');
        } else { res.writeHead(404); res.end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
});
after(async () => {
    if (browser) await browser.close();
    if (server) await new Promise(resolve => server.close(resolve));
});

async function harness(t, width = 390, height = 844, query = '') {
    const context = await browser.newContext({viewport: {width, height}, hasTouch: width <= 650});
    const page = await context.newPage();
    page.setDefaultTimeout(5000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'Application JS must not throw.'); });
    await page.goto(origin + '/Anabelka/admin/products' + query);
    if (!query) await page.locator('[data-product-edit][data-product-id="1"]').click();
    await page.locator('#product-editor:not([hidden])').waitFor();
    if (!query) await page.waitForFunction(() => document.querySelector('#product-color-list [data-product-manual-color]'));
    const section = label => page.locator('details.product-form-section').filter({has: page.locator('summary > span:first-child', {hasText: label})});
    const open = async label => { if (!await section(label).evaluate(node => node.open)) await section(label).locator('summary').click(); };
    return {page, section, open};
}

async function openLabels(page) {
    return page.locator('details.product-form-section[open] > summary > span:first-child').allTextContents();
}

test('every editor opening keeps main fields visible and starts the seven optional sections closed', async t => {
    const h = await harness(t);
    assert.deepEqual(await openLabels(h.page), []);
    assert.deepEqual(await h.page.locator('details.product-form-section > summary > span:first-child').allTextContents(), labels);
    assert.equal(await h.page.locator('#product-edit-category').locator('..').isVisible(), true, 'Category stays in Основне');
    for (const id of ['name', 'sku', 'slug', 'description', 'active']) {
        assert.equal(await h.page.locator('#product-edit-' + id).isVisible(), true, id + ' stays in Основне');
    }
    assert.equal(await h.page.locator('.product-section-heading').locator('summary').count(), 0);
    assert.equal(await h.page.evaluate(() => document.activeElement.matches('input, textarea, select')), false,
        'Ordinary editor opening keeps the existing mobile keyboard policy');
    await h.open('Ціни');
    await h.open('Кольори');
    await h.open('Фотографії');
    assert.deepEqual(await openLabels(h.page), ['Ціни', 'Кольори', 'Фотографії']);
    await h.page.locator('.product-editor-cancel').click();
    await h.page.locator('[data-product-edit][data-product-id="2"]').click();
    assert.deepEqual(await openLabels(h.page), []);
    await h.page.locator('.product-editor-cancel').click();
    await h.page.locator('[data-product-create]').click();
    assert.deepEqual(await openLabels(h.page), []);
});

test('size, color, photo and translation updates keep the current expanded sections', async t => {
    const h = await harness(t);
    await h.open('Ціни'); await h.open('Залишки та розміри'); await h.open('Кольори'); await h.open('Фотографії');
    const state = await openLabels(h.page);
    await h.page.locator('[data-size-add]').click();
    await h.page.locator('#product-size-list [data-size-name]').last().fill('L');
    await h.page.locator('[data-product-color-add]').click();
    await h.page.locator('[data-color-picker-name]').fill('Чорний');
    await h.page.locator('[data-color-picker-apply]').click();
    await h.page.locator('[data-product-color-remove]').last().click();
    await h.page.locator('#product-image-input').setInputFiles({name: 'photo.png', mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', 'base64')});
    await h.page.locator('#product-image-input').setInputFiles([]);
    assert.deepEqual(await openLabels(h.page), state);
    await h.open('Переклади');
    await h.page.evaluate(() => { window.AnabelkaAITranslation = {suggest: async () => ({name: 'Translated', description: 'Description'})}; });
    await h.page.locator('[data-product-ai-translate]').click();
    await h.page.waitForFunction(() => document.querySelector('.product-translation-name').value === 'Translated');
    assert.deepEqual(await openLabels(h.page), [...state, 'Переклади']);
});

test('product inline validation clears immediately after correction', async t => {
    const h = await harness(t);
    await h.page.locator('#product-edit-price').fill('');
    await h.page.locator('.product-editor-save').click();
    const notice = h.page.locator('#product-edit-price').locator('..').locator('.product-field-error');
    assert.equal(await notice.count(), 1);
    assert.equal(await h.page.locator('#product-edit-price').getAttribute('aria-invalid'), 'true');
    await h.page.locator('#product-edit-price').fill('49.50');
    assert.equal(await notice.count(), 0);
    assert.notEqual(await h.page.locator('#product-edit-price').getAttribute('aria-invalid'), 'true');
});

test('native validation reveals and focuses an invalid field in a collapsed section', async t => {
    const h = await harness(t);
    await h.page.evaluate(() => {
        document.querySelector('#product-edit-price').value = '';
        document.querySelector('#product-edit-price').closest('details').open = false;
    });
    await h.page.locator('.product-editor-save').click();
    await h.page.waitForFunction(() => document.activeElement.id === 'product-edit-price');
    assert.equal(await h.section('Ціни').evaluate(node => node.open), true);
    const guidance = h.page.locator('#product-edit-price').locator('..').locator('.product-field-error');
    assert.match(await guidance.innerText(), /Заповніть це поле/);
    assert.equal(await guidance.evaluate(node => node.parentElement.querySelector('input')?.id), 'product-edit-price');
    assert.equal(await h.page.locator('#site-message.show').count(), 0,
        'Field validation must not display a global toast.');
    assert.equal(await h.page.locator('#product-editor').getAttribute('hidden'), null);
});

test('validation opens every collapsed ancestor and reaches its invalid input', async t => {
    const h = await harness(t);
    await h.page.evaluate(() => {
        const input = document.querySelector('#product-edit-old-price');
        input.value = '-1';
        const field = input.closest('label');
        const nested = document.createElement('details');
        nested.id = 'nested-price-details';
        nested.innerHTML = '<summary>Додаткові ціни</summary>';
        field.replaceWith(nested);
        nested.appendChild(field);
    });
    await h.page.locator('.product-editor-save').click();
    await h.page.waitForFunction(() => document.activeElement.id === 'product-edit-old-price');
    assert.equal(await h.section('Ціни').evaluate(node => node.open), true);
    assert.equal(await h.page.locator('#nested-price-details').evaluate(node => node.open), true);
});

test('missing and duplicate sizes reveal the stock section without submitting', async t => {
    const h = await harness(t);
    await h.page.evaluate(() => { document.querySelector('#product-size-list [data-size-name]').value = ''; });
    const start = saves.length;
    await h.page.locator('.product-editor-save').click();
    await h.page.waitForFunction(() => document.activeElement.matches('#product-size-list [data-size-name]'));
    assert.equal(await h.section('Залишки та розміри').evaluate(node => node.open), true);
    await h.page.locator('#product-size-list [data-size-name]').fill('M');
    await h.page.locator('[data-size-add]').click();
    await h.page.locator('#product-size-list [data-size-name]').last().fill('m');
    assert.deepEqual(await h.page.locator('#product-size-list [data-size-name]').evaluateAll(nodes => nodes.map(node => node.value)), ['M', 'm']);
    await h.section('Залишки та розміри').locator('summary').click();
    await h.page.locator('.product-editor-save').click();
    try {
        await h.page.waitForFunction(() => document.activeElement.matches('#product-size-list [data-size-name]'));
    } catch (error) {
        assert.fail(JSON.stringify(await h.page.evaluate(() => ({
            active: {tag: document.activeElement.tagName, id: document.activeElement.id, name: document.activeElement.name},
            message: document.querySelector('#site-message').textContent,
            sizes: [...document.querySelectorAll('#product-size-list [data-size-name]')].map(node => ({value: node.value, message: node.validationMessage})),
            invalid: [...document.querySelector('#product-editor-form').elements]
                .filter(node => node.validity && !node.validity.valid)
                .map(node => ({name: node.name, value: node.value, message: node.validationMessage}))
        }))));
    }
    assert.equal(await h.section('Залишки та розміри').evaluate(node => node.open), true);
    assert.equal(saves.length, start);
});

test('translation target URL expands translations and focuses its field', async t => {
    const h = await harness(t, 390, 844, '?highlight=1&focus_language=en');
    await h.page.waitForFunction(() => document.activeElement.matches('.product-translation-description'));
    assert.deepEqual(await openLabels(h.page), ['Переклади']);
});

test('closed section values retain their names and reach the existing save flow', async t => {
    const h = await harness(t);
    const start = saves.length;
    await h.page.locator('.product-editor-save').click();
    await h.page.waitForFunction(() => document.querySelector('#site-message').textContent === 'Перевірка збереження завершена');
    assert.equal(await h.page.locator('#site-message').evaluate(node => node.classList.contains('is-error')), true);
    assert.equal(await h.page.locator('#site-message').getAttribute('role'), 'alert');
    assert.equal(saves.length, start + 1);
    const submitted = saves.at(-1);
    for (const [name, value] of [['category_id', '7'], ['sku', 'SET-1'], ['price', '49.50'], ['rank_price[2]', '45.00'],
        ['material', 'Поліамід'], ['brand', 'Anabelka'], ['translation_name[en]', 'Lace set'], ['main_image_id', '17']]) {
        assert.ok(submitted.includes(`name="${name}"\r\n\r\n${value}`), name + ' survives closed details');
    }
    assert.deepEqual(await openLabels(h.page), []);
    assert.equal(await h.page.locator('.product-editor-save').isEnabled(), true);
});

for (const [width, height] of [[320, 640], [360, 800], [390, 844], [412, 915], [768, 1024],
    [640, 320], [800, 360], [844, 390], [915, 412], [1024, 768]]) {
    test(`section cards fit ${width}x${height}, support keyboard toggling and expose 44px targets`, async t => {
        const h = await harness(t, width, height);
        if (process.env.ANABELKA_UI_TEST_SCREENSHOTS) {
            fs.mkdirSync(process.env.ANABELKA_UI_TEST_SCREENSHOTS, {recursive: true});
            await h.page.screenshot({path: path.join(process.env.ANABELKA_UI_TEST_SCREENSHOTS,
                `sections-closed-${width}x${height}.png`)});
            await h.section('Ціни').locator('summary').evaluate(node => node.scrollIntoView({block: 'start'}));
            await h.page.screenshot({path: path.join(process.env.ANABELKA_UI_TEST_SCREENSHOTS,
                `sections-cards-${width}x${height}.png`)});
        }
        const summaries = h.page.locator('details.product-form-section > summary');
        for (const summary of await summaries.all()) {
            await summary.scrollIntoViewIfNeeded();
            const metrics = await summary.evaluate(node => {
                const rect = node.getBoundingClientRect();
                const toggle = node.querySelector('.product-section-toggle');
                const icon = toggle ? getComputedStyle(toggle) : getComputedStyle(node, '::after');
                const card = node.parentElement.getBoundingClientRect();
                return {width: rect.width, height: rect.height, iconWidth: parseFloat(icon.width), iconHeight: parseFloat(icon.height),
                    iconColor: icon.color, iconBackground: icon.backgroundColor, cardLeft: card.left, cardRight: card.right};
            });
            assert.ok(metrics.width >= 44 && metrics.height >= 44, 'Summary is a 44px touch target');
            assert.ok(metrics.iconWidth >= 44 && metrics.iconHeight >= 44, 'Toggle square is 44px');
            assert.equal(metrics.iconColor, 'rgb(138, 43, 226)');
            assert.equal(metrics.iconBackground, 'rgb(244, 234, 255)');
            assert.ok(metrics.cardLeft >= -1 && metrics.cardRight <= width + 1, 'Card fits the viewport');
        }
        const prices = h.section('Ціни').locator('summary');
        await prices.focus(); await prices.press('Enter');
        assert.equal(await h.section('Ціни').evaluate(node => node.open), true);
        await prices.press('Space');
        assert.equal(await h.section('Ціни').evaluate(node => node.open), false);
        await h.open('Ціни'); await h.open('Залишки та розміри');
        const overflow = await h.page.evaluate(() => [...document.querySelectorAll('.product-editor-body, .product-form-section')]
            .filter(node => node.scrollWidth > node.clientWidth + 1).map(node => node.className));
        assert.deepEqual(overflow, []);
        if (process.env.ANABELKA_UI_TEST_SCREENSHOTS) {
            const directory = process.env.ANABELKA_UI_TEST_SCREENSHOTS;
            fs.mkdirSync(directory, {recursive: true});
            await h.page.locator('.product-editor-body').evaluate(node => {node.scrollTop = 0;});
            await h.page.screenshot({path: path.join(directory, `sections-${width}x${height}.png`)});
        }
    });
}
