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

// CH-UI-05: receipts summarize current recipients, never disclose member cursors.
$receiptAdmins = [];
foreach (['Receipt Sender', 'Receipt Reader', 'Receipt Other'] as $index => $name) {
    $createAdmin->execute([
        $roleId, $name, "receipt-$index@example.invalid",
        password_hash('Test-password-123!', PASSWORD_DEFAULT)
    ]);
    $receiptAdmins[] = (int) $db->lastInsertId();
}
[$sender, $reader, $other] = $receiptAdmins;
$receiptDirect = AdminChat::direct($sender, $reader);
$emptyHistory = AdminChat::history($receiptDirect, $sender);
check($emptyHistory['messages'] === [] &&
    $emptyHistory['viewer_read_message_id'] === 0 &&
    $emptyHistory['has_more'] === false,
    'Empty history returns an integer own cursor and no continuation');
foreach ($emptyHistory['members'] as $member) {
    check(!array_key_exists('last_read_message_id', $member),
        'History member metadata does not expose another member read cursor');
}
$first = AdminChat::send($receiptDirect, $sender, 'First receipt', 0);
$second = AdminChat::send($receiptDirect, $sender, 'Second receipt', 0);
$outgoing = AdminChat::messages($receiptDirect, $sender);
check($outgoing[0]['read_status'] === [
    'read_count' => 0, 'recipient_count' => 1, 'seen' => false
], 'Direct outgoing message starts sent with one unread recipient');
foreach (AdminChat::messages($receiptDirect, $reader) as $incoming) {
    check(!array_key_exists('read_status', $incoming),
        'Incoming direct messages omit read status entirely');
}
AdminChat::read($receiptDirect, $sender, $second);
check(AdminChat::messages($receiptDirect, $sender)[0]['read_status']['read_count'] === 0,
    'Sender own watermark never contributes to a receipt');
AdminChat::read($receiptDirect, $reader, $first);
$outgoing = AdminChat::messages($receiptDirect, $sender);
check($outgoing[0]['read_status'] === [
    'read_count' => 1, 'recipient_count' => 1, 'seen' => true
] && $outgoing[1]['read_status']['seen'] === false,
    'Recipient watermark marks only outgoing messages at or below its position');
AdminChat::read($receiptDirect, $reader, $second);
$acknowledgedRead = AdminChat::read($receiptDirect, $reader, $first);
check($acknowledgedRead === $second &&
    AdminChat::history($receiptDirect, $reader)['viewer_read_message_id'] === $second,
    'An older read request preserves and acknowledges the actual stored watermark');
$refreshed = AdminChat::history($receiptDirect, $sender, $second, $first);
check($refreshed['messages'] === [] && $refreshed['read_statuses'] === [
    ['id' => $first, 'read_status' => [
        'read_count' => 1, 'recipient_count' => 1, 'seen' => true
    ]],
    ['id' => $second, 'read_status' => [
        'read_count' => 1, 'recipient_count' => 1, 'seen' => true
    ]]
], 'Receipt refresh updates displayed outgoing messages even without newer history rows');
check(AdminChat::history($receiptDirect, $reader, 0, $first)['read_statuses'] === [],
    'Receipt refresh cannot disclose receipt details for incoming messages');

$foreignConversation = AdminChat::direct($sender, $other);
$foreignMessage = AdminChat::send($foreignConversation, $sender, 'Foreign message', 0);
deny(static fn() => AdminChat::read($receiptDirect, $reader, $foreignMessage),
    'A message from another conversation cannot advance the read watermark');
deny(static fn() => AdminChat::read($receiptDirect, $reader, $foreignMessage + 100000),
    'A future nonexistent message cannot be clamped into a false read watermark');
deny(static fn() => AdminChat::read($receiptDirect, $reader, 0),
    'A read request must name an existing message in this conversation');
deny(static fn() => AdminChat::history($receiptDirect, $other),
    'A non-member cannot obtain history or aggregated receipts');
check(AdminChat::history($receiptDirect, $reader)['viewer_read_message_id'] === $second,
    'Rejected read requests preserve the real viewer watermark');

$receiptGroup = AdminChat::createGroup($sender, 'Receipt group', [$reader, $other]);
$groupReceipt = AdminChat::send($receiptGroup, $sender, 'Group receipt', 0);
AdminChat::read($receiptGroup, $sender, $groupReceipt);
AdminChat::read($receiptGroup, $reader, $groupReceipt);
check(AdminChat::messages($receiptGroup, $sender)[0]['read_status'] === [
    'read_count' => 1, 'recipient_count' => 2, 'seen' => false
], 'Partial group receipt counts readers and requires all current recipients for seen');
check(!array_key_exists('read_status', AdminChat::messages($receiptGroup, $other)[0]),
    'Incoming group messages omit read status entirely');
deny(static fn() => AdminChat::read($receiptGroup, $bohdan, $groupReceipt),
    'Knowing a message ID does not let a non-member advance a group read marker');
