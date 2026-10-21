import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync('js/admin-dashboard-builder.js', 'utf8');
const view = fs.readFileSync('views/admin/dashboard-builder/index.php', 'utf8');
const bootstrap = fs.readFileSync('app/Core/NotificationBootstrap.php', 'utf8');
const entrypoint = fs.readFileSync('index.php', 'utf8');
const css = fs.readFileSync('css/admin-dashboard-builder.css', 'utf8');

function fragment(begin, end) {
    const start = source.indexOf(begin);
    const stop = source.indexOf(end, start + begin.length);
    assert.ok(start >= 0 && stop > start, begin + ' must exist');
    return source.slice(start, stop);
}

function runNotice(available = true) {
    const calls = [];
    const window = {};
    if (available) {
        window.AnabelkaNotify = {
            show(type, message) {
                calls.push([type, message]);
                return true;
            }
        };
    }
    const notify = vm.runInNewContext(
        '(function () {\n'
            + fragment('    function showMessage(message, isError)',
                '    function setSaving(value)')
            + '\nreturn showMessage;\n})()',
        {window}
    );
    return {notify, calls, window};
}

function dragHarness({kind = 'block', rejected = false, cancelled = false,
    moved = true, unchanged = false} = {}) {
    const notices = [];
    const actions = [];
    const originalBlocks = [11, 22];
    const originalLayout = [{block_id: 11, link_ids: [2]}];
    const nextBlocks = unchanged ? [11, 22] : [22, 11];
    const nextLayout = unchanged
        ? originalLayout
        : [{block_id: 11, link_ids: [3, 2]}];

    const state = {
        pointerId: 4,
        kind,
        moved,
        item: {classList: {remove(name) {actions.push('remove:' + name);}}},
        handle: {
            hasPointerCapture() {return false;},
            releasePointerCapture() {actions.push('release');}
        },
        originalBlocks,
        originalLayout
    };
    const context = {
        initialState: state,
        window: {AnabelkaNotify: {
            show(type, message) {notices.push([type, message]);return true;}
        }},
        document: {body: {classList: {remove(name) {actions.push('body:' + name);}}}},
        clearDropTargets() {actions.push('clear');},
        setSaving(value) {actions.push('saving:' + value);},
        blockOrder() {return nextBlocks;},
        layoutSnapshot() {return nextLayout;},
        sameArray(a, b) {return JSON.stringify(a) === JSON.stringify(b);},
        sameLayout(a, b) {return JSON.stringify(a) === JSON.stringify(b);},
        restoreBlockOrder(ids) {
            assert.deepEqual(ids, originalBlocks);
            actions.push('restore-block');
        },
        restoreLinkLayout(layout) {
            assert.deepEqual(layout, originalLayout);
            actions.push('restore-links');
        },
        syncEmptyStates() {actions.push('sync-empty');},
        async requestBlockOrder(ids) {
            assert.deepEqual(ids, nextBlocks);
            actions.push('post-blocks');
            if (rejected) throw new Error('Не вдалося зберегти порядок блоків.');
            return {message: 'Порядок блоків збережено.'};
        },
        async requestLinkLayout(layout) {
            assert.deepEqual(layout, nextLayout);
            actions.push('post-links');
            if (rejected) throw new Error('Не вдалося зберегти розкладку ярликів.');
            return {message: 'Розкладку ярликів збережено.'};
        }
    };
    const fn = vm.runInNewContext(
        '(function () {\nlet activeDrag = initialState;\n'
            + fragment('    function showMessage(message, isError)',
                '    function setSaving(value)')
            + '\n'
            + fragment('    async function finishDrag(event, cancelled)',
                "    root.querySelectorAll(\n        '[data-dashboard-builder-editor-toggle]'")
            + '\nreturn finishDrag;\n})()',
        context
    );
    return {
        actions,
        notices,
        async finish() {await fn({pointerId: 4}, cancelled);}
    };
}

