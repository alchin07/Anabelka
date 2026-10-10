import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {createServer} from 'node:http';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const php = String.raw`
require getcwd() . '/app/Services/ImageProcessorClient.php';
if (getenv('IMAGE_PROCESSOR_TEST_ACTION') === 'status') {
    $method = new ReflectionMethod(ImageProcessorClient::class, 'statusCode');
    $statuses = [];
    foreach ([200, 400, 500] as $status) {
        $statuses[] = $method->invoke(null, [
            'HTTP/1.1 ' . $status . ' Test response',
            'Content-Type: application/json'
        ]);
    }
    $statuses[] = $method->invoke(null, []);
    $statuses[] = $method->invoke(null, ['Content-Type: application/json']);
    echo json_encode([
        'statuses' => $statuses,
        'version_id' => PHP_VERSION_ID,
        'modern_headers' => function_exists('http_get_last_response_headers')
    ]);
} else {
    $method = new ReflectionMethod(ImageProcessorClient::class, 'request');
    try {
        echo json_encode(['data' => $method->invoke(
            null, 'GET', getenv('IMAGE_PROCESSOR_TEST_PATH'), null, 3
        )]);
    } catch (Throwable $error) {
        echo json_encode([
            'type' => get_class($error),
            'message' => $error->getMessage(),
            'diagnostics' => $error instanceof ImageProcessorException
                ? $error->diagnostics : null
        ]);
    }
}
`;

// The PHP request must run asynchronously so this process can serve its HTTP response.
function runPhp(endpoint, path, args = [], action = 'request') {
    return new Promise((resolve, reject) => {
        const child = spawn(process.env.PHP_BIN || 'php', [...args, '-r', php], {
            cwd: root,
            env: {
                ...process.env,
                ANABELKA_IMAGE_PROCESSOR_URL: endpoint,
                IMAGE_PROCESSOR_TEST_PATH: path,
                IMAGE_PROCESSOR_TEST_ACTION: action
            },
            stdio: ['ignore', 'pipe', 'pipe']
        });
        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => child.kill('SIGKILL'), 10000);
        child.stdout.setEncoding('utf8').on('data', chunk => { stdout += chunk; });
        child.stderr.setEncoding('utf8').on('data', chunk => { stderr += chunk; });
        child.on('error', error => {
            clearTimeout(timer);
            reject(error);
        });
        child.on('close', (code, signal) => {
            clearTimeout(timer);
            try {
                assert.equal(code, 0, stderr || `PHP exited with ${signal || code}`);
                assert.equal(stderr, '');
                resolve(JSON.parse(stdout));
            } catch (error) {
                reject(error);
            }
        });
    });
}

