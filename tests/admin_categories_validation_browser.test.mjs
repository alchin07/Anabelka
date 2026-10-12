import assert from 'node:assert/strict';
import test, {before, after} from 'node:test';
import {phpJson} from './helpers/php-probe.mjs';
import {launchBrowser, fixtureServer, browserPage, expectToast, toast, contrast, waitForDocumentLoad} from './helpers/notify-browser.mjs';

let browser;
let fixture;
let document;
const requiredName = 'Будь ласка, заповніть назву категорії';
before(async () => {
    document = phpJson("require 'tests/fixtures/admin_categories_notify.php';");
    browser = await launchBrowser();
    fixture = await fixtureServer(url => url.pathname === '/sentinel'
        ? '<!doctype html><title>Попередня сторінка</title><button id="sentinel">Назад</button>'
        : document);
});
after(async () => { if (browser) await browser.close(); if (fixture) await fixture.close(); });

async function harness(t, options = {}) {
    fixture.responses.length = 0;
    const page = await browserPage(t, browser, fixture.origin,
        {pathname: '/Anabelka/admin/categories', ...options});
    await page.locator('[data-category-create].is-root').first().waitFor();
    return page;
}

async function open(page, kind) {
    const selectors = {create: '[data-category-create].is-root', edit: '[data-category-edit="3"]',
        move: '[data-category-move="1"]', delete: '[data-category-delete="3"]'};
    await page.locator(selectors[kind]).first().click();
    await page.locator(`#category-${kind}-modal`).waitFor({state: 'visible'});
    await page.clock.runFor(30);
}

async function submit(page, kind) {
    await page.locator(`#category-${kind}-form [type="submit"]`).click();
}

async function errorFor(page, selector) {
    return page.locator(selector).evaluate(field => {
        const ids = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
        const errors = ids.map(id => document.getElementById(id)).filter(node => node?.classList.contains('category-field-error'));
        return {invalid: field.getAttribute('aria-invalid'), ids, errors: errors.map(node => ({id: node.id,
            text: node.textContent, hidden: node.hidden, role: node.getAttribute('role')}))};
    });
}

test('validation banner shares success-toast colors and sits below its form', async t => {
    const page = await harness(t, {width: 320});
    await open(page, 'create');
    await submit(page, 'create');
    const banner = page.locator('#category-create-modal .category-form-errors');
    const appearance = await banner.evaluate(node => {
        const toast = document.getElementById('site-message');
        toast.classList.add('is-success');
        const surface = element => {
            const style = getComputedStyle(element);
            return {background: style.backgroundColor, color: style.color,
                border: style.borderTopColor, borderWidth: style.borderLeftWidth,
                radius: style.borderTopLeftRadius, padding: style.paddingTop,
                fontSize: style.fontSize, fontWeight: style.fontWeight, shadow: style.boxShadow};
        };
        const result = {banner: surface(node), toast: surface(toast),
            belowForm: node.previousElementSibling?.id === 'category-create-form',
            withinForm: Boolean(node.closest('form')), hasLiveRegion: node.hasAttribute('aria-live')};
        toast.classList.remove('is-success');
        return result;
    });
    assert.deepEqual(appearance.banner, appearance.toast);
    assert.equal(appearance.belowForm, true);
    assert.equal(appearance.withinForm, false);
    assert.equal(appearance.hasLiveRegion, false);
    assert.equal(await page.locator('#category-create-form .category-field-error').count(), 0);
    await page.locator('#category-create-name').fill('Виправлена назва');
    assert.equal(await banner.count(), 0, 'Correcting the last invalid field removes the banner.');
});

