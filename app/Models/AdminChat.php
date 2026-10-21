<?php

/**
 * Приватні розмови адміністрації. Кожен запит до повідомлень перевіряє
 * членство поточного адміністратора, незалежно від клієнтського інтерфейсу.
 */
final class AdminChat
{
    public static function db(): PDO
    {
        return Database::connect();
    }

    public static function admins(int $viewerId): array
    {
        $stmt = self::db()->prepare("
            SELECT au.id, au.name, ar.name AS role_name
            FROM admin_users au
            INNER JOIN admin_roles ar ON ar.id = au.role_id
            WHERE au.is_active = 1 AND au.id <> ?
            ORDER BY au.name ASC, au.id ASC
        ");
        $stmt->execute([$viewerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listFor(int $viewerId): array
    {
        $stmt = self::db()->prepare("
            SELECT c.id, c.kind, c.title, c.created_by, c.updated_at,
                CASE WHEN c.kind = 'group' THEN c.title ELSE
                    (SELECT a.name
                     FROM admin_chat_members other
                     JOIN admin_users a ON a.id = other.admin_id
                     WHERE other.conversation_id = c.id AND other.admin_id <> ?
                     LIMIT 1)
                END AS display_name,
                (SELECT m.body FROM admin_chat_messages m
                 WHERE m.conversation_id = c.id
                 ORDER BY m.id DESC LIMIT 1) AS preview,
                (SELECT COUNT(*) FROM admin_chat_messages m
                 WHERE m.conversation_id = c.id
                   AND m.id > self_member.last_read_message_id
                   AND m.sender_id <> ?
                   AND m.deleted_at IS NULL) AS unread,
                (SELECT MAX(m.id) FROM admin_chat_messages m
                 WHERE m.conversation_id = c.id) AS last_message_id
            FROM admin_chat_members self_member
            INNER JOIN admin_chat_conversations c
                ON c.id = self_member.conversation_id
            WHERE self_member.admin_id = ?
            ORDER BY c.updated_at DESC, c.id DESC
            LIMIT 150
        ");
        $stmt->execute([$viewerId, $viewerId, $viewerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function unreadTotal(int $viewerId): int
    {
        $stmt = self::db()->prepare("
            SELECT COUNT(*)
            FROM admin_chat_messages m
            INNER JOIN admin_chat_members cm
                ON cm.conversation_id = m.conversation_id
            WHERE cm.admin_id = ?
                AND m.id > cm.last_read_message_id
                AND m.sender_id <> ?
                AND m.deleted_at IS NULL
        ");
        $stmt->execute([$viewerId, $viewerId]);
        return (int) $stmt->fetchColumn();
    }

    public static function conversation(int $id, int $adminId): array
    {
        if ($id <= 0 || $adminId <= 0) {
            throw new DomainException('Розмову не знайдено.');
        }
        $stmt = self::db()->prepare("
            SELECT c.id, c.kind, c.title, c.created_by
            FROM admin_chat_conversations c
            INNER JOIN admin_chat_members m ON m.conversation_id = c.id
            WHERE c.id = ? AND m.admin_id = ?
            LIMIT 1
        ");
        $stmt->execute([$id, $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Розмову не знайдено.');
        }
        return $row;
    }

    public static function members(int $id, int $viewerId): array
    {
        self::conversation($id, $viewerId);
        $stmt = self::db()->prepare("
            SELECT au.id, au.name, ar.name AS role_name, cm.joined_at
            FROM admin_chat_members cm
            JOIN admin_users au ON au.id = cm.admin_id
            JOIN admin_roles ar ON ar.id = au.role_id
            WHERE cm.conversation_id = ?
            ORDER BY au.name, au.id
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function direct(int $viewerId, int $targetId): int
    {
        if ($targetId <= 0 || $targetId === $viewerId) {
            throw new InvalidArgumentException('Оберіть іншого адміністратора.');
        }
        $stmt = self::db()->prepare("SELECT id FROM admin_users WHERE id = ? AND is_active = 1");
        $stmt->execute([$targetId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('Адміністратора не знайдено.');
        }
        $ids = [$viewerId, $targetId];
        sort($ids, SORT_NUMERIC);
        $key = hash('sha256', implode(':', $ids));
        $db = self::db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO admin_chat_conversations (kind, created_by, direct_key)
                VALUES ('direct', ?, ?)
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
            ");
            $stmt->execute([$viewerId, $key]);
            $id = (int) $db->lastInsertId();
            $member = $db->prepare("
                INSERT IGNORE INTO admin_chat_members (conversation_id, admin_id)
                VALUES (?, ?)
            ");
            foreach ($ids as $adminId) {
                $member->execute([$id, $adminId]);
            }
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function createGroup(int $creatorId, string $title, array $members): int
    {
        $title = self::validTitle($title);
        $ids = array_values(array_unique(array_filter(array_map('intval', $members),
            static fn($id) => $id > 0 && $id !== $creatorId)));
        if (!$ids || count($ids) > 50) {
            throw new InvalidArgumentException('Оберіть від 1 до 50 учасників групи.');
        }
        $db = self::db();
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $check = $db->prepare("SELECT id FROM admin_users WHERE is_active = 1 AND id IN ($marks)");
        $check->execute($ids);
        if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($ids)) {
            throw new InvalidArgumentException('Серед учасників є недоступний адміністратор.');
        }
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO admin_chat_conversations (kind, title, created_by)
                VALUES ('group', ?, ?)
            ");
            $stmt->execute([$title, $creatorId]);
            $id = (int) $db->lastInsertId();
            $member = $db->prepare("
                INSERT INTO admin_chat_members (conversation_id, admin_id) VALUES (?, ?)
            ");
            foreach (array_merge([$creatorId], $ids) as $adminId) {
                $member->execute([$id, $adminId]);
            }
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function manageGroup(
        int $id, int $actorId, string $action, int $memberId = 0, string $title = ''
    ): void {
        $conversation = self::conversation($id, $actorId);
        if ($conversation['kind'] !== 'group') {
            throw new DomainException('Це не груповий чат.');
        }
        $actor = AdminAccess::current();
        $isSenior = in_array((string) ($actor['role_slug'] ?? ''), ['owner', 'store_owner'], true);
        if ((int) $conversation['created_by'] !== $actorId && !$isSenior) {
            throw new DomainException('Керувати групою може її творець або власник.');
        }
        $db = self::db();
        if ($action === 'rename') {
            $stmt = $db->prepare("UPDATE admin_chat_conversations SET title = ? WHERE id = ?");
            $stmt->execute([self::validTitle($title), $id]);
            return;
        }
        if ($memberId <= 0) {
            throw new InvalidArgumentException('Оберіть адміністратора.');
        }
        if ($action === 'add') {
            $check = $db->prepare("SELECT 1 FROM admin_users WHERE id = ? AND is_active = 1");
            $check->execute([$memberId]);
            if (!$check->fetchColumn()) {
                throw new DomainException('Адміністратора не знайдено.');
            }
            $stmt = $db->prepare("
                INSERT IGNORE INTO admin_chat_members (conversation_id, admin_id)
                VALUES (?, ?)
            ");
            $stmt->execute([$id, $memberId]);
            return;
        }
        if ($action === 'remove') {
            if ($memberId === (int) $conversation['created_by']) {
                throw new DomainException('Не можна вилучити творця групи.');
            }
            $stmt = $db->prepare("
                DELETE FROM admin_chat_members WHERE conversation_id = ? AND admin_id = ?
            ");
            $stmt->execute([$id, $memberId]);
            return;
        }
        throw new InvalidArgumentException('Невідома дія.');
    }

    public static function messages(int $id, int $viewerId, int $after = 0): array
    {
        self::conversation($id, $viewerId);
        $db = self::db();
        if ($after > 0) {
            $stmt = $db->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, au.name AS sender_name,
                    m.body, m.reply_to_id, m.created_at, m.edited_at, m.deleted_at,
                    quoted.body AS reply_body, reply_sender.name AS reply_sender
                FROM admin_chat_messages m
                JOIN admin_users au ON au.id = m.sender_id
                LEFT JOIN admin_chat_messages quoted ON quoted.id = m.reply_to_id
                LEFT JOIN admin_users reply_sender ON reply_sender.id = quoted.sender_id
                WHERE m.conversation_id = ? AND m.id > ?
                ORDER BY m.id ASC LIMIT 100
            ");
            $stmt->execute([$id, $after]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $db->prepare("
                SELECT m.id, m.conversation_id, m.sender_id, au.name AS sender_name,
                    m.body, m.reply_to_id, m.created_at, m.edited_at, m.deleted_at,
                    quoted.body AS reply_body, reply_sender.name AS reply_sender
                FROM admin_chat_messages m
                JOIN admin_users au ON au.id = m.sender_id
                LEFT JOIN admin_chat_messages quoted ON quoted.id = m.reply_to_id
                LEFT JOIN admin_users reply_sender ON reply_sender.id = quoted.sender_id
                WHERE m.conversation_id = ?
                ORDER BY m.id DESC LIMIT 70
            ");
            $stmt->execute([$id]);
            $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        $ids = array_map(static fn($row) => (int) $row['id'], $rows);
        $attachments = [];
        if ($ids) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare("
                SELECT id, message_id, original_name, mime_type, byte_size
                FROM admin_chat_attachments WHERE message_id IN ($marks)
                ORDER BY id ASC
            ");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $attachments[(int) $file['message_id']][] = $file;
            }
        }
        foreach ($rows as &$row) {
            $deleted = !empty($row['deleted_at']);
            $row['body'] = $deleted ? '' : (string) ($row['body'] ?? '');
            $row['attachments'] = $deleted ? [] : ($attachments[(int) $row['id']] ?? []);
            if ($deleted) {
                $row['reply_body'] = '';
            }
        }
        unset($row);
        return $rows;
    }

    public static function send(int $id, int $actorId, string $body, int $replyId): int
    {
        self::conversation($id, $actorId);
        $body = trim($body);
        if (mb_strlen($body, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('Повідомлення не може бути довшим за 4000 символів.');
        }
        if ($replyId > 0) {
            $check = self::db()->prepare("
                SELECT 1 FROM admin_chat_messages
                WHERE id = ? AND conversation_id = ? AND deleted_at IS NULL
            ");
            $check->execute([$replyId, $id]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('Повідомлення для відповіді недоступне.');
            }
        }
        $stmt = self::db()->prepare("
            INSERT INTO admin_chat_messages (conversation_id, sender_id, body, reply_to_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$id, $actorId, $body, $replyId > 0 ? $replyId : null]);
        $messageId = (int) self::db()->lastInsertId();
        self::db()->prepare("
            UPDATE admin_chat_conversations SET updated_at = NOW() WHERE id = ?
        ")->execute([$id]);
        return $messageId;
    }

    public static function addAttachment(
        int $messageId, string $diskName, string $original, string $mime, int $bytes
    ): void {
        self::db()->prepare("
            INSERT INTO admin_chat_attachments
                (message_id, disk_name, original_name, mime_type, byte_size)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$messageId, $diskName, $original, $mime, $bytes]);
    }

    public static function edit(int $id, int $actorId, string $body): void
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body, 'UTF-8') > 4000) {
            throw new InvalidArgumentException('Вкажіть текст до 4000 символів.');
        }
        $stmt = self::db()->prepare("
            UPDATE admin_chat_messages
            SET body = ?, edited_at = NOW()
            WHERE id = ? AND sender_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$body, $id, $actorId]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('Можна редагувати лише власне повідомлення.');
        }
    }

    public static function delete(int $id, int $actorId): void
    {
        $stmt = self::db()->prepare("
            UPDATE admin_chat_messages
            SET body = '', deleted_at = NOW()
            WHERE id = ? AND sender_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$id, $actorId]);
        if ($stmt->rowCount() === 0) {
            throw new DomainException('Можна видаляти лише власне повідомлення.');
        }
    }

    public static function read(int $id, int $actorId, int $messageId): void
    {
        self::conversation($id, $actorId);
        $stmt = self::db()->prepare("
            SELECT COALESCE(MAX(id), 0) FROM admin_chat_messages
            WHERE conversation_id = ?
        ");
        $stmt->execute([$id]);
        $latest = (int) $stmt->fetchColumn();
        $mark = max(0, min($messageId, $latest));
        self::db()->prepare("
            UPDATE admin_chat_members
            SET last_read_message_id = GREATEST(last_read_message_id, ?)
            WHERE conversation_id = ? AND admin_id = ?
        ")->execute([$mark, $id, $actorId]);
    }

    public static function file(int $id, int $actorId): array
    {
        $stmt = self::db()->prepare("
            SELECT f.id, f.disk_name, f.original_name, f.mime_type, f.byte_size
            FROM admin_chat_attachments f
            JOIN admin_chat_messages m ON m.id = f.message_id
            JOIN admin_chat_members cm ON cm.conversation_id = m.conversation_id
            WHERE f.id = ? AND cm.admin_id = ? AND m.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$id, $actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Вкладення не знайдено.');
        }
        return $row;
    }

    public static function search(int $actorId, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term, 'UTF-8') < 2 || mb_strlen($term, 'UTF-8') > 100) {
            return [];
        }
        $stmt = self::db()->prepare("
            SELECT m.id, m.conversation_id, m.body, m.created_at, au.name AS sender_name
            FROM admin_chat_messages m
            INNER JOIN admin_chat_members cm
                ON cm.conversation_id = m.conversation_id
            INNER JOIN admin_users au ON au.id = m.sender_id
            WHERE cm.admin_id = ? AND m.deleted_at IS NULL AND m.body LIKE ?
            ORDER BY m.id DESC LIMIT 40
        ");
        $stmt->execute([$actorId, '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function validTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title, 'UTF-8') > 120) {
            throw new InvalidArgumentException('Вкажіть назву групи до 120 символів.');
        }
        return $title;
    }
}
