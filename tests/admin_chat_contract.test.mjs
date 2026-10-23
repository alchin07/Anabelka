import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const file = (name) => fs.readFileSync(path.join(root, name), 'utf8');

test('routes are registered in the existing application', () => {
    assert.ok(file('app/Core/App.php').includes("routes/AdminChat.php"));
    const routes = file('routes/AdminChat.php');
    for (const action of ['index', 'list', 'messages', 'direct', 'group',
        'manageGroup', 'send', 'edit', 'delete', 'read', 'search', 'file']) {
        assert.ok(routes.includes('AdminChatController@' + action), action);
    }
    assert.ok(!routes.includes('csrf' + "' => false"));
});

test('admin chat requires its own permission', () => {
    const access = file('app/Models/AdminAccess.php');
    assert.ok(access.includes("'/admin/chat' => ['chat.use', 'chat.use']"));
    assert.ok(access.includes("['chat.use',"));
    const header = file('views/admin/partials/header.php');
    assert.ok(header.includes('/admin/chat'));
    assert.ok(header.includes('data-chat-unread-badge'));
});

test('messages, attachments and disk are protected', () => {
    const model = file('app/Models/AdminChat.php');
    assert.ok(model.includes('assertMessageAuthorMembership'));
    assert.ok(model.includes('self::conversation($id, $viewerId)'));
    assert.ok(model.includes('cm.admin_id = ?'));
    assert.ok(model.includes('WHERE f.id = ? AND cm.admin_id = ?'));
    const controller = file('app/Controllers/AdminChatController.php');
    for (const check of ['move_uploaded_file', 'is_uploaded_file',
        'new finfo(FILEINFO_MIME_TYPE)', 'random_bytes(20)',
        'Content-Security-Policy', 'Cache-Control: private, no-store']) {
        assert.ok(controller.includes(check), check);
    }
    assert.ok(file('.htaccess').includes('RewriteRule ^storage/admin-chat'));
    assert.ok(file('.gitignore').includes('storage/admin-chat/'));
});

test('SQL migration only creates four isolated chat tables', () => {
    const sql = file('database/migrations/2026-10-21_admin_chat.sql');
    for (const table of ['admin_chat_conversations', 'admin_chat_members',
        'admin_chat_messages', 'admin_chat_attachments']) {
        assert.ok(sql.includes('CREATE TABLE IF NOT EXISTS ' + table), table);
    }
    assert.equal((sql.match(/CREATE TABLE IF NOT EXISTS/g) || []).length, 4);
    assert.doesNotMatch(sql, /ALTER TABLE|DROP TABLE|TRUNCATE/i);
});

test('emoji panel bundles categories without external CDN', () => {
    const js = file('js/admin-chat.js');
    const emoji = file('js/admin-chat-emoji.js');
    for (const section of ['faces', 'people', 'nature', 'food',
        'travel', 'activities', 'objects', 'symbols', 'flags']) {
        assert.ok(emoji.includes("id: '" + section + "'"), section);
    }
    assert.ok(js.includes('textContent'));
    assert.ok(file('css/admin-chat.css').includes('max-width:730px'));
    assert.ok(!emoji.includes('https://'));
    assert.ok(!js.includes('innerHTML ='));
});