test('empty category name uses branded inline validation and never invokes native reporting', async t => {
    const page = await harness(t);
    await open(page, 'create');
    await page.evaluate(() => {
        window.nativeValidationCalls = [];
        for (const prototype of [HTMLFormElement.prototype, HTMLInputElement.prototype, HTMLSelectElement.prototype, HTMLTextAreaElement.prototype]) {
            for (const method of ['checkValidity', 'reportValidity']) {
                prototype[method] = function () { window.nativeValidationCalls.push(method); return false; };
            }
        }
        document.querySelector('#category-create-name').addEventListener('invalid', () => window.nativeValidationCalls.push('invalid'));
    });
    const count = fixture.requests.length;
    await submit(page, 'create');
    assert.equal(fixture.requests.length, count, 'Invalid data must not reach the HTTP API.');
    const error = await errorFor(page, '#category-create-name');
    assert.equal(error.invalid, 'true');
    assert.equal(error.errors.length, 1);
    assert.equal(error.errors[0].text, requiredName);
    assert.equal(error.errors[0].role, null, 'The focused described field must not also announce a live alert.');
    assert.deepEqual(await page.evaluate(() => window.nativeValidationCalls), []);
    assert.equal(await page.locator('#category-create-name').evaluate(node => node === document.activeElement), true);
    await page.clock.runFor(400);
    assert.equal(await page.locator('#category-create-name').evaluate(node => node === document.activeElement), true,
        'The shared focus policy must not blur validation focus on its delayed timers.');
    assert.equal(await page.locator('#category-create-form').evaluate(node => node.noValidate), true);
    assert.equal(await page.locator('#category-create-modal').isVisible(), true);
    assert.equal(await page.locator('#category-create-form [type="submit"]').isEnabled(), true);
    assert.equal((await toast(page)).shown, false, 'Field guidance does not enqueue a general notification.');
});

for (const kind of ['create', 'edit']) {
    test(`${kind} rejects whitespace-only names without sending an HTTP request`, async t => {
        const page = await harness(t);
        await open(page, kind);
        await page.locator(`#category-${kind}-name`).fill(' \u00a0  ');
        const count = fixture.requests.length;
        await submit(page, kind);
        assert.equal(fixture.requests.length, count);
        assert.equal((await errorFor(page, `#category-${kind}-name`)).errors[0]?.text, requiredName);
        assert.equal(await page.locator(`#category-${kind}-modal`).isVisible(), true);
        assert.equal(await page.locator(`#category-${kind}-form [type="submit"]`).isEnabled(), true);
    });
}

test('Enter in the empty name runs the same branded validation', async t => {
    const page = await harness(t);
    await open(page, 'create');
    const count = fixture.requests.length;
    await page.locator('#category-create-name').click();
    await page.locator('#category-create-name').press('Enter');
    assert.equal(fixture.requests.length, count);
    assert.equal((await errorFor(page, '#category-create-name')).errors[0]?.text, requiredName);
    assert.equal(await page.locator('#category-create-modal').isVisible(), true);
    const focus = await page.locator('#category-create-name').evaluate(node => {
        const style = getComputedStyle(node);
        return {visible: node.matches(':focus-visible'), outline: style.outlineColor,
            outlineWidth: style.outlineWidth, outlineStyle: style.outlineStyle, border: style.borderColor,
            shadow: style.boxShadow,
            background: getComputedStyle(node.closest('.category-modal-window')).backgroundColor};
    });
    assert.equal(focus.visible, true);
    assert.equal(focus.border, 'rgb(138, 43, 226)', 'The invalid border uses the approved brand purple.');
    assert.equal(focus.outline, focus.border, 'Focus must not combine outlines of different colors.');
    assert.notEqual(focus.shadow, 'none', 'The focus halo remains visible around the invalid field.');
    assert.equal(focus.outlineStyle, 'solid');
    assert.ok(parseFloat(focus.outlineWidth) >= 2);
    assert.ok(contrast(focus.outline, focus.background) >= 3, JSON.stringify(focus));
});

