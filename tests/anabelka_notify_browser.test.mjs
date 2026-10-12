import assert from 'node:assert/strict';
import test, {before, after} from 'node:test';
import {launchBrowser, fixtureServer, browserPage, expectToast, toast, contrast, storageKey} from './helpers/notify-browser.mjs';
import {phpJson} from './helpers/php-probe.mjs';

let browser;
let fixture;
const durations = {success: 2800, error: 4800, warning: 4200, info: 3500};
before(async () => {
    browser = await launchBrowser();
    fixture = await fixtureServer(url => {
        const early = url.searchParams.has('early');
        const bodyEarly = url.searchParams.has('body-early');
        const missingHost = url.searchParams.has('missing-host');
        const flash = url.searchParams.has('flash') ? [
            {type: 'error', message: 'PHP та браузер.', options: {}},
            {type: 'info', message: 'Наступне PHP.', options: {}}
        ] : [];
        const module = '<script src="/Anabelka/js/anabelka-notify.js?v=browser"></script>';
        return '<!doctype html><html lang="uk"><head><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width, initial-scale=1">'
            + '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css">'
            + '<style>body{margin:0;font-family:sans-serif}</style>'
            + ((early || bodyEarly) ? module : '')
            + (early ? '<script>window.earlyResult=AnabelkaNotify.error("До появи body.");</script>' : '')
            + '</head><body>'
            + (bodyEarly ? '<script>window.bodyEarlyResult=AnabelkaNotify.info("Body ще читається.");</script>' : '')
            + '<input id="before" aria-label="Поле"><button id="after">Наступне</button>'
            + (missingHost ? '' : '<div id="site-message" class="site-message anabelka-notify"></div>')
            + (flash.length ? '<script id="anabelka-flash" type="application/json">' + JSON.stringify(flash) + '</script>' : '')
            + ((early || bodyEarly) ? '' : module)
            + (url.searchParams.has('duplicate-load') ? module : '')
            + '</body></html>';
    });
});
after(async () => { if (browser) await browser.close(); if (fixture) await fixture.close(); });

test('FIFO keeps a visible error, suppresses active/pending duplicates and advances in order', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    await page.evaluate(() => {
        AnabelkaNotify.error('Перша помилка.');
        AnabelkaNotify.success('Потім збереження.');
        AnabelkaNotify.success('Потім збереження.');
        AnabelkaNotify.error('Перша помилка.');
        AnabelkaNotify.info('Останнє.');
    });
    await expectToast(page, 'error', 'Перша помилка.');
    await page.clock.runFor(4799);
    await expectToast(page, 'error', 'Перша помилка.');
    await page.clock.runFor(1);
    await expectToast(page, 'success', 'Потім збереження.');
    await page.clock.runFor(2800);
    await expectToast(page, 'info', 'Останнє.');
    await page.clock.runFor(3500);
    assert.equal((await toast(page)).shown, false, 'Duplicates must not extend or repeat the queue.');
});

for (const [type, duration] of Object.entries(durations)) {
    test(`${type} stays visible for exactly ${duration} ms`, async t => {
        const page = await browserPage(t, browser, fixture.origin);
        await page.evaluate(type => AnabelkaNotify[type]('Точна тривалість.'), type);
        await page.clock.runFor(duration - 1);
        await expectToast(page, type, 'Точна тривалість.');
        await page.clock.runFor(1);
        assert.equal((await toast(page)).shown, false);
    });
}

test('safe literal text, severity live region semantics and custom duration work in the real DOM', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    const text = '</script><img src=x onerror="window.notifyXss=1"><b>Лише текст</b>';
    await page.evaluate(text => AnabelkaNotify.error(text, {duration: 1234}), text);
    await expectToast(page, 'error', text);
    assert.equal(await page.locator('#site-message img, #site-message b, #site-message script').count(), 0);
    assert.equal(await page.evaluate(() => window.notifyXss), undefined);
    assert.equal((await toast(page)).role, 'alert');
    assert.equal((await toast(page)).live, 'assertive');
    assert.equal((await toast(page)).atomic, 'true');
    await page.clock.runFor(1234);
    await page.evaluate(() => AnabelkaNotify.info('Ввічливе повідомлення.'));
    assert.equal((await toast(page)).role, 'status');
    assert.equal((await toast(page)).live, 'polite');
});