AdminChat::read($receiptGroup, $other, $groupReceipt);
check(AdminChat::messages($receiptGroup, $sender)[0]['read_status']['seen'] === true,
    'A group receipt is seen when every current recipient has read it');
$_SESSION['admin_user_id'] = $owner;
AdminChat::manageGroup($receiptGroup, $sender, 'remove', $other);
check(AdminChat::messages($receiptGroup, $sender)[0]['read_status'] === [
    'read_count' => 1, 'recipient_count' => 1, 'seen' => true
], 'Removing a recipient changes receipt counts to current membership');
AdminChat::manageGroup($receiptGroup, $sender, 'add', $other);
check(AdminChat::messages($receiptGroup, $sender)[0]['read_status'] === [
    'read_count' => 1, 'recipient_count' => 2, 'seen' => false
], 'Re-added recipient starts unread and current group receipt becomes partial');
AdminChat::manageGroup($receiptGroup, $sender, 'remove', $other);
AdminChat::manageGroup($receiptGroup, $sender, 'remove', $reader);
check(AdminChat::messages($receiptGroup, $sender)[0]['read_status'] === [
    'read_count' => 0, 'recipient_count' => 0, 'seen' => false
], 'A creator-only group has no recipient and never reports seen');
$db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$other]);
check(AdminChat::messages($foreignConversation, $sender)[0]['read_status'] === [
    'read_count' => 0, 'recipient_count' => 0, 'seen' => false
], 'A direct conversation with a deleted sole recipient never reports seen');

// A scalar watermark is safe only if initial history starts at the oldest unread
// message when latest-70 would skip unread content. Follow-up pages stay ordered.
$backlog = AdminChat::createGroup($sender, 'Unread backlog', [$reader]);
$backlogIds = [];
for ($index = 0; $index < 175; $index++) {
    $backlogIds[] = AdminChat::send($backlog, $sender, 'Backlog ' . $index, 0);
}
$page = AdminChat::history($backlog, $reader);
check(count($page['messages']) === 70 && $page['has_more'] === true &&
    $page['starts_at_unread'] === true &&
    (int) $page['messages'][0]['id'] === $backlogIds[0] &&
    (int) $page['messages'][69]['id'] === $backlogIds[69],
    'Large unread backlog opens at first unread rather than skipping to latest seventy');
check($page['viewer_read_message_id'] === 0,
    'Fetching history does not itself advance a read watermark');
AdminChat::read($backlog, $reader, $backlogIds[69]);
check(AdminChat::unreadTotal($reader) === 105,
    'Reading a visible backlog page leaves all unseen later messages unread');
$page = AdminChat::history($backlog, $reader, $backlogIds[69]);
check(count($page['messages']) === 100 && $page['has_more'] === true &&
    (int) $page['messages'][0]['id'] === $backlogIds[70] &&
    (int) $page['messages'][99]['id'] === $backlogIds[169],
    'Incremental history returns the next hundred in order and signals overflow');
$lastPage = AdminChat::history($backlog, $reader, $backlogIds[169]);
check(count($lastPage['messages']) === 5 && $lastPage['has_more'] === false &&
    (int) $lastPage['messages'][4]['id'] === $backlogIds[174],
    'Incremental history ends without skipping messages after a hundred-message page');
AdminChat::read($backlog, $reader, $backlogIds[174]);
$latest = AdminChat::history($backlog, $reader);
check(count($latest['messages']) === 70 && $latest['has_more'] === false &&
    $latest['starts_at_unread'] === false &&
    (int) $latest['messages'][0]['id'] === $backlogIds[105],
    'A fully read conversation retains the latest-seventy history window');

// Count visible positions rather than unread rows: one old unread message may be
// separated from the latest window by the viewer's own outgoing messages.
$sparseBacklog = AdminChat::createGroup($sender, 'Sparse unread backlog', [$reader]);
$sparseFirst = AdminChat::send($sparseBacklog, $sender, 'Old unread', 0);
for ($index = 0; $index < 75; $index++) {
    $sparseLast = AdminChat::send($sparseBacklog, $reader, 'Own context ' . $index, 0);
}
$sparsePage = AdminChat::history($sparseBacklog, $reader);
check((int) $sparsePage['messages'][0]['id'] === $sparseFirst &&
    $sparsePage['has_more'] === true,
    'Even one old unread message cannot be skipped behind outgoing context');

$createAdmin->execute([
    $roleId, 'Archived Receipt Author', 'archived-receipt-author@example.invalid',
    password_hash('Test-password-123!', PASSWORD_DEFAULT)
]);
$archivedAuthor = (int) $db->lastInsertId();
$archivedBacklog = AdminChat::createGroup($sender, 'Archived author backlog', [$reader, $archivedAuthor]);
$archivedFirst = AdminChat::send($archivedBacklog, $archivedAuthor, 'Unread archived author', 0);
for ($index = 0; $index < 75; $index++) {
    AdminChat::send($archivedBacklog, $reader, 'Own archived context ' . $index, 0);
}
$unreadBeforeDeletion = AdminChat::unreadTotal($reader);
$db->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$archivedAuthor]);
$archivedPage = AdminChat::history($archivedBacklog, $reader);
$archivedList = listedConversations($reader);
check($archivedPage['starts_at_unread'] === true &&
    (int) $archivedPage['messages'][0]['id'] === $archivedFirst &&
    $archivedPage['messages'][0]['sender_id'] === null &&
    !array_key_exists('read_status', $archivedPage['messages'][0]),
    'Deleted sender content stays incoming and anchors unread history before later own messages');
