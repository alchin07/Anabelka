import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
import {access, mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {setTimeout as delay} from 'node:timers/promises';
import test from 'node:test';

const php = process.env.PHP_BIN || 'php';
const fixture = 'tests/product_image_preview_concurrency_runtime.php';
function run(args) {
    const result = spawnSync(php, [fixture, ...args], {encoding: 'utf8', timeout: 15000});
    assert.equal(result.error, undefined, String(result.error));
    assert.equal(result.status, 0, result.stderr || result.stdout);
    assert.equal(result.stderr, '');
    return result.stdout;
}
function start(args, children) {
    const child = spawn(php, [fixture, ...args]);
    const closed = new Promise(resolve => child.once('close', resolve));
    children.push({child, closed});
    let stdout = '', stderr = '';
    child.stdout.on('data', bytes => { stdout += bytes; });
    child.stderr.on('data', bytes => { stderr += bytes; });
    const finished = new Promise((resolve, reject) => {
        child.on('error', reject);
        child.on('close', status => {
            try {
                assert.equal(status, 0, stderr || stdout);
                assert.equal(stderr, '');
                resolve(JSON.parse(stdout));
            } catch (error) { reject(error); }
        });
    });
    // Keep failures handled while the driver waits for filesystem coordination.
    finished.catch(() => {});
    return finished;
}
async function exists(path) {
    try { await access(path); return true; } catch { return false; }
}
async function waitFor(predicate, description) {
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
        if (await predicate()) { return; }
        await delay(10);
    }
    assert.fail('Timed out waiting for ' + description);
}
async function readyRow(root, preview) {
    const rows = JSON.parse(await readFile(join(root, 'database.json'), 'utf8'));
    assert.equal(rows[7].status, 'ready');
    assert.equal(rows[7].job_id, preview.job_id);
    assert.equal(rows[7].master_path, preview.paths.master);
}
async function intact(root, preview) {
    for (const [key, path] of Object.entries(preview.paths)) {
        const bytes = await readFile(join(root, path));
        assert.equal(createHash('sha256').update(bytes).digest('hex'), preview.hashes[key], key + ' bytes preserved');
    }
}
async function absent(root, preview) {
    for (const path of Object.values(preview.paths)) { assert.equal(await exists(join(root, path)), false, path); }
}

for (const scenario of ['success', 'rollback']) {
    test(scenario === 'success'
        ? 'competing confirms serialize on the real image lock and reject the stale loser'
        : 'failed competing confirm rolls back its finals and preserves previous ready before the waiter publishes',
    {timeout: 20000}, async context => {
        const root = await mkdtemp(join(tmpdir(), 'anabelka-preview-concurrency-'));
        const children = [];
        try {
            const {previous, first, second, kernel_wait_observable} = JSON.parse(run(['setup', root]));
            if (!kernel_wait_observable) {
                context.skip('requires native Linux PHP with observable kernel flock waits');
                return;
            }
            await readyRow(root, previous);
            await intact(root, previous);
            const firstResult = start(['confirm', root, 'first', scenario, first.token], children);
            await waitFor(() => exists(join(root, 'first-save-waiting')), 'first confirm inside locked publication');
            const secondResult = start(['confirm', root, 'second', scenario, second.token], children);
            await waitFor(() => exists(join(root, 'second-started')), 'second confirm start');
            // A third process proves the waiter owns its distinct token stripe.
            // The kernel wait channel then proves it is actually blocked in flock,
            // rather than merely descheduled before attempting the image lock.
            await waitFor(() => run(['probe-token', root, 'probe', scenario, second.token]) === 'held\n', 'second confirm holding its distinct token lock');
            const {pid} = JSON.parse(await readFile(join(root, 'second-started'), 'utf8'));
            await waitFor(async () => {
                try { return (await readFile('/proc/' + pid + '/wchan', 'utf8')).trim() === 'locks_lock_inode_wait'; }
                catch { return false; }
            }, 'second confirm blocked in the real image flock');
            assert.equal(await exists(join(root, 'second-began')), false, 'waiter cannot start DB work while first owns image lock');
            await readyRow(root, previous);
            await intact(root, previous);
            await intact(root, first);
            await absent(root, second);
            await writeFile(join(root, 'first-save-release'), '1');
            const acceptedFirst = await firstResult;
            if (scenario === 'success') {
                assert.equal(acceptedFirst.success, true);
                assert.equal(acceptedFirst.result.job_id, first.job_id);
                const rejectedSecond = await secondResult;
                assert.equal(rejectedSecond.success, false);
                assert.equal(rejectedSecond.class, 'InvalidArgumentException');
                assert.match(rejectedSecond.message, /Після цієї проби фотографію вже змінено/);
                assert.equal(await exists(join(root, 'second-saved')), false);
                await readyRow(root, first);
                await intact(root, first);
                await absent(root, second);
            } else {
                assert.equal(acceptedFirst.success, false);
                assert.equal(acceptedFirst.message, 'injected competing confirm commit failure');
                await waitFor(() => exists(join(root, 'second-begin-waiting')), 'waiter entering transaction after rollback');
                await readyRow(root, previous);
                await intact(root, previous);
                await absent(root, first);
                await absent(root, second);
                await writeFile(join(root, 'second-begin-release'), '1');
                const acceptedSecond = await secondResult;
                assert.equal(acceptedSecond.success, true);
                assert.equal(acceptedSecond.result.job_id, second.job_id);
                await readyRow(root, second);
                await intact(root, second);
                await absent(root, first);
            }
            await intact(root, previous);
        } finally {
            for (const {child} of children) {
                if (child.exitCode === null && child.signalCode === null) { child.kill('SIGKILL'); }
            }
            await Promise.all(children.map(({closed}) => closed));
            await rm(root, {recursive: true, force: true});
        }
    });
}
