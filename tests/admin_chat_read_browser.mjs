import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fixtureServer, launchBrowser, repo} from './helpers/notify-browser.mjs';

// Exercise the shipped document, CSS, chat script and photo viewer. Only identity,
// API transport and tab lifecycle signals are fixtures; geometry/scroll/IO are real.
const markup = fs.readFileSync(path.join(repo, 'views/admin/chat/index.php'), 'utf8')
    .replace(/<\?php[^]*?\?>/g, '').replace(/<\?=[^]*?\?>/g, '1');
const image = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="#8a2be2"/></svg>';
const message = (id, own = true, extra = {}) => ({id, sender_id: own ? 1 : 2,
    sender_name: own ? 'Я' : 'Олена', body: `Повідомлення ${id}`,
    created_at: '2026-10-23 12:15:00', attachments: [],
    ...(own ? {read_status: {read_count: 0, recipient_count: 1, seen: false}} : {}), ...extra});
const row = (page, id) => page.locator(`.chat-message-row[data-message-id="${id}"]`);
const status = (page, id) => row(page, id).locator('.chat-read-status');

async function until(predicate, label, page) {
    const deadline = Date.now() + 4000;
    while (!(await predicate())) {
        assert.ok(Date.now() < deadline, label);
        if (page) { await page.clock.runFor(100); }
        await new Promise(resolve => setTimeout(resolve, 15));
    }
}

async function fixture(t, browser, server, options = {}) {
    const context = await browser.newContext({viewport: options.viewport || {width: 1200, height: 900}});
    t.after(() => context.close());
    const page = await context.newPage();
    page.setDefaultTimeout(4000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(() => assert.deepEqual(errors, [], 'The shipped browser scripts must not throw.'));
    await page.clock.install();
    // Headless Chromium has no OS tab switcher. Drive the same native browser
    // signals deterministically without replacing rendering or application code.
    await page.addInitScript(() => {
        window.__chatTabHidden = false;
        window.__chatTabFocused = true;
        Object.defineProperties(document, {
            hidden: {configurable: true, get: () => window.__chatTabHidden},
            visibilityState: {configurable: true, get: () => window.__chatTabHidden ? 'hidden' : 'visible'},
            hasFocus: {configurable: true, value: () => window.__chatTabFocused}
        });
    });
    const state = {messages: options.messages || [], kind: options.kind || 'direct',
        memberCount: options.memberCount || 2, cursor: options.cursor || 0,
        startsAtUnread: options.startsAtUnread ?? false, hasMore: false,
        statuses: [], histories: 0, reads: [], failReads: 0, pages: [], pageSize: options.pageSize || 0};
    const conversation = () => ({id: 10, kind: state.kind, display_name: 'Тестова бесіда',
        member_count: state.memberCount, unread: 0});
    await page.route('**/Anabelka/assets/chat-emoji/graphics/**', route => {
        if (route.request().url().endsWith('/manifest.json')) {
            return route.fulfill({json: {version: 1, codes: ['smiley']}});
        }
        return route.fulfill({contentType: 'image/svg+xml', body: image});
    });
    await page.route('**/Anabelka/admin/chat/file?**', route =>
        route.fulfill({contentType: 'image/svg+xml', body: image}));
    await page.route('**/Anabelka/admin/chat/api/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.endsWith('/list')) {
            return route.fulfill({json: {success: true, conversations: [conversation()], admins: [], unread_total: 0}});
        }
        if (url.pathname.endsWith('/send')) {
            const id = Math.max(0, ...state.messages.map(message => Number(message.id))) + 1;
            state.messages.push(message(id));
            return route.fulfill({json: {success: true, message_id: id}});
        }
        if (url.pathname.endsWith('/read')) {
            const body = route.request().postData() || '';
            const target = Number(/name="message_id"\r\n\r\n(\d+)/.exec(body)?.[1]);
            const failed = state.failReads-- > 0;
            state.reads.push({id: target, failed});
            if (!failed) { state.cursor = Math.max(state.cursor, target); }
            return route.fulfill({status: failed ? 503 : 200,
                json: failed ? {success: false, message: 'Тимчасова помилка'} : {success: true, viewer_read_message_id: state.cursor}});
        }
        if (url.pathname.endsWith('/messages')) {
            state.histories++;
            const after = Number(url.searchParams.get('after') || 0);
            const remaining = state.messages.filter(message => Number(message.id) > after);
            const messages = state.pageSize ? remaining.slice(0, state.pageSize) : state.messages;
            state.pages.push({after, ids: messages.map(message => message.id)});
            return route.fulfill({json: {success: true, messages,
                read_statuses: state.statuses, viewer_read_message_id: state.cursor,
                starts_at_unread: state.startsAtUnread, has_more: state.pageSize ? remaining.length > state.pageSize : state.hasMore,
                conversation: conversation(), members: Array.from({length: state.memberCount}, (_, id) => ({id: id + 1}))}});
        }
        return route.fulfill({json: {success: true}});
    });
    await page.goto(server.origin + '/Anabelka/admin/chat');
    await page.locator('.chat-conversation').waitFor();
    return {page, state,
        async open() {
            await page.locator('.chat-conversation').click();
            await until(() => state.histories > 0, 'Opening a thread must fetch its history.');
            if (state.messages.length) { await row(page, state.messages[0].id).waitFor(); }
        },
        async tick(ms = 600) { await page.clock.runFor(ms); },
        async poll() {
            const response = page.waitForResponse(response => /\/api\/messages\?/.test(response.url()));
            await page.clock.runFor(6700);
            await (await response).finished();
            await page.evaluate(() => Promise.resolve());
            await new Promise(resolve => setTimeout(resolve, 15));
            await page.clock.runFor(600);
        }};
}