test('programmatically supplied overlength source and translation names are rejected despite native tooLong being false', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    const nativeState = await page.evaluate(() => {
        const name = document.querySelector('#category-edit-name');
        const translation = document.querySelector('.category-translation-name');
        name.value = 'Н'.repeat(151);
        translation.value = 'E'.repeat(256);
        return [name.validity.tooLong, translation.validity.tooLong];
    });
    assert.deepEqual(nativeState, [false, false], 'This covers values written by JS/AI, beyond browser keystroke maxlength handling.');
    const count = fixture.requests.length;
    await submit(page, 'edit');
    assert.equal(fixture.requests.length, count);
    assert.equal((await errorFor(page, '#category-edit-name')).errors[0]?.text, 'Введіть не більше 150 символів.');
    assert.equal((await errorFor(page, '.category-translation-name')).errors[0]?.text, 'Введіть не більше 255 символів.');
    assert.equal(await page.locator('#category-edit-name').evaluate(node => node === document.activeElement), true,
        'The first invalid field wins over later translation errors.');
});

test('create also rejects programmatically supplied names beyond 150 characters', async t => {
    const page = await harness(t);
    await open(page, 'create');
    await page.locator('#category-create-name').evaluate(node => { node.value = 'А'.repeat(151); });
    const count = fixture.requests.length;
    await submit(page, 'create');
    assert.equal(fixture.requests.length, count);
    assert.equal((await errorFor(page, '#category-create-name')).errors[0]?.text, 'Введіть не більше 150 символів.');
});

test('AI correction clears an existing overlength translation error and preserves AI workflow metadata', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    await page.locator('.category-translation-name').evaluate(node => { node.value = 'A'.repeat(256); });
    const count = fixture.requests.length;
    await submit(page, 'edit');
    assert.equal(fixture.requests.length, count);
    assert.equal((await errorFor(page, '.category-translation-name')).errors[0]?.text, 'Введіть не більше 255 символів.');
    await page.evaluate(() => {
        window.AnabelkaAITranslation = {suggest: async () => ({name: 'AI corrected translation', description: 'AI corrected description'})};
    });
    await page.locator('[data-category-ai-translate]').click();
    await page.waitForFunction(() => document.querySelector('.category-translation-name').value === 'AI corrected translation');
    const corrected = await errorFor(page, '.category-translation-name');
    assert.notEqual(corrected.invalid, 'true');
    assert.deepEqual(corrected.errors, []);
    assert.equal(await page.locator('.category-translation-source').inputValue(), 'ai');
    assert.equal(await page.locator('[data-category-translation-status]').inputValue(), 'approved');
    assert.equal(fixture.requests.length, count, 'AI correction does not silently save the category.');
});

test('ValidityState type mismatch and literal customValidity messages both block submission', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    const literal = '<img src=x onerror="window.categoryValidationXss=1">Перевірте переклад.';
    await page.evaluate(message => {
        const name = document.querySelector('#category-edit-name');
        name.type = 'email';
        name.value = 'not-an-address';
        document.querySelector('.category-translation-name').setCustomValidity(message);
    }, literal);
    const count = fixture.requests.length;
    await submit(page, 'edit');
    assert.equal(fixture.requests.length, count);
    assert.equal(await page.locator('#category-edit-name').evaluate(node => node.validity.typeMismatch), true);
    const nameError = await errorFor(page, '#category-edit-name');
    assert.equal(nameError.invalid, 'true');
    assert.ok(nameError.errors[0]?.text.trim(), 'Type mismatch needs accessible field guidance.');
    assert.equal((await errorFor(page, '.category-translation-name')).errors[0]?.text, literal);
    assert.equal(await page.locator('.category-field-error img').count(), 0);
    assert.equal(await page.evaluate(() => window.categoryValidationXss), undefined);
});

