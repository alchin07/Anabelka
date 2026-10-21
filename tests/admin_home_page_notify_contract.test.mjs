import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const read = path => fs.readFileSync(path, 'utf8');
const script = read('js/admin-home-page.js');
const view = read('views/admin/home-page/index.php');

function extract(start, end) {
    const a = script.indexOf(start);
    const b = script.indexOf(end, a + start.length);
    assert.ok(a >= 0 && b > a, start);
    return script.slice(a, b);
}

const notifyFragment = extract(
    '    function showMessage(message, isError)',
    '    function setSaving(list, saving)'
);

function messageAPI(enabled = true) {
    const calls = [];
    const window = enabled ? {AnabelkaNotify: {
        show(type, text) {calls.push([type, text]);return true;}
    }} : {};
    const fn = vm.runInNewContext(
        '(function () {\n' + notifyFragment
            + '\nreturn showMessage;\n})()', {window}
    );
    return {fn, calls};
}

function drag({mode = 'editor', failed = false,
    cancelled = false, unchanged = false} = {}) {
    const calls = [];
    const notices = [];
    // The real builder's orderIds() reads string-valued DOM data attributes.
    const original = ['12', '21'];
    const swapped = ['21', '12'];
    const list = {
        order: unchanged ? [...original] : [...swapped],
        classList: {remove(name) {calls.push('remove-list:' + name);}}
    };
    const state = {
        kind: 'block',
        pointerId: 7,
        item: {classList: {remove(name) {calls.push('remove-item:' + name);}}},
        handle: {hasPointerCapture() {return false;}},
        source: 'preview-source',
        zone: 'main',
        list,
        originalIds: original,
        visibleIds: [...original]
    };
    const context = {
        startState: state,
        window: {AnabelkaNotify: {
            show(type, text) {notices.push([type, text]);return true;}
        }},
        document: {body: {classList: {remove(name) {calls.push('remove-body:' + name);}}}},
        orderIds(target) {assert.equal(target, list);return [...target.order];},
        sameOrder(left, right) {return left.join(',') === right.join(',');},
        sameIdSet(left, right) {return [...left].sort().join(',') === [...right].sort().join(',');},
        applyPreviewSubsetOrder(target, submitted) {
            assert.equal(target, list);
            target.order = [...submitted];
            calls.push('apply-preview');
            return true;
        },
        restoreOrder(target, ids) {
            assert.equal(target, list);
            list.order = [...ids];
            calls.push('restore');
        },
        syncArrowButtons() {calls.push('sync-arrows');},
        broadcastZoneOrder() {calls.push('broadcast-order');},
        broadcastHighlight(value) {calls.push('highlight:' + value);},
        setSaving(target, busy) {assert.equal(target, list);calls.push('saving:' + busy);},
        sendPreviewSaveResult(current, success) {
            assert.equal(current, state);
            calls.push('preview-result:' + success);
        },
        async requestSave(zone, blockIds) {
            assert.equal(zone, 'main');
            assert.equal(blockIds.join(','), '21,12');
            calls.push('request-save');
            if (failed) throw new Error('Не вдалося зберегти порядок блоків.');
            return {message: 'Порядок блоків збережено.'};
        }
    };
    const fn = mode === 'editor'
        ? vm.runInNewContext(
            '(function () {\nlet activeDrag = startState;\n'
                + notifyFragment
                + extract('    async function finishDrag(event, cancelled)',
                    "    root.querySelectorAll(\n        '[data-home-builder-list]'")
                + '\nreturn finishDrag;\n})()', context)
        : vm.runInNewContext(
            '(function () {\nlet previewDragState = startState;\n'
                + notifyFragment
                + extract('    async function finishPreviewDrag(source, data, cancelled)',
                    '    function syncArrowButtons(list)')
                + '\nreturn finishPreviewDrag;\n})()', context);
    return {
        calls, notices, list,
        async finish() {
            if (mode === 'editor') {
                await fn({pointerId: 7}, cancelled);
            } else {
                await fn('preview-source', {
                    zone: 'main',
                    blockIds: unchanged ? [...original] : [...swapped]
                }, cancelled);
            }
        }
    };
}

