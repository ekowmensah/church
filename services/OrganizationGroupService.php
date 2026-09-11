<?php

class OrganizationGroupService {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getOrganizationConfig(int $organizationId, bool $forUpdate = false): array {
        $sql = 'SELECT id, church_id, name, logo_path, assignment_strategy
                  FROM organizations
                 WHERE id = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $organization = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$organization) {
            throw new RuntimeException('Organization not found.');
        }

        return $organization;
    }

    public function getUnits(int $organizationId, ?string $unitType = null, bool $activeOnly = true): array {
        $sql = "SELECT unit.*, COUNT(assignment.id) AS member_count
                  FROM organization_units unit
                  LEFT JOIN organization_unit_assignments assignment ON assignment.unit_id = unit.id
                 WHERE unit.organization_id = ?";
        if ($unitType !== null) {
            $sql .= ' AND unit.unit_type = ?';
        }
        if ($activeOnly) {
            $sql .= ' AND unit.is_active = 1';
        }

        $sql .= ' GROUP BY unit.id ORDER BY unit.unit_type, unit.branch, unit.rank_order, unit.name';
        $stmt = $this->conn->prepare($sql);
        if ($unitType !== null) {
            $stmt->bind_param('is', $organizationId, $unitType);
        } else {
            $stmt->bind_param('i', $organizationId);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Assign all units required by an approved membership.
     * The caller owns the surrounding transaction.
     */
    public function assignForApprovedMembership(
        int $memberId,
        int $organizationId,
        int $approvalId,
        ?int $actorUserId,
        array $options = []
    ): array {
        $organization = $this->getOrganizationConfig($organizationId, true);
        $membership = $this->getMembership($memberId, $organizationId, true);
        $strategy = $organization['assignment_strategy'] ?: 'balanced_auto';
        $assignments = [];

        if ($strategy === 'none') {
            return $assignments;
        }

        if ($strategy === 'balanced_auto' || $strategy === 'balanced_plus_section') {
            $group = $this->chooseBalancedUnit($organizationId, 'group', 'primary_group');
            $this->setAssignment(
                $membership,
                $group,
                'primary_group',
                'automatic',
                $approvalId,
                $actorUserId,
                null,
                'Assigned automatically during organization membership approval.'
            );
            $assignments['primary_group'] = $group;
        }

        if ($strategy === 'manual_vocal_part') {
            $partId = (int) ($options['vocal_part_unit_id'] ?? 0);
            $part = $this->getValidUnit($organizationId, $partId, 'vocal_part', true);
            $this->setAssignment(
                $membership,
                $part,
                'vocal_part',
                'manual',
                $approvalId,
                $actorUserId,
                null,
                'Vocal part selected by the organization leader during approval.'
            );
            $assignments['vocal_part'] = $part;
        }

        if ($strategy === 'balanced_plus_section') {
            $sectionId = (int) ($options['brigade_section_unit_id'] ?? 0);
            $rankOrLevel = trim((string) ($options['brigade_rank'] ?? ''));
            if ($rankOrLevel === '') {
                throw new RuntimeException('Enter the Brigade rank or level before approving this membership.');
            }

            $section = $this->getValidUnit($organizationId, $sectionId, 'brigade_section', true);
            $this->setAssignment(
                $membership,
                $section,
                'brigade_section',
                'manual',
                $approvalId,
                $actorUserId,
                $rankOrLevel,
                'Brigade section selected from the member rank or level during approval.'
            );
            $assignments['brigade_section'] = $section;
        }

        return $assignments;
    }

    public function backfillBalancedOrganization(int $organizationId, ?int $actorUserId, int $limit = 100): int {
        $organization = $this->getOrganizationConfig($organizationId, true);
        if (!in_array($organization['assignment_strategy'], ['balanced_auto', 'balanced_plus_section'], true)) {
            throw new RuntimeException('Automatic backfill is only available for balanced organizations.');
        }

        $limit = max(1, min(500, $limit));
        $stmt = $this->conn->prepare(
            "SELECT mo.id, mo.member_id, mo.organization_id
               FROM member_organizations mo
               LEFT JOIN organization_unit_assignments assignment
                      ON assignment.member_organization_id = mo.id
                     AND assignment.assignment_type = 'primary_group'
              WHERE mo.organization_id = ?
                AND assignment.id IS NULL
              ORDER BY mo.id
              LIMIT {$limit}
              FOR UPDATE"
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $memberships = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($memberships as $membership) {
            $group = $this->chooseBalancedUnit($organizationId, 'group', 'primary_group');
            $this->setAssignment(
                $membership,
                $group,
                'primary_group',
                'legacy_backfill',
                null,
                $actorUserId,
                null,
                'Assigned through the administrator-approved legacy membership backfill.'
            );
        }

        return count($memberships);
    }

    public function assignLeader(
        int $organizationId,
        int $unitId,
        int $memberId,
        ?int $actorUserId,
        string $leaderRole = 'leader'
    ): void {
        if (!in_array($leaderRole, ['leader', 'assistant'], true)) {
            throw new RuntimeException('Invalid unit leader role.');
        }

        $stmt = $this->conn->prepare(
            'SELECT unit.*, mo.id AS membership_id
               FROM organization_units unit
               LEFT JOIN member_organizations mo
                      ON mo.organization_id = unit.organization_id
                     AND mo.member_id = ?
              WHERE unit.id = ? AND unit.organization_id = ? AND unit.is_active = 1
              FOR UPDATE'
        );
        $stmt->bind_param('iii', $memberId, $unitId, $organizationId);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$unit || empty($unit['membership_id'])) {
            throw new RuntimeException('The selected leader must be an active member of the organization.');
        }

        if ($unit['unit_type'] !== 'brigade_section') {
            $assignmentType = $unit['unit_type'] === 'vocal_part' ? 'vocal_part' : 'primary_group';
            $assignmentStmt = $this->conn->prepare(
                'SELECT id FROM organization_unit_assignments
                  WHERE member_organization_id = ? AND assignment_type = ? AND unit_id = ?'
            );
            $membershipId = (int) $unit['membership_id'];
            $assignmentStmt->bind_param('isi', $membershipId, $assignmentType, $unitId);
            $assignmentStmt->execute();
            $isUnitMember = (bool) $assignmentStmt->get_result()->fetch_assoc();
            $assignmentStmt->close();
            if (!$isUnitMember) {
                throw new RuntimeException('A group or vocal-part leader must first be assigned to that unit.');
            }
        }

        $closeStmt = $this->conn->prepare(
            "UPDATE organization_unit_leaders
                SET status = 'inactive', effective_to = CURDATE()
              WHERE unit_id = ? AND leader_role = ? AND status = 'active'"
        );
        $closeStmt->bind_param('is', $unitId, $leaderRole);
        $closeStmt->execute();
        $closeStmt->close();

        $notes = $unit['unit_type'] === 'brigade_section'
            ? 'Brigade officer eligibility confirmed by assigning organization leader.'
            : null;
        $insertStmt = $this->conn->prepare(
            "INSERT INTO organization_unit_leaders
                (unit_id, member_id, leader_role, status, assigned_by, effective_from, notes)
             VALUES (?, ?, ?, 'active', ?, CURDATE(), ?)"
        );
        $insertStmt->bind_param('iisis', $unitId, $memberId, $leaderRole, $actorUserId, $notes);
        $insertStmt->execute();
        $insertStmt->close();
    }

    /**
     * Assign or reassign an existing organization member to a unit.
     * The caller owns the surrounding transaction.
     */
    public function assignMemberToUnit(
        int $organizationId,
        int $unitId,
        int $memberId,
        ?int $actorUserId,
        ?string $rankOrLevel = null,
        string $reason = ''
    ): array {
        $stmt = $this->conn->prepare(
            'SELECT * FROM organization_units
              WHERE id = ? AND organization_id = ? AND is_active = 1
              FOR UPDATE'
        );
        $stmt->bind_param('ii', $unitId, $organizationId);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$unit) {
            throw new RuntimeException('The selected organization unit is invalid or inactive.');
        }

        $assignmentTypes = [
            'group' => 'primary_group',
            'vocal_part' => 'vocal_part',
            'brigade_section' => 'brigade_section',
        ];
        $assignmentType = $assignmentTypes[$unit['unit_type']] ?? null;
        if ($assignmentType === null) {
            throw new RuntimeException('The selected organization unit type is unsupported.');
        }

        $rankOrLevel = trim((string) $rankOrLevel);
        if ($unit['unit_type'] === 'brigade_section' && $rankOrLevel === '') {
            throw new RuntimeException('Enter the Brigade rank or level before assigning a section.');
        }
        if ($unit['unit_type'] !== 'brigade_section') {
            $rankOrLevel = null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            $reason = 'Assignment updated by an authorized organization administrator.';
        }
        $reason = mb_substr($reason, 0, 255);

        $membership = $this->getMembership($memberId, (int) $unit['organization_id'], true);
        $this->setAssignment(
            $membership,
            $unit,
            $assignmentType,
            'reassignment',
            null,
            $actorUserId,
            $rankOrLevel,
            $reason
        );

        return $unit;
    }

    private function getMembership(int $memberId, int $organizationId, bool $forUpdate): array {
        $sql = 'SELECT id, member_id, organization_id
                  FROM member_organizations
                 WHERE member_id = ? AND organization_id = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('ii', $memberId, $organizationId);
        $stmt->execute();
        $membership = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$membership) {
            throw new RuntimeException('Create the organization membership before assigning a group.');
        }

        return $membership;
    }

    private function getValidUnit(int $organizationId, int $unitId, string $unitType, bool $forUpdate): array {
        if ($unitId < 1) {
            throw new RuntimeException('Select the required organization unit before approval.');
        }

        $sql = 'SELECT * FROM organization_units
                 WHERE id = ? AND organization_id = ? AND unit_type = ? AND is_active = 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('iis', $unitId, $organizationId, $unitType);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$unit) {
            throw new RuntimeException('The selected organization unit is invalid or inactive.');
        }

        return $unit;
    }

    private function chooseBalancedUnit(int $organizationId, string $unitType, string $assignmentType): array {
        $stmt = $this->conn->prepare(
            'SELECT * FROM organization_units
              WHERE organization_id = ? AND unit_type = ? AND is_active = 1
              ORDER BY COALESCE(rank_order, 2147483647), id
              FOR UPDATE'
        );
        $stmt->bind_param('is', $organizationId, $unitType);
        $stmt->execute();
        $units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!$units) {
            throw new RuntimeException('No active groups are configured for this organization.');
        }

        $unitIds = array_map('intval', array_column($units, 'id'));
        $counts = array_fill_keys($unitIds, 0);
        $idList = implode(',', $unitIds);
        $countStmt = $this->conn->prepare(
            "SELECT unit_id, COUNT(*) AS total
               FROM organization_unit_assignments
              WHERE assignment_type = ? AND unit_id IN ({$idList})
              GROUP BY unit_id"
        );
        $countStmt->bind_param('s', $assignmentType);
        $countStmt->execute();
        $result = $countStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $counts[(int) $row['unit_id']] = (int) $row['total'];
        }
        $countStmt->close();

        $selected = $units[0];
        foreach ($units as $unit) {
            if ($counts[(int) $unit['id']] < $counts[(int) $selected['id']]) {
                $selected = $unit;
            }
        }

        return $selected;
    }

    private function setAssignment(
        array $membership,
        array $unit,
        string $assignmentType,
        string $assignmentMethod,
        ?int $approvalId,
        ?int $actorUserId,
        ?string $rankOrLevel,
        string $reason
    ): void {
        $membershipId = (int) $membership['id'];
        $unitId = (int) $unit['id'];
        $stmt = $this->conn->prepare(
            'SELECT id, unit_id, rank_or_level FROM organization_unit_assignments
              WHERE member_organization_id = ? AND assignment_type = ?
              FOR UPDATE'
        );
        $stmt->bind_param('is', $membershipId, $assignmentType);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $fromUnitId = $existing ? (int) $existing['unit_id'] : null;
        $action = $existing ? 'reassigned' : 'assigned';
        $existingRank = $existing ? trim((string) ($existing['rank_or_level'] ?? '')) : null;
        $newRank = trim((string) $rankOrLevel);
        if ($existing && $fromUnitId === $unitId && $existingRank === $newRank) {
            return;
        }

        if ($existing) {
            $updateStmt = $this->conn->prepare(
                'UPDATE organization_unit_assignments
                    SET unit_id = ?, assignment_method = ?,
                        source_approval_id = COALESCE(?, source_approval_id), rank_or_level = ?,
                        assigned_by = ?, effective_from = CURDATE()
                  WHERE id = ?'
            );
            $assignmentId = (int) $existing['id'];
            $updateStmt->bind_param('isisii', $unitId, $assignmentMethod, $approvalId, $rankOrLevel, $actorUserId, $assignmentId);
            $updateStmt->execute();
            $updateStmt->close();
        } else {
            $insertStmt = $this->conn->prepare(
                'INSERT INTO organization_unit_assignments
                    (member_organization_id, unit_id, assignment_type, assignment_method,
                     source_approval_id, rank_or_level, assigned_by, effective_from)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())'
            );
            $insertStmt->bind_param('iissisi', $membershipId, $unitId, $assignmentType, $assignmentMethod, $approvalId, $rankOrLevel, $actorUserId);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $historyStmt = $this->conn->prepare(
            'INSERT INTO organization_unit_assignment_history
                (member_organization_id, organization_id, member_id, assignment_type,
                 from_unit_id, to_unit_id, action, assignment_method, source_approval_id,
                 rank_or_level, reason, actor_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $organizationId = (int) $membership['organization_id'];
        $memberId = (int) $membership['member_id'];
        $historyStmt->bind_param(
            'iiisiississi',
            $membershipId,
            $organizationId,
            $memberId,
            $assignmentType,
            $fromUnitId,
            $unitId,
            $action,
            $assignmentMethod,
            $approvalId,
            $rankOrLevel,
            $reason,
            $actorUserId
        );
        $historyStmt->execute();
        $historyStmt->close();
    }
}
