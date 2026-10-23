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
        conversations: [], admins: [], messages: [], members: [], membersLoadedFor: 0,
        membersVersion: 0, conversation: null,
        signature: '', syncing: false, threadLoading: false, replyId: 0,
        lastRead: {}, deleteId: 0, sending: false, searching: false,
        hasMore: false, pageLoading: false, readBusy: false, opened: 0, loadedThrough: 0
    };

    const layout = el('chat-layout');
    const list = el('chat-conversations');
    const thread = el('chat-messages');
    const bodyInput = el('chat-message');
    const fileInput = el('chat-files');
    const searchInput = el('chat-search');
    const emojiPanel = el('chat-emoji-panel');
    const viewedMessages = new Set();
    let readTimer = 0;
    const readObserver = typeof IntersectionObserver === 'function'
        ? new IntersectionObserver(function () { scheduleRead(); }, {root: thread, threshold: [0, .1, 1]})
        : null;
    const emojiData = window.AnabelkaChatEmojiData || {categories: [], names: {}};
    const pictureData = window.AnabelkaChatClassicData || {files: {}, labels: {}, categories: []};
    // Only a server-local folder of authorized PNGs may activate graphic smilies.
    const pictureBase = root.dataset.emojiGraphics || '';
    let pictureAvailable = false;
    // An explicit local manifest enables only icons actually installed.
    const pictureCodes = new Set();
    let pictureCategories = [];
    function activeEmojiCategories() {
        // No graphics? Keep the original, fully functional Unicode picker.
        return pictureAvailable
            ? pictureCategories.concat(emojiData.categories)
            : emojiData.categories;
    }
    function pictureToken(token) {
        if (!pictureAvailable || typeof token !== 'string') { return ''; }
        const match = /^\(([A-Za-z0-9_$!]{1,40})\)$/.exec(token);
        return match && pictureCodes.has(match[1]) ? match[1] : '';
    }
    function pictureUrl(code) {
        const filename = pictureData.files[code];
        // Literal names from our static allowlist only, never message input as a path.
        if (!filename || !/^[A-Za-z0-9_-]{1,50}\.png$/.test(filename)) {
            return '';
        }
        return pictureBase + '/' + filename;
    }
    function pictureImage(code, className) {
        const url = pictureUrl(code);
        if (!url) { return null; }
        const img = node('img', className || 'chat-emoji-art');
        img.src = url;
        img.alt = pictureData.labels[code] || code;
        img.loading = 'lazy';
        img.decoding = 'async';
        img.width = 32;
        img.height = 32;
        img.addEventListener('error', function () {
            img.replaceWith(document.createTextNode('(' + code + ')'));
        }, {once: true});
        return img;
    }
    function appendFormattedMessage(container, text) {
        text = String(text || '');
        if (!pictureAvailable) {
            container.textContent = text;
            return;
        }
        const pattern = /\(([A-Za-z0-9_$!]{1,40})\)/g;
        let found, pos = 0;
        while ((found = pattern.exec(text)) !== null) {
            const code = found[1], pic = pictureCodes.has(code) ? pictureImage(code, 'chat-message-emoji') : null;
            if (!pic) { continue; }
            container.appendChild(document.createTextNode(text.slice(pos, found.index)));
            container.appendChild(pic);
            pos = pattern.lastIndex;
        }
        container.appendChild(document.createTextNode(text.slice(pos)));
    }
    function activatePictureEmoji() {
        if (!pictureBase || !pictureData.files.smiley) { return; }
        // Only use an explicitly installed, authorized, local image pack.
        // The manifest prevents a single PNG from enabling 212 broken icons.
        fetch(pictureBase + '/manifest.json', {
            credentials: 'same-origin', cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('No local graphic pack'); }
                return response.json();
            })
            .then(function (manifest) {
                if (!manifest || manifest.version !== 1 || !Array.isArray(manifest.codes)) {
                    return;
                }
                const installed = Array.from(new Set(manifest.codes))
                    .filter(function (code) {
                        return typeof code === 'string' &&
                            Object.prototype.hasOwnProperty.call(pictureData.files, code);
                    });
                if (!installed.length) { return; }
                // Probe a real image before switching the visible emoji set.
                const probe = new Image();
                probe.onload = function () {
                    if (probe.naturalWidth < 8) { return; }
                    installed.forEach(function (code) { pictureCodes.add(code); });
                    pictureCategories = pictureData.categories.map(function (cat) {
                        return {
                            id: cat.id, label: cat.label, icon: cat.icon,
                            keywords: cat.keywords,
                            items: cat.codes.filter(function (code) {
                                return pictureCodes.has(code);
                            }).map(function (code) { return '(' + code + ')'; }),
                            isPictureSet: true
                        };
                    }).filter(function (cat) { return cat.items.length > 0; });
                    pictureAvailable = true;
                    currentEmojiCategory = 'classic';
                    state.signature = '';
                    if (state.active && state.messages.length) { renderThread(false); }
                    if (!emojiPanel.hidden) { renderEmojiTabs(); renderEmojiGrid(); }
                };
                probe.onerror = function () { /* Unicode emoji fallback. */ };
                probe.src = pictureUrl(installed[0]);
            })
            .catch(function () { /* No graphics: Unicode still works. */ });
    }

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

    function participantCount(conversation) {
        if (!conversation || conversation.kind !== 'group') { return null; }
        if (Number(conversation.id) === state.active &&
            state.membersLoadedFor === state.active && state.members.length > 0) {
            return state.members.length;
        }
        const value = conversation.member_count;
        if ((typeof value !== 'number' && typeof value !== 'string') ||
            !/^\d+$/.test(String(value))) { return null; }
        const count = Number(value);
        return Number.isSafeInteger(count) && count > 0 ? count : null;
    }

    function brandedAvatar(conversation) {
        const group = Boolean(conversation && conversation.kind === 'group');
        const count = participantCount(conversation);
        const avatar = node('span', 'chat-avatar-icon');
        avatar.setAttribute('role', 'img');
        avatar.setAttribute('aria-label', group
            ? 'Груповий чат · ' + (count === null ? 'учасники завантажуються' : 'учасників: ' + count)
            : 'Особиста розмова');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 40 40');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2.2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        const transforms = group ? ['translate(1 1) scale(1.2)']
            : ['translate(-1 -1) scale(.83)', 'translate(12 11) scale(.83)'];
        transforms.forEach(function (transform) {
            const heart = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            heart.setAttribute('d', 'M16 27.5C13 24.9 4.5 19.2 4.5 12.3C4.5 5.6 12.5 3.7 16 9C19.5 3.7 27.5 5.6 27.5 12.3C27.5 19.2 19 24.9 16 27.5Z');
            heart.setAttribute('transform', transform);
            svg.appendChild(heart);
        });
        avatar.appendChild(svg);
        if (group && count !== null) {
            const badge = node('span', 'chat-member-count' + (count > 99 ? ' is-wide' : ''),
                count > 99 ? '99+' : count);
            badge.setAttribute('aria-hidden', 'true');
            avatar.appendChild(badge);
        }
        return avatar;
    }

    function updateConversationHeader() {
        const conversation = chatFor(state.active);
        const count = participantCount(conversation);
        el('chat-thread-title').textContent = conversation
            ? (conversation.display_name || 'Особиста розмова') : 'Оберіть розмову';
        el('chat-thread-avatar').replaceChildren(brandedAvatar(conversation));
        el('chat-thread-subtitle').textContent = conversation
            ? (conversation.kind === 'group'
                ? 'Груповий чат' + (count === null ? '' : ' · ' + count + ' учасників')
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
            const avatar = node('span', 'chat-avatar');
            avatar.appendChild(brandedAvatar(chat));
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
        // The new list count is current while the open thread's members reload.
        state.membersLoadedFor = 0;
        state.membersVersion++;
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
        state.opened++;
        viewedMessages.clear();
        state.hasMore = false;
        state.loadedThrough = 0;
        state.signature = '';
        state.messages = [];
        state.members = [];
        state.membersLoadedFor = 0;
        state.membersVersion++;
        clearChildren(thread);
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
        state.active = 0;
        state.opened++;
        viewedMessages.clear();
        if (readObserver) { readObserver.disconnect(); }
        state.conversation = null;
        state.members = [];
        state.membersLoadedFor = 0;
        clearReply();
        layout.classList.remove('has-open-thread');
        closeEmoji();
        const url = new URL(location.href);
        url.searchParams.delete('c');
        history.replaceState(history.state, '', url.pathname + url.search);
    }

    /**
     * Standalone 1-3 emoji are displayed as large stickers without bubbles.
     * Both installed graphic codes and standard Unicode grapheme emoji work.
     * Plain text, unavailable graphic codes and attachments keep regular bubbles.
     */
    function standaloneEmojiTokens(text) {
        const value = String(text || '').trim();
        if (!value) { return []; }
        const segmenter = typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function'
            ? new Intl.Segmenter(undefined, {granularity: 'grapheme'})
            : null;
        const result = [];
        let cursor = 0;
        while (cursor < value.length) {
            while (cursor < value.length && /\s/u.test(value[cursor])) {
                cursor++;
            }
            if (cursor >= value.length) { break; }
            if (result.length >= 3) { return []; }

            const remainder = value.slice(cursor);
            const graphic = /^\(([A-Za-z0-9_$!]{1,40})\)/.exec(remainder);
            if (graphic) {
                const code = pictureToken(graphic[0]);
                if (!code) { return []; }
                result.push({type: 'graphic', value: code});
                cursor += graphic[0].length;
                continue;
            }

            // Without proper grapheme support, keep Unicode in the normal bubble
            // rather than splitting skin tones, flags or joined emoji.
            if (!segmenter) { return []; }
            const first = segmenter.segment(remainder)[Symbol.iterator]().next().value;
            const cluster = first && first.segment || '';
            if (
                !cluster ||
                !/[\p{Extended_Pictographic}\p{Regional_Indicator}\u20e3]/u.test(cluster)
            ) {
                return [];
            }
            result.push({type: 'unicode', value: cluster});
            cursor += cluster.length;
        }
        return result;
    }


    function renderReadStatus(entry) {
        if (Number(entry.sender_id) !== adminId || entry.deleted_at || !entry.read_status) { return null; }
        const count = Math.max(0, Number(entry.read_status.read_count) || 0);
        const total = Math.max(0, Number(entry.read_status.recipient_count) || 0);
        const seen = total > 0 && count === total && entry.read_status.seen === true;
        const partial = state.conversation && state.conversation.kind === 'group' && count > 0 && count < total;
        const label = (seen ? 'Переглянуто' : 'Відправлено') +
            (partial ? ' · Переглянули: ' + count + ' із ' + total : '');
        const status = node('span', 'chat-read-status' + (seen ? ' is-seen' : ''));
        status.setAttribute('title', label);
        status.setAttribute('aria-label', label);
        const checks = node('span', 'chat-read-checks', seen ? '✓✓' : '✓');
        checks.setAttribute('aria-hidden', 'true');
        status.appendChild(checks);
        if (partial) { status.appendChild(node('span', 'chat-read-count', 'Переглянули: ' + count + ' із ' + total)); }
        return status;
    }

    function renderMessage(entry) {
        const own = Number(entry.sender_id) === adminId;
        const deleted = Boolean(entry.deleted_at);
        const row = node('div', 'chat-message-row' + (own ? ' is-mine' : ''));
        row.dataset.messageId = entry.id;
        const attachments = Array.isArray(entry.attachments) ? entry.attachments : [];
        const loneEmojis = !deleted && !attachments.length
            ? standaloneEmojiTokens(entry.body || '')
            : [];
        if (loneEmojis.length) {
            row.classList.add('is-emoji-only', 'is-emoji-count-' + loneEmojis.length);
        }
        const bubble = node('article', 'chat-bubble');
        if (!own) { bubble.appendChild(node('span', 'chat-sender', entry.sender_name || 'Адміністратор')); }
        if (entry.reply_to_id && !deleted) {
            const quoted = node('div', 'chat-quoted');
            quoted.appendChild(node('strong', '', entry.reply_sender || 'Повідомлення'));
            quoted.appendChild(node('span', '', entry.reply_body || 'Повідомлення видалено'));
            bubble.appendChild(quoted);
        }
        const messageBody = node('p', 'chat-message-body');
        if (deleted) {
            messageBody.textContent = 'Повідомлення видалено';
        } else if (loneEmojis.length) {
            loneEmojis.forEach(function (emoji) {
                if (emoji.type === 'graphic') {
                    const img = pictureImage(emoji.value, 'chat-message-emoji');
                    messageBody.appendChild(img || document.createTextNode('(' + emoji.value + ')'));
                } else {
                    messageBody.appendChild(node('span', 'chat-message-native-emoji', emoji.value));
                }
            });
        } else {
            appendFormattedMessage(messageBody, entry.body || '');
        }
        bubble.appendChild(messageBody);
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
        const status = renderReadStatus(entry);
        if (status) { meta.appendChild(status); }
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
        observeMessages();
    }

    function observeMessages() {
        if (readObserver) {
            readObserver.disconnect();
            thread.querySelectorAll('.chat-message-row').forEach(function (row) { readObserver.observe(row); });
        }
        scheduleRead();
    }

    function canViewThread() {
        return Boolean(state.active && !document.hidden && document.hasFocus() &&
            layout.classList.contains('has-open-thread') &&
            !document.querySelector('dialog[open]') && el('chat-photo-viewer').hidden && emojiPanel.hidden);
    }

    function scheduleRead() {
        window.clearTimeout(readTimer);
        readTimer = window.setTimeout(function () { markVisibleRead(); }, 250);
    }

    async function markVisibleRead() {
        if (!canViewThread()) { return; }
        const box = thread.getBoundingClientRect();
        const top = Math.max(0, box.top), bottom = Math.min(window.innerHeight, box.bottom);
        const left = Math.max(0, box.left), right = Math.min(window.innerWidth, box.right);
        if (bottom <= top || right <= left) { return; }
        thread.querySelectorAll('.chat-message-row:not(.is-mine)').forEach(function (row) {
            // Inspect content, not a footer/action that may peek into the viewport.
            const visible = Array.from(row.querySelectorAll('.chat-message-body, .chat-file-card')).some(function (content) {
                const rect = content.getBoundingClientRect();
                return rect.width > 0 && rect.height > 0 &&
                    Math.min(right, rect.right) > Math.max(left, rect.left) &&
                    Math.min(bottom, rect.bottom) - Math.max(top, rect.top) >= Math.min(24, rect.height);
            });
            if (visible) { viewedMessages.add(Number(row.dataset.messageId)); }
        });
        if (state.readBusy) { return; }
        const id = state.active, opened = state.opened;
        let mark = state.lastRead[id] || 0;
        for (const message of state.messages) {
            const messageId = Number(message.id);
            if (messageId <= mark) { continue; }
            // A newly sent preview can sit beyond an unloaded unread page.
            if (messageId > state.loadedThrough) { break; }
            if (Number(message.sender_id) !== adminId && !message.deleted_at && !viewedMessages.has(messageId)) { break; }
            mark = messageId;
        }
        if (mark <= (state.lastRead[id] || 0) || !canViewThread()) { return; }
        state.readBusy = true;
        let retry = false;
        try {
            const result = await request('api/read', {conversation_id: id, message_id: mark});
            state.lastRead[id] = Math.max(state.lastRead[id] || 0, mark, Number(result.viewer_read_message_id) || 0);
            if (id === state.active && opened === state.opened) { await loadList(); }
        } catch (error) {
            retry = true; // Keep observed ids; advance the local watermark only on success.
        } finally {
            state.readBusy = false;
            if (id === state.active && opened === state.opened) {
                window.clearTimeout(readTimer);
                readTimer = window.setTimeout(function () { markVisibleRead(); }, retry ? 1500 : 250);
            }
        }
    }

    function mergeThread(data, forceBottom) {
        const oldContent = JSON.stringify(state.messages.map(function (message) {
            const copy = Object.assign({}, message); delete copy.read_status; return copy;
        }));
        const messages = new Map(state.messages.map(function (message) { return [Number(message.id), message]; }));
        (data.messages || []).forEach(function (message) { messages.set(Number(message.id), message); });
        (data.message_updates || []).forEach(function (update) {
            const message = messages.get(Number(update.id));
            if (message) {
                message.body = update.deleted_at ? '' : String(update.body || '');
                message.edited_at = update.edited_at;
                message.deleted_at = update.deleted_at;
                if (update.deleted_at) {
                    message.attachments = [];
                    message.reply_body = '';
                    delete message.read_status;
                }
            }
            messages.forEach(function (quoted) {
                if (Number(quoted.reply_to_id) === Number(update.id)) {
                    quoted.reply_body = update.deleted_at ? '' : String(update.body || '');
                }
            });
        });
        (data.read_statuses || []).forEach(function (receipt) {
            const message = messages.get(Number(receipt.id));
            if (message && !message.deleted_at && Number(message.sender_id) === adminId) { message.read_status = receipt.read_status; }
        });
        state.messages = Array.from(messages.values()).sort(function (a, b) { return Number(a.id) - Number(b.id); });
        const content = JSON.stringify(state.messages.map(function (message) {
            const copy = Object.assign({}, message); delete copy.read_status; return copy;
        }));
        if (oldContent !== content || !state.signature) {
            renderThread(Boolean(forceBottom));
        } else {
            // Receipt polling must preserve edits, loaded images, photo viewer and scroll.
            state.messages.forEach(function (message) {
                const row = thread.querySelector('[data-message-id="' + Number(message.id) + '"]');
                const meta = row && row.querySelector('.chat-message-meta');
                if (!meta) { return; }
                const previous = meta.querySelector('.chat-read-status');
                if (previous) { previous.remove(); }
                const status = renderReadStatus(message);
                if (status) { meta.appendChild(status); }
            });
        }
        state.signature = JSON.stringify(state.messages);
    }

    async function loadMoreMessages() {
        if (!state.hasMore || state.pageLoading || state.threadLoading || !canViewThread() || !state.messages.length) { return; }
        // A sent preview beyond a gap is not the end of the contiguous page.
        // Load only when the reader reaches that page's actual boundary.
        const boundary = thread.querySelector('[data-message-id="' + state.loadedThrough + '"]');
        if (!boundary) { return; }
        const box = thread.getBoundingClientRect(), edge = boundary.getBoundingClientRect();
        if (edge.bottom > box.bottom + 90 || edge.bottom < box.top) { return; }
        const id = state.active, opened = state.opened;
        const after = state.loadedThrough;
        state.pageLoading = true;
        try {
            const data = await request('api/messages?conversation_id=' + id + '&after=' + after);
            if (id !== state.active || opened !== state.opened) { return; }
            state.hasMore = Boolean(data.has_more);
            const rows = Array.isArray(data.messages) ? data.messages : [];
            if (rows.length) { state.loadedThrough = Math.max(state.loadedThrough, Number(rows[rows.length - 1].id)); }
            const previous = thread.scrollTop;
            const viewport = thread.getBoundingClientRect();
            const anchor = Array.from(thread.querySelectorAll('.chat-message-row')).filter(function (row) {
                const rect = row.getBoundingClientRect();
                return rect.top < viewport.bottom && rect.bottom > viewport.top;
            }).pop();
            const anchorTop = anchor ? anchor.getBoundingClientRect().top - viewport.top : 0;
            const anchorId = anchor && anchor.dataset.messageId;
            mergeThread(data, false);
            thread.scrollTop = previous;
            const retained = anchorId && thread.querySelector('[data-message-id="' + Number(anchorId) + '"]');
            if (retained) {
                thread.scrollTop += retained.getBoundingClientRect().top - thread.getBoundingClientRect().top - anchorTop;
            }
            scheduleRead();
        } catch (error) { /* The next scroll/poll retries a page without acknowledging it. */ }
        finally { state.pageLoading = false; }
    }

    async function loadThread(forceBottom) {
        if (!state.active || state.threadLoading) { return; }
        const conversationId = state.active;
        const opened = state.opened;
        const membersVersion = state.membersVersion;
        state.threadLoading = true;
        try {
            const firstId = state.messages.length ? Number(state.messages[0].id) : 0;
            const data = await request('api/messages?conversation_id=' + conversationId + '&receipt_from=' + firstId);
            if (conversationId !== state.active || opened !== state.opened) { return; }
            const initial = !state.signature;
            state.lastRead[conversationId] = Math.max(state.lastRead[conversationId] || 0, Number(data.viewer_read_message_id) || 0);
            state.conversation = data.conversation || null;
            const conversation = chatFor(conversationId);
            // A list refresh may already reflect an add/remove completed after
            // this request started. Keep that count until fresh members arrive.
            if (membersVersion === state.membersVersion) {
                state.members = Array.isArray(data.members) ? data.members : [];
                state.membersLoadedFor = state.members.length > 0 ? conversationId : 0;
                if (conversation && conversation.kind === 'group' && state.membersLoadedFor) {
                    conversation.member_count = state.members.length;
                }
            }
            const incoming = Array.isArray(data.messages) ? data.messages : [];
            if (incoming.length) {
                state.loadedThrough = Math.max(state.loadedThrough, Number(incoming[incoming.length - 1].id));
            }
            state.hasMore = Boolean(data.has_more);
            const firstUnread = incoming.find(function (message) {
                return Number(message.id) > state.lastRead[conversationId] && Number(message.sender_id) !== adminId && !message.deleted_at;
            });
            mergeThread(data, forceBottom && !(initial && firstUnread));
            if (initial && firstUnread) {
                const row = thread.querySelector('[data-message-id="' + Number(firstUnread.id) + '"]');
                if (row) { thread.scrollTop += row.getBoundingClientRect().top - thread.getBoundingClientRect().top; }
            }
            renderList();
            updateConversationHeader();
            scheduleRead();
        } finally {
            state.threadLoading = false;
            if (membersVersion !== state.membersVersion && state.active) {
                await loadThread(false);
            }
            loadMoreMessages();
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
        const conversationId = state.active, opened = state.opened;
        data.append('conversation_id', String(conversationId));
        data.append('body', text);
        data.append('reply_to_id', String(state.replyId));
        files.forEach(function (file) { data.append('files[]', file); });
        state.sending = true;
        el('chat-send').disabled = true;
        try {
            const sent = await request('api/send', data);
            if (conversationId !== state.active || opened !== state.opened) { await loadList(); return; }
            bodyInput.value = '';
            fileInput.value = '';
            renderFiles();
            clearReply();
            closeEmoji();
            await loadList();
            await loadThread(true);
            if (conversationId === state.active && opened === state.opened && Number(sent.message_id) > 0 &&
                !state.messages.some(function (message) { return Number(message.id) === Number(sent.message_id); })) {
                // Show a successful send immediately without claiming that the
                // missing pages between it and loadedThrough were viewed.
                const latest = await request('api/messages?conversation_id=' + conversationId +
                    '&after=' + (Number(sent.message_id) - 1));
                if (conversationId === state.active && opened === state.opened) { mergeThread(latest, true); }
            }
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
        activeEmojiCategories().forEach(function (category) {
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
            ? activeEmojiCategories().filter(function (x) { return x.id !== 'recent' && x.id !== 'classic'; })
            : activeEmojiCategories().filter(function (x) { return x.id === currentEmojiCategory; });
        let found = 0;
        categories.forEach(function (category) {
            const items = category.id === 'recent' ? recentItems() : category.items;
            const matches = items.filter(function (token) {
                if (!query) { return true; }
                const codeMatch = typeof token === 'string' && /^\(([A-Za-z0-9_$!]{1,40})\)$/.exec(token);
                const descriptor = codeMatch
                    ? (pictureData.labels[codeMatch[1]] || '') + ' ' + codeMatch[1]
                    : String(emojiData.names[token] || '');
                return String(token).toLocaleLowerCase().includes(query) ||
                    (descriptor + ' ' + category.label + ' ' + category.keywords)
                        .toLocaleLowerCase().includes(query);
            });
            if (!matches.length) { return; }
            found += matches.length;
            grid.appendChild(node('h4', '', category.label));
            matches.forEach(function (token) {
                const code = pictureToken(token);
                const label = code
                    ? (pictureData.labels[code] || code)
                    : String(emojiData.names[token] || token).split(' ').slice(0, 3).join(' ');
                const button = node('button');
                button.type = 'button';
                button.title = label;
                button.setAttribute('aria-label', label);
                if (code) {
                    const img = pictureImage(code, 'chat-emoji-art');
                    if (img) { button.appendChild(img); }
                    else { button.textContent = token; }
                } else {
                    button.textContent = token;
                }
                button.addEventListener('click', function () { emojiInsert(token); });
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
        // A photo overlay owns the browser Back event before thread navigation.
        if (window.AnabelkaChatPhotoViewer &&
            window.AnabelkaChatPhotoViewer.consumePopState()) { return; }
        const id = Number(new URLSearchParams(location.search).get('c')) || 0;
        if (!id) { toList(); }
        else if (chatFor(id)) { openConversation(id, false); }
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) { refresh(); scheduleRead(); }
    });
    window.addEventListener('focus', scheduleRead);
    window.addEventListener('resize', scheduleRead);
    window.addEventListener('scroll', scheduleRead, {passive: true});
    thread.addEventListener('scroll', function () { scheduleRead(); loadMoreMessages(); }, {passive: true});
    thread.addEventListener('load', scheduleRead, true);
    if (typeof ResizeObserver === 'function') { new ResizeObserver(scheduleRead).observe(thread); }
    if (typeof MutationObserver === 'function') {
        new MutationObserver(scheduleRead).observe(root.parentNode, {subtree: true, attributes: true, attributeFilter: ['hidden', 'open']});
    }

    updateConversationHeader();
    refresh().then(function () {
        if (state.active && chatFor(state.active)) { openConversation(state.active, false); }
    });
    window.setInterval(refresh, 6500);
    activatePictureEmoji();
}());
