<?php

final class SystemNotificationService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function dispatchPending(int $limit = 40): int
    {
        $limit = max(1, min(100, $limit));
        $result = $this->db->query(
            "SELECT * FROM system_notification_events WHERE status = 'pending'"
            . ' ORDER BY id ASC LIMIT ' . $limit
        );
        if (!$result) {
            return 0;
        }
        $delivered = 0;
        while ($event = $result->fetch_assoc()) {
            $eventId = (int) $event['id'];
            $claim = $this->db->prepare(
                "UPDATE system_notification_events SET status = 'processing', attempts = attempts + 1"
                . " WHERE id = ? AND status = 'pending'"
            );
            $claim->bind_param('i', $eventId);
            $claim->execute();
            $claimed = $claim->affected_rows === 1;
            $claim->close();
            if (!$claimed) {
                continue;
            }
            try {
                $this->deliver($event);
                $done = $this->db->prepare(
                    "UPDATE system_notification_events SET status = 'delivered', processed_at = NOW(), last_error = NULL WHERE id = ?"
                );
                $done->bind_param('i', $eventId);
                $done->execute();
                $done->close();
                $delivered++;
            } catch (Throwable $exception) {
                $error = mb_substr($exception->getMessage(), 0, 500);
                $failed = $this->db->prepare(
                    "UPDATE system_notification_events SET status = IF(attempts >= 3, 'failed', 'pending'), last_error = ? WHERE id = ?"
                );
                $failed->bind_param('si', $error, $eventId);
                $failed->execute();
                $failed->close();
                error_log('Notification event ' . $eventId . ' failed: ' . $error);
            }
        }
        return $delivered;
    }

    private function deliver(array $event): void
    {
        if (!empty($event['subject_user_id'])) {
            $this->insertNotification($event, (int) $event['subject_user_id'], null);
        }
        if (!empty($event['subject_member_id'])) {
            $this->insertNotification($event, null, (int) $event['subject_member_id']);
        }
        $permission = trim((string) ($event['recipient_permission'] ?? ''));
        if ($permission === '') {
            return;
        }

        $churchId = (int) ($event['church_id'] ?? 0);
        $stmt = $this->db->prepare(
            "SELECT DISTINCT user_account.id"
            . " FROM users user_account"
            . " JOIN user_roles user_role ON user_role.user_id = user_account.id AND user_role.is_active = 1"
            . " JOIN roles access_role ON access_role.id = user_role.role_id AND access_role.is_active = 1"
            . " JOIN role_permissions role_permission ON role_permission.role_id = access_role.id AND role_permission.is_active = 1"
            . " JOIN permissions permission ON permission.id = role_permission.permission_id AND permission.is_active = 1"
            . " LEFT JOIN members member ON member.id = user_account.member_id"
            . " WHERE user_account.status = 'active' AND permission.name = ?"
            . " AND (access_role.id = 1 OR ? = 0 OR COALESCE(user_account.church_id, member.church_id) = ?)"
        );
        $stmt->bind_param('sii', $permission, $churchId, $churchId);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($users as $user) {
            $this->insertNotification($event, (int) $user['id'], null);
        }
    }

    private function insertNotification(array $event, ?int $userId, ?int $memberId): void
    {
        $eventId = (int) $event['id'];
        $meta = json_encode([
            'event_code' => $event['event_code'],
            'entity_type' => $event['entity_type'],
            'entity_id' => $event['entity_id'],
        ], JSON_UNESCAPED_SLASHES);
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO dashboard_notifications'
            . ' (target_user_id, target_member_id, notification_type, category, severity, icon,'
            . ' title, message, action_url, source_event_id, is_read, meta_json)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->bind_param(
            'iisssssssis',
            $userId,
            $memberId,
            $event['event_code'],
            $event['category'],
            $event['severity'],
            $event['icon'],
            $event['title'],
            $event['message'],
            $event['action_url'],
            $eventId,
            $meta
        );
        $stmt->execute();
        $stmt->close();
    }
}
