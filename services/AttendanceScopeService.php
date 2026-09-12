<?php

final class AttendanceScopeService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private array $roleIds = [];

    public function __construct(mysqli $conn, ?int $userId, ?int $memberId) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;

        if ($this->userId !== null) {
            if ($this->memberId === null) {
                $stmt = $this->conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $this->userId);
                $stmt->execute();
                $linked = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!empty($linked['member_id'])) {
                    $this->memberId = (int) $linked['member_id'];
                }
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
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null
        );
    }

    public function actorUserId(): ?int {
        return $this->userId;
    }

    public function actorMemberId(): ?int {
        return $this->memberId;
    }

    public function isAdministrator(): bool {
        return (bool) array_intersect([1, 2], $this->roleIds);
    }

    public function getSession(int $sessionId): array {
        $stmt = $this->conn->prepare(
            "SELECT session.*, church.name AS church_name,
                    organization.name AS organization_name,
                    unit.name AS unit_name, unit.unit_type, unit.branch
               FROM attendance_sessions session
               LEFT JOIN churches church ON church.id = session.church_id
               LEFT JOIN organizations organization
                      ON session.attendance_scope = 'organization'
                     AND organization.id = session.scope_id
               LEFT JOIN organization_units unit ON unit.id = session.organization_unit_id
              WHERE session.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$session) {
            throw new RuntimeException('Attendance session not found.');
        }
        return $session;
    }

    public function getOrganizationContexts(): array {
        if ($this->isAdministrator()) {
            $result = $this->conn->query(
                "SELECT organization.id AS organization_id, organization.name AS organization_name,
                        organization.church_id, 1 AS can_review,
                        NULL AS unit_id, NULL AS unit_name, NULL AS leader_role
                   FROM organizations organization
                  ORDER BY organization.name"
            );
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        $contexts = [];
        if ($this->userId !== null || $this->memberId !== null) {
            $stmt = $this->conn->prepare(
                "SELECT organization.id AS organization_id, organization.name AS organization_name,
                        organization.church_id, 1 AS can_review,
                        NULL AS unit_id, NULL AS unit_name, NULL AS leader_role
                   FROM organization_leaders leader
                   JOIN organizations organization ON organization.id = leader.organization_id
                  WHERE leader.status = 'active'
                    AND ((? IS NOT NULL AND leader.user_id = ?)
                      OR (? IS NOT NULL AND leader.member_id = ?))"
            );
            $stmt->bind_param('iiii', $this->userId, $this->userId, $this->memberId, $this->memberId);
            $stmt->execute();
            $contexts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        if ($this->memberId !== null) {
            $stmt = $this->conn->prepare(
                "SELECT organization.id AS organization_id, organization.name AS organization_name,
                        organization.church_id, 0 AS can_review,
                        unit.id AS unit_id, unit.name AS unit_name, leader.leader_role
                   FROM organization_unit_leaders leader
                   JOIN organization_units unit ON unit.id = leader.unit_id AND unit.is_active = 1
                   JOIN organizations organization ON organization.id = unit.organization_id
                  WHERE leader.member_id = ? AND leader.status = 'active'
                    AND (leader.effective_to IS NULL OR leader.effective_to >= CURDATE())
                  ORDER BY organization.name, unit.rank_order, unit.name"
            );
            $stmt->bind_param('i', $this->memberId);
            $stmt->execute();
            $unitContexts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $contexts = array_merge($contexts, $unitContexts);
        }

        return $contexts;
    }

    public function canMark(array $session): bool {
        if ($this->isAdministrator() || in_array(4, $this->roleIds, true)) {
            return true;
        }

        if (($session['attendance_scope'] ?? '') !== 'organization') {
            return $this->userHasPermission('mark_attendance');
        }

        $organizationId = (int) ($session['scope_id'] ?? 0);
        if ($organizationId < 1) {
            return false;
        }
        if ($this->leadsOrganization($organizationId)) {
            return true;
        }

        $unitId = (int) ($session['organization_unit_id'] ?? 0);
        return $unitId > 0 && $this->leadsUnit($unitId, $organizationId);
    }

    public function canView(array $session): bool {
        if (($session['attendance_scope'] ?? '') === 'organization') {
            return $this->canMark($session) || $this->canReview($session);
        }
        return $this->isAdministrator()
            || $this->userHasPermission('view_attendance_list')
            || $this->userHasPermission('mark_attendance');
    }

    public function canReview(array $session): bool {
        if (($session['attendance_scope'] ?? '') !== 'organization') {
            return false;
        }
        return $this->isAdministrator()
            || in_array(4, $this->roleIds, true)
            || $this->leadsOrganization((int) ($session['scope_id'] ?? 0));
    }

    public function getEligibleMembers(array $session, string $search = ''): array {
        $scope = (string) ($session['attendance_scope'] ?? 'church');
        $scopeId = (int) ($session['scope_id'] ?? 0);
        $unitId = (int) ($session['organization_unit_id'] ?? 0);

        $sql = "SELECT DISTINCT member.id, member.first_name, member.middle_name,
                        member.last_name, member.crn, member.class_id,
                        class.name AS class_name, member.gender
                   FROM members member
                   LEFT JOIN bible_classes class ON class.id = member.class_id";
        $params = [];
        $types = '';

        if ($scope === 'organization') {
            $sql .= ' JOIN member_organizations membership ON membership.member_id = member.id';
            if ($unitId > 0) {
                $sql .= ' JOIN organization_unit_assignments assignment
                             ON assignment.member_organization_id = membership.id
                            AND assignment.unit_id = ?';
                $params[] = $unitId;
                $types .= 'i';
            }
        }

        $sql .= " WHERE member.status = 'active'";
        if (!empty($session['church_id'])) {
            $sql .= ' AND member.church_id = ?';
            $params[] = (int) $session['church_id'];
            $types .= 'i';
        }
        if ($scope === 'bible_class' && $scopeId > 0) {
            $sql .= ' AND member.class_id = ?';
            $params[] = $scopeId;
            $types .= 'i';
        } elseif ($scope === 'organization' && $scopeId > 0) {
            $sql .= ' AND membership.organization_id = ?';
            $params[] = $scopeId;
            $types .= 'i';
        }
        if ($search !== '') {
            $sql .= ' AND (member.first_name LIKE ? OR member.middle_name LIKE ?
                           OR member.last_name LIKE ? OR member.crn LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
            $types .= 'ssss';
        }
        $sql .= ' ORDER BY member.last_name, member.first_name, member.middle_name';

        $stmt = $this->conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $members;
    }

    public function getAttendanceMap(int $sessionId, array $memberIds): array {
        if (!$memberIds) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $memberIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conn->prepare(
            "SELECT member_id, status, is_draft FROM attendance_records
              WHERE session_id = ? AND member_id IN ({$placeholders})"
        );
        $params = array_merge([$sessionId], $ids);
        $types = 'i' . str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $map = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['member_id']] = $row;
        }
        $stmt->close();
        return $map;
    }

    public function saveDraftStatus(int $sessionId, int $memberId, string $status): void {
        $session = $this->getSession($sessionId);
        $this->assertMarkable($session, true);
        $this->assertStatus($status);
        $eligible = $this->getEligibleMembers($session);
        $eligibleIds = array_map('intval', array_column($eligible, 'id'));
        if (!in_array($memberId, $eligibleIds, true)) {
            throw new RuntimeException('That member is outside this attendance scope.');
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO attendance_records
                (session_id, member_id, status, is_draft, marked_by, marked_by_member_id, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), is_draft = 1,
                 marked_by = VALUES(marked_by), marked_by_member_id = VALUES(marked_by_member_id),
                 updated_at = NOW()"
        );
        $stmt->bind_param('iisii', $sessionId, $memberId, $status, $this->userId, $this->memberId);
        $stmt->execute();
        $stmt->close();
        $this->moveToDraftIfNeeded($session, 'Attendance reopened while editing.');
    }

    public function submitAttendance(int $sessionId, array $statuses): int {
        $session = $this->getSession($sessionId);
        $this->assertMarkable($session, false);
        $members = $this->getEligibleMembers($session);
        if (!$members) {
            throw new RuntimeException('No active members are assigned to this attendance scope.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO attendance_records
                    (session_id, member_id, status, is_draft, marked_by, marked_by_member_id, created_at, updated_at)
                 VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE status = VALUES(status), is_draft = 0,
                     marked_by = VALUES(marked_by), marked_by_member_id = VALUES(marked_by_member_id),
                     updated_at = NOW()"
            );
            foreach ($members as $member) {
                $memberId = (int) $member['id'];
                $status = (string) ($statuses[$memberId] ?? 'absent');
                $this->assertStatus($status);
                $stmt->bind_param('iisii', $sessionId, $memberId, $status, $this->userId, $this->memberId);
                $stmt->execute();
            }
            $stmt->close();

            $fromStatus = (string) ($session['approval_status'] ?? 'draft');
            $toStatus = ($session['attendance_scope'] ?? '') === 'organization' ? 'submitted' : 'approved';
            $update = $this->conn->prepare(
                'UPDATE attendance_sessions
                    SET approval_status = ?, submitted_by_user_id = ?, submitted_by_member_id = ?,
                        submitted_at = NOW(), reviewed_by_user_id = NULL,
                        reviewed_by_member_id = NULL, reviewed_at = NULL, review_notes = NULL
                  WHERE id = ?'
            );
            $update->bind_param('siii', $toStatus, $this->userId, $this->memberId, $sessionId);
            $update->execute();
            $update->close();
            $this->recordHistory($session, 'submitted', $fromStatus, $toStatus, null);
            $this->conn->commit();
            return count($members);
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function reviewAttendance(int $sessionId, string $decision, string $notes = ''): void {
        $session = $this->getSession($sessionId);
        if (!$this->canReview($session)) {
            throw new RuntimeException('You cannot review attendance for this organization.');
        }
        if (($session['approval_status'] ?? '') !== 'submitted') {
            throw new RuntimeException('Only submitted attendance can be approved or rejected.');
        }
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new RuntimeException('Invalid review decision.');
        }
        $notes = mb_substr(trim($notes), 0, 500);
        if ($decision === 'rejected' && $notes === '') {
            throw new RuntimeException('Add a reason before rejecting attendance.');
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'UPDATE attendance_sessions
                    SET approval_status = ?, reviewed_by_user_id = ?, reviewed_by_member_id = ?,
                        reviewed_at = NOW(), review_notes = ?
                  WHERE id = ? AND approval_status = \'submitted\''
            );
            $stmt->bind_param('siisi', $decision, $this->userId, $this->memberId, $notes, $sessionId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                throw new RuntimeException('Attendance was already reviewed by another leader.');
            }
            $stmt->close();
            $this->recordHistory($session, $decision, 'submitted', $decision, $notes ?: null);
            $this->conn->commit();
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function listOrganizationSessions(?int $organizationId = null): array {
        $contexts = $this->getOrganizationContexts();
        $reviewOrganizations = [];
        $unitIds = [];
        foreach ($contexts as $context) {
            if (!empty($context['can_review'])) {
                $reviewOrganizations[(int) $context['organization_id']] = true;
            } elseif (!empty($context['unit_id'])) {
                $unitIds[(int) $context['unit_id']] = true;
            }
        }
        if (!$reviewOrganizations && !$unitIds) {
            return [];
        }

        $conditions = [];
        if ($reviewOrganizations) {
            $conditions[] = 'session.scope_id IN (' . implode(',', array_keys($reviewOrganizations)) . ')';
        }
        if ($unitIds) {
            $conditions[] = 'session.organization_unit_id IN (' . implode(',', array_keys($unitIds)) . ')';
        }
        $sql = "SELECT session.*, organization.name AS organization_name, unit.name AS unit_name,
                       (SELECT COUNT(*) FROM attendance_records record
                         WHERE record.session_id = session.id AND record.is_draft = 0) AS marked_count
                  FROM attendance_sessions session
                  JOIN organizations organization ON organization.id = session.scope_id
                  LEFT JOIN organization_units unit ON unit.id = session.organization_unit_id
                 WHERE session.attendance_scope = 'organization'
                   AND (" . implode(' OR ', $conditions) . ')';
        if ($organizationId !== null && $organizationId > 0) {
            $sql .= ' AND session.scope_id = ' . (int) $organizationId;
        }
        $sql .= ' ORDER BY session.service_date DESC, session.id DESC LIMIT 100';
        $result = $this->conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function createOrganizationSession(
        int $organizationId,
        ?int $unitId,
        string $title,
        string $serviceDate
    ): int {
        $contextSession = ['attendance_scope' => 'organization', 'scope_id' => $organizationId];
        if (!$this->isAdministrator() && !$this->leadsOrganization($organizationId)) {
            throw new RuntimeException('Only the organization leader or an administrator can create this session.');
        }
        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Enter a session title.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDate);
        if (!$date || $date->format('Y-m-d') !== $serviceDate) {
            throw new RuntimeException('Enter a valid service date.');
        }

        $stmt = $this->conn->prepare('SELECT church_id FROM organizations WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $organization = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$organization) {
            throw new RuntimeException('Organization not found.');
        }
        if ($unitId !== null) {
            $stmt = $this->conn->prepare(
                'SELECT id FROM organization_units
                  WHERE id = ? AND organization_id = ? AND is_active = 1 LIMIT 1'
            );
            $stmt->bind_param('ii', $unitId, $organizationId);
            $stmt->execute();
            $validUnit = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$validUnit) {
                throw new RuntimeException('Select a valid active unit in this organization.');
            }
        }

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO attendance_sessions
                    (church_id, title, service_date, attendance_scope, scope_id,
                     organization_unit_id, approval_status, created_by_user_id, created_by_member_id)
                 VALUES (?, ?, ?, 'organization', ?, ?, 'draft', ?, ?)"
            );
            $churchId = (int) $organization['church_id'];
            $stmt->bind_param(
                'issiiii', $churchId, $title, $serviceDate, $organizationId,
                $unitId, $this->userId, $this->memberId
            );
            $stmt->execute();
            $sessionId = (int) $this->conn->insert_id;
            $stmt->close();
            $created = $this->getSession($sessionId);
            $this->recordHistory($created, 'created', null, 'draft', null);
            $this->conn->commit();
            return $sessionId;
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function getOrganizationUnits(int $organizationId): array {
        $stmt = $this->conn->prepare(
            'SELECT id, name, unit_type, branch
               FROM organization_units
              WHERE organization_id = ? AND is_active = 1
              ORDER BY unit_type, branch, rank_order, name'
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $units;
    }

    private function assertMarkable(array $session, bool $draftSave): void {
        if (!$this->canMark($session)) {
            throw new RuntimeException('You cannot mark attendance for this session.');
        }
        $status = (string) ($session['approval_status'] ?? 'draft');
        if (!$this->canReview($session) && in_array($status, ['submitted', 'approved'], true)) {
            throw new RuntimeException('This attendance has been submitted and is locked for organization review.');
        }
    }

    private function assertStatus(string $status): void {
        if (!in_array($status, ['present', 'absent', 'sick', 'permission', 'distance', 'invalid'], true)) {
            throw new RuntimeException('Invalid attendance status.');
        }
    }

    private function moveToDraftIfNeeded(array $session, string $notes): void {
        $from = (string) ($session['approval_status'] ?? 'draft');
        if ($from === 'draft') {
            return;
        }
        $stmt = $this->conn->prepare(
            "UPDATE attendance_sessions
                SET approval_status = 'draft', reviewed_by_user_id = NULL,
                    reviewed_by_member_id = NULL, reviewed_at = NULL, review_notes = NULL
              WHERE id = ?"
        );
        $sessionId = (int) $session['id'];
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $stmt->close();
        $this->recordHistory($session, 'reopened', $from, 'draft', $notes);
    }

    private function recordHistory(
        array $session,
        string $action,
        ?string $fromStatus,
        string $toStatus,
        ?string $notes
    ): void {
        $stmt = $this->conn->prepare(
            'INSERT INTO attendance_workflow_history
                (session_id, organization_id, organization_unit_id, action, from_status,
                 to_status, actor_user_id, actor_member_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sessionId = (int) $session['id'];
        $organizationId = ($session['attendance_scope'] ?? '') === 'organization'
            ? (int) ($session['scope_id'] ?? 0) : null;
        $unitId = !empty($session['organization_unit_id'])
            ? (int) $session['organization_unit_id'] : null;
        $stmt->bind_param(
            'iiisssiis', $sessionId, $organizationId, $unitId, $action,
            $fromStatus, $toStatus, $this->userId, $this->memberId, $notes
        );
        $stmt->execute();
        $stmt->close();
    }

    private function leadsOrganization(int $organizationId): bool {
        if ($organizationId < 1 || ($this->userId === null && $this->memberId === null)) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM organization_leaders
              WHERE organization_id = ? AND status = 'active'
                AND ((? IS NOT NULL AND user_id = ?)
                  OR (? IS NOT NULL AND member_id = ?)) LIMIT 1"
        );
        $stmt->bind_param(
            'iiiii', $organizationId, $this->userId, $this->userId,
            $this->memberId, $this->memberId
        );
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }

    private function leadsUnit(int $unitId, int $organizationId): bool {
        if ($this->memberId === null) {
            return false;
        }
        $stmt = $this->conn->prepare(
            "SELECT 1
               FROM organization_unit_leaders leader
               JOIN organization_units unit ON unit.id = leader.unit_id
              WHERE leader.unit_id = ? AND unit.organization_id = ?
                AND leader.member_id = ? AND leader.status = 'active'
                AND unit.is_active = 1
                AND (leader.effective_to IS NULL OR leader.effective_to >= CURDATE())
              LIMIT 1"
        );
        $stmt->bind_param('iii', $unitId, $organizationId, $this->memberId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }

    private function userHasPermission(string $permission): bool {
        if ($this->userId === null) {
            return false;
        }
        $stmt = $this->conn->prepare(
            'SELECT 1
               FROM permissions permission
              WHERE permission.name = ?
                AND (
                    EXISTS (
                        SELECT 1 FROM user_roles user_role
                        JOIN role_permissions role_permission
                          ON role_permission.role_id = user_role.role_id
                       WHERE user_role.user_id = ?
                         AND role_permission.permission_id = permission.id
                    )
                    OR EXISTS (
                        SELECT 1 FROM user_permissions user_permission
                         WHERE user_permission.user_id = ?
                           AND user_permission.permission_id = permission.id
                           AND user_permission.allowed = 1
                    )
                ) LIMIT 1'
        );
        $stmt->bind_param('sii', $permission, $this->userId, $this->userId);
        $stmt->execute();
        $found = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}
