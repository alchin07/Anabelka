import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import test from 'node:test';

function run(args = []) {
    const result = spawnSync(process.env.PHP_BIN || 'php',
        ['tests/product_image_preview_security_runtime.php', ...args],
        {encoding: 'utf8', timeout: 30000});
    assert.equal(result.error, undefined, String(result.error));
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(result.stderr, '');
    return result.stdout;
}

test('real processing routes require admin CSRF and products.manage', () => {
    assert.equal(run(), 'product image preview security runtime passed\n');
});

for (const action of ['preview', 'confirm', 'cancel', 'legacy']) {
    test(action + ' rejects invalid CSRF before DB access or controller action', () => {
        const output = run([action]);
        assert.match(output, /\nHTTP_STATUS=403\n$/);
        const result = JSON.parse(output.slice(0, output.indexOf('\nHTTP_STATUS=')));
        assert.equal(result.success, false);
        assert.ok(result.message);
    });
}
