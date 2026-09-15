<?php

final class InAppMessagingService
{
    private mysqli $db;
    private string $actorType;
    private int $actorId;
    private int $churchId;
    private ?bool $available = null;

    public function __construct(mysqli $db, string $actorType, int $actorId)
    {
        if (!in_array($actorType, ['user', 'member'], true) || $actorId <= 0) {
            throw new InvalidArgumentException('A valid messaging actor is required.');
        }

        $this->db = $db;
        $this->actorType = $actorType;
        $this->actorId = $actorId;
        $this->churchId = $this->resolveChurchId();
    }

    public static function fromSession(mysqli $db): self
    {
        if (!empty($_SESSION['user_id'])) {
            return new self($db, 'user', (int) $_SESSION['user_id']);
        }
        if (!empty($_SESSION['member_id'])) {
            return new self($db, 'member', (int) $_SESSION['member_id']);
        }

        throw new RuntimeException('Sign in before opening messages.');
    }

    public function actorType(): string
    {
        return $this->actorType;
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function isAvailable(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        $sql = "SELECT
                    (SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME IN ('chat_threads', 'chat_thread_members', 'chat_messages', 'dashboard_notifications')) AS table_count,
                    (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_threads'
                       AND COLUMN_NAME IN ('direct_key', 'created_by_member_id')) AS thread_columns,
                    (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_thread_members'
                       AND COLUMN_NAME = 'last_read_message_id') AS read_column";
        $row = $this->db->query($sql)->fetch_assoc();
        $this->available = (int) ($row['table_count'] ?? 0) === 4
            && (int) ($row['thread_columns'] ?? 0) === 2
            && (int) ($row['read_column'] ?? 0) === 1;

        return $this->available;
    }

    public function unreadMessageCount(): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $actorColumn = $this->actorType === 'user' ? 'membership.user_id' : 'membership.member_id';
        $senderColumn = $this->actorType === 'user' ? 'message.sender_user_id' : 'message.sender_member_id';
        $sql = "SELECT COUNT(*) AS unread_count
                FROM chat_thread_members membership
                JOIN chat_threads thread ON thread.id = membership.thread_id AND thread.is_active = 1
                JOIN chat_messages message
                  ON message.thread_id = thread.id
                 AND message.is_deleted = 0
                 AND message.id > COALESCE(membership.last_read_message_id, 0)
                WHERE {$actorColumn} = ?
                  AND ({$senderColumn} IS NULL OR {$senderColumn} <> ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $this->actorId, $this->actorId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
        $stmt->close();

        return $count;
    }

    public function unreadNotificationCount(): int
    {
        $column = $this->actorType === 'user' ? 'target_user_id' : 'target_member_id';
        $stmt = $this->db->prepare("SELECT COUNT(*) AS unread_count
            FROM dashboard_notifications WHERE {$column} = ? AND is_read = 0");
        $stmt->bind_param('i', $this->actorId);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['unread_count'] ?? 0);
        $stmt->close();

        return $count;
    }

    public function listThreads(): array
    {
        $this->requireAvailable();
        $actorColumn = $this->actorType === 'user' ? 'membership.user_id' : 'membership.member_id';
        $senderColumn = $this->actorType === 'user' ? 'message.sender_user_id' : 'message.sender_member_id';
        $sql = "SELECT thread.id, thread.subject, thread.thread_type, thread.updated_at,
                       COALESCE(MAX(message.created_at), thread.created_at) AS last_activity,
                       COALESCE(SUM(CASE
                           WHEN message.is_deleted = 0
                            AND message.id > COALESCE(membership.last_read_message_id, 0)
                            AND ({$senderColumn} IS NULL OR {$senderColumn} <> ?)
                           THEN 1 ELSE 0 END), 0) AS unread_count
                FROM chat_thread_members membership
                JOIN chat_threads thread ON thread.id = membership.thread_id AND thread.is_active = 1
                LEFT JOIN chat_messages message ON message.thread_id = thread.id
                WHERE {$actorColumn} = ?
                GROUP BY thread.id, thread.subject, thread.thread_type, thread.updated_at, thread.created_at
                ORDER BY last_activity DESC, thread.id DESC
                LIMIT 100";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $this->actorId, $this->actorId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['display_name'] = $row['subject'] ?: $this->participantLabel((int) $row['id']);
            $row['unread_count'] = (int) $row['unread_count'];
        }
        unset($row);

        return $rows;
    }