test('stored queue and PHP JSON overlap appears once, in order, immediately on reload', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    await page.evaluate(() => {
        AnabelkaNotify.store('error', 'PHP та браузер.');
        AnabelkaNotify.store('warning', 'Наступне браузера.');
        AnabelkaNotify.store('warning', 'Наступне браузера.');
    });
    await page.goto(fixture.origin + '/fixture?flash');
    await expectToast(page, 'error', 'PHP та браузер.');
    assert.equal(await page.evaluate(key => sessionStorage.getItem(key), storageKey), null);
    await page.clock.runFor(4800);
    await expectToast(page, 'info', 'Наступне PHP.');
    await page.clock.runFor(3500);
    await expectToast(page, 'warning', 'Наступне браузера.');
    await page.clock.runFor(4200);
    assert.equal((await toast(page)).shown, false);
    await page.goto(fixture.origin + '/fixture');
    assert.equal((await toast(page)).shown, false, 'A consumed flash must never repeat on another document.');
});

test('legacy stored object is consumed once without a post-load delay', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    await page.evaluate(key => sessionStorage.setItem(key, JSON.stringify({type: 'success', message: 'Старий формат.'})), storageKey);
    await page.reload();
    await expectToast(page, 'success', 'Старий формат.');
    await page.reload();
    assert.equal((await toast(page)).shown, false);
});

test('head loading before body defers queued messages until DOM-ready without losing them', async t => {
    const page = await browserPage(t, browser, fixture.origin, {pathname: '/fixture?early&missing-host'});
    assert.equal(await page.evaluate(() => window.earlyResult), true);
    assert.equal(await page.locator('#site-message').count(), 1);
    await expectToast(page, 'error', 'До появи body.');
});

test('early body call waits for an authored host later in the same document', async t => {
    const page = await browserPage(t, browser, fixture.origin, {pathname: '/fixture?body-early'});
    assert.equal(await page.evaluate(() => window.bodyEarlyResult), true);
    assert.equal(await page.locator('#site-message').count(), 1,
        'An early inline body call must not create a second host before the authored host is parsed.');
    await expectToast(page, 'info', 'Body ще читається.');
});

test('missing host is created once and repeated module loads retain one host and queue', async t => {
    const page = await browserPage(t, browser, fixture.origin, {pathname: '/fixture?missing-host&duplicate-load'});
    await page.evaluate(() => { AnabelkaNotify.success('Один хост.'); AnabelkaNotify.info('Друга.'); });
    assert.equal(await page.locator('#site-message').count(), 1);
    await expectToast(page, 'success', 'Один хост.');
    await page.clock.runFor(2800);
    await expectToast(page, 'info', 'Друга.');
    await page.clock.runFor(3500);
    assert.equal((await toast(page)).shown, false);
});

test('disabled storage does not break PHP flash consumption or direct notifications', async t => {
    const page = await browserPage(t, browser, fixture.origin, {pathname: '/fixture?flash', init: () => {
        Object.defineProperty(window, 'sessionStorage', {get() { throw new DOMException('Disabled', 'SecurityError'); }});
    }});
    await expectToast(page, 'error', 'PHP та браузер.');
    assert.equal(await page.evaluate(() => AnabelkaNotify.store('success', 'Недоступне.')), false);
    await page.evaluate(() => AnabelkaNotify.success('Працює без storage.'));
    await page.clock.runFor(4800 + 3500);
    await expectToast(page, 'success', 'Працює без storage.');
});

test('actual PHP bridge injects one module/host, escapes markup, and consumes flash only once', async t => {
    const text = '</script><script>window.notifyXss=1</script><b>PHP текст</b>';
    const encoded = Buffer.from(JSON.stringify([{type: 'success', message: text}])).toString('base64');
    const documents = phpJson(`$fixtureMessages=json_decode(base64_decode('${encoded}'),true); require 'tests/fixtures/anabelka_notify_document.php';`);
    const phpFixture = await fixtureServer((_url, visit) => documents[Math.min(visit - 1, 1)]);
    t.after(() => phpFixture.close());
    const page = await browserPage(t, browser, phpFixture.origin);
    await expectToast(page, 'success', text);
    assert.equal(await page.locator('#site-message').count(), 1);
    assert.equal(await page.locator('script[src*="/js/anabelka-notify.js"]').count(), 1);
    assert.equal(await page.locator('link[href*="/css/anabelka-notify.css"]').count(), 1);
    assert.equal(await page.locator('#site-message b, #site-message script').count(), 0);
    assert.equal(await page.evaluate(() => window.notifyXss), undefined);
    await page.reload();
    assert.equal((await toast(page)).shown, false);
});

test('toast is keyboard reachable without stealing focus, pauses while focused and Escape advances queue', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    await page.locator('#after').focus();
    await page.evaluate(() => { AnabelkaNotify.error('Можна прочитати.'); AnabelkaNotify.success('Наступна.'); });
    assert.equal(await page.evaluate(() => document.activeElement.id), 'after');
    await page.keyboard.press('Tab');
    assert.equal(await page.evaluate(() => Boolean(document.activeElement.closest('#site-message'))), true,
        'The toast or its dismiss control must be reachable using Tab.');
    await page.clock.runFor(15000);
    await expectToast(page, 'error', 'Можна прочитати.');
    await page.keyboard.press('Escape');
    await expectToast(page, 'success', 'Наступна.');
});

