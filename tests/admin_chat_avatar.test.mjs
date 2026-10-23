import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../js/admin-chat.js', import.meta.url), 'utf8');

// Execute the real renderers with a small DOM and a replaceable network boundary.
class Element {
    constructor(tag, namespaceURI = null) {
        this.tagName = tag;
        this.namespaceURI = namespaceURI;
        this.children = [];
        this.attributes = {};
        this.className = '';
        this.value = '';
        this.hidden = false;
        this._text = '';
        this.classList = {add: (...names) => {
            this.className = [this.className, ...names].filter(Boolean).join(' ');
        }, remove: () => {}};
    }
    appendChild(child) { this.children.push(child); return child; }
    replaceChildren(...children) { this._text = ''; this.children = children; }
    setAttribute(key, value) { this.attributes[key] = String(value); }
    getAttribute(key) { return this.attributes[key] ?? null; }
    addEventListener() {}
    set textContent(value) { this._text = String(value); this.children = []; }
    get textContent() { return this._text + this.children.map(x => x.textContent).join(''); }
}
function findAll(element, predicate) {
    return [element, ...element.children.flatMap(child => findAll(child, predicate))].filter(predicate);
}
const byClass = (element, name) => findAll(element, x => x.className.split(' ').includes(name));
const badge = avatar => byClass(avatar, 'chat-member-count')[0];
const shape = element => ({tag: element.tagName, ns: element.namespaceURI,
    class: element.className, attrs: element.attributes, text: element._text,
    children: element.children.map(shape)});

function fixture() {
    const elements = new Map();
    const el = id => {
        if (!elements.has(id)) { elements.set(id, new Element('span')); }
        return elements.get(id);
    };
    const state = {active: 0, conversations: [], admins: [], members: [], membersLoadedFor: 0, membersVersion: 0,
        messages: [], conversation: null, signature: '', threadLoading: false, lastRead: {}};
    const context = vm.createContext({
        state, el, document: {hidden: false, createElement: tag => new Element(tag),
            createElementNS: (ns, tag) => new Element(tag, ns), querySelectorAll: () => []},
        list: el('chat-conversations'), searchInput: el('chat-search'),
        layout: el('chat-layout'), closeEmoji() {}, clearReply() {}, renderThread() {},
        renderGroupDialog() {}, inform() {}, request: async () => ({}),
        URL, URLSearchParams, location: {href: 'https://example.invalid/Anabelka/admin/chat',
            search: ''}, history: {replaceState() {}},
    });
    const names = ['node', 'clearChildren', 'chatFor', 'participantCount', 'brandedAvatar',
        'updateConversationHeader', 'renderList', 'loadList', 'loadThread',
        'openConversation', 'toList', 'manageGroup'];
    const snippets = names.map(name => {
        const match = source.match(new RegExp('^    (?:async )?function ' + name + '\\([^]*?^    }', 'm'));
        assert.ok(match, name + ' exists');
        return match[0];
    });
    vm.runInContext(snippets.join('\n'), context);
    return {state, context, el, avatar: c => context.brandedAvatar(c)};
}
function group(count, id = 1) { return {id, kind: 'group', member_count: count, display_name: 'Команда'}; }

test('direct avatar has two transparent rounded SVG hearts and an accessible label', () => {
    const {avatar} = fixture();
    const icon = avatar({id: 1, kind: 'direct', member_count: 12});
    const paths = findAll(icon, x => x.tagName === 'path');
    assert.equal(paths.length, 2);
    assert.ok(findAll(icon, x => x.namespaceURI === 'http://www.w3.org/2000/svg').length >= 3);
    assert.equal(badge(icon), undefined);
    assert.equal(icon.getAttribute('role'), 'img');
    assert.match(icon.getAttribute('aria-label'), /Особиста/);
    assert.equal(icon.textContent, '');
    const svg = findAll(icon, x => x.tagName === 'svg')[0];
    assert.equal(svg.getAttribute('fill'), 'none');
    assert.equal(svg.getAttribute('stroke-linecap'), 'round');
    assert.equal(svg.getAttribute('stroke-linejoin'), 'round');
});

test('group avatar has one heart, exact positive counts and a separate 99+ badge', () => {
    const {avatar} = fixture();
    for (const count of [1, 2, 9, 10, 99, '2', 100, 1234]) {
        const icon = avatar(group(count));
        assert.equal(findAll(icon, x => x.tagName === 'path').length, 1);
        assert.equal(badge(icon).textContent, Number(count) > 99 ? '99+' : String(count));
        assert.match(icon.getAttribute('aria-label'), new RegExp(String(count)));
    }
    assert.ok(badge(avatar(group(100))).className.includes('is-wide'));
});

test('pending or invalid data never invents zero or injects markup', () => {
    const {avatar} = fixture();
    for (const count of [undefined, null, '', 0, '0', -1, NaN, Infinity, 1.5, '<img src=x>']) {
        const icon = avatar(group(count));
        assert.equal(badge(icon), undefined);
        assert.doesNotMatch(icon.textContent, /0|<img/);
    }
});

