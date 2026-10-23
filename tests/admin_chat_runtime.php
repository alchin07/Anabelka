<?php
/**
 * Isolated Admin Chat integration tests.
 * Runs ONLY against an ephemeral CI database named "anabelka".
 * Never run this file against a real Anabelka installation.
 */
declare(strict_types=1);

if (getenv('ANABELKA_CHAT_CI_TEST') !== '1') {
    fwrite(STDERR, "Refusing to run outside the isolated CI environment.\n");
    exit(2);
}
if (getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_NAME') !== 'anabelka') {
    fwrite(STDERR, "Unexpected integration DB target.\n");
    exit(2);
}

session_start();
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Models/AdminAccess.php';
require_once __DIR__ . '/../app/Models/AdminChat.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    fwrite(STDOUT, 'PASS: ' . $message . "\n");
}
function deny(callable $action, string $message): void
{
    try {
        $action();
    } catch (DomainException $e) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function listedConversations(int $viewerId): array
{
    $listed = [];
    foreach (AdminChat::listFor($viewerId) as $row) {
        $listed[(int) $row['id']] = $row;
    }
    return $listed;
}
$db = Database::connect();
$db->query('SELECT 1')->fetchColumn();
AdminAccess::ensureSchema();
$schema = (string) file_get_contents(
    __DIR__ . '/../database/migrations/2026-10-21_admin_chat.sql'
);
foreach (preg_split('/;\s*(?:\n|$)/', $schema) as $sql) {
    $sql = trim($sql);
    if ($sql !== '' && substr($sql, 0, 2) === '--') {
        $sql = preg_replace('/\A(?:--[^\n]*\n)+/u', '', $sql) ?? $sql;
    }
    if ($sql !== '') {
        $db->exec($sql);
    }
}
check((int) $db->query("
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name LIKE 'admin_chat_%'
")->fetchColumn() === 4, 'Four chat tables created in temporary database');

$owner = AdminAccess::createOwner('Dev Test', 'dev-chat@example.invalid', 'Pass-dev-1234!');
$roleId = (int) $db->query(
    "SELECT id FROM admin_roles WHERE slug = 'administrator'"
)->fetchColumn();
check($owner > 0 && $roleId > 0, 'Existing administrator identity model available');
$createAdmin = $db->prepare("
    INSERT INTO admin_users (role_id, name, email, password_hash, is_active)
    VALUES (?, ?, ?, ?, 1)
");
foreach (['Anna Test', 'Bohdan Test'] as $index => $name) {
    $createAdmin->execute([
        $roleId, $name, "admin-chat-$index@example.invalid",
        password_hash('Test-password-123!', PASSWORD_DEFAULT)
    ]);
    $ids[] = (int) $db->lastInsertId();
}
[$anna, $bohdan] = $ids;
check(count(AdminChat::admins($owner)) === 2, 'Admins can be listed by session');

$direct = AdminChat::direct($owner, $anna);
check($direct > 0 && $direct === AdminChat::direct($anna, $owner),
    'Direct conversation is unique in both directions');
$listed = listedConversations($owner);
check(array_key_exists('member_count', $listed[$direct]) &&
    $listed[$direct]['member_count'] === null,
    'Direct list row has no group member count');
check($listed[$direct]['display_name'] === 'Anna Test' &&
    (int) $listed[$direct]['unread'] === 0 && $listed[$direct]['preview'] === null,
    'Direct list preserves its recipient name and empty message state');

$msg = AdminChat::send($direct, $owner, 'Привіт! ✨', 0);
$history = AdminChat::messages($direct, $anna);
check(count($history) === 1 && $history[0]['body'] === 'Привіт! ✨',
    'Authorized participant receives Unicode message');
check(AdminChat::unreadTotal($anna) === 1, 'Recipient has one unread message');
$listed = listedConversations($anna);
check($listed[$direct]['member_count'] === null &&
    (int) $listed[$direct]['unread'] === 1 &&
    (int) $listed[$direct]['last_message_id'] === $msg &&
    $listed[$direct]['preview'] === 'Привіт! ✨',
    'Direct list preserves unread count and latest message alongside null member count');
AdminChat::read($direct, $anna, $msg);
check(AdminChat::unreadTotal($anna) === 0, 'Read marker clears unread counter');
$listed = listedConversations($anna);
check($listed[$direct]['member_count'] === null &&
    (int) $listed[$direct]['unread'] === 0,
    'Reading a direct conversation leaves its member count unset');
deny(static fn() => AdminChat::messages($direct, $bohdan),
    'Non-member cannot read private thread');

deny(static fn() => AdminChat::edit($msg, $anna, 'Changed by stranger'),
    'Non-author cannot edit another admin message');
AdminChat::edit($msg, $owner, 'Привіт, змінено! ✨');
check(AdminChat::messages($direct, $anna)[0]['body'] === 'Привіт, змінено! ✨',
    'Author can edit message');

AdminChat::addAttachment($msg, str_repeat('a', 40), 'test.txt', 'text/plain', 15);
$fileId = (int) $db->lastInsertId();
check((int) AdminChat::file($fileId, $anna)['id'] === $fileId,
    'Group participant may request attachment record');
deny(static fn() => AdminChat::file($fileId, $bohdan),
    'Non-member cannot request attachment');
AdminChat::delete($msg, $owner);
deny(static fn() => AdminChat::file($fileId, $anna),
    'Attachment is unavailable after deletion of message');

$group = AdminChat::createGroup($owner, 'Команда', [$anna, $bohdan]);
check(count(AdminChat::members($group, $owner)) === 3,
    'Group created with three members');
$pairGroup = AdminChat::createGroup($owner, 'Owner pair', [$anna]);
$privateGroup = AdminChat::createGroup($anna, 'Private members', [$bohdan]);
$listed = listedConversations($owner);
check((string) ($listed[$group]['member_count'] ?? '') === '3' &&
    (string) ($listed[$pairGroup]['member_count'] ?? '') === '2',
    'Each joined group lists its own exact member count including the viewer');
check(!isset($listed[$privateGroup]),
    'A group and its member count are absent from a non-member list');
$listed = listedConversations($anna);
check((string) ($listed[$privateGroup]['member_count'] ?? '') === '2' &&
    (string) ($listed[$group]['member_count'] ?? '') === '3',
    'A member sees isolated counts for groups with different memberships');
$_SESSION['admin_user_id'] = $anna;
deny(static fn() => AdminChat::manageGroup($group, $anna, 'rename', 0, 'Bad title'),
    'Ordinary group member cannot rename owner group');
$_SESSION['admin_user_id'] = $owner;
AdminChat::manageGroup($group, $owner, 'rename', 0, 'Чат відділу');
check(AdminChat::conversation($group, $anna)['title'] === 'Чат відділу',
    'Owner may rename group');
AdminChat::manageGroup($pairGroup, $owner, 'remove', $anna);
$listed = listedConversations($owner);
check((string) ($listed[$pairGroup]['member_count'] ?? '') === '1' &&
    (string) ($listed[$group]['member_count'] ?? '') === '3',
    'A creator-only group lists one member without changing another group count');
$listed = listedConversations($anna);
check(!isset($listed[$pairGroup]),
    'Removed participant no longer sees a singleton group or its count');
// A removed administrator must not be able to fetch a previously known file URL.
$groupMessage = AdminChat::send($group, $owner, 'Private group attachment', 0);
AdminChat::addAttachment($groupMessage, str_repeat('c', 40),
    'group-private.pdf', 'application/pdf', 100);
$groupFileId = (int) $db->lastInsertId();
check((int) AdminChat::file($groupFileId, $bohdan)['id'] === $groupFileId,
    'Joined member may fetch private group attachment');
$listed = listedConversations($anna);
check((string) ($listed[$group]['member_count'] ?? '') === '3' &&
    (int) $listed[$group]['unread'] === 1 &&
    (int) $listed[$group]['last_message_id'] === $groupMessage &&
    $listed[$group]['preview'] === 'Private group attachment',
    'Sending an attachment message preserves group count and list message metadata');
$groupHistory = AdminChat::messages($group, $anna);
check(count($groupHistory) === 1 &&
    count($groupHistory[0]['attachments']) === 1 &&
    (int) $groupHistory[0]['attachments'][0]['id'] === $groupFileId,
    'Group member still receives the message attachment');
AdminChat::read($group, $anna, $groupMessage);
$listed = listedConversations($anna);
check((string) ($listed[$group]['member_count'] ?? '') === '3' &&
    (int) $listed[$group]['unread'] === 0 && AdminChat::unreadTotal($anna) === 0,
    'Reading group messages clears unread totals without changing membership count');
deny(static fn() => AdminChat::manageGroup($group, $owner, 'remove', $owner),
    'Group creator cannot be removed without ownership transfer');
AdminChat::manageGroup($group, $owner, 'remove', $bohdan);
check(count(AdminChat::members($group, $owner)) === 2,
    'Removal actually deletes member record');
$listed = listedConversations($owner);
check((string) ($listed[$group]['member_count'] ?? '') === '2' &&
    (string) ($listed[$pairGroup]['member_count'] ?? '') === '1',
    'Removing a member refreshes only that group count');
$listed = listedConversations($bohdan);
check(!isset($listed[$group]) &&
    (string) ($listed[$privateGroup]['member_count'] ?? '') === '2',
    'Removed member loses the group list row while another joined group keeps its count');
deny(static fn() => AdminChat::manageGroup($group, $owner, 'remove', $bohdan),
    'Removing an absent member cannot silently report success');
deny(static fn() => AdminChat::messages($group, $bohdan),
    'Removed member immediately loses history access');
deny(static fn() => AdminChat::file($groupFileId, $bohdan),
    'Removed member cannot download previously accessible PDF by old file ID');
check((int) AdminChat::file($groupFileId, $owner)['id'] === $groupFileId,
    'Remaining member retains PDF access');
AdminChat::manageGroup($group, $owner, 'add', $bohdan);
check(count(AdminChat::members($group, $owner)) === 3,
    'Group creator may re-add a member');
$listed = listedConversations($bohdan);
check((string) ($listed[$group]['member_count'] ?? '') === '3' &&
    (string) ($listed[$privateGroup]['member_count'] ?? '') === '2',
    'Re-added member sees the restored count without changing another group');
$replyTo = AdminChat::send($group, $owner, 'Важлива інформація', 0);
AdminChat::send($group, $anna, 'Зрозуміло 👍', $replyTo);
check(count(AdminChat::search($bohdan, 'Зрозуміло')) === 1,
    'Search returns only messages from joined group');

$ownerOfDeleted = AdminChat::createGroup($anna, 'Archive', [$owner]);
$oldMsg = AdminChat::send($direct, $anna, 'History after deletion', 0);
$db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$anna]);
check(AdminChat::conversation($ownerOfDeleted, $owner)['created_by'] === null,
    'Deleting an admin does not block group history');
$listed = listedConversations($owner);
check((string) ($listed[$ownerOfDeleted]['member_count'] ?? '') === '1' &&
    (string) ($listed[$group]['member_count'] ?? '') === '2' &&
    (string) ($listed[$pairGroup]['member_count'] ?? '') === '1' &&
    $listed[$direct]['member_count'] === null,
    'Deleted membership updates each group count while direct rows remain uncounted');
$history = AdminChat::messages($direct, $owner);
$matching = array_values(array_filter($history,
    static fn($row) => (int) $row['id'] === $oldMsg
));
check(count($matching) === 1 &&
    $matching[0]['sender_name'] === 'Колишній адміністратор',
    'Deleted sender remains anonymous in archived messages');

fwrite(STDOUT, "All isolated admin chat database integration checks passed.\n");
