<?php

final class EventManagementService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private ?int $churchId = null;
    private bool $superAdmin;

    public function __construct(
        mysqli $conn,
        ?int $userId,
        ?int $memberId,
        bool $superAdmin = false
    ) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->superAdmin = $superAdmin;

        if ($this->userId !== null) {
            $stmt = $this->conn->prepare('SELECT church_id, member_id FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $this->churchId = (int) ($row['church_id'] ?? 0) ?: null;
                if ($this->memberId === null) {
                    $this->memberId = (int) ($row['member_id'] ?? 0) ?: null;
                }
            }
        }

        if ($this->churchId === null && $this->memberId !== null) {
            $stmt = $this->conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->memberId);
            $stmt->execute();
            $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
            $stmt->close();
        }
    }

    public static function fromSession(mysqli $conn): self {
        $roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
        if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
            !empty($_SESSION['is_super_admin']) || in_array(1, $roleIds, true)
        );
    }

    public function getChurchId(): ?int {
        return $this->churchId;
    }

    public function isSuperAdmin(): bool {
        return $this->superAdmin;
    }

    public function getEvent(int $eventId, bool $includeCancelled = false): ?array {
        $where = ['event.id = ?'];
        $types = 'i';
        $params = [$eventId];
        if (!$includeCancelled) $where[] = "event.status = 'active'";
        if (!$this->superAdmin) {
            if ($this->churchId === null) return null;
            $where[] = 'event.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            'SELECT event.* FROM events event WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $event = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $event;
    }

    public function listEvents(bool $includeCancelled = true, bool $upcomingOnly = false): array {
        $where = [];
        $types = '';
        $params = [];
        if (!$includeCancelled) $where[] = "event.status = 'active'";
        if ($upcomingOnly) $where[] = 'event.event_date >= CURDATE()';
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'event.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $sql = "SELECT event.*, event_type.name AS type_name,
                       SUM(registration.registration_status IN ('registered', 'attended')) AS active_registrations,
                       SUM(registration.registration_status = 'attended') AS attended_count,
                       SUM(registration.registration_status = 'no_show') AS no_show_count,
                       SUM(registration.registration_status = 'cancelled') AS cancelled_registrations
                  FROM events event
                  LEFT JOIN event_types event_type ON event_type.id = event.event_type_id
                  LEFT JOIN event_registrations registration ON registration.event_id = event.id";
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY event.id, event_type.name ORDER BY event.event_date DESC, event.event_time DESC';
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function listRegistrations(int $eventId): array {
        if (!$this->getEvent($eventId, true)) {
            throw new RuntimeException('The event was not found in your church.');
        }
        $stmt = $this->conn->prepare(
            "SELECT registration.*, member.crn, member.first_name,
                    member.middle_name, member.last_name, member.phone
               FROM event_registrations registration
               JOIN members member ON member.id = registration.member_id
              WHERE registration.event_id = ?
              ORDER BY FIELD(registration.registration_status,
                             'registered', 'attended', 'no_show', 'cancelled'),
                       member.last_name, member.first_name, registration.registered_at DESC"
        );
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function listEligibleMembers(int $eventId): array {
        $event = $this->getEvent($eventId);
        if (!$event || empty($event['church_id'])) return [];
        $churchId = (int) $event['church_id'];
        $stmt = $this->conn->prepare(
            "SELECT member.id, member.crn, member.first_name, member.middle_name,
                    member.last_name, registration.registration_status
               FROM members member
               LEFT JOIN event_registrations registration
                 ON registration.event_id = ? AND registration.member_id = member.id
              WHERE member.church_id = ? AND member.status = 'active'
              ORDER BY member.last_name, member.first_name, member.middle_name"
        );
        $stmt->bind_param('ii', $eventId, $churchId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function setEventStatus(int $eventId, string $status, string $reason = ''): void {
        if (!in_array($status, ['active', 'cancelled'], true)) {
            throw new InvalidArgumentException('Choose a valid event status.');
        }
        if (!$this->getEvent($eventId, true)) {
            throw new RuntimeException('The event was not found in your church.');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($status === 'cancelled' && $reason === '') {
            throw new RuntimeException('Enter a cancellation reason.');
        }
        $stmt = $this->conn->prepare(
            "UPDATE events
                SET status = ?,
                    cancelled_by_user_id = CASE WHEN ? = 'cancelled' THEN ? ELSE NULL END,
                    cancelled_at = CASE WHEN ? = 'cancelled' THEN NOW() ELSE NULL END,
                    cancellation_reason = CASE WHEN ? = 'cancelled' THEN ? ELSE NULL END
              WHERE id = ?"
        );
        $actorUserId = $this->userId;
        $stmt->bind_param(
            'ssisssi',
            $status,
            $status,
            $actorUserId,
            $status,
            $status,
            $reason,
            $eventId
        );
        $stmt->execute();
        $stmt->close();
    }

    public function registerMember(
        int $eventId,
        int $memberId,
        string $source = 'portal',
        string $notes = ''
    ): array {
        if (!in_array($source, ['portal', 'admin', 'ussd', 'import'], true)) {
            throw new InvalidArgumentException('Choose a valid registration source.');
        }
        $event = $this->getEvent($eventId);
        if (!$event || empty($event['church_id'])) {
            throw new RuntimeException('The event is unavailable in your church.');
        }
        if ((int) $event['registration_enabled'] !== 1) {
            throw new RuntimeException('Registration is closed for this event.');
        }
        if (!empty($event['registration_deadline'])
            && strtotime((string) $event['registration_deadline']) < time()) {
            throw new RuntimeException('The registration deadline has passed.');
        }

        $stmt = $this->conn->prepare(
            "SELECT id, church_id FROM members WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member || (int) $member['church_id'] !== (int) $event['church_id']) {
            throw new RuntimeException('Only an active member of the event church can register.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT id, registration_status FROM event_registrations
                 WHERE event_id = ? AND member_id = ? FOR UPDATE'
            );
            $stmt->bind_param('ii', $eventId, $memberId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && in_array($existing['registration_status'], ['registered', 'attended'], true)) {
                $this->conn->commit();
                return ['registration_id' => (int) $existing['id'], 'created' => false];
            }

            $capacity = (int) ($event['registration_capacity'] ?? 0);
            if ($capacity > 0) {
                $stmt = $this->conn->prepare(
                    "SELECT COUNT(*) AS total FROM event_registrations
                     WHERE event_id = ? AND registration_status IN ('registered', 'attended')"
                );
                $stmt->bind_param('i', $eventId);
                $stmt->execute();
                $activeCount = (int) $stmt->get_result()->fetch_assoc()['total'];
                $stmt->close();
                if ($activeCount >= $capacity) {
                    throw new RuntimeException('This event has reached its registration capacity.');
                }
            }

            $notes = mb_substr(trim($notes), 0, 255);
            $actorUserId = $this->userId;
            if ($existing) {
                $stmt = $this->conn->prepare(
                    "UPDATE event_registrations
                     SET registration_status = 'registered', registration_source = ?,
                         registered_by = ?, registered_at = NOW(), notes = ?,
                         cancelled_by_user_id = NULL, cancelled_at = NULL,
                         cancellation_reason = NULL
                     WHERE id = ?"
                );
                $registrationId = (int) $existing['id'];
                $stmt->bind_param('sisi', $source, $actorUserId, $notes, $registrationId);
                $stmt->execute();
            } else {
                $stmt = $this->conn->prepare(
                    "INSERT INTO event_registrations
                        (event_id, member_id, registered_at, registration_status,
                         registration_source, registered_by, notes)
                     VALUES (?, ?, NOW(), 'registered', ?, ?, ?)"
                );
                $stmt->bind_param('iisis', $eventId, $memberId, $source, $actorUserId, $notes);
                $stmt->execute();
                $registrationId = (int) $stmt->insert_id;
            }
            $stmt->close();
            $this->conn->commit();
            return ['registration_id' => $registrationId, 'created' => true];
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function cancelRegistration(
        int $registrationId,
        int $requestingMemberId,
        bool $adminOverride = false,
        string $reason = ''
    ): void {
        $actorUserId = $this->userId;
        $stmt = $this->conn->prepare(
            'SELECT registration.id, registration.member_id, registration.registration_status,
                    event.id AS event_id,
                    event.church_id
             FROM event_registrations registration
             JOIN events event ON event.id = registration.event_id
             WHERE registration.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $registrationId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || (!$this->superAdmin
            && ($this->churchId === null || (int) $row['church_id'] !== $this->churchId))) {
            throw new RuntimeException('The registration is unavailable in your church.');
        }
        if (!$adminOverride && (int) $row['member_id'] !== $requestingMemberId) {
            throw new RuntimeException('You can only cancel your own registration.');
        }
        if (!$adminOverride && $row['registration_status'] !== 'registered') {
            throw new RuntimeException('Only a current registered status can be cancelled by the member.');
        }
        $reason = mb_substr(trim($reason), 0, 500);
        if ($adminOverride && $reason === '') {
            throw new RuntimeException('Enter a reason for administrative cancellation.');
        }
        if ($reason === '') $reason = 'Cancelled by member';
        $stmt = $this->conn->prepare(
            "UPDATE event_registrations
             SET registration_status = 'cancelled', cancelled_by_user_id = ?,
                 cancelled_at = NOW(), cancellation_reason = ?
             WHERE id = ? AND registration_status IN ('registered', 'attended', 'no_show')"
        );
        $stmt->bind_param('isi', $actorUserId, $reason, $registrationId);
        $stmt->execute();
        $stmt->close();
    }

    public function updateRegistrationStatus(
        int $registrationId,
        string $status,
        string $reason = ''
    ): void {
        if (!in_array($status, ['registered', 'attended', 'no_show', 'cancelled'], true)) {
            throw new InvalidArgumentException('Choose a valid registration status.');
        }
        if ($status === 'cancelled') {
            $this->cancelRegistration($registrationId, 0, true, $reason);
            return;
        }
        $accessStmt = $this->conn->prepare(
            'SELECT registration.id
               FROM event_registrations registration
               JOIN events event ON event.id = registration.event_id
              WHERE registration.id = ?' . ($this->superAdmin ? '' : ' AND event.church_id = ?') .
            ' LIMIT 1'
        );
        if ($this->superAdmin) {
            $accessStmt->bind_param('i', $registrationId);
        } else {
            if ($this->churchId === null) throw new RuntimeException('Your account has no church scope.');
            $accessStmt->bind_param('ii', $registrationId, $this->churchId);
        }
        $accessStmt->execute();
        $hasAccess = (bool) $accessStmt->get_result()->fetch_assoc();
        $accessStmt->close();
        if (!$hasAccess) throw new RuntimeException('The registration was not found in your church.');

        $stmt = $this->conn->prepare(
            'UPDATE event_registrations registration
             JOIN events event ON event.id = registration.event_id
             SET registration.registration_status = ?,
                 registration.cancelled_by_user_id = NULL,
                 registration.cancelled_at = NULL,
                 registration.cancellation_reason = NULL
             WHERE registration.id = ?' . ($this->superAdmin ? '' : ' AND event.church_id = ?')
        );
        if ($this->superAdmin) {
            $stmt->bind_param('si', $status, $registrationId);
        } else {
            if ($this->churchId === null) throw new RuntimeException('Your account has no church scope.');
            $stmt->bind_param('sii', $status, $registrationId, $this->churchId);
        }
        $stmt->execute();
        $stmt->close();
    }
}