check(AdminChat::unreadTotal($reader) === $unreadBeforeDeletion &&
    (int) $archivedList[$archivedBacklog]['unread'] === 1,
    'Deleting an author does not silently clear their unread message from totals or conversation badges');

// The client retains loaded history while the server polls a smaller latest
// window. Refresh edits/deletions from that cache floor with the receipt batch.
AdminChat::edit($backlogIds[0], $sender, 'Edited outside latest window');
AdminChat::delete($backlogIds[1], $sender);
$cacheRefresh = AdminChat::history($backlog, $sender, 0, $backlogIds[0]);
$updates = [];
foreach (($cacheRefresh['message_updates'] ?? []) as $update) {
    $updates[$update['id']] = $update;
}
check((int) $cacheRefresh['messages'][0]['id'] > $backlogIds[1] &&
    ($updates[$backlogIds[0]]['body'] ?? null) === 'Edited outside latest window' &&
    !empty($updates[$backlogIds[0]]['edited_at']) &&
    $updates[$backlogIds[0]]['deleted_at'] === null,
    'Retained outgoing messages receive edits even after falling outside latest seventy');
check($updates[$backlogIds[1]]['body'] === '' &&
    !empty($updates[$backlogIds[1]]['deleted_at']),
    'Retained deleted messages receive an empty tombstone outside latest seventy');
$setReply = $db->prepare('UPDATE admin_chat_messages SET reply_to_id = ? WHERE id = ?');
$setReply->execute([$backlogIds[0], $backlogIds[71]]);
$setReply->execute([$backlogIds[1], $backlogIds[72]]);
$quoteRefresh = AdminChat::history($backlog, $sender, 0, $backlogIds[70]);
$quoteUpdates = [];
foreach (($quoteRefresh['message_updates'] ?? []) as $update) {
    $quoteUpdates[$update['id']] = $update;
}
check(($quoteUpdates[$backlogIds[0]]['body'] ?? null) === 'Edited outside latest window' &&
    ($quoteUpdates[$backlogIds[1]]['body'] ?? null) === '' &&
    !empty($quoteUpdates[$backlogIds[1]]['deleted_at']) &&
    !in_array($backlogIds[0], array_column($quoteRefresh['read_statuses'], 'id'), true),
    'Cached replies receive changed quote originals below the cache floor without extra receipts');
AdminChat::read($sparseBacklog, $reader, $sparseLast);
AdminChat::edit($sparseFirst, $sender, 'Edited incoming cached message');
$incomingRefresh = AdminChat::history($sparseBacklog, $reader, 0, $sparseFirst);
check((int) $incomingRefresh['messages'][0]['id'] > $sparseFirst &&
    $incomingRefresh['message_updates'][0]['id'] === $sparseFirst &&
    $incomingRefresh['message_updates'][0]['body'] === 'Edited incoming cached message' &&
    !in_array($sparseFirst, array_column($incomingRefresh['read_statuses'], 'id'), true),
    'Retained incoming edits are refreshed without exposing incoming read receipts');
check(array_key_exists('message_updates', $emptyHistory) && $emptyHistory['message_updates'] === [],
    'History without a cache floor returns an empty message updates array');

// MariaDB counts executed statements for this connection. Both pages use one
// membership/watermark query regardless of message and receipt result count.
$countHistoryQueries = static function (callable $request) use ($db): int {
    $before = $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM);
    $request();
    $after = $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM);
    return (int) $after[1] - (int) $before[1] - 1;
};
$smallQueryCount = $countHistoryQueries(
    static fn() => AdminChat::history($receiptDirect, $sender, $first, $first)
);
$largeQueryCount = $countHistoryQueries(
    static fn() => AdminChat::history($backlog, $sender, $backlogIds[69], $backlogIds[0])
);
check($smallQueryCount > 0 && $smallQueryCount === $largeQueryCount,
    'One-message and hundred-message receipt requests execute the same number of queries');
AdminChat::delete($first, $sender);
$deletedReceiptHistory = AdminChat::history($receiptDirect, $sender, 0, $first);
check(!array_key_exists('read_status', $deletedReceiptHistory['messages'][0]) &&
    count($deletedReceiptHistory['read_statuses']) === 1 &&
    $deletedReceiptHistory['read_statuses'][0]['id'] === $second,
    'Deleted messages have no visible receipt and do not reappear in receipt refresh');

fwrite(STDOUT, "All isolated admin chat database integration checks passed.\n");
