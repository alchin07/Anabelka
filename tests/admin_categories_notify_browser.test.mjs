import assert from 'node:assert/strict';
import test, {before, after} from 'node:test';
import {phpJson} from './helpers/php-probe.mjs';
import {launchBrowser, fixtureServer, browserPage, expectToast, toast, storageKey, waitForDocumentLoad} from './helpers/notify-browser.mjs';

let browser;
let fixture;
const documentCache = new Map();
function categoryDocument(uri) {
    if (!documentCache.has(uri)) {
        const encoded = Buffer.from(uri).toString('base64');
        documentCache.set(uri, phpJson(`$fixtureUri=base64_decode('${encoded}'); require 'tests/fixtures/admin_categories_notify.php';`));
    }
    return documentCache.get(uri);
}
before(async () => {
    browser = await launchBrowser();
    fixture = await fixtureServer(url => url.pathname === '/sentinel'
        ? '<!doctype html><title>Попередня сторінка</title><button id="sentinel">Назад</button>'
        : categoryDocument(url.pathname + url.search));
});
after(async () => { if (browser) await browser.close(); if (fixture) await fixture.close(); });

async function harness(t, {pathname = '/Anabelka/admin/categories', width = 390} = {}) {
    fixture.responses.length = 0;
    const page = await browserPage(t, browser, fixture.origin, {pathname, width});
    await page.locator('[data-category-ai-translate]').waitFor({state: 'attached'});
    assert.equal(await page.locator('#site-message').count(), 1);
    assert.equal(await page.locator('script[src*="/js/anabelka-notify.js"]').count(), 1);
    const submit = async (selector, response = {body: {success: true, message: 'Операцію виконано.'}}) => {
        fixture.responses.push(response);
        const count = fixture.requests.length;
        await waitForDocumentLoad(page, () => page.locator(selector).click());
        assert.equal(fixture.requests.length, count + 1);
        await expectToast(page, 'success', response.body.message || 'Збережено.');
        assert.equal(await page.evaluate(key => sessionStorage.getItem(key), storageKey), null);
        assert.equal(await page.locator('.category-modal:not([hidden])').count(), 0);
        const request = fixture.requests.at(-1);
        assert.equal(request.headers['x-requested-with'], 'XMLHttpRequest');
        assert.ok(request.body.includes('name="_csrf"\r\n\r\ncategory-fixture-csrf'));
        return request;
    };
    return {page, submit};
}

function field(request, name, value) {
    assert.ok(request.body.includes(`name="${name}"\r\n\r\n${value}`), `${name}=${value} must reach the HTTP API`);
}

test('creating a root category reloads immediately and delivers success once', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-create].is-root').first().click();
    await h.page.locator('#category-create-name').fill('Нова коренева');
    await h.page.locator('#category-create-description').fill('Опис створення');
    const request = await h.submit('#category-create-form [type="submit"]');
    assert.equal(request.url, '/Anabelka/admin/categories/create');
    field(request, 'department_id', '1'); field(request, 'name', 'Нова коренева');
    field(request, 'description', 'Опис створення'); field(request, 'parent_id', '');
    await h.page.reload();
    assert.equal((await toast(h.page)).shown, false, 'Successful reload flash is one-shot.');
});

test('creating a child retains parent id and inherited department form behavior', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-create][data-parent-id="1"]').click();
    assert.equal(await h.page.locator('#category-create-department').isDisabled(), true);
    await h.page.locator('#category-create-name').fill('Нова дочірня');
    const request = await h.submit('#category-create-form [type="submit"]');
    field(request, 'parent_id', '1'); field(request, 'name', 'Нова дочірня');
    assert.equal(request.body.includes('name="department_id"'), false,
        'Native disabled select behavior stays intact; server derives the department.');
});