test('disabled, hidden and readonly controls with willValidate false cannot block a valid request', async t => {
    const page = await harness(t);
    await page.locator('[data-category-create][data-parent-id="1"]').click();
    await page.clock.runFor(30);
    await page.locator('#category-create-name').fill('Назва дитини');
    const state = await page.evaluate(() => {
        const select = document.querySelector('#category-create-department');
        const hidden = document.querySelector('#category-create-parent');
        const readonly = document.querySelector('#category-create-description');
        readonly.readOnly = true;
        for (const field of [select, hidden, readonly]) field.setCustomValidity('Не перевіряти це поле.');
        return [select, hidden, readonly].map(field => ({disabled: field.disabled, willValidate: field.willValidate}));
    });
    assert.equal(state[0].disabled, true);
    assert.deepEqual(state.map(field => field.willValidate), [false, false, false]);
    fixture.responses.push({status: 422, body: {success: false, message: 'Контрольний результат сервера.'}});
    const count = fixture.requests.length;
    await submit(page, 'create');
    await page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
    assert.equal(fixture.requests.length, count + 1);
    assert.equal(await page.locator('#category-create-modal .category-form-errors .category-field-error').count(), 0);
    assert.equal(fixture.requests.at(-1).body.includes('name="department_id"'), false);
});

test('each inline error has its own id and preserves pre-existing describedby references through correction', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    await page.evaluate(() => {
        for (const [selector, hintId] of [['#category-edit-name', 'source-name-hint'], ['.category-translation-name', 'translation-name-hint']]) {
            const field = document.querySelector(selector);
            const hint = document.createElement('span');
            hint.id = hintId;
            hint.textContent = 'Підказка до поля.';
            field.after(hint);
            field.setAttribute('aria-describedby', hintId);
        }
        document.querySelector('#category-edit-name').value = '';
        document.querySelector('.category-translation-name').setCustomValidity('Перевірте переклад.');
    });
    await submit(page, 'edit');
    const source = await errorFor(page, '#category-edit-name');
    const translation = await errorFor(page, '.category-translation-name');
    assert.equal(source.errors.length, 1);
    assert.equal(translation.errors.length, 1);
    assert.notEqual(source.errors[0].id, translation.errors[0].id);
    assert.equal(await page.locator('#category-edit-modal .category-form-errors').count(), 1);
    assert.equal(await page.locator('#category-edit-modal .category-form-errors .category-field-error').count(), 2);
    assert.equal(await page.locator('#category-edit-modal .category-form-errors').evaluate(node => node.previousElementSibling.id),
        'category-edit-form');
    assert.ok(source.errors[0].id && translation.errors[0].id);
    assert.ok(source.ids.includes('source-name-hint'));
    assert.ok(translation.ids.includes('translation-name-hint'));
    assert.equal(await page.locator(`#${source.errors[0].id}`).count(), 1);
    assert.equal(await page.locator(`#${translation.errors[0].id}`).count(), 1);
    await page.locator('#category-edit-name').fill('Виправлена назва');
    await page.locator('.category-translation-name').evaluate(node => {
        node.setCustomValidity('');
        node.dispatchEvent(new Event('change', {bubbles: true}));
    });
    assert.equal(await page.locator('#category-edit-modal .category-form-errors').count(), 0,
        'Correcting all invalid fields removes the grouped banner.');
    for (const [selector, hint] of [['#category-edit-name', 'source-name-hint'], ['.category-translation-name', 'translation-name-hint']]) {
        const state = await errorFor(page, selector);
        assert.notEqual(state.invalid, 'true');
        assert.deepEqual(state.errors, []);
        assert.deepEqual(state.ids, [hint]);
    }
});

test('an unchanged repeated invalid submission creates no repeated error mutations or announcements', async t => {
    const page = await harness(t);
    await open(page, 'create');
    await submit(page, 'create');
    assert.equal(await page.locator('#category-create-modal .category-form-errors .category-field-error').count(), 1);
    await page.evaluate(() => {
        window.repeatedErrorMutations = [];
        window.validationObserver = new MutationObserver(records => {
            for (const record of records) {
                const target = record.target.nodeType === Node.ELEMENT_NODE ? record.target : record.target.parentElement;
                const errorChanged = target?.closest('.category-field-error')
                    || [...record.addedNodes, ...record.removedNodes].some(node => node.nodeType === Node.ELEMENT_NODE
                        && (node.matches('.category-field-error') || node.querySelector('.category-field-error')));
                const ariaChanged = record.type === 'attributes' && ['aria-invalid', 'aria-describedby'].includes(record.attributeName);
                if (errorChanged || ariaChanged) window.repeatedErrorMutations.push(record.type + ':' + (record.attributeName || ''));
            }
        });
        window.validationObserver.observe(document.querySelector('#category-create-modal .category-modal-window'),
            {subtree: true, childList: true, characterData: true, attributes: true});
    });
    const count = fixture.requests.length;
    await submit(page, 'create');
    await submit(page, 'create');
    assert.equal(fixture.requests.length, count);
    assert.deepEqual(await page.evaluate(() => window.repeatedErrorMutations), []);
    assert.equal((await toast(page)).shown, false);
    const error = await errorFor(page, '#category-create-name');
    assert.equal(error.errors[0].role, null);
    assert.equal(await page.locator('.category-field-error[aria-live]').count(), 0);
});

