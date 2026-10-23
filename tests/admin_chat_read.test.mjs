import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../js/admin-chat.js', import.meta.url), 'utf8');
class Element {
    constructor(tag, className = '', text = '') { this.tagName = tag; this.className = className; this.textContent = String(text); this.children = []; this.attributes = {}; }
    appendChild(child) { this.children.push(child); return child; }
    setAttribute(key, value) { this.attributes[key] = String(value); }
}
function renderer(kind = 'direct') {
    const match = source.match(/^    function renderReadStatus\([^]*?^    }/m);
    assert.ok(match, 'outgoing read renderer exists');
    const context = vm.createContext({adminId: 1, state: {conversation: {kind}},
        node: (tag, cls = '', text = '') => new Element(tag, cls, text)});
    vm.runInContext(match[0], context);
    return entry => context.renderReadStatus(entry);
}
const outgoing = status => ({id: 10, sender_id: 1, read_status: status});
test('outgoing status remains sent until a positive recipient count is fully read', () => {
    const render = renderer();
    for (const status of [{read_count: 0, recipient_count: 1, seen: false},
        {read_count: 0, recipient_count: 0, seen: true}]) {
        const result = render(outgoing(status));
        assert.equal(result.attributes['aria-label'], 'Відправлено');
        assert.equal(result.children[0].textContent, '✓');
        assert.doesNotMatch(result.className, /is-seen/);
    }
    const result = render(outgoing({read_count: 1, recipient_count: 1, seen: true}));
    assert.equal(result.attributes['aria-label'], 'Переглянуто');
    assert.equal(result.children[0].textContent, '✓✓');
    assert.match(result.className, /is-seen/);
});
test('incoming, deleted and unavailable receipt data do not render a status', () => {
    const render = renderer();
    assert.equal(render({sender_id: 2, read_status: {read_count: 1, recipient_count: 1, seen: true}}), null);
    assert.equal(render({...outgoing({read_count: 1, recipient_count: 1}), deleted_at: '2026-10-23'}), null);
    assert.equal(render({sender_id: 1}), null);
});
test('only partial group receipts display recipient counts without claiming full view', () => {
    const partial = {read_count: 1, recipient_count: 2, seen: false};
    const result = renderer('group')(outgoing(partial));
    assert.equal(result.attributes['aria-label'], 'Відправлено · Переглянули: 1 із 2');
    assert.equal(result.children[1].textContent, 'Переглянули: 1 із 2');
    assert.equal(renderer('direct')(outgoing(partial)).children.length, 1);
    assert.equal(renderer('group')(outgoing({read_count: 0, recipient_count: 2, seen: false})).children.length, 1);
});