test('editing category and manual translations submits existing fields then reloads', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.locator('#category-edit-name').fill('Нова назва');
    await h.page.locator('#category-edit-description').fill('Новий опис');
    await h.page.locator('.category-translation-name').fill('New English');
    await h.page.locator('.category-translation-description').fill('New description');
    const request = await h.submit('#category-edit-form [type="submit"]');
    assert.equal(request.url, '/Anabelka/admin/categories/update');
    field(request, 'category_id', '3'); field(request, 'name', 'Нова назва');
    field(request, 'translation_name[en]', 'New English');
    field(request, 'translation_description[en]', 'New description');
    field(request, 'translation_source[en]', 'manual');
    field(request, 'translation_status[en]', 'approved');
});

test('deleting an empty category uses confirmation form and reload notification', async t => {
    const h = await harness(t);
    assert.equal(await h.page.locator('[data-category-delete="1"]').isDisabled(), true);
    await h.page.locator('[data-category-delete="3"]').click();
    assert.ok((await h.page.locator('#category-delete-context').innerText()).includes('Порожня'));
    const request = await h.submit('#category-delete-form [type="submit"]');
    assert.equal(request.url, '/Anabelka/admin/categories/delete'); field(request, 'category_id', '3');
});

for (const [name, toggle] of [['visibility', 'is_active'], ['18+', 'is_adult']]) {
    test(`${name} toggle uses the unchanged API payload and displays reload success`, async t => {
        const h = await harness(t);
        const request = await h.submit(`[data-category-toggle="${toggle}"][data-category-id="3"]`);
        assert.equal(request.url, '/Anabelka/admin/categories/toggle');
        field(request, 'category_id', '3'); field(request, 'field', toggle);
        field(request, 'value', toggle === 'is_active' ? '0' : '1');
    });
}

test('moving a branch keeps its descendants excluded and sends the selected parent', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-move="1"]').click();
    const options = await h.page.locator('#category-move-parent option').evaluateAll(nodes => nodes.map(node => node.value));
    assert.deepEqual(options, ['', '3', '4']);
    await h.page.locator('#category-move-parent').selectOption('4', {force: true});
    assert.equal(await h.page.locator('#category-move-department').isDisabled(), true);
    const request = await h.submit('#category-move-form [type="submit"]');
    assert.equal(request.url, '/Anabelka/admin/categories/move');
    field(request, 'category_id', '1'); field(request, 'parent_id', '4');
});

for (const direction of ['up', 'down']) {
    test(`sorting ${direction} sends direction and immediately reloads with success`, async t => {
        const h = await harness(t);
        const request = await h.submit(`[data-category-reorder="${direction}"][data-category-id="3"]`);
        assert.equal(request.url, '/Anabelka/admin/categories/move');
        field(request, 'category_id', '3'); field(request, 'direction', direction);
    });
}

test('native required-field validation keeps the modal open and prevents HTTP requests', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-create].is-root').first().click();
    await h.page.clock.runFor(30);
    await h.page.evaluate(() => {
        document.querySelector('#category-create-name').addEventListener('invalid', () => { window.nativeInvalidSeen = true; });
    });
    const count = fixture.requests.length;
    await h.page.locator('#category-create-form [type="submit"]').click();
    assert.equal(fixture.requests.length, count);
    assert.equal(await h.page.locator('#category-create-name').evaluate(node => node.validity.valueMissing), true);
    assert.equal(await h.page.evaluate(() => window.nativeInvalidSeen), true, 'Browser native constraint validation remains active.');
    assert.equal(await h.page.locator('#category-create-modal').isVisible(), true);
    assert.equal(await h.page.locator('#category-create-form [type="submit"]').isEnabled(), true);
});