test('form reset and opening another category clear field validation state', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    await page.locator('#category-edit-name').click();
    await page.locator('#category-edit-name').fill('');
    assert.equal(await page.locator('#category-edit-name').inputValue(), '');
    await submit(page, 'edit');
    assert.equal((await errorFor(page, '#category-edit-name')).invalid, 'true');
    await page.locator('#category-edit-form').evaluate(form => form.reset());
    await page.clock.runFor(1);
    assert.notEqual((await errorFor(page, '#category-edit-name')).invalid, 'true');
    assert.equal(await page.locator('#category-edit-modal .category-form-errors .category-field-error').count(), 0);
    await submit(page, 'edit');
    assert.equal((await errorFor(page, '#category-edit-name')).invalid, 'true');
    await page.keyboard.press('Escape');
    await page.locator('#category-edit-modal').waitFor({state: 'hidden'});
    await page.locator('[data-category-edit="4"]').click();
    await page.clock.runFor(30);
    assert.equal(await page.locator('#category-edit-name').inputValue(), 'Інша');
    assert.notEqual((await errorFor(page, '#category-edit-name')).invalid, 'true');
    assert.equal(await page.locator('#category-edit-modal .category-form-errors .category-field-error').count(), 0);
});

test('real Android Back history handler closes an invalid modal and restores its trigger focus', async t => {
    const page = await harness(t);
    await page.goto(fixture.origin + '/sentinel');
    await page.goto(fixture.origin + '/Anabelka/admin/categories');
    assert.equal(await page.locator('script[src*="anabelka-admin-back.js"]').count(), 1);
    assert.equal(await page.evaluate(() => typeof window.AnabelkaAdminBack?.register), 'function');
    const trigger = page.locator('[data-category-create].is-root').first();
    await open(page, 'create');
    await submit(page, 'create');
    assert.equal((await errorFor(page, '#category-create-name')).invalid, 'true');
    assert.equal(await page.evaluate(() => history.state?.__anabelkaAdminBackGuard), 1);
    await page.goBack();
    await page.locator('#category-create-modal').waitFor({state: 'hidden'});
    assert.equal(new URL(page.url()).pathname, '/Anabelka/admin/categories');
    assert.equal(await trigger.evaluate(node => node === document.activeElement), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), '');
    await open(page, 'create');
    assert.notEqual((await errorFor(page, '#category-create-name')).invalid, 'true');
    assert.equal(await page.locator('#category-create-modal .category-form-errors .category-field-error').count(), 0);
});

