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

    public function queueScheduledReminders(): void
    {
        // Daily birthday events notify the member and users who are allowed to
        // view birthday information. The dated key makes repeated page/cron
        // runs harmless.
        $this->db->query("INSERT IGNORE INTO system_notification_events
            (event_code, church_id, subject_member_id, recipient_permission,
             category, severity, icon, title, message, action_url,
             entity_type, entity_id, dedupe_key)
            SELECT 'birthday.today', member.church_id, member.id, 'view_birthdays',
                   'birthdays', 'success', 'fas fa-birthday-cake',
                   'Birthday today',
                   CONCAT(TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)),
                          ' celebrates a birthday today.'),
                   'views/birthday_directory.php', 'member', member.id,
                   CONCAT('birthday.today:', member.id, ':', DATE_FORMAT(CURDATE(), '%Y-%m-%d'))
            FROM members member
            WHERE member.status = 'active' AND member.is_archived = 0
              AND member.dob IS NOT NULL AND member.dob >= '1900-01-01'
              AND DATE_FORMAT(member.dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')");

        // Registered members receive reminders seven days and one day before
        // an active event. One event is queued per registration and interval.
        $this->db->query("INSERT IGNORE INTO system_notification_events
            (event_code, church_id, subject_member_id, category, severity, icon,
             title, message, action_url, entity_type, entity_id, dedupe_key)
            SELECT CONCAT('event.reminder_', DATEDIFF(event.event_date, CURDATE()), 'd'),
                   event.church_id, registration.member_id, 'events', 'info',
                   'fas fa-calendar-day', 'Upcoming event reminder',
                   CONCAT(event.name, ' is scheduled for ', DATE_FORMAT(event.event_date, '%M %e, %Y'),
                          IF(event.event_time IS NULL, '.', CONCAT(' at ', TIME_FORMAT(event.event_time, '%h:%i %p'), '.'))),
                   CONCAT('views/member_events.php?event_id=', event.id),
                   'event_registration', registration.id,
                   CONCAT('event.reminder:', registration.id, ':', DATEDIFF(event.event_date, CURDATE()), 'd')
            FROM event_registrations registration
            JOIN events event ON event.id = registration.event_id
            JOIN members member ON member.id = registration.member_id
            WHERE event.status = 'active' AND registration.registration_status = 'registered'
              AND member.status = 'active' AND member.is_archived = 0
              AND DATEDIFF(event.event_date, CURDATE()) IN (1, 7)");
    }

    private function deliver(array $event): void
    {
        if (!empty($event['subject_user_id'])) {
            $this->insertNotification($event, (int) $event['subject_user_id'], null, 'subject');
        }
        if (!empty($event['subject_member_id'])) {
            $this->insertNotification($event, null, (int) $event['subject_member_id'], 'subject');
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
            $this->insertNotification($event, (int) $user['id'], null, 'reviewer');
        }

        if (!empty($event['notify_church_members']) && $churchId > 0) {
            $members = $this->db->prepare("SELECT id FROM members
                WHERE church_id = ? AND status = 'active' AND is_archived = 0");
            $members->bind_param('i', $churchId);
            $members->execute();
            $memberRows = $members->get_result()->fetch_all(MYSQLI_ASSOC);
            $members->close();
            foreach ($memberRows as $member) {
                $this->insertNotification($event, null, (int) $member['id'], 'member_broadcast');
            }
        }
    }

    private function insertNotification(
        array $event,
        ?int $userId,
        ?int $memberId,
        string $audience
    ): void
    {
        $eventId = (int) $event['id'];
        [$title, $message] = $this->notificationCopy($event, $audience);
        $meta = json_encode([
            'event_code' => $event['event_code'],
            'entity_type' => $event['entity_type'],
            'entity_id' => $event['entity_id'],
            'audience' => $audience,
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
            $title,
            $message,
            $event['action_url'],
            $eventId,
            $meta
        );
        $stmt->execute();
        $stmt->close();
    }

    private function notificationCopy(array $event, string $audience): array
    {
        $code = (string) ($event['event_code'] ?? '');
        $originalTitle = (string) ($event['title'] ?? 'Notification');
        $originalMessage = (string) ($event['message'] ?? '');
        $subjectName = $this->subjectName($event);

        if ($audience === 'reviewer') {
            return match ($code) {
                'event.created' => [
                    'Event published to members',
                    $originalMessage,
                ],
                'event.cancelled' => [
                    'Event cancellation published',
                    $originalMessage,
                ],
                'payment.received' => [
                    'New member payment received',
                    $subjectName . ': ' . $originalMessage,
                ],
                'profile.change_requested' => [
                    'Profile change awaiting review',
                    $subjectName . ' submitted profile changes for authorized review.',
                ],
                'event.registered' => [
                    'New event registration',
                    $subjectName . ' registered for an event.',
                ],
                'asset.requested' => [
                    'Asset request awaiting review',
                    $subjectName . ' submitted an asset-use request.',
                ],
                'attendance.submitted' => [
                    'Attendance awaiting approval',
                    $originalMessage,
                ],
                'member.registered' => [
                    'New member registered',
                    $originalMessage,
                ],
                'member.transferred' => [
                    'Member transfer recorded',
                    $subjectName . ': ' . $originalMessage,
                ],
                'health.recorded' => [
                    'New health record recorded',
                    $subjectName . ' has a new health record. Open it only when your role authorizes health-data access.',
                ],
                'event.registration_cancelled', 'event.registration_attended',
                'event.registration_no_show', 'event.registration_registered' => [
                    'Event registration status changed',
                    $subjectName . ': ' . $originalMessage,
                ],
                default => str_starts_with($code, 'member.')
                    ? ['Member lifecycle action recorded', $originalMessage]
                    : [$originalTitle, $originalMessage],
            };
        }

        if ($audience === 'member_broadcast') {
            return match ($code) {
                'event.created' => ['New church event', $originalMessage],
                'event.cancelled' => ['Church event cancelled', $originalMessage],
                default => [$originalTitle, $originalMessage],
            };
        }

        return match ($code) {
            'birthday.today' => [
                'Happy birthday!',
                'Happy birthday! Your church family celebrates with you today.',
            ],
            'payment.received' => [
                'Your payment was received',
                str_replace(' was recorded', ' was credited to your account', $originalMessage),
            ],
            'event.created' => [
                'Your event was published',
                $originalMessage,
            ],
            'event.cancelled' => [
                'Your event cancellation was published',
                $originalMessage,
            ],
            'profile.change_requested' => [
                'Your profile update was submitted',
                'Your profile changes are awaiting authorized review. We will notify you after a decision.',
            ],
            'event.registered' => [
                'Your event registration is recorded',
                'Your event registration was received successfully. Open this notification for the event details.',
            ],
            'event.cancelled_registration' => [
                'Your event registration was cancelled',
                $originalMessage,
            ],
            'event.registration_cancelled' => [
                'Your event registration was cancelled',
                $originalMessage,
            ],
            'event.registration_attended' => [
                'Event attendance confirmed',
                $originalMessage,
            ],
            'event.registration_no_show' => [
                'Event registration marked no-show',
                $originalMessage,
            ],
            'member.registered' => [
                'Welcome—your membership record is ready',
                'Your church membership record has been created successfully.',
            ],
            'member.transferred' => [
                'Your membership transfer was recorded',
                $originalMessage,
            ],
            'health.recorded' => [
                'Your health record was updated',
                'A health screening record was added to your account. Open your health records to review the authorized details.',
            ],
            'asset.requested' => [
                'Your asset request was submitted',
                'Your asset-use request is awaiting an authorized decision.',
            ],
            'attendance.submitted' => [
                'Your attendance record was submitted',
                $originalMessage,
            ],
            default => [$originalTitle, $originalMessage],
        };
    }

    private function subjectName(array $event): string
    {
        $memberId = (int) ($event['subject_member_id'] ?? 0);
        if ($memberId > 0) {
            $stmt = $this->db->prepare(
                "SELECT TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS subject_name"
                . ' FROM members WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $memberId);
        } else {
            $userId = (int) ($event['subject_user_id'] ?? 0);
            if ($userId <= 0) {
                return 'A church member';
            }
            $stmt = $this->db->prepare('SELECT name AS subject_name FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $userId);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return trim((string) ($row['subject_name'] ?? '')) ?: 'A church member';
    }
}