test('a successful retry carries its unread error through reload before success', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.locator('#category-edit-name').fill('Повторне збереження');
    fixture.responses.push({status: 422, body: {success: false, message: 'Попередня помилка збереження.'}});
    await h.page.locator('#category-edit-form [type="submit"]').click();
    await h.page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
    await expectToast(h.page, 'error', 'Попередня помилка збереження.');
    fixture.responses.push({body: {success: true, message: 'Повторне збереження успішне.'}});
    await waitForDocumentLoad(h.page, () => h.page.locator('#category-edit-form [type="submit"]').click());
    await expectToast(h.page, 'error', 'Попередня помилка збереження.');
    await h.page.clock.runFor(4800);
    await expectToast(h.page, 'success', 'Повторне збереження успішне.');
    await h.page.clock.runFor(2800);
    assert.equal((await toast(h.page)).shown, false);
    await h.page.reload();
    assert.equal((await toast(h.page)).shown, false);
});

for (const [name, response, message] of [
    ['JSON validation', {status: 422, body: {success: false, message: 'Таку категорію вже створено.'}}, 'Таку категорію вже створено.'],
    ['malformed JSON', {status: 200, body: '{broken'}, 'Сервер повернув некоректну відповідь.'],
    ['HTML 500', {status: 500, body: '<h1>Помилка PHP</h1>'}, 'Помилка сервера. Перевірте журнал KSWEB.'],
    ['network failure', {network: true}, 'Failed to fetch'],
]) {
    test(`${name} shows shared error, preserves entered form data and restores submit`, async t => {
        const h = await harness(t);
        await h.page.locator('[data-category-edit="3"]').click();
        await h.page.locator('#category-edit-name').fill('Незбережений текст');
        const visits = fixture.visits();
        if (response.network) {
            await h.page.route('**/Anabelka/admin/categories/update', route => route.abort('failed'));
        } else {
            fixture.responses.push(response);
        }
        await h.page.locator('#category-edit-form [type="submit"]').click();
        await h.page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
        await expectToast(h.page, 'error', message);
        assert.equal(fixture.visits(), visits);
        assert.equal(await h.page.locator('#category-edit-name').inputValue(), 'Незбережений текст');
        assert.equal(await h.page.locator('#category-edit-modal').isVisible(), true);
        assert.equal(await h.page.locator('#category-edit-form [type="submit"]').isEnabled(), true);
        assert.equal((await toast(h.page)).role, 'alert');
    });
}

test('a rejected toggle reenables its trigger, keeps the page and renders server text literally', async t => {
    const h = await harness(t);
    const text = '<img src=x onerror="window.categoryXss=1">Відхилено.';
    fixture.responses.push({status: 403, body: {success: false, message: text}});
    const button = h.page.locator('[data-category-toggle="is_adult"][data-category-id="3"]');
    const visits = fixture.visits();
    await button.click();
    await h.page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
    await expectToast(h.page, 'error', text);
    assert.equal(await button.isEnabled(), true);
    assert.equal(fixture.visits(), visits);
    assert.equal(await h.page.locator('#site-message img').count(), 0);
    assert.equal(await h.page.evaluate(() => window.categoryXss), undefined);
});

test('translation target save replaces editor history and consumes success on real missing-translations view', async t => {
    const h = await harness(t);
    await h.page.goto(fixture.origin + '/sentinel');
    await h.page.goto(fixture.origin + '/Anabelka/admin/categories?highlight=3&focus_language=en');
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.locator('#category-edit-modal').waitFor({state: 'visible'});
    await h.page.clock.runFor(30);
    assert.equal(await h.page.locator('[data-category-language="en"].is-translation-focus').count(), 1);
    await h.page.locator('.category-translation-name').fill('Approved English');
    const request = await h.submit('#category-edit-form [type="submit"]', {body: {success: true, message: 'Переклад збережено.'}});
    field(request, 'translation_name[en]', 'Approved English');
    assert.equal(new URL(h.page.url()).pathname, '/Anabelka/admin/translations/missing');
    assert.equal(new URL(h.page.url()).search, '?section=categories');
    assert.equal(await h.page.locator('.translations-admin').count(), 1);
    await h.page.goBack();
    assert.equal(new URL(h.page.url()).pathname, '/sentinel', 'location.replace must remove the translation editor history entry.');
});