test('shared AnabelkaNotify keeps errors and successful saves distinct', () => {
    const {fn, calls} = messageAPI();
    assert.equal(fn('Збережено.', false), true);
    assert.equal(fn('Не вдалося зберегти.', true), true);
    assert.deepEqual(calls, [
        ['success', 'Збережено.'],
        ['error', 'Не вдалося зберегти.']
    ]);
    assert.equal(messageAPI(false).fn('Помилка.', true), false);
});

test('temporary legacy DnD host and timer removed; server notices remain permanent', () => {
    assert.doesNotMatch(script,
        /messageTimer|messageElement|admin-home-builder-dnd-message/);
    assert.doesNotMatch(view, /id="admin-home-builder-dnd-message"/);
    assert.match(script, /notify\.show\(/);
    assert.match(view, /admin-home-page\.js\?v=6/);
    assert.match(script, /let resizeTimer = 0/);
    assert.match(view, /\$message !== ''/);
    assert.match(view, /\$error !== ''/);
    assert.match(view, /admin-home-builder-flash is-success" role="status"/);
    assert.match(view, /admin-home-builder-flash is-error" role="alert"/);
    assert.match(read('index.php'), /NotificationBootstrap::register\(\)/);
    assert.match(read('app/Core/NotificationBootstrap.php'), /anabelka-notify\.js/);
    assert.match(read('css/admin-home-page.css'), /\.admin-home-builder-flash\.is-error/);
});

for (const mode of ['editor', 'preview']) {
    test(mode + ' reorder succeeds with one central notice and no rollback', async () => {
        const h = drag({mode});
        await h.finish();
        assert.deepEqual(h.notices, [['success', 'Порядок блоків збережено.']]);
        assert.equal(h.calls.filter(x => x === 'request-save').length, 1);
        assert.ok(h.calls.includes('saving:true'));
        assert.ok(h.calls.includes('saving:false'));
        assert.ok(!h.calls.includes('restore'));
        if (mode === 'preview') {
            assert.ok(h.calls.includes('preview-result:true'));
            assert.ok(h.calls.includes('broadcast-order'));
        }
    });

    test(mode + ' failed save restores original order and notifies error once', async () => {
        const h = drag({mode, failed: true});
        await h.finish();
        assert.deepEqual(h.notices, [['error', 'Не вдалося зберегти порядок блоків.']]);
        assert.deepEqual(h.list.order, ['12', '21']);
        assert.ok(h.calls.includes('restore'));
        assert.ok(h.calls.includes('saving:false'));
        if (mode === 'preview') {
            assert.ok(h.calls.includes('preview-result:false'));
        }
    });

    test(mode + ' cancellation has no save and no notification', async () => {
        const h = drag({mode, cancelled: true});
        await h.finish();
        assert.deepEqual(h.notices, []);
        assert.deepEqual(h.list.order, ['12', '21']);
        assert.ok(!h.calls.includes('request-save'));
        if (mode === 'preview') {
            assert.ok(h.calls.includes('preview-result:true'));
        }
    });

    test(mode + ' no-op has no save and no notification', async () => {
        const h = drag({mode, unchanged: true});
        await h.finish();
        assert.deepEqual(h.notices, []);
        assert.deepEqual(h.list.order, ['12', '21']);
        assert.ok(!h.calls.includes('request-save'));
    });
}

test('preview bridge, CSRF request and destructive confirmations are unchanged', () => {
    assert.match(script, /\/Anabelka\/admin\/home-page\/reorder/);
    assert.match(script, /form\.append\('_csrf'/);
    assert.match(script, /anabelka-builder-preview-save-result/);
    assert.match(script, /applyPreviewSubsetOrder/);
    assert.match(script, /broadcastZoneOrder/);
    assert.match(script, /finishPreviewDrag/);
    assert.match(script, /finishDrag/);
    assert.match(view, /data-csrf=/);
    assert.match(view, /onsubmit="return confirm\(/);
});
