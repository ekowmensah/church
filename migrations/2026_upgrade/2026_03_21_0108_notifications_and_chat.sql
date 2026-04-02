-- 2026_03_21_0108_notifications_and_chat.sql
-- Purpose: support in-app chat visibility and dashboard notifications for members/admin users.

CREATE TABLE IF NOT EXISTS chat_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_type ENUM('direct', 'group', 'system') NOT NULL DEFAULT 'direct',
    subject VARCHAR(180) NULL,
    created_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_chat_threads_type_active (thread_type, is_active),
    KEY idx_chat_threads_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_thread_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    user_id INT NULL,
    member_id INT NULL,
    member_role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME NULL,
    is_muted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_chat_thread_member (thread_id, user_id, member_id),
    KEY idx_chat_thread_members_thread (thread_id),
    KEY idx_chat_thread_members_user (user_id),
    KEY idx_chat_thread_members_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_user_id INT NULL,
    sender_member_id INT NULL,
    message_text TEXT NOT NULL,
    message_type ENUM('text', 'system', 'attachment') NOT NULL DEFAULT 'text',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at DATETIME NULL,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_chat_messages_thread_created (thread_id, created_at),
    KEY idx_chat_messages_sender_user (sender_user_id),
    KEY idx_chat_messages_sender_member (sender_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dashboard_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    target_user_id INT NULL,
    target_member_id INT NULL,
    notification_type VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    action_url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    meta_json JSON NULL,
    PRIMARY KEY (id),
    KEY idx_dashboard_notifications_target_user (target_user_id, is_read, created_at),
    KEY idx_dashboard_notifications_target_member (target_member_id, is_read, created_at),
    KEY idx_dashboard_notifications_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE chat_thread_members
    ADD CONSTRAINT fk_chat_thread_members_thread
        FOREIGN KEY (thread_id) REFERENCES chat_threads(id)
        ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE chat_messages
    ADD CONSTRAINT fk_chat_messages_thread
        FOREIGN KEY (thread_id) REFERENCES chat_threads(id)
        ON DELETE CASCADE ON UPDATE CASCADE;