test('category AI success uses shared success queue and keeps translation source/status semantics', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.evaluate(() => {
        AnabelkaNotify.error('Попередня помилка.');
        window.AnabelkaAITranslation = {suggest: async () => ({name: 'AI English', description: 'AI description'})};
    });
    await h.page.locator('[data-category-ai-translate]').click();
    await h.page.waitForFunction(() => document.querySelector('.category-translation-name').value === 'AI English');
    await expectToast(h.page, 'error', 'Попередня помилка.');
    await h.page.clock.runFor(4800);
    await expectToast(h.page, 'success', 'ШІ-переклад отримано. «Зберегти» підтвердить переклад.');
    assert.equal(await h.page.locator('.category-translation-source').inputValue(), 'ai');
    assert.equal(await h.page.locator('[data-category-translation-status]').inputValue(), 'approved');
    assert.equal(await h.page.locator('[data-category-ai-translate]').isEnabled(), true);
});

test('category AI rejection uses shared error and restores its button without alert', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.evaluate(() => {
        AnabelkaNotify.info('Попереднє повідомлення.');
        window.AnabelkaAITranslation = {suggest: async () => {
            window.aiRejectionAttempted = true;
            throw new Error('ШІ недоступний.');
        }};
        window.alert = () => { window.unexpectedAlert = true; };
    });
    await h.page.locator('[data-category-ai-translate]').click();
    await h.page.waitForFunction(() => window.aiRejectionAttempted === true);
    await expectToast(h.page, 'info', 'Попереднє повідомлення.');
    await h.page.clock.runFor(3500);
    await expectToast(h.page, 'error', 'ШІ недоступний.');
    assert.equal(await h.page.locator('[data-category-ai-translate]').isEnabled(), true);
    assert.equal(await h.page.evaluate(() => window.unexpectedAlert), undefined);
});

test('category AI unavailable warning uses shared queue and leaves field values intact', async t => {
    const h = await harness(t);
    await h.page.locator('[data-category-edit="3"]').click();
    await h.page.evaluate(() => {
        AnabelkaNotify.error('Попередня помилка.');
        window.AnabelkaAITranslation = null;
    });
    const original = await h.page.locator('.category-translation-name').inputValue();
    await h.page.locator('[data-category-ai-translate]').click();
    await expectToast(h.page, 'error', 'Попередня помилка.');
    await h.page.clock.runFor(4800);
    await expectToast(h.page, 'warning', 'Система ШІ-перекладу ще завантажується. Спробуйте ще раз.');
    assert.equal(await h.page.locator('.category-translation-name').inputValue(), original);
});

for (const width of [320, 360, 375, 390, 412, 430]) {
    test(`real category page error toast fits ${width}px and keeps controls usable`, async t => {
        const h = await harness(t, {width});
        const text = 'ДовгаПомилкаБезПробілів'.repeat(80);
        fixture.responses.push({status: 422, body: {success: false, message: text}});
        const button = h.page.locator('[data-category-toggle="is_active"][data-category-id="3"]');
        await button.click();
        await h.page.waitForFunction(() => document.querySelector('#site-message').classList.contains('show'));
        await h.page.clock.runFor(200);
        await expectToast(h.page, 'error', text);
        const metrics = await h.page.locator('#site-message').evaluate(node => {
            const rect = node.getBoundingClientRect();
            return {left: rect.left, right: rect.right, scroll: node.scrollWidth, width: node.clientWidth,
                pageScroll: document.documentElement.scrollWidth};
        });
        assert.ok(metrics.left >= 11 && metrics.right <= width - 11, JSON.stringify(metrics));
        assert.ok(metrics.scroll <= metrics.width + 1, JSON.stringify(metrics));
        assert.ok(metrics.pageScroll <= width, JSON.stringify(metrics));
        assert.equal(await button.isEnabled(), true);
    });
}