    public function getThread(int $threadId): array
    {
        $this->assertThreadMembership($threadId);
        $stmt = $this->db->prepare('SELECT id, thread_type, subject, created_at, updated_at
            FROM chat_threads WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $threadId);
        $stmt->execute();
        $thread = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$thread) {
            throw new RuntimeException('Conversation not found.');
        }
        $thread['display_name'] = $thread['subject'] ?: $this->participantLabel($threadId);

        return $thread;
    }

    public function getMessages(int $threadId): array
    {
        $this->assertThreadMembership($threadId);
        $stmt = $this->db->prepare(
            "SELECT message.id, message.message_text, message.message_type, message.created_at,
                    message.sender_user_id, message.sender_member_id,
                    COALESCE(user_account.name,
                        TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)),
                        'System') AS sender_name
             FROM chat_messages message
             LEFT JOIN users user_account ON user_account.id = message.sender_user_id
             LEFT JOIN members member ON member.id = message.sender_member_id
             WHERE message.thread_id = ? AND message.is_deleted = 0
             ORDER BY message.id ASC LIMIT 500"
        );
        $stmt->bind_param('i', $threadId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['is_mine'] = $this->actorType === 'user'
                ? (int) ($row['sender_user_id'] ?? 0) === $this->actorId
                : (int) ($row['sender_member_id'] ?? 0) === $this->actorId;
        }
        unset($row);

        return $rows;
    }

    public function searchRecipients(string $query = ''): array
    {
        $query = trim($query);
        if ($this->actorType === 'member') {
            $like = '%' . $query . '%';
            $stmt = $this->db->prepare(
                "SELECT 'user' AS recipient_type, user_account.id,
                        user_account.name AS display_name, user_account.email AS reference
                 FROM users user_account
                 LEFT JOIN members member ON member.id = user_account.member_id
                 WHERE user_account.status = 'active'
                   AND COALESCE(user_account.church_id, member.church_id) = ?
                   AND (? = '' OR user_account.name LIKE ? OR user_account.email LIKE ?)
                 ORDER BY user_account.name LIMIT 100"
            );
            $stmt->bind_param('isss', $this->churchId, $query, $like, $like);
        } else {
            $like = '%' . $query . '%';
            $stmt = $this->db->prepare(
                "SELECT 'member' AS recipient_type, member.id,
                        TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS display_name,
                        member.crn AS reference
                 FROM members member
                 WHERE member.church_id = ? AND member.status = 'active' AND member.is_archived = 0
                   AND (? = '' OR member.crn LIKE ?
                        OR CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name) LIKE ?)
                 ORDER BY member.first_name, member.last_name LIMIT 100"
            );
            $stmt->bind_param('isss', $this->churchId, $query, $like, $like);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function startConversation(string $targetType, int $targetId, string $messageText, string $subject = ''): int
    {
        $this->requireAvailable();
        $this->assertRecipient($targetType, $targetId);
        $messageText = $this->validateMessage($messageText);
        $subject = trim($subject);
        if (mb_strlen($subject) > 180) {
            throw new InvalidArgumentException('The subject cannot exceed 180 characters.');
        }

        $actors = [$this->actorType . ':' . $this->actorId, $targetType . ':' . $targetId];
        sort($actors, SORT_STRING);
        $directKey = implode('|', $actors);
        $createdByUser = $this->actorType === 'user' ? $this->actorId : null;
        $createdByMember = $this->actorType === 'member' ? $this->actorId : null;

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO chat_threads
                    (thread_type, subject, direct_key, created_by_user_id, created_by_member_id, is_active)
                 VALUES ('direct', NULLIF(?, ''), ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), is_active = 1"
            );
            $stmt->bind_param('ssii', $subject, $directKey, $createdByUser, $createdByMember);
            $stmt->execute();
            $threadId = (int) $this->db->insert_id;
            $stmt->close();

            $this->insertParticipant($threadId, $this->actorType, $this->actorId, 'owner');
            $this->insertParticipant($threadId, $targetType, $targetId, 'member');
            $this->insertMessage($threadId, $messageText);
            $this->db->commit();

            return $threadId;
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
    }

    public function reply(int $threadId, string $messageText): int
    {
        $this->assertThreadMembership($threadId);
        return $this->insertMessage($threadId, $this->validateMessage($messageText));
    }

    public function markThreadRead(int $threadId): void
    {
        $this->assertThreadMembership($threadId);
        $actorColumn = $this->actorType === 'user' ? 'user_id' : 'member_id';
        $stmt = $this->db->prepare(
            "UPDATE chat_thread_members membership
             SET membership.last_read_message_id = COALESCE(
                    (SELECT MAX(message.id) FROM chat_messages message
                     WHERE message.thread_id = membership.thread_id AND message.is_deleted = 0),
                    membership.last_read_message_id),
                 membership.last_read_at = NOW()
             WHERE membership.thread_id = ? AND membership.{$actorColumn} = ?"
        );
        $stmt->bind_param('ii', $threadId, $this->actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function listNotifications(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $column = $this->actorType === 'user' ? 'target_user_id' : 'target_member_id';
        $stmt = $this->db->prepare("SELECT id, notification_type, title, message, action_url,
                is_read, created_at, read_at
            FROM dashboard_notifications WHERE {$column} = ?
            ORDER BY is_read ASC, created_at DESC, id DESC LIMIT ?");
        $stmt->bind_param('ii', $this->actorId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function markNotificationRead(int $notificationId): void
    {
        $column = $this->actorType === 'user' ? 'target_user_id' : 'target_member_id';
        $stmt = $this->db->prepare("UPDATE dashboard_notifications
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE id = ? AND {$column} = ?");
        $stmt->bind_param('ii', $notificationId, $this->actorId);
        $stmt->execute();
        $stmt->close();
    }

    public function markAllNotificationsRead(): void
    {
        $column = $this->actorType === 'user' ? 'target_user_id' : 'target_member_id';
        $stmt = $this->db->prepare("UPDATE dashboard_notifications
            SET is_read = 1, read_at = COALESCE(read_at, NOW())
            WHERE {$column} = ? AND is_read = 0");
        $stmt->bind_param('i', $this->actorId);
        $stmt->execute();
        $stmt->close();
    }

    private function resolveChurchId(): int
    {
        if ($this->actorType === 'member') {
            $stmt = $this->db->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
        } else {
            $stmt = $this->db->prepare('SELECT COALESCE(user_account.church_id, member.church_id) AS church_id
                FROM users user_account LEFT JOIN members member ON member.id = user_account.member_id
                WHERE user_account.id = ? LIMIT 1');
        }
        $stmt->bind_param('i', $this->actorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $churchId = (int) ($row['church_id'] ?? 0);
        if ($churchId <= 0) {
            throw new RuntimeException('The signed-in account has no church assignment.');
        }

        return $churchId;
    }

    private function assertRecipient(string $targetType, int $targetId): void
    {
        if (!in_array($targetType, ['user', 'member'], true) || $targetId <= 0) {
            throw new InvalidArgumentException('Choose a valid recipient.');
        }
        if ($this->actorType === 'member' && $targetType !== 'user') {
            throw new RuntimeException('Members may start new conversations only with church administrators.');
        }
        if ($targetType === $this->actorType && $targetId === $this->actorId) {
            throw new InvalidArgumentException('Choose someone other than yourself.');
        }

        if ($targetType === 'user') {
            $stmt = $this->db->prepare("SELECT 1 FROM users user_account
                LEFT JOIN members member ON member.id = user_account.member_id
                WHERE user_account.id = ? AND user_account.status = 'active'
                  AND COALESCE(user_account.church_id, member.church_id) = ? LIMIT 1");
        } else {
            $stmt = $this->db->prepare("SELECT 1 FROM members
                WHERE id = ? AND church_id = ? AND status = 'active' AND is_archived = 0 LIMIT 1");
        }
        $stmt->bind_param('ii', $targetId, $this->churchId);
        $stmt->execute();
        $valid = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$valid) {
            throw new RuntimeException('The recipient is unavailable or outside your church.');
        }
    }

    private function assertThreadMembership(int $threadId): void
    {
        $this->requireAvailable();
        if ($threadId <= 0) {
            throw new InvalidArgumentException('Choose a valid conversation.');
        }
        $column = $this->actorType === 'user' ? 'user_id' : 'member_id';
        $stmt = $this->db->prepare("SELECT 1 FROM chat_thread_members membership
            JOIN chat_threads thread ON thread.id = membership.thread_id AND thread.is_active = 1
            WHERE membership.thread_id = ? AND membership.{$column} = ? LIMIT 1");
        $stmt->bind_param('ii', $threadId, $this->actorId);
        $stmt->execute();
        $valid = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$valid) {
            throw new RuntimeException('You do not have access to this conversation.');
        }
    }

    private function participantLabel(int $threadId): string
    {
        $stmt = $this->db->prepare(
            "SELECT GROUP_CONCAT(DISTINCT COALESCE(user_account.name,
                        TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)))
                    ORDER BY COALESCE(user_account.name, member.first_name) SEPARATOR ', ') AS names
             FROM chat_thread_members participant
             LEFT JOIN users user_account ON user_account.id = participant.user_id
             LEFT JOIN members member ON member.id = participant.member_id
             WHERE participant.thread_id = ?
               AND NOT ((? = 'user' AND participant.user_id = ?)
                     OR (? = 'member' AND participant.member_id = ?))"
        );
        $stmt->bind_param('isisi', $threadId, $this->actorType, $this->actorId, $this->actorType, $this->actorId);
        $stmt->execute();
        $label = trim((string) ($stmt->get_result()->fetch_assoc()['names'] ?? ''));
        $stmt->close();

        return $label !== '' ? $label : 'Conversation';
    }

    private function insertParticipant(int $threadId, string $type, int $id, string $role): void
    {
        $userId = $type === 'user' ? $id : null;
        $memberId = $type === 'member' ? $id : null;
        $stmt = $this->db->prepare('INSERT IGNORE INTO chat_thread_members
            (thread_id, user_id, member_id, member_role) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiis', $threadId, $userId, $memberId, $role);
        $stmt->execute();
        $stmt->close();
    }

    private function insertMessage(int $threadId, string $messageText): int
    {
        $senderUser = $this->actorType === 'user' ? $this->actorId : null;
        $senderMember = $this->actorType === 'member' ? $this->actorId : null;
        $stmt = $this->db->prepare('INSERT INTO chat_messages
            (thread_id, sender_user_id, sender_member_id, message_text, message_type)
            VALUES (?, ?, ?, ?, \'text\')');
        $stmt->bind_param('iiis', $threadId, $senderUser, $senderMember, $messageText);
        $stmt->execute();
        $messageId = (int) $this->db->insert_id;
        $stmt->close();

        $touch = $this->db->prepare('UPDATE chat_threads SET updated_at = NOW() WHERE id = ?');
        $touch->bind_param('i', $threadId);
        $touch->execute();
        $touch->close();

        return $messageId;
    }

    private function validateMessage(string $messageText): string
    {
        $messageText = trim($messageText);
        if ($messageText === '') {
            throw new InvalidArgumentException('Enter a message.');
        }
        if (mb_strlen($messageText) > 5000) {
            throw new InvalidArgumentException('Messages cannot exceed 5,000 characters.');
        }

        return $messageText;
    }

    private function requireAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Messaging is not available until database Phase 0022 is installed.');
        }
    }
}
