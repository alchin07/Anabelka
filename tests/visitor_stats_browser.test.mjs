import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import test, {before, after} from 'node:test';

// Real Chromium, actual PHP view/CSS/header/JS, explicit synthetic report data.
// No live site, DB, real administrator session or native OS/device is used.
// npm install --prefix ../test-deps playwright
// ../test-deps/node_modules/.bin/playwright install chromium
// ANABELKA_UI_TEST_DEPS=../test-deps node --test tests/visitor_stats_browser.test.mjs
// ANABELKA_UI_TEST_BROWSER=/path/to/chromium selects an installed browser.
// ANABELKA_UI_TEST_SCREENSHOTS=/tmp/vc01-browser saves the rendered UI states.
const require = createRequire(import.meta.url);
let playwright;
try {
    playwright = require('playwright');
} catch {
    for (const root of [process.env.ANABELKA_UI_TEST_DEPS,
        fileURLToPath(new URL('../../test-deps/', import.meta.url)),
        process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES].filter(Boolean)) {
        try {
            playwright = createRequire(path.resolve(root, 'package.json'))('playwright');
            break;
        } catch {}
    }
}
assert.ok(playwright, 'Install Playwright in ANABELKA_UI_TEST_DEPS; this browser test must run, not skip.');

const repo = process.env.ANABELKA_UI_TEST_SOURCE_DIR
    ? path.resolve(process.env.ANABELKA_UI_TEST_SOURCE_DIR)
    : fileURLToPath(new URL('../', import.meta.url));
const fixture = path.join(repo, 'tests/fixtures/visitor_stats_page.php');
const screenshotDirectory = process.env.ANABELKA_UI_TEST_SCREENSHOTS;
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAAFElEQVR4nGO8seMLAwwwMSAB3BwAfrACjAvRXXAAAAAASUVORK5CYII=', 'base64');
let server;
let origin;
let browser;
const serverErrors = [];

before(async () => {
    browser = await playwright.chromium.launch({headless: true,
        executablePath: process.env.ANABELKA_UI_TEST_BROWSER || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
    server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        try {
            if (url.pathname === '/Anabelka/admin/visitor-stats') {
                const state = url.searchParams.get('fixture') || 'populated';
                const period = url.searchParams.get('period') || '30';
                const html = execFileSync(process.env.PHP_BINARY || 'php',
                    [fixture, state, period], {encoding: 'utf8'});
                response.setHeader('Content-Type', 'text/html; charset=utf-8');
                response.end(html);
            } else if (/^\/Anabelka\/(?:css|js)\/[a-z0-9.-]+\.(?:css|js)$/i.test(url.pathname)) {
                const file = url.pathname.slice('/Anabelka/'.length);
                response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : 'text/javascript');
                response.end(fs.readFileSync(path.join(repo, file)));
            } else if (url.pathname === '/Anabelka/uploads/products/browser-fixture.png') {
                response.setHeader('Content-Type', 'image/png');
                response.end(png);
            } else if (url.pathname === '/Anabelka/admin/work-time/heartbeat') {
                // The real shared header sends a heartbeat. It must stay local.
                response.setHeader('Content-Type', 'application/json');
                response.end('{"success":true}');
            } else if (url.pathname === '/Anabelka/admin/system/error-notifications') {
                // The owner navigation also polls its notification badge.
                response.setHeader('Content-Type', 'application/json');
                response.end('{"ok":true,"count":0}');
            } else if (url.pathname === '/favicon.ico') {
                response.writeHead(204);
                response.end();
            } else {
                serverErrors.push('Unexpected local request: ' + url.pathname);
                response.writeHead(404);
                response.end();
            }
        } catch (error) {
            serverErrors.push(String(error));
            response.writeHead(500);
            response.end('Browser fixture failed.');
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    if (screenshotDirectory) fs.mkdirSync(screenshotDirectory, {recursive: true});
});

after(async () => {
    if (browser) await browser.close();
    if (server) await new Promise(resolve => server.close(resolve));
    assert.deepEqual(serverErrors, [], 'All actual application assets must load from the local fixture server.');
});

async function open(t, width, state = 'populated', period = 30) {
    const context = await browser.newContext({viewport: {width, height: 900}, hasTouch: width < 700});
    const page = await context.newPage();
    const errors = [];
    const externalRequests = [];
    page.setDefaultTimeout(5000);
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', async route => {
        if (route.request().url().startsWith(origin + '/')) await route.continue();
        else {
            externalRequests.push(route.request().url());
            await route.abort();
        }
    });
    t.after(async () => {
        await context.close();
        assert.deepEqual(errors, [], 'Shared admin JavaScript must not throw in Chromium.');
        assert.deepEqual(externalRequests, [], 'The report must not request external images or assets.');
    });
    const response = await page.goto(`${origin}/Anabelka/admin/visitor-stats?fixture=${state}&period=${period}`);
    assert.equal(response.status(), 200);
    await page.locator('.visitor-stats-page').waitFor();
    assert.equal(await page.locator('.admin-site-header').count(), 1, 'Render the actual shared admin header.');
    return page;
}

