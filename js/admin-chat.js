/* Анабелька — адміністраторський чат: без сторонніх JS, CDN та WebSocket.
   Після перенесення на спільний сервер працює через ту саму MariaDB. */
(function () {
    'use strict';

    const root = document.getElementById('admin-chat');
    if (!root) { return; }
    const el = function (id) { return document.getElementById(id); };
    const API = '/Anabelka/admin/chat/';
    const adminId = Number(root.dataset.adminId || 0);
    const csrf = root.dataset.csrf || '';
    const state = {
        active: Number(new URLSearchParams(location.search).get('c')) || 0,
        conversations: [], admins: [], messages: [], members: [], conversation: null,
        signature: '', syncing: false, threadLoading: false, replyId: 0,
        lastRead: {}, deleteId: 0, sending: false, searching: false
    };

    const layout = el('chat-layout');
    const list = el('chat-conversations');
    const thread = el('chat-messages');
    const bodyInput = el('chat-message');
    const fileInput = el('chat-files');
    const searchInput = el('chat-search');
    const emojiPanel = el('chat-emoji-panel');
    const emojiData = window.AnabelkaChatEmojiData || {categories: [], names: {}};
    let currentEmojiCategory = 'recent';
    let searchDelay = null;
    let toastDelay = null;
    let recentEmoji = [];

    try {
        const parsed = JSON.parse(localStorage.getItem('anabelka-chat-recent-emoji') || '[]');
        if (Array.isArray(parsed)) {
            recentEmoji = parsed.filter(function (x) { return typeof x === 'string'; }).slice(0, 28);
        }
    } catch (e) { /* Недоступне сховище не заважає роботі чату. */ }

    function node(tag, className, textValue) {
        const element = document.createElement(tag);
        if (className) { element.className = className; }
        if (textValue !== undefined) { element.textContent = String(textValue); }
        return element;
    }

    function inform(message, isError) {
        const toast = el('chat-toast');
        toast.textContent = message;
        toast.hidden = false;
        toast.style.background = isError ? '#6519b9' : '#8a2be2';
        window.clearTimeout(toastDelay);
        toastDelay = window.setTimeout(function () { toast.hidden = true; }, 4200);
    }

    async function request(path, data) {
        const options = {
            credentials: 'same-origin', redirect: 'follow',
            headers: {'Accept': 'application/json', 'X-Anabelka-Request': 'admin-chat'}
        };
        if (data !== undefined) {
            const form = data instanceof FormData ? data : new FormData();
            if (!(data instanceof FormData)) {
                Object.keys(data).forEach(function (key) {
                    const value = data[key];
                    if (Array.isArray(value)) {
                        value.forEach(function (item) { form.append(key + '[]', String(item)); });
                    } else {
                        form.append(key, String(value));
                    }
                });
            }
            form.append('_csrf', csrf);
            options.method = 'POST';
            options.body = form;
            options.headers['X-CSRF-Token'] = csrf;
        }
        const response = await fetch(API + path, options);
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) {
            throw new Error('Сеанс завершено. Оновіть сторінку та увійдіть знову.');
        }
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Не вдалося виконати дію.');
        }
        return result;
    }

    function dialogOpen(id) {
        const dialog = el(id);
        if (!dialog) { return; }
        if (typeof dialog.showModal === 'function') { dialog.showModal(); }
        else { dialog.setAttribute('open', ''); }
    }

    function dialogClose(id) {
        const dialog = el(id);
        if (dialog && typeof dialog.close === 'function') { dialog.close(); }
        else if (dialog) { dialog.removeAttribute('open'); }
    }

    function clearChildren(container) {
        container.replaceChildren();
    }

    function showTime(value) {
        const raw = String(value || '');
        return raw.length >= 16 ? raw.substring(11, 16) : '';
    }

    function chatFor(id) {
        return state.conversations.find(function (c) { return Number(c.id) === Number(id); });
    }

    function updateConversationHeader() {
        const conversation = chatFor(state.active);
        el('chat-thread-title').textContent = conversation
            ? (conversation.display_name || 'Особиста розмова') : 'Оберіть розмову';
        el('chat-thread-avatar').textContent = conversation && conversation.kind === 'group' ? '👥' : '💬';
        el('chat-thread-subtitle').textContent = conversation
            ? (conversation.kind === 'group'
                ? 'Груповий чат · ' + state.members.length + ' учасників'
                : 'Особиста розмова') : 'Спілкуйтеся з адміністраторами';
        el('chat-manage-group').hidden = !conversation || conversation.kind !== 'group';
    }

    function renderList() {
        const query = searchInput.value.trim().toLocaleLowerCase();
        clearChildren(list);
        const conversations = state.conversations.filter(function (chat) {
            return !query || (String(chat.display_name || '') + ' ' +
                String(chat.preview || '')).toLocaleLowerCase().includes(query);
        });
        if (!conversations.length) {
            list.appendChild(node('p', 'chat-help', query
                ? 'Бесід із такою назвою немає.' : 'Ще немає розмов. Натисніть ✎ або «+ Група».'));
        }
        conversations.forEach(function (chat) {
            const id = Number(chat.id);
            const button = node('button', 'chat-conversation');
            button.type = 'button';
            button.setAttribute('role', 'listitem');
            if (id === state.active) { button.classList.add('is-active'); }
            const avatar = node('span', 'chat-avatar', chat.kind === 'group' ? '👥' : '💬');
            const copy = node('span', 'chat-conversation-copy');
            copy.appendChild(node('strong', '', chat.display_name || 'Особиста розмова'));
            copy.appendChild(node('span', '', chat.preview || 'Напишіть перше повідомлення'));
            button.appendChild(avatar);
            button.appendChild(copy);
            const unread = Number(chat.unread || 0);
            if (unread) { button.appendChild(node('span', 'chat-unread', unread > 99 ? '99+' : unread)); }
            button.addEventListener('click', function () { openConversation(id, true); });
            list.appendChild(button);
        });
    }

    async function loadList() {
        const data = await request('api/list');
        state.conversations = Array.isArray(data.conversations) ? data.conversations : [];
        state.admins = Array.isArray(data.admins) ? data.admins : [];
        const badge = el('chat-all-count');
        const total = Number(data.unread_total || 0);
        badge.hidden = total <= 0;
        badge.textContent = total > 99 ? '99+' : String(total);
        document.querySelectorAll('[data-chat-unread-badge]').forEach(function (elem) {
            elem.hidden = total <= 0;
            elem.textContent = total > 99 ? '99+' : String(total);
        });
        renderList();
        updateConversationHeader();
    }

    function openConversation(id, recordHistory) {
        id = Number(id);
        if (!chatFor(id)) { return; }
        state.active = id;
        state.signature = '';
        state.messages = [];
        state.members = [];
        state.lastRead[id] = state.lastRead[id] || 0;
        clearReply();
        closeEmoji();
        layout.classList.add('has-open-thread');
        updateConversationHeader();
        renderList();
        if (recordHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('c', String(id));
            const mobile = window.matchMedia('(max-width:730px)').matches;
            const previous = new URLSearchParams(window.location.search).get('c');
            if (mobile && previous !== String(id)) {
                history.pushState({adminChat: true}, '', url.pathname + url.search);
            } else {
                history.replaceState(history.state, '', url.pathname + url.search);
            }
        }
        loadThread(true).catch(function (error) { inform(error.message, true); });
    }

    function toList() {
        layout.classList.remove('has-open-thread');
        closeEmoji();
        const url = new URL(location.href);
        url.searchParams.delete('c');
        history.replaceState(history.state, '', url.pathname + url.search);
    }

    function renderMessage(entry) {
        const own = Number(entry.sender_id) === adminId;
        const deleted = Boolean(entry.deleted_at);
        const row = node('div', 'chat-message-row' + (own ? ' is-mine' : ''));
        row.dataset.messageId = entry.id;
        const bubble = node('article', 'chat-bubble');
        if (!own) { bubble.appendChild(node('span', 'chat-sender', entry.sender_name || 'Адміністратор')); }
        if (entry.reply_to_id && !deleted) {
            const quoted = node('div', 'chat-quoted');
            quoted.appendChild(node('strong', '', entry.reply_sender || 'Повідомлення'));
            quoted.appendChild(node('span', '', entry.reply_body || 'Повідомлення видалено'));
            bubble.appendChild(quoted);
        }
        bubble.appendChild(node('p', 'chat-message-body',
            deleted ? 'Повідомлення видалено' : entry.body || ''));
        if (!deleted && Array.isArray(entry.attachments)) {
            entry.attachments.forEach(function (file) {
                const href = API + 'file?id=' + encodeURIComponent(file.id);
                const link = node('a', 'chat-file-card');
                link.href = href;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                if (String(file.mime_type).startsWith('image/')) {
                    link.classList.add('chat-image-card');
                    const img = node('img');
                    img.src = href;
                    img.alt = file.original_name || 'Зображення';
                    img.loading = 'lazy';
                    link.appendChild(img);
                } else {
                    link.appendChild(node('span', '', '📎 ' +
                        (file.original_name || 'Файл') + ' · ' +
                        Math.max(1, Math.round(Number(file.byte_size || 0) / 1024)) + ' КБ'));
                }
                bubble.appendChild(link);
            });
        }
        const meta = node('div', 'chat-message-meta', showTime(entry.created_at));
        if (entry.edited_at && !deleted) { meta.appendChild(node('span', '', '· змінено')); }
        bubble.appendChild(meta);
        row.appendChild(bubble);
        if (!deleted) {
            const actions = node('div', 'chat-message-actions');
            const reply = node('button', '', '↩ Відповісти');
            reply.type = 'button';
            reply.addEventListener('click', function () {
                state.replyId = Number(entry.id);
                el('chat-reply-text').textContent =
                    (entry.sender_name || '') + ': ' + (entry.body || '📎 Файл');
                el('chat-reply-banner').hidden = false;
                bodyInput.focus();
            });
            actions.appendChild(reply);
            if (own) {
                const edit = node('button', '', '✎ Змінити');
                edit.type = 'button';
                edit.addEventListener('click', function () {
                    startEdit(bubble, entry);
                });
                actions.appendChild(edit);
                const remove = node('button', '', '× Видалити');
                remove.type = 'button';
                remove.addEventListener('click', function () {
                    state.deleteId = Number(entry.id);
                    dialogOpen('chat-delete-dialog');
                });
                actions.appendChild(remove);
            }
            row.appendChild(actions);
        }
        return row;
    }

    function startEdit(bubble, message) {
        if (bubble.querySelector('.chat-edit-wrap')) { return; }
        const editing = node('div', 'chat-edit-wrap');
        const input = node('textarea');
        input.value = message.body || '';
        input.maxLength = 4000;
        editing.appendChild(input);
        const controls = node('div');
        const save = node('button', 'chat-primary', 'Зберегти');
        save.type = 'button';
        const cancel = node('button', 'chat-secondary', 'Скасувати');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { editing.remove(); });
        save.addEventListener('click', async function () {
            save.disabled = true;
            try {
                await request('api/edit', {message_id: message.id, body: input.value});
                state.signature = '';
                await loadThread(true);
            } catch (error) { inform(error.message, true); }
            finally { save.disabled = false; }
        });
        controls.appendChild(save);
        controls.appendChild(cancel);
        editing.appendChild(controls);
        bubble.appendChild(editing);
        input.focus();
    }

    function renderThread(forceBottom) {
        const atBottom = forceBottom || thread.scrollHeight - thread.scrollTop - thread.clientHeight < 90;
        const previous = thread.scrollTop;
        clearChildren(thread);
        if (!state.messages.length) {
            const empty = node('div', 'chat-empty');
            empty.appendChild(node('span', 'chat-empty-icon', '💜'));
            empty.appendChild(node('strong', '', 'Поки що немає повідомлень'));
            empty.appendChild(node('span', '', 'Напишіть перше повідомлення.'));
            thread.appendChild(empty);
        } else {
            state.messages.forEach(function (message) {
                thread.appendChild(renderMessage(message));
            });
        }
        thread.scrollTop = atBottom ? thread.scrollHeight : previous;
    }

    async function loadThread(forceBottom) {
        if (!state.active || state.threadLoading) { return; }
        const conversationId = state.active;
        state.threadLoading = true;
        try {
            const data = await request('api/messages?conversation_id=' + conversationId);
            if (conversationId !== state.active) { return; }
            state.messages = Array.isArray(data.messages) ? data.messages : [];
            state.members = Array.isArray(data.members) ? data.members : [];
            state.conversation = data.conversation || null;
            const signature = JSON.stringify(state.messages);
            if (signature !== state.signature) {
                state.signature = signature;
                renderThread(Boolean(forceBottom));
            }
            updateConversationHeader();
            const last = state.messages.length ? Number(state.messages[state.messages.length - 1].id) : 0;
            if (last > (state.lastRead[conversationId] || 0) && !document.hidden) {
                state.lastRead[conversationId] = last;
                await request('api/read', {conversation_id: conversationId, message_id: last});
            }
        } finally {
            state.threadLoading = false;
        }
    }

    async function refresh() {
        if (state.syncing || document.hidden) { return; }
        state.syncing = true;
        try {
            await loadList();
            if (state.active) {
                if (!chatFor(state.active)) { toList(); state.active = 0; }
                else { await loadThread(false); }
            }
        } catch (error) {
            if (!state.conversations.length) {
                list.replaceChildren(node('p', 'chat-help', 'Чат тимчасово недоступний.'));
            }
        } finally {
            state.syncing = false;
        }
    }

    function clearReply() {
        state.replyId = 0;
        el('chat-reply-banner').hidden = true;
        el('chat-reply-text').textContent = '';
    }

    function renderFiles() {
        const zone = el('chat-selected-files');
        clearChildren(zone);
        const files = Array.from(fileInput.files || []);
        zone.hidden = !files.length;
        files.forEach(function (file) {
            zone.appendChild(node('span', 'chat-file-pill', '📎 ' + file.name));
        });
        if (files.length) {
            const clear = node('button', 'chat-secondary', 'Очистити');
            clear.type = 'button';
            clear.addEventListener('click', function () {
                fileInput.value = '';
                renderFiles();
            });
            zone.appendChild(clear);
        }
    }

    async function submitMessage(event) {
        event.preventDefault();
        if (state.sending) { return; }
        if (!state.active) { inform('Спочатку оберіть розмову.', true); return; }
        const text = bodyInput.value.trim();
        const files = Array.from(fileInput.files || []);
        if (!text && !files.length) { return; }
        if (files.length > 4 || files.some(function (f) { return f.size > 8388608; })) {
            inform('До 4 файлів, кожен не більше 8 МБ.', true);
            return;
        }
        const data = new FormData();
        data.append('conversation_id', String(state.active));
        data.append('body', text);
        data.append('reply_to_id', String(state.replyId));
        files.forEach(function (file) { data.append('files[]', file); });
        state.sending = true;
        el('chat-send').disabled = true;
        try {
            await request('api/send', data);
            bodyInput.value = '';
            fileInput.value = '';
            renderFiles();
            clearReply();
            closeEmoji();
            state.signature = '';
            await loadList();
            await loadThread(true);
        } catch (error) {
            inform(error.message, true);
        } finally {
            state.sending = false;
            el('chat-send').disabled = false;
            bodyInput.focus();
        }
    }

    function fillDirectUsers() {
        const select = el('chat-direct-user');
        clearChildren(select);
        state.admins.forEach(function (admin) {
            const option = node('option', '', admin.name + ' · ' + admin.role_name);
            option.value = admin.id;
            select.appendChild(option);
        });
    }

    function fillGroupUsers() {
        const wrap = el('chat-group-users');
        clearChildren(wrap);
        state.admins.forEach(function (admin) {
            const label = node('label');
            const check = node('input');
            check.type = 'checkbox';
            check.value = admin.id;
            label.appendChild(check);
            label.appendChild(node('span', '', admin.name + ' · ' + admin.role_name));
            wrap.appendChild(label);
        });
    }

    async function createDirect(event) {
        event.preventDefault();
        try {
            const result = await request('api/direct', {admin_id: el('chat-direct-user').value});
            dialogClose('chat-direct-dialog');
            await loadList();
            openConversation(result.conversation_id, true);
        } catch (error) { inform(error.message, true); }
    }

    async function createGroup(event) {
        event.preventDefault();
        const members = Array.from(el('chat-group-users').querySelectorAll('input:checked'))
            .map(function (input) { return input.value; });
        try {
            const result = await request('api/group', {
                title: el('chat-group-name').value, members: members
            });
            dialogClose('chat-group-dialog');
            el('chat-group-form').reset();
            await loadList();
            openConversation(result.conversation_id, true);
        } catch (error) { inform(error.message, true); }
    }

    function mayManageGroup() {
        const group = state.conversation;
        return group && (Number(group.created_by) === adminId ||
            ['owner', 'store_owner'].includes(root.dataset.role));
    }

    function renderGroupDialog() {
        const group = state.conversation;
        if (!group || group.kind !== 'group') { return; }
        const canManage = mayManageGroup();
        el('chat-group-settings').hidden = !canManage;
        el('chat-add-settings').hidden = !canManage;
        el('chat-rename-input').value = group.title || '';
        const wrap = el('chat-current-members');
        clearChildren(wrap);
        const memberIds = state.members.map(function (item) { return Number(item.id); });
        state.members.forEach(function (person) {
            const line = node('div', 'chat-member-line');
            line.appendChild(node('span', '',
                person.name + ' · ' + person.role_name +
                (Number(person.id) === Number(group.created_by) ? ' · Творець' : '')));
            if (canManage && Number(person.id) !== Number(group.created_by)) {
                const remove = node('button', '', 'Вилучити');
                remove.type = 'button';
                remove.addEventListener('click', function () {
                    manageGroup('remove', {member_id: person.id});
                });
                line.appendChild(remove);
            }
            wrap.appendChild(line);
        });
        const select = el('chat-add-user');
        clearChildren(select);
        state.admins.filter(function (admin) {
            return !memberIds.includes(Number(admin.id));
        }).forEach(function (admin) {
            const option = node('option', '', admin.name);
            option.value = admin.id;
            select.appendChild(option);
        });
        el('chat-add-member').disabled = !select.options.length;
    }

    async function manageGroup(action, payload) {
        try {
            await request('api/group/manage', Object.assign({
                conversation_id: state.active, action: action
            }, payload));
            await loadList();
            await loadThread(false);
            renderGroupDialog();
            inform('Групу оновлено.');
        } catch (error) { inform(error.message, true); }
    }

    async function runSearch() {
        const query = searchInput.value.trim();
        renderList();
        if (query.length < 2) {
            el('chat-search-results').hidden = true;
            return;
        }
        try {
            const result = await request('api/search?q=' + encodeURIComponent(query));
            if (query !== searchInput.value.trim()) { return; }
            const wrap = el('chat-search-results');
            clearChildren(wrap);
            const hits = Array.isArray(result.results) ? result.results : [];
            if (!hits.length) { wrap.appendChild(node('p', 'chat-help', 'У повідомленнях нічого не знайдено.')); }
            hits.forEach(function (hit) {
                const button = node('button', 'chat-search-hit');
                button.type = 'button';
                const chat = chatFor(hit.conversation_id);
                button.appendChild(node('strong', '', (chat ? chat.display_name : 'Чат') +
                    ' · ' + (hit.sender_name || '')));
                button.appendChild(node('span', '', hit.body || ''));
                button.addEventListener('click', function () {
                    openConversation(Number(hit.conversation_id), true);
                });
                wrap.appendChild(button);
            });
            wrap.hidden = false;
        } catch (error) { /* Пошук не перериває розмови. */ }
    }

    function recentItems() {
        if (recentEmoji.length) { return recentEmoji; }
        return (emojiData.categories.find(function (x) { return x.id === 'recent'; }) || {items: []}).items;
    }

    function closeEmoji() {
        emojiPanel.hidden = true;
        el('chat-emoji-toggle').setAttribute('aria-expanded', 'false');
    }

    function emojiInsert(symbol) {
        const start = bodyInput.selectionStart === null ? bodyInput.value.length : bodyInput.selectionStart;
        const end = bodyInput.selectionEnd === null ? start : bodyInput.selectionEnd;
        bodyInput.setRangeText(symbol, start, end, 'end');
        bodyInput.dispatchEvent(new Event('input', {bubbles: true}));
        bodyInput.focus();
        recentEmoji = [symbol].concat(recentEmoji.filter(function (x) { return x !== symbol; })).slice(0, 28);
        try { localStorage.setItem('anabelka-chat-recent-emoji', JSON.stringify(recentEmoji)); }
        catch (e) { /* Необов'язково. */ }
        renderEmojiGrid();
    }

    function renderEmojiTabs() {
        const tabs = el('chat-emoji-tabs');
        clearChildren(tabs);
        emojiData.categories.forEach(function (category) {
            const button = node('button', '', category.icon);
            button.type = 'button';
            button.title = category.label;
            button.setAttribute('aria-label', category.label);
            button.setAttribute('aria-pressed', String(currentEmojiCategory === category.id));
            button.addEventListener('click', function () {
                currentEmojiCategory = category.id;
                el('chat-emoji-search').value = '';
                renderEmojiTabs();
                renderEmojiGrid();
            });
            tabs.appendChild(button);
        });
    }

    function renderEmojiGrid() {
        const grid = el('chat-emoji-grid');
        clearChildren(grid);
        const query = el('chat-emoji-search').value.trim().toLocaleLowerCase();
        const categories = query
            ? emojiData.categories.filter(function (x) { return x.id !== 'recent'; })
            : emojiData.categories.filter(function (x) { return x.id === currentEmojiCategory; });
        let found = 0;
        categories.forEach(function (category) {
            const list = category.id === 'recent' ? recentItems() : category.items;
            const matches = list.filter(function (symbol) {
                if (!query) { return true; }
                return symbol.includes(query) ||
                    (String(emojiData.names[symbol] || '') + ' ' +
                        category.label + ' ' + category.keywords)
                        .toLocaleLowerCase().includes(query);
            });
            if (!matches.length) { return; }
            found += matches.length;
            grid.appendChild(node('h4', '', category.label));
            matches.forEach(function (symbol) {
                const button = node('button', '', symbol);
                button.type = 'button';
                button.title = String(emojiData.names[symbol] || symbol).split(' ').slice(0, 3).join(' ');
                button.setAttribute('aria-label', button.title);
                button.addEventListener('click', function () { emojiInsert(symbol); });
                grid.appendChild(button);
            });
        });
        if (!found) { grid.appendChild(node('p', 'chat-help', 'Емодзі не знайдено.')); }
    }

    function openEmoji() {
        if (!state.active) { inform('Спочатку оберіть розмову.', true); return; }
        emojiPanel.hidden = !emojiPanel.hidden;
        el('chat-emoji-toggle').setAttribute('aria-expanded', String(!emojiPanel.hidden));
        if (!emojiPanel.hidden) {
            renderEmojiTabs();
            renderEmojiGrid();
            el('chat-emoji-search').focus();
        }
    }

    // Події: форми, вкладення, контекст повідомлень, системна навігація.
    el('chat-compose').addEventListener('submit', submitMessage);
    bodyInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            el('chat-compose').requestSubmit();
        }
    });
    el('chat-attach').addEventListener('click', function () {
        if (!state.active) { inform('Спочатку оберіть розмову.', true); return; }
        fileInput.click();
    });
    fileInput.addEventListener('change', renderFiles);
    el('chat-reply-cancel').addEventListener('click', clearReply);
    el('chat-new-direct').addEventListener('click', function () {
        if (!state.admins.length) { inform('Інших активних адміністраторів немає.', true); return; }
        fillDirectUsers();
        dialogOpen('chat-direct-dialog');
    });
    el('chat-new-group').addEventListener('click', function () {
        if (!state.admins.length) { inform('Немає учасників для групи.', true); return; }
        fillGroupUsers();
        dialogOpen('chat-group-dialog');
    });
    el('chat-direct-form').addEventListener('submit', createDirect);
    el('chat-group-form').addEventListener('submit', createGroup);
    el('chat-manage-group').addEventListener('click', function () {
        renderGroupDialog(); dialogOpen('chat-manage-dialog');
    });
    el('chat-rename').addEventListener('click', function () {
        manageGroup('rename', {title: el('chat-rename-input').value});
    });
    el('chat-add-member').addEventListener('click', function () {
        manageGroup('add', {member_id: el('chat-add-user').value});
    });
    el('chat-delete-confirm').addEventListener('click', async function () {
        const id = state.deleteId;
        if (!id) { return; }
        try {
            await request('api/delete', {message_id: id});
            dialogClose('chat-delete-dialog');
            state.deleteId = 0;
            state.signature = '';
            await loadThread(false);
        } catch (error) { inform(error.message, true); }
    });
    el('chat-mobile-back').addEventListener('click', function () {
        if (history.state && history.state.adminChat) { history.back(); }
        else { toList(); }
    });
    searchInput.addEventListener('input', function () {
        window.clearTimeout(searchDelay);
        renderList();
        searchDelay = window.setTimeout(runSearch, 300);
    });
    el('chat-emoji-toggle').addEventListener('click', openEmoji);
    el('chat-emoji-close').addEventListener('click', closeEmoji);
    el('chat-emoji-search').addEventListener('input', renderEmojiGrid);
    document.addEventListener('pointerdown', function (event) {
        if (!emojiPanel.hidden && !emojiPanel.contains(event.target) &&
            !el('chat-emoji-toggle').contains(event.target)) { closeEmoji(); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') { closeEmoji(); }
    });
    document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
        button.addEventListener('click', function () {
            const dialog = button.closest('dialog');
            if (dialog) { dialogClose(dialog.id); }
        });
    });
    window.addEventListener('popstate', function () {
        const id = Number(new URLSearchParams(location.search).get('c')) || 0;
        if (!id) { toList(); }
        else if (chatFor(id)) { openConversation(id, false); }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refresh(); }
    });

    refresh().then(function () {
        if (state.active && chatFor(state.active)) { openConversation(state.active, false); }
    });
    window.setInterval(refresh, 6500);
}());