test('list and header share identical icons; unread count remains separate', () => {
    const {state, context, el} = fixture();
    state.active = 1;
    state.conversations = [{...group(2), unread: 7}];
    context.renderList(); context.updateConversationHeader();
    const listIcon = byClass(el('chat-conversations'), 'chat-avatar-icon')[0];
    const headerIcon = byClass(el('chat-thread-avatar'), 'chat-avatar-icon')[0];
    assert.deepEqual(shape(listIcon), shape(headerIcon));
    assert.equal(badge(headerIcon).textContent, '2');
    assert.equal(byClass(el('chat-conversations'), 'chat-unread')[0].textContent, '7');
    assert.doesNotMatch(el('chat-thread-subtitle').textContent, /· 0 /);
});

test('loaded members belong only to the active conversation and opening resets ownership', () => {
    const {state, context, el} = fixture();
    state.active = 1; state.membersLoadedFor = 1; state.members = [{id: 1}, {id: 2}];
    state.conversations = [group(8), group(4, 2)];
    assert.equal(badge(context.brandedAvatar(group(8))).textContent, '2');
    assert.equal(badge(context.brandedAvatar(group(4, 2))).textContent, '4');
    state.threadLoading = true; // Keep the second group's request pending.
    context.openConversation(2, false);
    assert.equal(state.membersLoadedFor, 0);
    assert.equal(badge(el('chat-thread-avatar')).textContent, '4');
    context.toList();
    assert.equal(state.membersLoadedFor, 0);
});

test('polling list uses fresh count until members reload, without changing unread', async () => {
    const {state, context, el} = fixture();
    state.active = 1; state.membersLoadedFor = 1; state.members = [{id: 1}];
    state.conversations = [group(1)];
    context.request = async () => ({conversations: [{...group(2), unread: 8}], unread_total: 8});
    await context.loadList();
    assert.equal(badge(el('chat-thread-avatar')).textContent, '2');
    assert.equal(badge(el('chat-conversations')).textContent, '2');
    assert.equal(el('chat-all-count').textContent, '8');
});

test('member responses refresh both avatars even when messages are unchanged', async () => {
    const {state, context, el} = fixture();
    state.active = 1; state.signature = '[]'; state.conversations = [group(1)];
    for (const count of [2, 1]) {
        context.request = async () => ({messages: [], conversation: group(count),
            members: Array.from({length: count}, (_, i) => ({id: i + 1}))});
        await context.loadThread(false);
        assert.equal(badge(el('chat-thread-avatar')).textContent, String(count));
        assert.equal(badge(el('chat-conversations')).textContent, String(count));
        assert.equal(Number(state.conversations[0].member_count), count);
    }
});

test('late response from previous group cannot overwrite active group badge', async () => {
    const {state, context, el} = fixture();
    state.active = 1; state.conversations = [group(2), group(5, 2)];
    let resolve;
    context.request = () => new Promise(done => { resolve = done; });
    const pending = context.loadThread(false);
    state.active = 2; context.updateConversationHeader();
    resolve({messages: [], members: [{id: 1}], conversation: group(1)});
    await pending;
    assert.equal(badge(el('chat-thread-avatar')).textContent, '5');
    assert.equal(state.membersLoadedFor, 0);
});

test('add/remove operations refresh counts through the existing request sequence', async () => {
    const {state, context, el} = fixture();
    state.active = 1; state.signature = '[]'; state.conversations = [group(1)];
    let count = 1;
    context.request = async (path, payload) => {
        if (path === 'api/group/manage') { count += payload.action === 'add' ? 1 : -1; return {}; }
        if (path === 'api/list') { return {conversations: [group(count)]}; }
        return {messages: [], members: Array.from({length: count}, (_, id) => ({id})), conversation: group(count)};
    };
    for (const [action, expected] of [['add', '2'], ['remove', '1']]) {
        await context.manageGroup(action, {member_id: 2});
        assert.equal(badge(el('chat-thread-avatar')).textContent, expected);
        assert.equal(badge(el('chat-conversations')).textContent, expected);
    }
});

test('a pending pre-removal response cannot restore an old count and reloads current members', async () => {
    const {state, context, el} = fixture();
    state.active = 1; state.signature = '[]'; state.conversations = [group(2)];
    let resolveOld, messageRequests = 0;
    context.request = async path => {
        if (path === 'api/group/manage') { return {}; }
        if (path === 'api/list') { return {conversations: [group(1)]}; }
        messageRequests++;
        if (messageRequests === 1) { return new Promise(done => { resolveOld = done; }); }
        // Observe the badge before the corrective response, too: no stale flash.
        assert.equal(badge(el('chat-thread-avatar')).textContent, '1');
        return {messages: [], members: [{id: 1}], conversation: group(1)};
    };
    const pending = context.loadThread(false);
    await context.manageGroup('remove', {member_id: 2});
    assert.equal(badge(el('chat-thread-avatar')).textContent, '1');
    resolveOld({messages: [], members: [{id: 1}, {id: 2}], conversation: group(2)});
    await pending;
    assert.equal(badge(el('chat-thread-avatar')).textContent, '1');
    assert.equal(badge(el('chat-conversations')).textContent, '1');
    assert.equal(state.members.length, 1);
    assert.equal(messageRequests, 2);
});
