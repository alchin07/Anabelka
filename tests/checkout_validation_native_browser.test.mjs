import assert from 'node:assert/strict';
import test, {before, after} from 'node:test';
import {launchBrowser, fixtureServer, browserPage, expectToast} from './helpers/notify-browser.mjs';

let browser;
let fixture;

before(async () => {
    browser = await launchBrowser();
    fixture = await fixtureServer(url => {
        const quick = url.searchParams.has('quick');
        const action = quick ? '/Anabelka/quick-order' : '/Anabelka/checkout';
        const customerField = quick
            ? '<label>Номер телефону <input type="tel" name="customer_phone" required></label>'
            : '<label>Email <input type="email" name="customer_email" required></label>';
        const translations = JSON.stringify({
            required: 'Заповніть поле «{field}».',
            email: 'Некоректний Email.',
            labels: {customer_name:'Ім’я', customer_phone:'Номер телефону',
                customer_email:'Email'}
        });

        return '<!doctype html><html lang="uk"><head><meta charset="utf-8">'
            + '<meta name="viewport" content="width=device-width, initial-scale=1">'
            + '<link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css">'
            + '<link rel="stylesheet" href="/Anabelka/css/checkout-validation.css">'
            + '</head><body><form method="post" action="' + action + '">'
            + '<label>Ім’я <input name="customer_name" value="VIP GOLD" required></label>'
            + '<div>' + customerField + '</div>'
            + '<button type="submit">Продовжити</button></form>'
            + '<div id="site-message" class="site-message anabelka-notify"></div>'
            + '<script type="application/json" id="checkout-validation-messages">'
            + translations + '</script>'
            + '<script src="/Anabelka/js/anabelka-notify.js"></script>'
            + '<script src="/Anabelka/js/checkout-validation.js"></script>'
            + '</body></html>';
    });
});

after(async () => {
    if (browser) await browser.close();
    if (fixture) await fixture.close();
});

for (const quick of [true, false]) {
    test((quick ? 'quick order' : 'checkout')
        + ' cancels the native Android-style popup but keeps HTML5 validation',
    async t => {
        const page = await browserPage(t, browser, fixture.origin, {
            width: 360,
            height: 740,
            clock: false,
            pathname: quick ? '/fixture?quick' : '/fixture?checkout'
        });
        const input = page.locator(
            quick ? '[name="customer_phone"]' : '[name="customer_email"]'
        );
        const beforeRequests = fixture.requests.length;
        await page.evaluate(() => {
            window.invalidWasCanceled = false;
            document.querySelector('form').addEventListener('invalid', event => {
                window.invalidWasCanceled = event.defaultPrevented;
            }, true);
        });
        await page.getByRole('button', {name: 'Продовжити'}).click();
        await page.locator('#site-message.show').waitFor();

        const message = quick ? 'Заповніть поле «Номер телефону».'
            : 'Заповніть поле «Email».';
        assert.equal(await page.evaluate(() => window.invalidWasCanceled), true);
        assert.equal(await input.getAttribute('aria-invalid'), 'true');
        assert.equal(await page.locator('.checkout-validation-message').textContent(), message);
        await expectToast(page, 'warning', message);
        assert.equal(fixture.requests.length, beforeRequests,
            'An invalid order must not reach the server.');
        assert.equal(await input.evaluate(node => document.activeElement === node), true);
        const outline = await input.evaluate(node => getComputedStyle(node).outlineColor);
        assert.equal(outline, 'rgb(138, 43, 226)');

        await input.fill(quick ? '+4917600000000' : 'person@example.com');
        assert.equal(await input.getAttribute('aria-invalid'), null);
        assert.equal(await page.locator('.checkout-validation-message').count(), 0);
        assert.equal(await input.evaluate(node => node.validity.valid), true);
    });
}