for (const selector of ['#category-edit-name', '.category-translation-name']) {
    test(`short edit viewport focuses ${selector} and keeps form-level notice scrollable`, async t => {
        const page = await harness(t, {width: 360, height: 480});
        await open(page, 'edit');
        await page.locator(selector).evaluate(node => {
            if (node.id === 'category-edit-name') node.value = '';
            else node.value = 'T'.repeat(256);
        });
        const modalWindow = page.locator('#category-edit-modal .category-modal-window');
        await modalWindow.evaluate(node => { node.scrollTop = node.scrollHeight; });
        await submit(page, 'edit');
        await page.clock.runFor(300);
        const result = await page.locator(selector).evaluate(field => {
            const modal = field.closest('.category-modal-window').getBoundingClientRect();
            const rect = field.getBoundingClientRect();
            const error = (field.getAttribute('aria-describedby') || '').split(/\s+/)
                .map(id => document.getElementById(id)).find(node => node?.classList.contains('category-field-error'));
            return {focused: field === document.activeElement, fieldTop: rect.top, fieldBottom: rect.bottom,
                belowForm: error?.closest('.category-form-errors')?.previousElementSibling === field.form,
                modalTop: modal.top, modalBottom: modal.bottom};
        });
        assert.equal(result.focused, true);
        assert.ok(result.fieldTop >= result.modalTop && result.fieldBottom <= result.modalBottom, JSON.stringify(result));
        assert.equal(result.belowForm, true);
        const summary = page.locator('#category-edit-modal .category-form-errors');
        await summary.scrollIntoViewIfNeeded();
        const reachable = await summary.evaluate(node => {
            const panel = node.getBoundingClientRect();
            const modal = node.closest('.category-modal-window').getBoundingClientRect();
            return panel.top >= modal.top && panel.bottom <= Math.min(modal.bottom, innerHeight);
        });
        assert.equal(reachable, true, 'The form-level notice remains reachable in a short Android viewport.');
    });
}

test('maximum valid names preserve the complete FormData payload and Enter still submits successfully', async t => {
    const page = await harness(t);
    await open(page, 'edit');
    const values = {name: ' ' + 'Н'.repeat(148) + ' ', translation: 'E'.repeat(255)};
    await page.locator('#category-edit-name').fill(values.name);
    await page.locator('.category-translation-name').fill(values.translation);
    const before = await page.locator('#category-edit-form').evaluate(form => [...new FormData(form).entries()]);
    fixture.responses.push({body: {success: true, message: 'Межові значення збережено.'}});
    const count = fixture.requests.length;
    await page.locator('#category-edit-name').click();
    await waitForDocumentLoad(page, () => page.locator('#category-edit-name').press('Enter'));
    assert.equal(fixture.requests.length, count + 1);
    const request = fixture.requests.at(-1);
    const boundary = /boundary=(.*)$/.exec(request.headers['content-type'])[1];
    const received = request.body.split('--' + boundary).flatMap(part => {
        const match = /name="([^"]+)"\r\n\r\n([\s\S]*)\r\n$/.exec(part);
        return match ? [[match[1], match[2]]] : [];
    });
    assert.deepEqual(received, before, 'Validation must not trim names, alter workflow fields, checkbox duplicates, or CSRF.');
    await expectToast(page, 'success', 'Межові значення збережено.');
});

for (const [kind, fieldSelector] of [['move', '#category-move-parent'], ['delete', '#category-delete-form [type="submit"]']]) {
    test(`${kind} preserves generic customValidity constraints with inline field guidance`, async t => {
        const page = await harness(t);
        await open(page, kind);
        await page.locator(fieldSelector).evaluate(node => node.setCustomValidity('Контрольна помилка поля.'));
        const count = fixture.requests.length;
        await submit(page, kind);
        assert.equal(fixture.requests.length, count);
        assert.equal((await errorFor(page, fieldSelector)).errors[0]?.text, 'Контрольна помилка поля.');
        assert.equal(await page.locator(`#category-${kind}-modal`).isVisible(), true);
        assert.equal(await page.locator(`#category-${kind}-form [type="submit"]`).isEnabled(), true);
    });
}

