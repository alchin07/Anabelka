import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('js/admin-ai-translation.js', 'utf8');

function requestWithFetch(fakeFetch) {
    const start = source.indexOf('    async function request(url, options)');
    const end = source.indexOf('    function updateStatus()', start);
    assert.ok(start >= 0 && end > start, 'AI request function must exist');

    return vm.runInNewContext(
        '(function () {\n' + source.slice(start, end) +
            '\nreturn request;\n})()',
        {fetch: fakeFetch}
    );
}

test('successful translation requests keep original endpoint, method and payload', async () => {
    let captured;
    const request = requestWithFetch(async (url, options) => {
        captured = {url, options};
        return {ok: true, json: async () => ({success: true, translation: {name: 'Self-pickup'}})};
    });
    const settings = {method: 'POST', body: 'test-data'};
    const response = await request('/Anabelka/admin/ai-translation/suggest', settings);
    assert.deepEqual(captured, {
        url: '/Anabelka/admin/ai-translation/suggest', options: settings
    });
    assert.equal(response.translation.name, 'Self-pickup');
});

test('browser Failed to fetch is localized, not exposed as an English error', async () => {
    const request = requestWithFetch(async () => {
        throw new TypeError('Failed to fetch');
    });
    await assert.rejects(
        request('/Anabelka/admin/ai-translation/suggest', {method: 'POST'}),
        error => {
            assert.equal(error.name, 'Error');
            assert.equal(error.message,
                'Не вдалося зв’язатися із сервером. Перевірте з’єднання та повторіть спробу.');
            return true;
        }
    );
});

test('server 403, API and provider errors remain unchanged', async () => {
    const request = requestWithFetch(async () => ({
        ok: false,
        json: async () => ({success: false, message: 'Недостатньо прав.'})
    }));
    await assert.rejects(request('/Anabelka/admin/ai-translation/suggest'),
        /Недостатньо прав/);
});

test('abort and other non-network exceptions are preserved', async () => {
    const canceled = new Error('Дію скасовано');
    canceled.name = 'AbortError';
    const request = requestWithFetch(async () => {throw canceled;});
    await assert.rejects(request('/Anabelka/admin/ai-translation/suggest'),
        error => error === canceled);
});

test('network feedback does not retry translation or rewrite draft workflow', () => {
    assert.doesNotMatch(source, /fetch\([\s\S]*?fetch\(/);
    assert.match(source, /return translation/);
    const delivery = fs.readFileSync('js/admin-delivery-ai-translation.js', 'utf8');
    assert.match(delivery, /setDeliveryTranslationWorkflow\([\s\S]*?'ai',\s*'draft'/);
});
