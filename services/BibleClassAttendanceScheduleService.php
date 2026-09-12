<?php

final class BibleClassAttendanceScheduleService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private array $roleIds = [];

    public function __construct(mysqli $conn, ?int $userId = null, ?int $memberId = null) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;

        if ($this->userId !== null) {
            if ($this->memberId === null) {
                $stmt = $this->conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $this->userId);
                $stmt->execute();
                $this->memberId = (int) ($stmt->get_result()->fetch_assoc()['member_id'] ?? 0) ?: null;
                $stmt->close();
            }
            $stmt = $this->conn->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $this->roleIds[] = (int) $row['role_id'];
            }
            $stmt->close();
        }
    }

    public static function fromSession(mysqli $conn): self {
        $service = new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null
        );
        $sessionRoles = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
        if (isset($_SESSION['role_id'])) {
            $sessionRoles[] = (int) $_SESSION['role_id'];
        }
        $service->roleIds = array_values(array_unique(array_merge($service->roleIds, $sessionRoles)));
        return $service;
    }

    public function getLeaderClasses(): array {
        if ($this->isAdministrator() || in_array(4, $this->roleIds, true)) {
            $result = $this->conn->query(
                'SELECT class.id AS class_id, class.name AS class_name, class.code,
                        class.church_id, class.class_group_id, class_group.name AS group_name,
                        class_group.meeting_day
                   FROM bible_classes class
                   JOIN class_groups class_group ON class_group.id = class.class_group_id
                  ORDER BY class.name'
            );
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        if ($this->userId === null && $this->memberId === null) {
            return [];
        }
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT class.id AS class_id, class.name AS class_name, class.code,
                    class.church_id, class.class_group_id, class_group.name AS group_name,
                    class_group.meeting_day
               FROM bible_class_leaders leader
               JOIN bible_classes class ON class.id = leader.class_id
               JOIN class_groups class_group ON class_group.id = class.class_group_id
              WHERE leader.status = 'active'
                AND ((? IS NOT NULL AND leader.user_id = ?)
                  OR (? IS NOT NULL AND leader.member_id = ?))
              ORDER BY class.name"
        );
        $stmt->bind_param('iiii', $this->userId, $this->userId, $this->memberId, $this->memberId);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $classes;
    }

    public function canAccessClass(int $classId): bool {
        if ($this->isAdministrator() || in_array(4, $this->roleIds, true)) {
            return true;
        }
        foreach ($this->getLeaderClasses() as $class) {
            if ((int) $class['class_id'] === $classId) {
                return true;
            }
        }
        return false;
    }

    public function getClass(int $classId): array {
        $stmt = $this->conn->prepare(
            'SELECT class.id, class.name, class.code, class.church_id, class.class_group_id,
                    class_group.name AS group_name, class_group.meeting_day, class_group.is_active
               FROM bible_classes class
               JOIN class_groups class_group ON class_group.id = class.class_group_id
              WHERE class.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $class = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$class) {
            throw new RuntimeException('Bible class not found.');
        }
        return $class;
    }

    public function ensureForDate(string $date, string $source = 'cron'): array {
        $attendanceDate = $this->normalizeDate($date);
        $meetingDay = (int) (new DateTimeImmutable($attendanceDate))->format('w');
        $stmt = $this->conn->prepare(
            'SELECT class.id
               FROM bible_classes class
               JOIN class_groups class_group ON class_group.id = class.class_group_id
              WHERE class_group.is_active = 1 AND class_group.meeting_day = ?
              ORDER BY class.id'
        );
        $stmt->bind_param('i', $meetingDay);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $result = ['date' => $attendanceDate, 'generated' => 0, 'existing' => 0, 'sessions' => []];
        foreach ($classes as $class) {
            $outcome = $this->ensureForClassDate((int) $class['id'], $attendanceDate, $source);
            $result[$outcome['created'] ? 'generated' : 'existing']++;
            $result['sessions'][] = $outcome;
        }
        return $result;
    }

    public function ensureForClassDate(int $classId, string $date, string $source = 'dashboard'): array {
        if (!in_array($source, ['dashboard', 'cron', 'admin'], true)) {
            throw new RuntimeException('Invalid attendance generation source.');
        }
        $attendanceDate = $this->normalizeDate($date);
        $class = $this->getClass($classId);
        if (!(int) $class['is_active']) {
            throw new RuntimeException('The class group is inactive.');
        }
        if ($class['meeting_day'] === null || $class['meeting_day'] === '') {
            throw new RuntimeException('The class group meeting day has not been configured.');
        }
        $actualDay = (int) (new DateTimeImmutable($attendanceDate))->format('w');
        if ($actualDay !== (int) $class['meeting_day']) {
            throw new RuntimeException('The selected date is not the configured class-group meeting day.');
        }

        $this->conn->begin_transaction();
        try {
            $insertLedger = $this->conn->prepare(
                "INSERT IGNORE INTO bible_class_attendance_schedule
                    (class_group_id, class_id, attendance_date, generation_status, generated_by, generated_by_user_id)
                 VALUES (?, ?, ?, 'pending', ?, ?)"
            );
            $groupId = (int) $class['class_group_id'];
            $insertLedger->bind_param('iissi', $groupId, $classId, $attendanceDate, $source, $this->userId);
            $insertLedger->execute();
            $insertLedger->close();

            $ledgerStmt = $this->conn->prepare(
                'SELECT * FROM bible_class_attendance_schedule
                  WHERE class_id = ? AND attendance_date = ? FOR UPDATE'
            );
            $ledgerStmt->bind_param('is', $classId, $attendanceDate);
            $ledgerStmt->execute();
            $ledger = $ledgerStmt->get_result()->fetch_assoc();
            $ledgerStmt->close();
            if (!$ledger) {
                throw new RuntimeException('Unable to reserve the scheduled attendance date.');
            }

            $session = null;
            if (!empty($ledger['session_id'])) {
                $sessionStmt = $this->conn->prepare(
                    "SELECT id, title, service_date FROM attendance_sessions
                      WHERE id = ? AND attendance_scope = 'bible_class'
                        AND scope_id = ? AND service_date = ? LIMIT 1"
                );
                $ledgerSessionId = (int) $ledger['session_id'];
                $sessionStmt->bind_param('iis', $ledgerSessionId, $classId, $attendanceDate);
                $sessionStmt->execute();
                $session = $sessionStmt->get_result()->fetch_assoc();
                $sessionStmt->close();
            }

            $created = false;
            if (!$session) {
                $existingStmt = $this->conn->prepare(
                    "SELECT id, title, service_date FROM attendance_sessions
                      WHERE attendance_scope = 'bible_class' AND scope_id = ?
                        AND service_date = ? ORDER BY id LIMIT 1 FOR UPDATE"
                );
                $existingStmt->bind_param('is', $classId, $attendanceDate);
                $existingStmt->execute();
                $session = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();
            }

            if (!$session) {
                $title = $class['name'] . ' Attendance';
                $sessionStmt = $this->conn->prepare(
                    "INSERT INTO attendance_sessions
                        (church_id, title, service_date, attendance_scope, scope_id,
                         class_group_id, attendance_report_category_id, classification_source,
                         generated_by_schedule, approval_status,
                         created_by_user_id, created_by_member_id)
                     VALUES (?, ?, ?, 'bible_class', ?, ?,
                             (SELECT id FROM attendance_report_categories WHERE code = 'bible_class' LIMIT 1),
                             'scope', 1, 'draft', ?, ?)"
                );
                $churchId = (int) $class['church_id'];
                $sessionStmt->bind_param(
                    'issiiii', $churchId, $title, $attendanceDate, $classId,
                    $groupId, $this->userId, $this->memberId
                );
                $sessionStmt->execute();
                $session = ['id' => (int) $this->conn->insert_id, 'title' => $title, 'service_date' => $attendanceDate];
                $sessionStmt->close();
                $created = true;
            } else {
                $syncSession = $this->conn->prepare(
                    'UPDATE attendance_sessions SET class_group_id = COALESCE(class_group_id, ?)
                      WHERE id = ?'
                );
                $sessionId = (int) $session['id'];
                $syncSession->bind_param('ii', $groupId, $sessionId);
                $syncSession->execute();
                $syncSession->close();
            }

            $updateLedger = $this->conn->prepare(
                "UPDATE bible_class_attendance_schedule
                    SET class_group_id = ?, session_id = ?, generation_status = 'generated',
                        generated_by = ?, generated_by_user_id = ?, updated_at = NOW()
                  WHERE id = ?"
            );
            $sessionId = (int) $session['id'];
            $ledgerId = (int) $ledger['id'];
            $updateLedger->bind_param('iisii', $groupId, $sessionId, $source, $this->userId, $ledgerId);
            $updateLedger->execute();
            $updateLedger->close();
            $this->conn->commit();

            return [
                'class_id' => $classId,
                'class_name' => $class['name'],
                'class_group_id' => $groupId,
                'date' => $attendanceDate,
                'session_id' => $sessionId,
                'created' => $created,
            ];
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function getClassSessions(int $classId, int $limit = 30): array {
        if (!$this->canAccessClass($classId)) {
            throw new RuntimeException('You cannot access attendance for this Bible class.');
        }
        $limit = max(1, min(100, $limit));
        $stmt = $this->conn->prepare(
            "SELECT session.*, class_group.name AS group_name,
                    (SELECT COUNT(*) FROM attendance_records record
                      WHERE record.session_id = session.id) AS marked_count
               FROM attendance_sessions session
               LEFT JOIN class_groups class_group ON class_group.id = session.class_group_id
              WHERE session.attendance_scope = 'bible_class' AND session.scope_id = ?
                AND session.service_date IS NOT NULL
              ORDER BY session.service_date DESC, session.id DESC
              LIMIT {$limit}"
        );
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $sessions;
    }

    public function getClassSession(int $classId, int $sessionId): array {
        if (!$this->canAccessClass($classId)) {
            throw new RuntimeException('You cannot access attendance for this Bible class.');
        }
        $stmt = $this->conn->prepare(
            "SELECT session.*, class_group.name AS group_name
               FROM attendance_sessions session
               LEFT JOIN class_groups class_group ON class_group.id = session.class_group_id
              WHERE session.id = ? AND session.attendance_scope = 'bible_class'
                AND session.scope_id = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $sessionId, $classId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$session) {
            throw new RuntimeException('That session does not belong to this Bible class.');
        }
        return $session;
    }

    public function getActiveMembers(int $classId): array {
        if (!$this->canAccessClass($classId)) {
            throw new RuntimeException('You cannot access members of this Bible class.');
        }
        $stmt = $this->conn->prepare(
            "SELECT id, crn, first_name, middle_name, last_name
               FROM members
              WHERE class_id = ? AND status = 'active'
              ORDER BY last_name, first_name, middle_name"
        );
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $members;
    }

    public function getAttendanceMap(int $classId, int $sessionId, array $memberIds): array {
        $this->getClassSession($classId, $sessionId);
        if (!$memberIds) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $memberIds))));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare(
            "SELECT member_id, status FROM attendance_records
              WHERE session_id = ? AND member_id IN ({$placeholders})"
        );
        $params = array_merge([$sessionId], $ids);
        $types = 'i' . str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $map = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['member_id']] = (string) $row['status'];
        }
        $stmt->close();
        return $map;
    }

    public function submitAttendance(int $classId, int $sessionId, array $statuses): int {
        $session = $this->getClassSession($classId, $sessionId);
        $members = $this->getActiveMembers($classId);
        if (!$members) {
            throw new RuntimeException('No active members are assigned to this Bible class.');
        }
        $validStatuses = ['present', 'absent', 'sick', 'permission', 'distance', 'invalid'];

        $this->conn->begin_transaction();
        try {
            $save = $this->conn->prepare(
                "INSERT INTO attendance_records
                    (session_id, member_id, status, is_draft, marked_by, marked_by_member_id, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), is_draft = 0,
                    marked_by = VALUES(marked_by), marked_by_member_id = VALUES(marked_by_member_id),
                    updated_at = NOW()"
            );
            foreach ($members as $member) {
                $memberId = (int) $member['id'];
                $status = strtolower(trim((string) ($statuses[$memberId] ?? 'absent')));
                if (!in_array($status, $validStatuses, true)) {
                    throw new RuntimeException('An invalid attendance status was submitted.');
                }
                $save->bind_param('iisii', $sessionId, $memberId, $status, $this->userId, $this->memberId);
                $save->execute();
            }
            $save->close();

            $update = $this->conn->prepare(
                "UPDATE attendance_sessions
                    SET approval_status = 'approved', submitted_by_user_id = ?, submitted_by_member_id = ?,
                        submitted_at = NOW(), reviewed_by_user_id = ?, reviewed_by_member_id = ?,
                        reviewed_at = NOW(), review_notes = NULL
                  WHERE id = ?"
            );
            $update->bind_param('iiiii', $this->userId, $this->memberId, $this->userId, $this->memberId, $sessionId);
            $update->execute();
            $update->close();

            $fromStatus = (string) ($session['approval_status'] ?? 'draft');
            $history = $this->conn->prepare(
                "INSERT INTO attendance_workflow_history
                    (session_id, action, from_status, to_status, actor_user_id, actor_member_id, notes)
                 VALUES (?, 'approved', ?, 'approved', ?, ?, 'Bible class attendance submitted by an authorized class leader.')"
            );
            $history->bind_param('isii', $sessionId, $fromStatus, $this->userId, $this->memberId);
            $history->execute();
            $history->close();

            $this->conn->commit();
            return count($members);
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    private function normalizeDate(string $date): string {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$parsed || $parsed->format('Y-m-d') !== trim($date)) {
            throw new RuntimeException('Enter a valid attendance date.');
        }
        return $parsed->format('Y-m-d');
    }

    private function isAdministrator(): bool {
        return (bool) array_intersect([1, 2], $this->roleIds);
    }
}
