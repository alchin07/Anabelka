import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';

const cases = {
    create: 'preview creation succeeds when manifest streams do not support locks',
    partial: 'short manifest writes finish before atomic replacement',
    zero: 'zero-byte writes preserve the previous manifest and remove the temporary file',
    false: 'failed writes preserve the previous manifest and remove the temporary file',
    'partial-zero': 'a stalled partial write never replaces the previous manifest',
    flush: 'flush failure preserves the previous manifest and removes the temporary file',
    close: 'close failure preserves the previous manifest and removes the temporary file',
    rename: 'rename failure preserves the previous manifest and removes the temporary file',
    chmod: 'permission failure preserves the previous manifest and removes the temporary file',
    collision: 'exclusive creation never overwrites or deletes an existing temporary file',
    json: 'JSON encoding failure leaves the previous manifest untouched',
    'write-exception': 'write exceptions close and remove the temporary file',
    'flush-exception': 'flush exceptions close and remove the temporary file',
    'close-exception': 'close exceptions remove the temporary file',
    'cleanup-close-exception': 'a secondary close exception still removes a failed temporary write',
    'rename-exception': 'rename exceptions remove the temporary file',
    'create-failure': 'failed manifest creation cleans staging and workers while preserving the ready result'
};

for (const [scenario, name] of Object.entries(cases)) {
    test(name, () => {
        const result = spawnSync(process.env.PHP_BIN || 'php',
            ['tests/product_image_preview_manifest_runtime.php', scenario],
            {encoding: 'utf8', timeout: 30000});
        assert.equal(result.error, undefined, String(result.error));
        assert.equal(result.status, 0, result.stderr || result.stdout);
        assert.equal(result.stderr, '');
        assert.equal(result.stdout, scenario + ' passed\n');
    });
}