async function assertFits(page, width) {
    const bounds = await page.evaluate(() => {
        const root = document.documentElement;
        const main = document.querySelector('.visitor-stats-page');
        const mainRect = main.getBoundingClientRect();
        const overflow = [...main.querySelectorAll('*')].filter(element => {
            if (!element.getClientRects().length || element.closest('svg, .visitor-visually-hidden')) return false;
            const scrollRegion = element.closest('.visitor-chart-scroll[role="region"][tabindex="0"]');
            if (scrollRegion && scrollRegion !== element && /auto|scroll/.test(getComputedStyle(scrollRegion).overflowX)) return false;
            const rect = element.getBoundingClientRect();
            return rect.left < -1 || rect.right > innerWidth + 1;
        }).slice(0, 8).map(element => ({tag: element.tagName, class: element.className,
            right: element.getBoundingClientRect().right}));
        return {viewport: innerWidth, document: root.scrollWidth, body: document.body.scrollWidth,
            mainLeft: mainRect.left, mainRight: mainRect.right, overflow};
    });
    assert.equal(bounds.viewport, width);
    assert.ok(bounds.document <= width + 1 && bounds.body <= width + 1,
        'No horizontal viewport overflow: ' + JSON.stringify(bounds));
    assert.ok(bounds.mainLeft >= 0 && bounds.mainRight <= width + 1,
        'Main report must fit the viewport: ' + JSON.stringify(bounds));
    assert.deepEqual(bounds.overflow, [], 'Long names and open controls must stay within the viewport.');
}

async function screenshot(page, name) {
    if (!screenshotDirectory) return;
    // Earlier lazy-image and keyboard checks scroll the document. Reset it so
    // the real sticky admin header appears at the top of the full-page image.
    await page.evaluate(() => {
        // Blurring cancels the browser's pending focus scroll before reset.
        if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
        document.querySelectorAll('.visitor-chart-scroll').forEach(element =>
            element.scrollTo({left: 0, behavior: 'instant'}));
        window.scrollTo({top: 0, behavior: 'instant'});
    });
    // Native focus/key scrolling can settle after scrollTo; it is unrelated to
    // report acceptance and must not turn optional screenshots into a flake.
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() =>
        requestAnimationFrame(() => {
            window.scrollTo({top: 0, behavior: 'instant'});
            resolve();
        }))));
    await page.screenshot({path: path.join(screenshotDirectory, name + '.png'), fullPage: true});
}

async function assertReadableChart(page) {
    const labels = await page.locator('.visitor-chart svg text').evaluateAll(elements => elements.map(element => {
        const matrix = element.getScreenCTM();
        return {label: element.textContent,
            screenFont: parseFloat(getComputedStyle(element).fontSize) * Math.hypot(matrix.a, matrix.b)};
    }));
    assert.ok(labels.length > 0 && labels.every(label => label.screenFont >= 10),
        'SVG axis text must remain readable after responsive scaling: ' + JSON.stringify(labels));
}

async function assertChartScrolling(page) {
    const scrollRegion = page.locator('.visitor-chart-scroll');
    const chartIsWider = await page.locator('.visitor-chart').evaluate(figure =>
        figure.querySelector('svg').getBoundingClientRect().width > figure.clientWidth + 1);
    if (chartIsWider) assert.equal(await scrollRegion.count(), 1,
        'A chart wider than its panel needs an explicit scroll region.');
    if (!await scrollRegion.count()) return;
    assert.equal(await scrollRegion.getAttribute('role'), 'region');
    assert.equal(await scrollRegion.getAttribute('tabindex'), '0');
    assert.ok(await scrollRegion.getAttribute('aria-label'), 'Chart scroll region needs an accessible name.');
    if (await scrollRegion.evaluate(element => element.scrollWidth > element.clientWidth)) {
        await scrollRegion.focus();
        assert.equal(await scrollRegion.evaluate(element => element === document.activeElement), true);
        await page.keyboard.press('ArrowRight');
        await page.waitForFunction(() => document.querySelector('.visitor-chart-scroll').scrollLeft > 0);
        await scrollRegion.evaluate(element => element.scrollTo({left: 0, behavior: 'instant'}));
    }
}