for (const kind of ['create', 'edit', 'move', 'delete']) {
    test(`${kind} rejects synthetic resubmission while its request is pending`, async t => {
        const page = await harness(t);
        await open(page, kind);
        if (kind === 'create') await page.locator('#category-create-name').fill('Перевірка очікування');
        await page.evaluate(() => {
            window.categoryFormPostCalls = 0;
            const originalFetch = window.fetch;
            window.fetch = function (url, options) {
                if (options?.method === 'POST') window.categoryFormPostCalls += 1;
                return originalFetch.apply(this, arguments);
            };
        });
        let intercepted = 0;
        let release;
        const pending = new Promise(resolve => { release = resolve; });
        const action = {create: 'create', edit: 'update', move: 'move', delete: 'delete'}[kind];
        await page.route(`**/Anabelka/admin/categories/${action}`, async route => {
            intercepted += 1;
            await pending;
            await route.fulfill({status: 403, contentType: 'application/json',
                body: JSON.stringify({success: false, message: 'Сеанс завершився. Оновіть сторінку.'})});
        });
        t.after(() => release());
        await submit(page, kind);
        await page.waitForFunction(kind => document.querySelector(`#category-${kind}-form [type="submit"]`).disabled, kind);
        await page.locator(`#category-${kind}-form`).evaluate(form => {
            for (let index = 0; index < 3; index += 1) {
                form.dispatchEvent(new SubmitEvent('submit', {bubbles: true, cancelable: true}));
            }
        });
        try {
            assert.equal(await page.evaluate(() => window.categoryFormPostCalls), 1,
                'Only the original submit may invoke the real fetch implementation.');
        } finally {
            release();
        }
        await page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
        await expectToast(page, 'error', 'Сеанс завершився. Оновіть сторінку.');
        assert.equal(await page.locator(`#category-${kind}-form [type="submit"]`).isEnabled(), true);
        assert.equal(await page.locator(`#category-${kind}-modal`).isVisible(), true);
        await page.clock.runFor(10000);
        assert.equal(intercepted, 1, 'A failed request must not schedule an automatic retry.');
    });
}

for (const [label, status, message] of [['server validation', 422, 'Таку категорію вже створено.'],
    ['CSRF rejection', 403, 'Недійсний CSRF-токен. Оновіть сторінку.']]) {
    test(`${label} retains server text and entered values without adding client errors or retrying`, async t => {
        const page = await harness(t);
        await open(page, 'edit');
        await page.locator('#category-edit-name').fill('Незбережена назва');
        fixture.responses.push({status, body: {success: false, message}});
        const count = fixture.requests.length;
        const visits = fixture.visits();
        await submit(page, 'edit');
        await page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
        await expectToast(page, 'error', message);
        assert.equal(fixture.requests.length, count + 1);
        assert.equal(fixture.visits(), visits);
        assert.equal(await page.locator('#category-edit-name').inputValue(), 'Незбережена назва');
        assert.equal(await page.locator('#category-edit-modal').isVisible(), true);
        assert.equal(await page.locator('#category-edit-modal .category-form-errors .category-field-error').count(), 0);
        assert.equal(await page.locator('#category-edit-form [type="submit"]').isEnabled(), true);
        await page.clock.runFor(10000);
        assert.equal(fixture.requests.length, count + 1);
    });
}

test('novalidate is enabled only by initialized category handlers and remains absent from rendered HTML', async t => {
    assert.doesNotMatch(document, /<form\b[^>]*\bnovalidate\b/i);
    const page = await harness(t);
    assert.deepEqual(await page.locator('.category-modal form').evaluateAll(forms => forms.map(form => form.noValidate)),
        [true, true, true, true]);
    await page.evaluate(() => {
        const form = document.createElement('form');
        form.id = 'unrelated-form';
        form.innerHTML = '<input required name="unrelated">';
        document.body.append(form);
    });
    assert.equal(await page.locator('#unrelated-form').evaluate(node => node.noValidate), false);
});