test('ImageProcessorClient reads real HTTP status and response JSON', async t => {
    const success = {
        ok: true,
        job_id: 'http-runtime-job',
        normalization: {mask_method: 'grabcut', note: 'готово'},
        outputs: ['master.webp', 'thumb.webp']
    };
    const diagnostics = {
        attempt: 2,
        mask_mode_requested: 'auto',
        mask_method: 'grabcut',
        processor_version: '0.11',
        worker_error: null
    };
    const errorBody = ok => ({
        ok,
        error: '  Worker rejected image  ',
        normalization: {attempt: 2, mask_method: 'old-method', worker_error: 'old-error'},
        mask_mode_requested: diagnostics.mask_mode_requested,
        mask_method: diagnostics.mask_method,
        processor_version: diagnostics.processor_version,
        worker_error: diagnostics.worker_error
    });
    const fixtures = new Map([
        ['/success', {status: 200, body: success}],
        ['/application-error', {status: 200, body: errorBody(false)}],
        ['/malformed', {status: 200, body: '{invalid json'}],
        ...[400, 500].flatMap(status => [false, true].map(ok => [
            `/error-${status}-${ok}`, {status, body: errorBody(ok)}
        ]))
    ]);
    const processingReasons = ['subject-not-detected', 'grabcut_mask_unavailable',
        'modnet_worker_failed', 'mask_quality_rejected'];
    for (const reason of processingReasons) {
        fixtures.set('/reason-' + reason, {status: 500, body: {
            ok: false, error: 'Не вдалося застосувати маску: ' + reason,
            normalization: {mask_mode_requested: 'modnet', mask_method: 'none', processor_version: '0.11',
                reason_code: reason, detection_reason: 'subject-not-detected',
                worker_error: 'worker crashed at /srv/private/weights/model.onnx',
                mask_selection: {failure_reason: reason}},
        }});
    }
    const backgroundFallback = {ok: true, normalization: {
        reason_code: 'background_fallback', detection_reason: 'subject-not-detected',
        fallback_reason: 'background_fallback', background_fallback: true,
        background_profile_requested: 'studio-light', background_profile: 'original-canvas',
        mask_mode_requested: 'auto', mask_method: 'none', processor_version: '0.11'
    }};
    fixtures.set('/background-fallback', {status: 200, body: backgroundFallback});
    const requests = [];
    const server = createServer((request, response) => {
        requests.push(request.url);
        const fixture = fixtures.get(request.url);
        response.writeHead(fixture?.status || 404, {'Content-Type': 'application/json'});
        response.end(fixture
            ? typeof fixture.body === 'string' ? fixture.body : JSON.stringify(fixture.body)
            : '{}');
    });
    await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
    });
    t.after(() => new Promise((resolve, reject) => {
        server.close(error => error ? reject(error) : resolve());
        server.closeAllConnections();
    }));
    const endpoint = `http://127.0.0.1:${server.address().port}`;

    const runtime = await runPhp(endpoint, '', [], 'status');
    await t.test('status parser recognizes 200, 400 and 500', () => {
        assert.deepEqual(runtime.statuses, [200, 400, 500, 0, 0]);
    });
    const modes = [['native response headers', []]];
    // PHP 8.4 can exercise both APIs; the legacy header variable is deprecated in 8.5.
    if (runtime.modern_headers && runtime.version_id < 80500) {
        modes.push(['legacy response headers', ['-d', 'disable_functions=http_get_last_response_headers']]);
    }
    for (const [mode, args] of modes) {
        await t.test(`HTTP 200 returns the exact decoded JSON using ${mode}`, async () => {
            assert.deepEqual(await runPhp(endpoint, '/success', args), {data: success});
        });
    }
    for (const status of [400, 500]) {
        for (const ok of [false, true]) {
            const path = `/error-${status}-${ok}`;
            await t.test(`HTTP ${status} rejects ok=${ok} and preserves diagnostics`, async () => {
                assert.deepEqual(await runPhp(endpoint, path), {
                    type: 'ImageProcessorException',
                    message: 'Worker rejected image',
                    diagnostics
                });
            });
        }
    }
    await t.test('HTTP 200 rejects an application error', async () => {
        assert.deepEqual(await runPhp(endpoint, '/application-error'), {
            type: 'ImageProcessorException',
            message: 'Worker rejected image',
            diagnostics
        });
    });
    await t.test('malformed JSON is rejected', async () => {
        assert.deepEqual(await runPhp(endpoint, '/malformed'), {
            type: 'RuntimeException',
            message: 'Обробник зображень повернув некоректну відповідь.',
            diagnostics: null
        });
    });
    for (const reason of processingReasons) {
        await t.test(`${reason} crosses the real HTTP boundary with the exact private diagnostics`, async () => {
            const fixture = fixtures.get('/reason-' + reason).body;
            assert.deepEqual(await runPhp(endpoint, '/reason-' + reason), {
                type: 'ImageProcessorException', message: fixture.error,
                diagnostics: fixture.normalization
            });
        });
    }
    await t.test('AUTO background_fallback success preserves its explicit diagnostics', async () => {
        assert.deepEqual(await runPhp(endpoint, '/background-fallback'), {data: backgroundFallback});
    });
    assert.deepEqual(requests.sort(), [
        ...fixtures.keys(), ...modes.slice(1).map(() => '/success')
    ].sort());
});
