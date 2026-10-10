import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

const reasons = ['subject-not-detected', 'grabcut_mask_unavailable', 'modnet_worker_failed',
    'mask_quality_rejected', 'background_fallback'];
const workerError = 'worker crashed at /srv/private/weights/model.onnx';

function run(action, reason, worker = workerError) {
    const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'anabelka-public-diagnostics-'));
    const log = path.join(directory, 'error.log');
    const metadata = path.join(directory, 'metadata.json');
    try {
        const result = spawnSync(process.env.PHP_BIN || 'php', ['tests/product_image_processor_diagnostics_runtime.php'], {
            encoding: 'utf8', timeout: 10000,
            env: {...process.env, IMAGE_PROCESSOR_TEST_ACTION: action, IMAGE_PROCESSOR_TEST_REASON: reason,
                IMAGE_PROCESSOR_TEST_WORKER_ERROR: worker, IMAGE_PROCESSOR_TEST_LOG: log,
                IMAGE_PROCESSOR_TEST_METADATA: metadata}
        });
        assert.equal(result.error, undefined, String(result.error));
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.equal(result.stderr, '');
        return {payload: JSON.parse(result.stdout), metadata: JSON.parse(fs.readFileSync(metadata, 'utf8')),
            log: fs.existsSync(log) ? fs.readFileSync(log, 'utf8') : ''};
    } finally {
        fs.rmSync(directory, {recursive: true, force: true});
    }
}

for (const reason of reasons) {
    test(`${reason} retains the exact private reason and keeps paths out of failure JSON`, () => {
        const {payload, metadata, log} = run('failure', reason);
        assert.equal(metadata.status, 500);
        assert.equal(payload.success, false);
        assert.equal(payload.message, 'Не вдалося обробити фотографію товару.');
        assert.equal(payload.diagnostics.reason_code, reason);
        assert.equal(payload.diagnostics.detection_reason, 'subject-not-detected');
        assert.equal(payload.diagnostics.fallback_reason, 'background_fallback');
        assert.equal(payload.diagnostics.background_fallback, true);
        assert.equal(payload.diagnostics.processing_time_ms, 153.25);
        assert.equal(payload.diagnostics.mask_failure_reason, reason);
        assert.equal(payload.diagnostics.worker_error, undefined);
        assert.doesNotMatch(JSON.stringify(payload), /\/srv\/private|model\.onnx|mask_selection/);
        assert.match(log, new RegExp(reason));
        assert.ok(log.includes(workerError), 'Private PHP error log retains the worker detail');
        const audit = metadata.events.find(event => event.event === 'product.image_processing_failed');
        assert.equal(audit.details.processing_reason, 'Mask processing failed: ' + reason);
        assert.equal(audit.details.reason_code, reason);
        assert.equal(audit.details.worker_error, workerError);
        assert.equal(audit.details.processing_time_ms, 153.25);
        assert.equal(audit.details.mask_failure_reason, reason);
    });
}

test('successful processing filters public diagnostics after recording the private audit', () => {
    const {payload, metadata} = run('success', 'background_fallback');
    assert.equal(payload.success, true);
    assert.equal(payload.processing.normalization.reason_code, 'background_fallback');
    assert.equal(payload.processing.normalization.processing_time_ms, 153.25);
    assert.equal(payload.processing.normalization.mask_failure_reason, 'background_fallback');
    assert.equal(payload.processing.normalization.worker_error, undefined);
    assert.equal(payload.processing.processor_version, '0.11:model-normalize-v8');
    assert.equal(payload.processing.normalization.method, 'person-detected-no-crop');
    assert.deepEqual(payload.processing.normalization.person_bbox, [2, 3, 100, 200]);
    assert.deepEqual(payload.processing.normalization.crop_box, [0, 0, 150, 300]);
    assert.equal(payload.processing.normalization.mask_foreground_ratio, 0.45);
    assert.equal(payload.processing.normalization.subject_mask_applied, true);
    assert.equal(payload.processing.original_path, 'storage/image-processor/originals/' + 'a'.repeat(32) + '/source.jpg');
    assert.equal(payload.processing.master_path, 'uploads/products/processed/' + 'a'.repeat(32) + '/master.webp');
    assert.doesNotMatch(JSON.stringify(payload), /\/srv\/private|model\.onnx|mask_selection/);
    assert.equal(metadata.events[0].details.worker_error, workerError);
    assert.equal(metadata.events[0].details.processing_time_ms, 153.25);
});

test('stable worker error codes remain available to the user', () => {
    assert.equal(run('failure', 'modnet_worker_failed', 'worker-timeout').payload.diagnostics.worker_error,
        'worker-timeout');
});

for (const detail of ['C:\\private\\weights\\model.onnx', 'storage/image-processor/private/model.onnx',
    'Traceback: worker failed while reading model.onnx']) {
    test(`worker detail ${JSON.stringify(detail)} stays private`, () => {
        const {payload, metadata, log} = run('failure', 'modnet_worker_failed', detail);
        assert.equal(payload.diagnostics.worker_error, undefined);
        assert.ok(!JSON.stringify(payload).includes('model.onnx'));
        assert.equal(metadata.events[0].details.worker_error, detail);
        // JSON logging may escape a Windows path, while the audit retains exact text.
        assert.ok(log.includes(detail) || log.includes(JSON.stringify(detail).slice(1, -1)));
    });
}

test('invalid diagnostic enum/version/reason values never become public paths', () => {
    const {payload} = run('unsafe', 'mask_quality_rejected');
    assert.deepEqual(payload.diagnostics, {background_fallback: true});
});

for (const timing of ['negative', 'nan', 'infinity', 'string']) {
    test(`${timing} total processing time is omitted from public diagnostics and audit`, () => {
        const {payload, metadata, log} = run('invalid-timing', timing);
        assert.equal(payload.diagnostics.processing_time_ms, undefined);
        assert.equal(metadata.events[0].details.processing_time_ms, undefined);
        assert.ok(log.includes(workerError), 'Invalid timing does not erase the private worker failure');
    });
}