for (const width of [320, 360, 390, 430, 1280]) {
    test(`populated report fits ${width}px, including long names and keyboard data table`, async t => {
        const page = await open(t, width);
        assert.equal(await page.locator('.visitor-summary-card').count(), 4);
        assert.deepEqual(await page.locator('.visitor-summary-card h3').allTextContents(),
            ['Сьогодні', 'Вчора', 'Останні 7 днів', 'Останні 30 днів']);
        assert.equal(await page.locator('.visitor-chart rect').count(), 30);
        const graphic = page.getByRole('img', {name: 'Щоденні перегляди сторінок за останні 30 днів'});
        assert.equal(await graphic.count(), 1, 'Chart has a meaningful accessible name.');
        assert.match(await graphic.getAttribute('aria-labelledby'), /visitor-chart-description/);
        const axisLabels = await page.locator('.visitor-chart svg text').allTextContents();
        for (const label of ['0', '24.09', '08.10', '23.10']) assert.ok(axisLabels.includes(label), `Visible axis label ${label}`);
        await assertReadableChart(page);
        assert.equal(await page.locator('.visitor-popularity-table tbody tr').count(), 6);
        assert.equal(await page.evaluate(() => window.fixtureInjected), undefined, 'Entity names must be escaped.');
        assert.equal(await page.locator('.visitor-popularity-table script, .visitor-popularity-table img[onerror]').count(), 0);
        const productImage = page.locator('.visitor-product-image');
        await productImage.scrollIntoViewIfNeeded();
        await page.waitForFunction(() => [...document.querySelectorAll('.visitor-product-image')]
            .every(image => image.complete && image.naturalWidth > 0));
        await assertChartScrolling(page);
        await assertFits(page, width);
        await screenshot(page, `populated-${width}`);

        const summary = page.locator('.visitor-daily-details > summary');
        await summary.focus();
        assert.equal(await summary.evaluate(element => element === document.activeElement), true);
        await page.keyboard.press('Enter');
        assert.equal(await page.locator('.visitor-daily-details').getAttribute('open'), '');
        assert.equal(await page.locator('[data-visitor-date]').count(), 30);
        const dates = await page.locator('[data-visitor-date]').evaluateAll(rows => rows.map(row => row.dataset.visitorDate));
        assert.equal(new Set(dates).size, 30);
        assert.equal(dates[0], '2026-10-23');
        assert.equal(dates.at(-1), '2026-09-24');
        assert.equal(await page.locator('.visitor-daily-details tbody th[scope="row"] time[datetime]').count(), 30);
        await assertFits(page, width);
        await screenshot(page, `daily-table-${width}`);
        await summary.focus();
        await page.keyboard.press('Space');
        assert.equal(await page.locator('.visitor-daily-details').getAttribute('open'), null);
    });
}

for (const state of ['empty', 'unavailable']) {
    for (const width of [320, 360, 390, 430, 1280]) {
        test(`${state} report fits ${width}px and keeps its state explicit`, async t => {
            const page = await open(t, width, state);
            await assertFits(page, width);
            if (state === 'unavailable') {
                assert.match(await page.getByRole('alert').innerText(), /Статистика тимчасово недоступна/);
                assert.equal(await page.locator('.visitor-summary-card, .visitor-chart svg, .visitor-popularity-table').count(), 0,
                    'Unavailable data must not be presented as successful zero counts.');
                assert.equal(await page.getByRole('link', {name: 'Спробувати ще раз'}).count(), 1);
            } else {
                assert.match(await page.getByRole('status').innerText(), /Даних поки немає/);
                assert.deepEqual(await page.locator('.visitor-summary-card strong').allTextContents(), ['0', '0', '0', '0']);
                assert.equal(await page.locator('.visitor-top-empty').count(), 2);
                assert.equal(await page.locator('[data-visitor-date]').count(), 30);
                assert.equal(await page.locator('.visitor-chart rect[height="0"]').count(), 30);
                await assertReadableChart(page);
                await assertChartScrolling(page);
            }
            if (width === 320 || width === 1280) await screenshot(page, `${state}-${width}`);
        });
    }
}

for (const width of [320, 1280]) {
    test(`popularity period 7/30/90 can be selected and submitted at ${width}px`, async t => {
        const page = await open(t, width);
        for (const period of [7, 30, 90]) {
            // The actual shared select script enhances the native select.
            const enhanced = page.locator('.visitor-period-form .anabelka-select-trigger');
            if (await enhanced.count()) {
                await enhanced.click();
                await assertFits(page, width);
                await page.locator('.visitor-period-form [role="option"]').filter({hasText: `Останні ${period} днів`}).click();
            } else {
                await page.locator('#visitor-period').selectOption(String(period));
            }
            await Promise.all([
                page.waitForURL(url => url.searchParams.get('period') === String(period)),
                page.getByRole('button', {name: 'Показати', exact: true}).click()
            ]);
            assert.equal(await page.locator('#visitor-period').inputValue(), String(period));
            assert.equal(await page.locator('.visitor-chart rect').count(), 30, 'Period changes popularity; chart remains 30 days.');
            await assertFits(page, width);
        }
    });
}
