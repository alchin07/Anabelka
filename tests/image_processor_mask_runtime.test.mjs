import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const result = spawnSync(
    process.env.PYTHON_BIN || 'python3',
    ['tests/test_image_processor_mask.py', '-v'],
    {cwd: root, encoding: 'utf8', timeout: 30000}
);

assert.ifError(result.error);
assert.equal(result.status, 0, result.stderr || result.stdout);
process.stdout.write(result.stderr || result.stdout);
