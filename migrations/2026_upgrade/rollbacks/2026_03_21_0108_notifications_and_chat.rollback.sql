-- rollback: 2026_03_21_0108_notifications_and_chat.sql

ALTER TABLE chat_messages DROP FOREIGN KEY fk_chat_messages_thread;
ALTER TABLE chat_thread_members DROP FOREIGN KEY fk_chat_thread_members_thread;

DROP TABLE IF EXISTS dashboard_notifications;
DROP TABLE IF EXISTS chat_messages;
DROP TABLE IF EXISTS chat_thread_members;
DROP TABLE IF EXISTS chat_threads;