async function tabState(page, {hidden = false, focused = true} = {}) {
    await page.evaluate(({hidden, focused}) => {
        window.__chatTabHidden = hidden;
        window.__chatTabFocused = focused;
        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event(focused ? 'focus' : 'blur'));
    }, {hidden, focused});
}

async function expectStatus(page, id, seen, count = '') {
    await page.waitForFunction(({id, seen, count}) => {
        const value = document.querySelector(`.chat-message-row[data-message-id="${id}"] .chat-read-status`);
        return value && value.classList.contains('is-seen') === seen &&
            (value.querySelector('.chat-read-count')?.textContent || '') === count;
    }, {id, seen, count});
    const value = await status(page, id).evaluate(node => ({
        checks: node.querySelector('.chat-read-checks')?.textContent,
        color: getComputedStyle(node.querySelector('.chat-read-checks') || node).color,
        accessible: node.getAttribute('aria-label'), title: node.title
    }));
    assert.equal(value.checks, seen ? '✓✓' : '✓');
    assert.equal(value.color, seen ? 'rgb(138, 43, 226)' : 'rgb(134, 121, 143)');
    assert.ok(value.accessible?.includes(seen ? 'Переглянуто' : 'Відправлено'));
    assert.ok(value.title.includes(seen ? 'Переглянуто' : 'Відправлено'));
}

