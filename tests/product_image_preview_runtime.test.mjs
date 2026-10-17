import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
const result = spawnSync(process.env.PHP_BIN || 'php', ['tests/product_image_preview_runtime.php'], {
  encoding: 'utf8', timeout: 30000
});
assert.equal(result.error, undefined, String(result.error));
assert.equal(result.status, 0, result.stderr || result.stdout);
assert.match(result.stdout, /^product image preview runtime passed\n$/);
assert.equal(result.stderr, '');