for (const failure of ['disabled', 'blocked']) {
    test(`JavaScript ${failure} leaves required native form semantics available`, async t => {
        const context = await browser.newContext({javaScriptEnabled: failure !== 'disabled'});
        const page = await context.newPage();
        t.after(() => context.close());
        if (failure === 'blocked') await page.route('**/js/admin-categories.js*', route => route.abort());
        await page.goto(fixture.origin + '/Anabelka/admin/categories');
        assert.deepEqual(await page.locator('.category-modal form').evaluateAll(forms => forms.map(form => form.noValidate)),
            [false, false, false, false]);
        assert.equal(await page.locator('#category-create-name').getAttribute('required'), '');
        assert.equal(await page.locator('#category-edit-name').getAttribute('required'), '');
        const count = fixture.requests.length;
        const state = await page.locator('#category-create-form').evaluate(form => {
            const name = form.querySelector('[name="name"]');
            let invalid = false;
            name.addEventListener('invalid', () => { invalid = true; });
            form.requestSubmit();
            return {missing: name.validity.valueMissing, valid: form.checkValidity(), invalid};
        });
        assert.equal(state.missing, true);
        assert.equal(state.valid, false);
        if (failure === 'blocked') assert.equal(state.invalid, true);
        assert.equal(fixture.requests.length, count);
    });
}

for (const width of [320, 360, 375, 390, 412, 430]) {
    test(`inline category errors fit ${width}px and leave the submit action usable`, async t => {
        const page = await harness(t, {width});
        await open(page, 'create');
        const message = 'ДовгаПомилкаБезПробілів'.repeat(35);
        await page.locator('#category-create-name').fill('Перевірка довгої помилки');
        await page.locator('#category-create-name').evaluate((node, text) => node.setCustomValidity(text), message);
        const count = fixture.requests.length;
        await submit(page, 'create');
        assert.equal(fixture.requests.length, count);
        assert.equal(await page.locator('#category-create-modal .category-form-errors .category-field-error').count(), 1);
        assert.equal((await errorFor(page, '#category-create-name')).errors[0]?.text, message);
        const metrics = await page.locator('#category-create-modal .category-form-errors .category-field-error').evaluate(node => {
            const rect = node.getBoundingClientRect();
            const modal = node.closest('.category-modal-window');
            return {left: rect.left, right: rect.right, scroll: node.scrollWidth, width: node.clientWidth,
                pageScroll: document.documentElement.scrollWidth, modalScroll: modal.scrollWidth, modalWidth: modal.clientWidth};
        });
        assert.ok(metrics.left >= 0 && metrics.right <= width, JSON.stringify(metrics));
        assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
        assert.ok(metrics.modalScroll <= metrics.modalWidth + 1, JSON.stringify(metrics));
        assert.ok(metrics.pageScroll <= width, JSON.stringify(metrics));
        const button = page.locator('#category-create-form [type="submit"]');
        await button.scrollIntoViewIfNeeded();
        assert.equal(await button.isVisible(), true);
        assert.equal(await button.isEnabled(), true);
    });
}

for (const theme of ['light', 'dark']) {
    test(`${theme} inline validation text has at least WCAG AA contrast`, async t => {
        const page = await harness(t);
        await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
        await open(page, 'create');
        await submit(page, 'create');
        assert.equal(await page.locator('#category-create-modal .category-form-errors .category-field-error').count(), 1);
        const colors = await page.locator('#category-create-modal .category-form-errors .category-field-error').evaluate(node => {
            const style = getComputedStyle(node);
            let background = node;
            while (background.parentElement && ['rgba(0, 0, 0, 0)', 'transparent'].includes(getComputedStyle(background).backgroundColor)) {
                background = background.parentElement;
            }
            return {foreground: style.color, background: getComputedStyle(background).backgroundColor,
                stripe: style.borderLeftColor,
                size: style.fontSize, display: style.display, visibility: style.visibility};
        });
        assert.equal(colors.background, 'rgb(244, 234, 255)', 'Guidance keeps the approved lavender background in both themes.');
        assert.equal(colors.foreground, 'rgb(101, 25, 185)', 'Guidance uses the approved dark purple text.');
        assert.equal(colors.stripe, 'rgb(138, 43, 226)', 'The accent stripe uses the approved brand purple.');
        assert.ok(contrast(colors.foreground, colors.background) >= 4.5, `${theme}: ${JSON.stringify(colors)}`);
        assert.notEqual(colors.display, 'none');
        assert.equal(colors.visibility, 'visible');
        assert.ok(parseFloat(colors.size) >= 12);
    });
}