test('dismissing the last focused toast releases hidden focus and later messages expire', async t => {
    const page = await browserPage(t, browser, fixture.origin);
    await page.evaluate(() => AnabelkaNotify.error('Остання помилка.'));
    await page.locator('#site-message').focus();
    await page.keyboard.press('Escape');
    assert.equal((await toast(page)).shown, false);
    assert.equal(await page.evaluate(() => document.activeElement.id === 'site-message'), false,
        'Focus must leave the dismissed aria-hidden notification.');
    await page.evaluate(() => AnabelkaNotify.info('Пізніше повідомлення.'));
    await expectToast(page, 'info', 'Пізніше повідомлення.');
    await page.clock.runFor(3500);
    assert.equal((await toast(page)).shown, false,
        'An old focused flag must not pause future messages permanently.');
});

for (const [name, head, body] of [
    ['nomodule', '<script nomodule src="/Anabelka/js/anabelka-notify.js?v=old"></script>', ''],
    ['disabled stylesheet', '<link rel="stylesheet" disabled href="/Anabelka/css/anabelka-notify.css?v=old">', ''],
    ['iframe fallback', '', '<iframe><div id="site-message"></div><script src="/Anabelka/js/anabelka-notify.js?v=old"></script><link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=old"></iframe>']
]) {
    test(`bootstrap delivers a visible one-shot flash despite ${name}`, async t => {
        const encoded = Buffer.from(JSON.stringify({head, body})).toString('base64');
        const documents = phpJson(`$inert=json_decode(base64_decode('${encoded}'),true); $fixtureHead=$inert['head']; $fixtureBody=$inert['body']; $fixtureMessages=[['type'=>'info','message'=>'PHP Probe']]; require 'tests/fixtures/anabelka_notify_document.php';`);
        const phpFixture = await fixtureServer((_url, visit) => documents[Math.min(visit - 1, 1)]);
        t.after(() => phpFixture.close());
        const page = await browserPage(t, browser, phpFixture.origin);
        await expectToast(page, 'info', 'PHP Probe');
        assert.equal(await page.locator('#site-message').count(), 1);
        assert.equal(await page.locator('#site-message').evaluate(node => getComputedStyle(node).position), 'fixed');
        await page.reload();
        assert.equal((await toast(page)).shown, false);
    });
}

for (const theme of ['light', 'dark']) {
    for (const type of Object.keys(durations)) {
        test(`${theme} ${type} text has WCAG AA contrast of at least 4.5`, async t => {
            const page = await browserPage(t, browser, fixture.origin);
            await page.evaluate(({theme, type}) => {
                document.documentElement.dataset.theme = theme;
                AnabelkaNotify[type]('Контраст тексту.');
            }, {theme, type});
            const colors = await page.locator('#site-message').evaluate(node => {
                const style = getComputedStyle(node);
                return {foreground: style.color, background: style.backgroundColor};
            });
            assert.ok(contrast(colors.foreground, colors.background) >= 4.5,
                `${theme} ${type}: ${JSON.stringify(colors)}`);
        });
    }
}

for (const width of [320, 360, 375, 390, 412, 430]) {
    for (const mode of ['long-string', 'multiline']) {
        test(`${mode} fits ${width}px without page/toast horizontal scrolling`, async t => {
            const page = await browserPage(t, browser, fixture.origin, {width});
            const text = mode === 'long-string' ? 'ТривалийБезПробілів'.repeat(80)
                : Array.from({length: 80}, (_, n) => `Рядок ${n + 1}: довге повідомлення з деталями.`).join('\n');
            await page.evaluate(text => AnabelkaNotify.warning(text), text);
            await page.clock.runFor(200);
            const metrics = await page.locator('#site-message').evaluate(node => {
                const rect = node.getBoundingClientRect();
                return {left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom,
                    width: rect.width, viewport: innerWidth, height: innerHeight,
                    scrollWidth: node.scrollWidth, clientWidth: node.clientWidth,
                    pageScroll: document.documentElement.scrollWidth};
            });
            assert.ok(metrics.left >= 11 && metrics.right <= width - 11, JSON.stringify(metrics));
            assert.ok(metrics.top >= 11 && metrics.bottom <= metrics.height - 11, JSON.stringify(metrics));
            assert.ok(metrics.scrollWidth <= metrics.clientWidth + 1, JSON.stringify(metrics));
            assert.ok(metrics.pageScroll <= width, JSON.stringify(metrics));
            assert.ok(metrics.width <= Math.min(360, width - 24) + 1);
        });
    }
}
