import {test} from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fixtureServer, launchBrowser, repo} from './helpers/notify-browser.mjs';

// Real markup/styles/scripts; only PHP identity output and API transport are fixtures.
const markup = fs.readFileSync(path.join(repo, 'views/admin/chat/index.php'), 'utf8')
    .replace(/<\?php[^]*?\?>/g, '').replace(/<\?=[^]*?\?>/g, '1');

test('CH-UI-04 real browser: avatar geometry and membership refresh', async t => {
    const server = await fixtureServer(async () => markup);
    t.after(() => server.close());
    const browser = await launchBrowser();
    t.after(() => browser.close());
    const artifactDir = process.env.ANABELKA_CHAT_UI_ARTIFACT_DIR;
    if (artifactDir) { fs.mkdirSync(artifactDir, {recursive: true}); }

    for (const width of [320, 360, 390, 430, 1366]) {
        for (const dpr of [1, 3]) {
            await t.test(`${width}px DPR ${dpr}`, async () => {
                const context = await browser.newContext({viewport: {width, height: 900}, deviceScaleFactor: dpr});
                try {
                    const page = await context.newPage();
                    const errors = [];
                    page.on('pageerror', error => errors.push(error.message));
                    await page.clock.install();
                    let count = 2;
                    const chats = () => [
                        {id: 10, kind: 'direct', display_name: 'Особиста розмова', unread: 7},
                        ...[count, 1, 99, 100].map((members, i) => ({id: i + 1,
                            kind: 'group', display_name: 'Команда ' + (i + 1), member_count: members, unread: 0}))
                    ];
                    await page.route('**/Anabelka/admin/chat/api/**', async route => {
                        const url = new URL(route.request().url());
                        const current = chats().find(chat => chat.id === Number(url.searchParams.get('conversation_id')));
                        const data = url.pathname.endsWith('/list')
                            ? {conversations: chats(), admins: [], unread_total: 7}
                            : {messages: [], conversation: current,
                                members: Array.from({length: current?.member_count || 2}, (_, id) => ({id: id + 1}))};
                        await route.fulfill({json: {success: true, ...data}});
                    });
                    await page.goto(server.origin + '/Anabelka/admin/chat');
                    await page.waitForFunction(() => document.querySelectorAll('.chat-conversation').length === 5);
                    const values = await page.locator('.chat-avatar').evaluateAll(avatars => avatars.map(avatar => {
                        const rect = avatar.getBoundingClientRect();
                        const badge = avatar.querySelector('.chat-member-count');
                        const box = badge?.getBoundingClientRect();
                        let textFits = true;
                        if (badge) {
                            const range = document.createRange(); range.selectNodeContents(badge);
                            const text = range.getBoundingClientRect();
                            textFits = text.left >= box.left + 2 && text.right <= box.right - 2;
                        }
                        return {width: rect.width, height: rect.height,
                            paths: avatar.querySelectorAll('path').length, count: badge?.textContent || null,
                            contained: !box || box.left >= rect.left && box.right <= rect.right &&
                                box.top >= rect.top && box.bottom <= rect.bottom,
                            textFits, color: getComputedStyle(avatar.querySelector('svg')).color,
                            background: getComputedStyle(avatar).backgroundColor,
                            badgeColor: badge && getComputedStyle(badge).color};
                    }));
                    assert.deepEqual(values.map(value => value.count), [null, '2', '1', '99', '99+']);
                    assert.deepEqual(values.map(value => value.paths), [2, 1, 1, 1, 1]);
                    for (const value of values) {
                        assert.equal(value.width, 43); assert.equal(value.height, 43);
                        assert.equal(value.contained, true); assert.equal(value.textFits, true);
                        assert.equal(value.color, 'rgb(138, 43, 226)');
                        assert.equal(value.background, 'rgb(244, 234, 255)');
                        if (value.count) { assert.equal(value.badgeColor, 'rgb(255, 255, 255)'); }
                    }
                    assert.equal(await page.locator('.chat-unread').textContent(), '7');
                    if (artifactDir && dpr === 1) {
                        await page.screenshot({path: path.join(artifactDir, `chat-list-${width}.png`), fullPage: true});
                    }
                    await page.locator('.chat-conversation').nth(1).click();
                    await page.waitForFunction(() => document.querySelector('#chat-thread-avatar .chat-member-count')?.textContent === '2');
                    assert.equal(await page.locator('#chat-thread-avatar .chat-avatar-icon').getAttribute('role'), 'img');
                    assert.equal(await page.locator('#chat-thread-avatar svg').innerHTML(),
                        await page.locator('.chat-conversation').nth(1).locator('svg').innerHTML());
                    for (const next of [3, 1]) {
                        count = next;
                        await page.clock.runFor(6600);
                        await page.waitForFunction(expected =>
                            document.querySelector('#chat-thread-avatar .chat-member-count')?.textContent === expected,
                        String(next));
                        assert.equal(await page.locator('.chat-conversation').nth(1).locator('.chat-member-count').textContent(), String(next));
                        assert.equal(await page.locator('#chat-all-count').textContent(), '7');
                    }
                    if (artifactDir && dpr === 1) {
                        await page.screenshot({path: path.join(artifactDir, `chat-thread-${width}.png`), fullPage: true});
                    }
                    assert.deepEqual(errors, []);
                } finally { await context.close(); }
            });
        }
    }
});
