<?php

require_once __DIR__ . '/permissions_v2.php';
require_once __DIR__ . '/church_helper.php';

function member_feedback_actor(): array
{
    if (!empty($_SESSION['member_id'])) {
        return ['type' => 'member', 'id' => (int) $_SESSION['member_id']];
    }
    return ['type' => 'user', 'id' => (int) ($_SESSION['user_id'] ?? 0)];
}

function member_feedback_load_thread(mysqli $conn, int $threadId): ?array
{
    if ($threadId <= 0) return null;
    $actor = member_feedback_actor();
    if ($actor['id'] <= 0) return null;

    if ($actor['type'] === 'member') {
        $stmt = $conn->prepare(
            "SELECT * FROM member_feedback_thread
             WHERE id = ? AND feedback_id IS NULL
               AND ((sender_type = 'member' AND sender_id = ?)
                 OR (recipient_type = 'member' AND recipient_id = ?))
             LIMIT 1"
        );
        $stmt->bind_param('iii', $threadId, $actor['id'], $actor['id']);
    } elseif (is_super_admin()) {
        $stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? AND feedback_id IS NULL LIMIT 1');
        $stmt->bind_param('i', $threadId);
    } else {
        $churchId = (int) get_user_church_id($conn);
        if ($churchId <= 0) return null;
        $stmt = $conn->prepare(
            "SELECT thread.*
             FROM member_feedback_thread thread
             LEFT JOIN members sender_member
               ON thread.sender_type = 'member' AND sender_member.id = thread.sender_id
             LEFT JOIN users sender_user
               ON thread.sender_type = 'user' AND sender_user.id = thread.sender_id
             LEFT JOIN members recipient_member
               ON thread.recipient_type = 'member' AND recipient_member.id = thread.recipient_id
             LEFT JOIN users recipient_user
               ON thread.recipient_type = 'user' AND recipient_user.id = thread.recipient_id
             WHERE thread.id = ? AND thread.feedback_id IS NULL
               AND COALESCE(sender_member.church_id, sender_user.church_id,
                            recipient_member.church_id, recipient_user.church_id) = ?
             LIMIT 1"
        );
        $stmt->bind_param('ii', $threadId, $churchId);
    }

    $stmt->execute();
    $thread = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $thread;
}

function member_feedback_reply_recipient(array $thread, string $senderType, int $senderId): array
{
    if ((string) $thread['sender_type'] === $senderType && (int) $thread['sender_id'] === $senderId) {
        return ['type' => (string) $thread['recipient_type'], 'id' => (int) $thread['recipient_id']];
    }
    if ((string) $thread['recipient_type'] === $senderType && (int) $thread['recipient_id'] === $senderId) {
        return ['type' => (string) $thread['sender_type'], 'id' => (int) $thread['sender_id']];
    }

    // Authorized church staff may handle a conversation without being one of
    // its original endpoints. Route their reply to the member when possible.
    if ((string) $thread['sender_type'] === 'member') {
        return ['type' => 'member', 'id' => (int) $thread['sender_id']];
    }
    if ((string) $thread['recipient_type'] === 'member') {
        return ['type' => 'member', 'id' => (int) $thread['recipient_id']];
    }
    return ['type' => (string) $thread['sender_type'], 'id' => (int) $thread['sender_id']];
}
