import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import {createRequire} from 'node:module';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

export const repo = fileURLToPath(new URL('../../', import.meta.url));
export const storageKey = 'anabelka-notify-flash';
const require = createRequire(import.meta.url);
let playwright;
try { playwright = require('playwright'); } catch {
    for (const root of [process.env.ANABELKA_UI_TEST_DEPS,
        process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES,
        path.resolve(repo, '../test-deps')].filter(Boolean)) {
        try { playwright = createRequire(path.resolve(root, 'package.json'))('playwright'); break; } catch {}
    }
}
assert.ok(playwright, 'Playwright is required: these browser regressions must run, not skip.');

export async function launchBrowser() {
    return playwright.chromium.launch({headless: true,
        executablePath: process.env.ANABELKA_UI_TEST_BROWSER || undefined,
        args: ['--no-sandbox', '--disable-dev-shm-usage']});
}

export async function fixtureServer(document) {
    const requests = [];
    const responses = [];
    let documentVisits = 0;
    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://localhost');
        if (/^\/Anabelka\/(css|js)\//.test(url.pathname)) {
            const relativeAsset = url.pathname.slice('/Anabelka/'.length);
            const overrideRoot = process.env.ANABELKA_NOTIFY_TEST_BASELINE_DIR;
            const override = overrideRoot && ['js/anabelka-notify.js', 'css/anabelka-notify.css'].includes(relativeAsset)
                ? path.resolve(overrideRoot, relativeAsset) : '';
            const asset = override || path.resolve(repo, relativeAsset);
            if ((!override && !asset.startsWith(repo)) || !fs.existsSync(asset)) { res.writeHead(404); res.end(); return; }
            res.setHeader('Content-Type', asset.endsWith('.css') ? 'text/css' : 'text/javascript');
            res.end(fs.readFileSync(asset));
            return;
        }
        if (req.method === 'POST') {
            const chunks = [];
            for await (const chunk of req) chunks.push(chunk);
            requests.push({url: url.pathname, body: Buffer.concat(chunks).toString(),
                headers: req.headers});
            const response = responses.shift() || {status: 200, body: {success: true, message: 'Збережено.'}};
            if (response.network) { req.socket.destroy(); return; }
            res.statusCode = response.status || 200;
            res.setHeader('Content-Type', typeof response.body === 'object' ? 'application/json' : 'text/html');
            res.end(typeof response.body === 'object' ? JSON.stringify(response.body) : response.body);
            return;
        }
        if (url.pathname === '/Anabelka/admin/ai-translation/providers') {
            res.setHeader('Content-Type', 'application/json');
            res.end(JSON.stringify({success: true, providers: [], selected: ''}));
            return;
        }
        if (url.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
        if (/\.(?:svg|png|jpe?g|webp|ico|woff2?)$/.test(url.pathname)) {
            res.writeHead(404); res.end(); return;
        }
        documentVisits += 1;
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(await document(url, documentVisits));
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    return {origin: `http://127.0.0.1:${server.address().port}`, requests, responses,
        visits: () => documentVisits,
        close: () => new Promise(resolve => server.close(resolve))};
}

export async function browserPage(t, browser, origin, {width = 390, height = 844,
    pathname = '/fixture', init, clock = true} = {}) {
    const context = await browser.newContext({viewport: {width, height}});
    const page = await context.newPage();
    page.setDefaultTimeout(4000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    if (init) await page.addInitScript(init);
    if (clock) {
        await page.clock.install({time: new Date('2026-10-12T12:00:00Z')});
        await page.clock.pauseAt(new Date('2026-10-12T12:00:10Z'));
    }
    t.after(async () => { await context.close(); assert.deepEqual(errors, [], 'Application JavaScript must not throw.'); });
    await page.goto(origin + pathname);
    return page;
}

export async function toast(page) {
    return page.locator('#site-message').evaluate(node => {
        const style = getComputedStyle(node);
        const transition = node.getAnimations().find(animation => animation.transitionProperty === 'opacity');
        const targetOpacity = transition ? transition.effect.getKeyframes().at(-1).opacity : style.opacity;
        const rect = node.getBoundingClientRect();
        return {
            text: (node.querySelector('[data-notify-message], .anabelka-notify-text, .anabelka-notify-message') || node).textContent.trim(),
            shown: node.classList.contains('show'), role: node.getAttribute('role'),
            cssVisible: style.display !== 'none' && style.visibility === 'visible' && Number(targetOpacity) === 1
                && rect.width > 0 && rect.height > 0,
            live: node.getAttribute('aria-live'), atomic: node.getAttribute('aria-atomic'),
            type: ['success', 'error', 'warning', 'info'].find(type => node.classList.contains('is-' + type))
        };
    });
}

export async function expectToast(page, type, text) {
    const value = await toast(page);
    assert.equal(value.text, text, 'The notification must preserve the exact literal message.');
    assert.equal(value.shown, true, 'A notification must be immediately visible.');
    assert.equal(value.cssVisible, true, 'A shown notification must render visible text, including its opacity transition target.');
    assert.equal(value.type, type);
}

export async function waitForDocumentLoad(page, action) {
    const marker = await page.evaluate(() => {
        const value = crypto.randomUUID();
        window.__anabelkaBrowserDocument = value;
        return value;
    });
    // Closing a modal can traverse its history guard before reload/replace.
    // waitForNavigation also accepts that same-document traversal; require a
    // new document's load event before reading the one-shot flash instead.
    await Promise.all([page.waitForEvent('load'), action()]);
    assert.notEqual(await page.evaluate(() => window.__anabelkaBrowserDocument), marker,
        'The action must load a new document, not only traverse modal history.');
}

export function contrast(foreground, background, backdrop = 'rgb(255, 255, 255)') {
    const rgba = color => {
        const values = color.match(/[\d.]+/g).map(Number);
        return {rgb: values.slice(0, 3), alpha: values[3] ?? 1};
    };
    const composite = (front, back) => front.rgb.map((channel, n) => channel * front.alpha + back[n] * (1 - front.alpha));
    const backgroundRgb = composite(rgba(background), rgba(backdrop).rgb);
    const foregroundRgb = composite(rgba(foreground), backgroundRgb);
    const luminance = color => {
        const rgb = color.map(value => {
            const normalized = value / 255;
            return normalized <= .04045 ? normalized / 12.92 : ((normalized + .055) / 1.055) ** 2.4;
        });
        return .2126 * rgb[0] + .7152 * rgb[1] + .0722 * rgb[2];
    };
    const values = [luminance(foregroundRgb), luminance(backgroundRgb)].sort((a, b) => a - b);
    return (values[1] + .05) / (values[0] + .05);
}
