import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';
import {fileURLToPath} from 'node:url';

test('PHP flash, XSS encoding, asset detection and session lifecycle runtime regressions', () => {
    const result = spawnSync(process.env.PHP_BIN || 'php', ['-n', 'tests/anabelka_flash_runtime.php'], {
        cwd: fileURLToPath(new URL('../', import.meta.url)), encoding: 'utf8', timeout: 30000
    });
    assert.ifError(result.error);
    assert.equal(result.status, 0, result.stderr + result.stdout);
    assert.match(result.stdout, /passed \(18 checks\)/);
});
