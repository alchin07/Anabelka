import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../js/admin-chat.js', import.meta.url), 'utf8');
const outgoing = (id, extra = {}) => ({id, sender_id: 1, body: `Original ${id}`,
    attachments: [], edited_at: null, deleted_at: null,
    read_status: {read_count: 1, recipient_count: 1, seen: true}, ...extra});

function fixture(messages) {
    const state = {messages: structuredClone(messages), signature: JSON.stringify(messages)};
    const renders = [];
    const context = vm.createContext({state, adminId: 1,
        thread: {querySelector: () => null}, renderReadStatus: () => null,
        renderThread(forceBottom) { renders.push({forceBottom, messages: structuredClone(state.messages)}); }});
    const match = source.match(/^    function mergeThread\([^]*?^    }/m);
    assert.ok(match, 'The real cached-history merge function must exist.');
    vm.runInContext(match[0], context);
    return {state, renders, merge: data => context.mergeThread(data, false)};
}

test('an edited retained row updates even after it falls outside the latest history window', () => {
    // Row 2 was rendered in the previous latest-70 window (2..71); the next
    // latest-70 refresh contains 3..72, so content updates must cover row 2.
    const fx = fixture(Array.from({length: 70}, (_, index) => outgoing(index + 2)));
    fx.merge({messages: Array.from({length: 70}, (_, index) => outgoing(index + 3)),
        message_updates: [{id: 2, body: 'Edited original', edited_at: '2026-10-23 13:00:00', deleted_at: null}]});
    assert.equal(fx.state.messages[0].id, 2);
    assert.equal(fx.state.messages[0].body, 'Edited original');
    assert.equal(fx.state.messages[0].edited_at, '2026-10-23 13:00:00');
    assert.equal(fx.state.messages.length, 71);
    assert.equal(fx.renders.length, 1, 'An actual content update must render the changed body.');
});

test('a deleted retained photo loses its cached body, attachments and outgoing receipt', () => {
    const fx = fixture([outgoing(2, {body: 'Private photo', attachments: [{id: 20, mime_type: 'image/png'}]}), outgoing(72)]);
    fx.merge({messages: [outgoing(72)], message_updates: [{id: 2, body: '',
        edited_at: null, deleted_at: '2026-10-23 13:00:00'}]});
    const deleted = fx.state.messages[0];
    assert.equal(deleted.deleted_at, '2026-10-23 13:00:00');
    assert.equal(deleted.body, '');
    assert.equal(deleted.attachments.length, 0);
    assert.equal(deleted.read_status, undefined);
    assert.equal(fx.renders.length, 1);
});

test('retained quoted copies follow edits and deletion of their original message', () => {
    const fx = fixture([outgoing(2), outgoing(72, {reply_to_id: 2, reply_body: 'Original 2', reply_sender: 'Автор'})]);
    fx.merge({messages: [], message_updates: [{id: 2, body: 'Corrected quote',
        edited_at: '2026-10-23 13:00:00', deleted_at: null}]});
    assert.equal(fx.state.messages[1].reply_body, 'Corrected quote');
    fx.merge({messages: [], message_updates: [{id: 2, body: '',
        edited_at: '2026-10-23 13:00:00', deleted_at: '2026-10-23 13:01:00'}]});
    assert.equal(fx.state.messages[1].reply_body, '');
});

test('content updates for an unretained original refresh a retained quote without inserting an incomplete row', () => {
    const fx = fixture([outgoing(72, {reply_to_id: 2, reply_body: 'Old quote'})]);
    fx.merge({messages: [], message_updates: [{id: 2, body: 'New quote',
        edited_at: '2026-10-23 13:00:00', deleted_at: null}]});
    assert.equal(fx.state.messages.length, 1);
    assert.equal(fx.state.messages[0].id, 72);
    assert.equal(fx.state.messages[0].reply_body, 'New quote');
});