test('CH-UI-05 read receipts in the real browser', async t => {
    const server = await fixtureServer(async () => markup);
    t.after(() => server.close());
    const browser = await launchBrowser();
    t.after(() => browser.close());

    await t.test('direct outgoing receipts update in place across all existing body types', async t => {
        const fx = await fixture(t, browser, server, {cursor: 6, messages: [
            message(1), message(2, false, {read_status: {read_count: 1, recipient_count: 1, seen: true}}),
            message(3, true, {body: '😀'}), message(4, true, {body: '(smiley)'}),
            message(5, true, {body: '', attachments: [
                {id: 50, mime_type: 'image/png', original_name: 'Перше.png'},
                {id: 51, mime_type: 'image/png', original_name: 'Друге.png'}]}),
            message(6, true, {body: '', attachments: [
                {id: 60, mime_type: 'application/pdf', original_name: 'Документ.pdf', byte_size: 2048}]})
        ]});
        await fx.open();
        await row(fx.page, 4).locator('img.chat-message-emoji').waitFor();
        for (const id of [1, 3, 4, 5, 6]) { await expectStatus(fx.page, id, false); }
        assert.equal(await row(fx.page, 2).locator('.chat-read-status').count(), 0,
            'An incoming message must never trust a forged outgoing read_status.');
        assert.equal(await row(fx.page, 3).locator('.chat-message-native-emoji').textContent(), '😀');
        assert.equal(await row(fx.page, 4).locator('.chat-message-emoji').count(), 1);
        assert.equal(await row(fx.page, 6).locator('a.chat-file-card').textContent(), '📎 Документ.pdf · 2 КБ');
        assert.equal(await row(fx.page, 6).locator('a.chat-file-card').getAttribute('target'), '_blank');
        await row(fx.page, 5).locator('.chat-image-card').first().click();
        await fx.page.locator('#chat-photo-next').click();
        await fx.page.locator('#chat-photo-zoom-in').click();
        const before = await fx.page.evaluate(() => {
            window.__receiptRows = Array.from(document.querySelectorAll('.chat-message-row'));
            window.__receiptImages = Array.from(document.querySelectorAll('.chat-message-row img'));
            return {scroll: document.getElementById('chat-messages').scrollTop,
                zoom: document.getElementById('chat-photo-zoom-label').textContent};
        });
        const visits = server.visits();
        fx.state.statuses = [1, 3, 4, 5, 6].map(id => ({id,
            read_status: {read_count: 1, recipient_count: 1, seen: true}}));
        await fx.poll();
        for (const id of [1, 3, 4, 5, 6]) { await expectStatus(fx.page, id, true); }
        assert.deepEqual(await fx.page.evaluate(() => ({
            sameRows: window.__receiptRows.every((node, i) => node === document.querySelectorAll('.chat-message-row')[i]),
            sameImages: window.__receiptImages.every((node, i) => node === document.querySelectorAll('.chat-message-row img')[i]),
            scroll: document.getElementById('chat-messages').scrollTop,
            counter: document.getElementById('chat-photo-counter').textContent,
            zoom: document.getElementById('chat-photo-zoom-label').textContent,
            selected: document.getElementById('chat-photo-save').download
        })), {sameRows: true, sameImages: true, scroll: before.scroll,
            counter: '2 / 2', zoom: before.zoom, selected: 'Друге.png'});
        assert.equal(server.visits(), visits, 'Receipt polling must not reload the document.');
        assert.deepEqual(fx.state.reads, [], 'Viewing already acknowledged history must not POST again.');
    });

    await t.test('groups show zero, partial and complete counts and use current membership', async t => {
        const fx = await fixture(t, browser, server, {kind: 'group', memberCount: 4, cursor: 1,
            messages: [message(1, true, {read_status: {read_count: 0, recipient_count: 3, seen: false}})]});
        await fx.open();
        await expectStatus(fx.page, 1, false);
        for (const [read, recipients, seen, members, text] of [
            [1, 3, false, 4, 'Переглянули: 1 із 3'],
            [3, 3, true, 4, ''], [2, 2, true, 3, ''],
            [2, 4, false, 5, 'Переглянули: 2 із 4'],
            // A singleton group has nobody else to read: never show two checks.
            [0, 0, true, 1, '']
        ]) {
            fx.state.memberCount = members;
            fx.state.statuses = [{id: 1, read_status: {read_count: read, recipient_count: recipients, seen}}];
            await fx.poll();
            await expectStatus(fx.page, 1, recipients > 0 && seen, text);
        }
    });

    await t.test('fetching history and loading a hidden thread cannot mark it read', async t => {
        const fx = await fixture(t, browser, server, {messages: [message(1, false)]});
        await fx.page.evaluate(() => fetch('/Anabelka/admin/chat/api/messages?conversation_id=10'));
        await fx.tick();
        assert.deepEqual(fx.state.reads, [], 'GET history is not an acknowledgement.');
        await tabState(fx.page, {hidden: true});
        await fx.open();
        await fx.tick();
        assert.deepEqual(fx.state.reads, [], 'A hidden document cannot acknowledge rendered history.');
        await tabState(fx.page);
        await fx.tick();
        await until(() => fx.state.reads.some(read => read.id === 1 && !read.failed),
            'Returning to a visible focused thread must acknowledge its visible message.', fx.page);
    });

    await t.test('offscreen incoming messages wait for actual scrolling into view', async t => {
        const fx = await fixture(t, browser, server, {startsAtUnread: true,
            messages: Array.from({length: 30}, (_, i) => message(i + 1, false))});
        await fx.open();
        await fx.tick();
        await until(() => fx.state.reads.length > 0, 'Visible incoming messages must be acknowledged.', fx.page);
        assert.ok(fx.state.reads.every(read => read.id > 0 && read.id < 30),
            'Loading thirty messages must not acknowledge the offscreen end of the history.');
        assert.equal(await row(fx.page, 30).evaluate(node => {
            const box = document.getElementById('chat-messages').getBoundingClientRect();
            return node.getBoundingClientRect().top >= box.bottom;
        }), true, 'The last message is genuinely outside the thread viewport.');
        for (let step = 0; step < 25; step++) {
            const bottom = await fx.page.locator('#chat-messages').evaluate(node => {
                node.scrollTop += node.clientHeight * .6;
                node.dispatchEvent(new Event('scroll'));
                return node.scrollTop + node.clientHeight >= node.scrollHeight - 1;
            });
            await fx.tick(400);
            if (bottom) { break; }
        }
        await until(() => fx.state.reads.some(read => read.id === 30 && !read.failed),
            'Scrolling all intervening messages into view must eventually acknowledge the last.', fx.page);
        assert.deepEqual(fx.state.reads.map(read => read.id),
            [...fx.state.reads.map(read => read.id)].sort((a, b) => a - b), 'Read cursors must never move backwards.');
    });

    await t.test('dialogs, photo viewer and an unfocused tab block newly visible history', async t => {
        const fx = await fixture(t, browser, server, {cursor: 1, messages: [message(1, true, {body: '',
            attachments: [{id: 50, mime_type: 'image/png', original_name: 'Фото.png'}]})]});
        await fx.open();
        await row(fx.page, 1).locator('.chat-image-card').click();
        fx.state.messages.push(message(2, false));
        await fx.poll();
        assert.deepEqual(fx.state.reads, [], 'A photo overlay obscures the history underneath.');
        await fx.page.locator('#chat-photo-close').click();
        await fx.tick();
        await until(() => fx.state.reads.some(read => read.id === 2 && !read.failed), 'Closing the photo viewer restores reading.', fx.page);
        await fx.page.evaluate(() => document.getElementById('chat-direct-dialog').showModal());
        fx.state.messages.push(message(3, false));
        await fx.poll();
        assert.equal(fx.state.reads.some(read => read.id >= 3), false, 'A modal dialog obscures the history.');
        await fx.page.evaluate(() => document.getElementById('chat-direct-dialog').close());
        await fx.tick();
        await until(() => fx.state.reads.some(read => read.id === 3 && !read.failed), 'Closing a dialog restores reading.', fx.page);
        await tabState(fx.page, {focused: false});
        fx.state.messages.push(message(4, false));
        await fx.poll();
        assert.equal(fx.state.reads.some(read => read.id >= 4), false, 'Visible but unfocused tabs cannot mark messages.');
        await tabState(fx.page);
        await fx.tick();
        await until(() => fx.state.reads.some(read => read.id === 4 && !read.failed), 'Focus return restores reading.', fx.page);
    });

    await t.test('backlog pages preserve scroll and cannot acknowledge a skipped unread gap', async t => {
        const fx = await fixture(t, browser, server, {pageSize: 20, startsAtUnread: true,
            messages: Array.from({length: 50}, (_, i) => message(i + 1, false))});
        await fx.open();
        await fx.tick();
        await until(() => fx.state.cursor > 0, 'The first visible backlog page must be readable.', fx.page);
        await fx.tick();
        const firstCursor = fx.state.cursor;
        const previousScroll = await fx.page.locator('#chat-messages').evaluate(node => {
            node.scrollTop = node.scrollHeight;
            node.dispatchEvent(new Event('scroll'));
            return node.scrollTop;
        });
        await row(fx.page, 40).waitFor();
        assert.ok(fx.state.pages.some(page => page.after === 20), 'The next API page starts after the loaded boundary.');
        assert.equal(await fx.page.locator('#chat-messages').evaluate(node => node.scrollTop), previousScroll,
            'Appending a later page must preserve the reader’s scroll position.');
        await fx.tick();
        assert.ok(fx.state.reads.every(read => read.id <= firstCursor),
            'Jumping to the page end cannot acknowledge unseen messages in the middle.');
        assert.equal(await row(fx.page, 50).count(), 0, 'Unrequested history must remain unloaded.');
        await fx.page.locator('#chat-messages').evaluate(node => { node.scrollTop = 0; node.dispatchEvent(new Event('scroll')); });
        for (let step = 0; step < 50 && fx.state.cursor < 50; step++) {
            await fx.tick(400);
            await fx.page.locator('#chat-messages').evaluate(node => {
                node.scrollTop += node.clientHeight * .6;
                node.dispatchEvent(new Event('scroll'));
            });
        }
        await until(() => fx.state.cursor === 50, 'Reading through every page must eventually acknowledge the complete backlog.', fx.page);
        assert.ok(fx.state.pages.some(page => page.after === 40), 'The final API page must be fetched after the correct boundary.');
    });

    await t.test('sending during an unread backlog shows the sent row without acknowledging unloaded history', async t => {
        const fx = await fixture(t, browser, server, {pageSize: 20, startsAtUnread: true,
            messages: Array.from({length: 50}, (_, i) => message(i + 1, false))});
        await fx.open();
        await fx.tick();
        await until(() => fx.state.cursor > 0, 'The reader must start within the first backlog page.', fx.page);
        const firstCursor = fx.state.cursor;
        await fx.page.locator('#chat-message').fill('Нове вихідне повідомлення');
        await fx.page.locator('#chat-send').click();
        await row(fx.page, 51).waitFor();
        await expectStatus(fx.page, 51, false);
        const sentGeometry = await row(fx.page, 51).evaluate(node => {
            const list = document.getElementById('chat-messages');
            const thread = list.getBoundingClientRect();
            const body = node.querySelector('.chat-message-body').getBoundingClientRect();
            return {visible: body.top >= thread.top && body.bottom <= thread.bottom,
                bodyTop: body.top, bodyBottom: body.bottom, threadTop: thread.top, threadBottom: thread.bottom,
                scrollTop: list.scrollTop, scrollHeight: list.scrollHeight, clientHeight: list.clientHeight};
        });
        assert.equal(sentGeometry.visible, true, 'The sent message must remain inside the visible thread viewport when backlog pages arrive.');
        await fx.tick();
        assert.ok(fx.state.reads.every(read => read.id <= firstCursor), 'Sending cannot advance a read cursor over unseen incoming history.');
        await fx.page.locator('#chat-messages').evaluate(node => {
            node.scrollTop = 0;
            node.dispatchEvent(new Event('scroll'));
        });
        await fx.tick();
        await fx.page.locator('#chat-messages').evaluate(node => {
            const boundary = node.querySelector('[data-message-id="20"]');
            node.scrollTop += boundary.getBoundingClientRect().bottom - node.getBoundingClientRect().bottom;
            node.dispatchEvent(new Event('scroll'));
        });
        await row(fx.page, 40).waitFor();
        assert.ok(fx.state.pages.some(page => page.after === 20),
            'Backlog pagination must resume after the contiguous loaded page, despite the newer sent row.');
    });

    await t.test('a late send response cannot leak messages or clear a draft after switching threads', async t => {
        const fx = await fixture(t, browser, server, {cursor: 1, messages: [message(1)]});
        await fx.open();
        await fx.page.route('**/Anabelka/admin/chat/api/list', route => route.fulfill({json: {success: true,
            conversations: [{id: 10, kind: 'direct', display_name: 'Тестова бесіда', unread: 0},
                {id: 20, kind: 'direct', display_name: 'Інша бесіда', unread: 0}], admins: [], unread_total: 0}}));
        await fx.page.route('**/Anabelka/admin/chat/api/messages?conversation_id=20*', route => route.fulfill({json: {
            success: true, messages: [message(200)], viewer_read_message_id: 200, read_statuses: [], has_more: false,
            conversation: {id: 20, kind: 'direct', display_name: 'Інша бесіда'}, members: [{id: 1}, {id: 3}]}}));
        await fx.poll();
        let started = false;
        let release;
        const gate = new Promise(resolve => { release = resolve; });
        await fx.page.route('**/Anabelka/admin/chat/api/send', async route => {
            started = true;
            await gate;
            await route.fulfill({json: {success: true, message_id: 99}});
        });
        await fx.page.locator('#chat-message').fill('Надіслано у першу бесіду');
        await fx.page.locator('#chat-send').click();
        await until(() => started, 'The first thread’s send must be in flight.');
        await fx.page.locator('.chat-conversation').nth(1).click();
        await row(fx.page, 200).waitFor();
        await fx.page.locator('#chat-message').fill('Чернетка іншої бесіди');
        release();
        await fx.page.waitForFunction(() => !document.getElementById('chat-send').disabled);
        assert.equal(await fx.page.locator('#chat-thread-title').textContent(), 'Інша бесіда');
        assert.equal(await fx.page.locator('#chat-message').inputValue(), 'Чернетка іншої бесіди',
            'Finishing an old send must preserve the new thread’s draft.');
        assert.equal(await row(fx.page, 99).count(), 0, 'The old send cannot render in the newly selected thread.');
        assert.equal(await row(fx.page, 1).count(), 0, 'The previous history must remain cleared.');
        assert.equal(await row(fx.page, 200).count(), 1);
    });

    for (const width of [320, 390]) {
        await t.test(`mobile ${width}px receipts fit text, photo and document messages`, async t => {
            const fx = await fixture(t, browser, server, {viewport: {width, height: 844}, kind: 'group', memberCount: 101,
                cursor: 3, messages: [message(1, true, {body: 'Довге повідомлення '.repeat(12)}),
                    message(2, true, {body: '', attachments: [{id: 50, mime_type: 'image/png', original_name: 'Фото.png'}]}),
                    message(3, true, {body: '', attachments: [{id: 60, mime_type: 'application/pdf',
                        original_name: 'Дуже-довга-назва-документа-для-перевірки.pdf', byte_size: 2048}]})]});
            fx.state.messages.forEach(message => { message.read_status = {read_count: 99, recipient_count: 100, seen: false}; });
            await fx.open();
            for (const id of [1, 2, 3]) { await expectStatus(fx.page, id, false, 'Переглянули: 99 із 100'); }
            assert.equal(await fx.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true,
                'Mobile receipt labels must not cause page overflow.');
            for (const id of [1, 2, 3]) {
                assert.equal(await row(fx.page, id).evaluate(node => {
                    const bubble = node.querySelector('.chat-bubble').getBoundingClientRect();
                    const status = node.querySelector('.chat-read-status').getBoundingClientRect();
                    return status.left >= bubble.left && status.right <= bubble.right &&
                        status.top >= bubble.top && status.bottom <= bubble.bottom;
                }), true, 'Wrapped receipt text must remain inside its bubble.');
            }
            const artifactDir = process.env.ANABELKA_CHAT_UI_ARTIFACT_DIR;
            if (artifactDir) {
                fs.mkdirSync(artifactDir, {recursive: true});
                await fx.page.screenshot({path: path.join(artifactDir, `chat-read-${width}.png`), fullPage: true});
            }
        });
    }

    await t.test('failed read acknowledgements retry and successful cursors remain monotonic', async t => {
        const fx = await fixture(t, browser, server, {startsAtUnread: true,
            messages: [message(1, false), message(2, false), message(3, false)]});
        fx.state.failReads = Number.MAX_SAFE_INTEGER;
        await fx.open();
        await fx.tick();
        await until(() => fx.state.reads.some(read => read.failed), 'The fixture must observe the failed acknowledgement.', fx.page);
        assert.equal(fx.state.cursor, 0, 'A failed POST must not advance the server cursor.');
        fx.state.failReads = 0;
        await fx.poll();
        await until(() => fx.state.reads.some(read => read.id === 3 && !read.failed),
            'A failed read must be retried instead of being cached as success.', fx.page);
        const acknowledged = fx.state.reads.length;
        await fx.poll();
        assert.equal(fx.state.reads.length, acknowledged, 'Already acknowledged messages must not repeatedly POST.');
        fx.state.messages.push(message(4, false));
        await fx.poll();
        await until(() => fx.state.reads.some(read => read.id === 4 && !read.failed), 'New visible messages advance the cursor.', fx.page);
        const cursors = fx.state.reads.map(read => read.id);
        assert.deepEqual(cursors, [...cursors].sort((a, b) => a - b));
    });
});
