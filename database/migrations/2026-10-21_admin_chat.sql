-- Анабелька · приватний чат адміністраторів
-- Виконати вручну після резервної копії. Без зміни наявних таблиць.
-- MySQL/MariaDB (utf8mb4, InnoDB)

CREATE TABLE IF NOT EXISTS admin_chat_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('direct', 'group') NOT NULL,
    title VARCHAR(120) NULL,
    created_by INT UNSIGNED NULL,
    direct_key CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_chat_direct_key (direct_key),
    KEY idx_admin_chat_updated (updated_at, id),
    CONSTRAINT fk_admin_chat_conversation_owner FOREIGN KEY (created_by)
        REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_chat_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id, admin_id),
    KEY idx_admin_chat_members_admin (admin_id, conversation_id),
    CONSTRAINT fk_admin_chat_member_conversation FOREIGN KEY (conversation_id)
        REFERENCES admin_chat_conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_chat_member_admin FOREIGN KEY (admin_id)
        REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NULL,
    body TEXT NULL,
    reply_to_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_admin_chat_message_conversation (conversation_id, id),
    KEY idx_admin_chat_message_sender (sender_id, id),
    CONSTRAINT fk_admin_chat_msg_conversation FOREIGN KEY (conversation_id)
        REFERENCES admin_chat_conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_chat_msg_sender FOREIGN KEY (sender_id)
        REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_admin_chat_msg_reply FOREIGN KEY (reply_to_id)
        REFERENCES admin_chat_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_chat_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    disk_name CHAR(48) NOT NULL,
    original_name VARCHAR(220) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    byte_size BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_chat_file_disk (disk_name),
    KEY idx_admin_chat_file_message (message_id),
    CONSTRAINT fk_admin_chat_file_message FOREIGN KEY (message_id)
        REFERENCES admin_chat_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