test('drag feedback uses the shared queue and preserves success/error distinction', () => {
    const h = runNotice();
    assert.equal(h.notify('Порядок збережено.', false), true);
    assert.equal(h.notify('Не вдалося зберегти.', true), true);
    assert.deepEqual(h.calls, [
        ['success', 'Порядок збережено.'],
        ['error', 'Не вдалося зберегти.']
    ]);
    const absent = runNotice(false);
    assert.equal(absent.notify('Не вдалося зберегти.', true), false);
    assert.deepEqual(absent.calls, []);
});

test('old timed DnD notice and its separate DOM host have been removed', () => {
    assert.doesNotMatch(source,
        /messageTimer|messageElement|dashboard-builder-dnd-message/);
    assert.doesNotMatch(view, /id="dashboard-builder-dnd-message"/);
    assert.match(source, /notify\.show\(/);
    assert.match(view, /admin-dashboard-builder\.js\?v=3/);
    assert.match(view, /dashboard-builder-flash[\s\S]*?role=/);
    assert.match(view, /\$flash\['message'\]/);
    assert.match(css, /\.dashboard-builder-flash\.is-error/);
    assert.match(entrypoint, /NotificationBootstrap::register\(\)/);
    assert.match(bootstrap, /anabelka-notify\.js/);
});

test('saving a reordered block shows one shared success and toggles busy state', async () => {
    const h = dragHarness();
    await h.finish();
    assert.deepEqual(h.notices, [['success', 'Порядок блоків збережено.']]);
    assert.ok(h.actions.includes('post-blocks'));
    assert.ok(h.actions.includes('saving:true'));
    assert.ok(h.actions.includes('saving:false'));
    assert.ok(!h.actions.includes('restore-block'));
});

test('failed block reorder restores previous order and reports an error', async () => {
    const h = dragHarness({rejected: true});
    await h.finish();
    assert.deepEqual(h.notices, [
        ['error', 'Не вдалося зберегти порядок блоків.']
    ]);
    assert.ok(h.actions.includes('post-blocks'));
    assert.ok(h.actions.includes('restore-block'));
    assert.ok(h.actions.includes('saving:false'));
});

test('saving reordered links shows one shared success without rebuilding them', async () => {
    const h = dragHarness({kind: 'link'});
    await h.finish();
    assert.deepEqual(h.notices, [
        ['success', 'Розкладку ярликів збережено.']
    ]);
    assert.ok(h.actions.includes('post-links'));
    assert.ok(!h.actions.includes('restore-links'));
    assert.ok(h.actions.includes('saving:false'));
});

test('failed link reorder rolls back and notifies through the shared queue', async () => {
    const h = dragHarness({kind: 'link', rejected: true});
    await h.finish();
    assert.deepEqual(h.notices, [
        ['error', 'Не вдалося зберегти розкладку ярликів.']
    ]);
    assert.ok(h.actions.includes('restore-links'));
    assert.ok(h.actions.includes('saving:false'));
});

test('cancellation and unchanged drags make no request or notice', async () => {
    for (const kind of ['block', 'link']) {
        for (const options of [{cancelled: true}, {unchanged: true}, {moved: false}]) {
            const h = dragHarness({kind, ...options});
            await h.finish();
            assert.deepEqual(h.notices, []);
            assert.ok(!h.actions.includes('post-blocks'));
            assert.ok(!h.actions.includes('post-links'));
            assert.ok(!h.actions.includes('saving:true'));
        }
    }
});

test('reorder endpoints, CSRF mechanism, permissions and inline PHP flashes are retained', () => {
    assert.match(source, /reorder-blocks/);
    assert.match(source, /reorder-links/);
    assert.match(source, /data-dashboard-builder-root/);
    assert.match(source, /setPointerCapture/);
    assert.match(source, /block_ids\[\]/);
    assert.match(source, /JSON\.stringify\(layout\)/);
    assert.match(source, /restoreBlockOrder/);
    assert.match(source, /restoreLinkLayout/);
    assert.match(source, /setSaving\(true\)/);
    assert.match(view, /data-csrf=/);
    assert.match(view, /class="dashboard-builder-flash/);
    assert.match(view, /role="<\?= \(\$flash\['type'\]/);
    // Deletion is a separate deliberate confirmation, not an ephemeral notice.
    assert.match(view, /onsubmit="return confirm\(/);
});
