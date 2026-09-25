<?php

class AttendanceAudienceService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Return a canonical roster. The presentation layer can continue to use
     * member-like keys while subject_type preserves the underlying register.
     */
    public function subjects(array $session, ?int $classId = null, ?int $organizationId = null, string $search = ''): array
    {
        $audience = $session['attendance_audience'] ?? 'members';
        if ($audience === 'sunday_school') {
            return $this->sundaySchoolSubjects($session, $classId, $search);
        }
        return $this->memberSubjects($session, $classId, $organizationId, $search, $audience === 'role_of_serving');
    }

    public function save(int $sessionId, string $subjectType, int $subjectId, string $status, int $userId, bool $draft): void
    {
        $validStatuses = ['present','absent','sick','permission','distance','invalid'];
        if (!in_array($status, $validStatuses, true) || !in_array($subjectType, ['member','sunday_school'], true)) {
            throw new InvalidArgumentException('Invalid attendance record.');
        }

        $memberId = $subjectType === 'member' ? $subjectId : null;
        $childId = $subjectType === 'sunday_school' ? $subjectId : null;
        $isDraft = $draft ? 1 : 0;
        $stmt = $this->conn->prepare(
            'INSERT INTO attendance_records
                (session_id, subject_type, member_id, sunday_school_id, status, marked_by, is_draft)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), marked_by = VALUES(marked_by),
                is_draft = VALUES(is_draft), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('isiisii', $sessionId, $subjectType, $memberId, $childId, $status, $userId, $isDraft);
        $stmt->execute();
        $stmt->close();
    }

    private function sundaySchoolSubjects(array $session, ?int $classId, string $search): array
    {
        $sql = "SELECT child.id, child.first_name, child.last_name, child.middle_name,
                       child.srn AS crn, child.class_id, class.name AS class_name,
                       child.gender, '' AS org_ids, 'sunday_school' AS subject_type
                FROM sunday_school child
                LEFT JOIN bible_classes class ON class.id = child.class_id
                WHERE child.church_id = ? AND child.transferred_to_member_id IS NULL";
        $params = [(int) $session['church_id']];
        $types = 'i';
        $scopeClass = ($session['attendance_scope'] ?? '') === 'bible_class'
            ? (int) ($session['scope_id'] ?? 0) : (int) ($classId ?? 0);
        if ($scopeClass > 0) {
            $sql .= ' AND child.class_id = ?';
            $params[] = $scopeClass;
            $types .= 'i';
        }
        if ($search !== '') {
            $sql .= " AND (child.first_name LIKE ? OR child.last_name LIKE ?
                      OR child.middle_name LIKE ? OR child.srn LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
            $types .= 'ssss';
        }
        $sql .= ' ORDER BY child.last_name, child.first_name';
        return $this->fetch($sql, $types, $params);
    }

    private function memberSubjects(
        array $session,
        ?int $classId,
        ?int $organizationId,
        string $search,
        bool $roleAudience
    ): array {
        $sql = "SELECT member.id, member.first_name, member.last_name, member.middle_name,
                       member.crn, member.class_id, class.name AS class_name, member.gender,
                       GROUP_CONCAT(DISTINCT membership.organization_id) AS org_ids,
                       'member' AS subject_type
                FROM members member
                LEFT JOIN bible_classes class ON class.id = member.class_id
                LEFT JOIN member_organizations membership ON membership.member_id = member.id";
        if ($roleAudience) {
            $sql .= ' INNER JOIN member_roles_of_serving serving ON serving.member_id = member.id';
        }
        $sql .= " WHERE member.church_id = ? AND member.status = 'active'
                  AND COALESCE(member.is_archived, 0) = 0";
        $params = [(int) $session['church_id']];
        $types = 'i';

        if ($roleAudience && (int) ($session['role_of_serving_id'] ?? 0) > 0) {
            $sql .= ' AND serving.role_id = ?';
            $params[] = (int) $session['role_of_serving_id'];
            $types .= 'i';
        }
        $scopeClass = ($session['attendance_scope'] ?? '') === 'bible_class'
            ? (int) ($session['scope_id'] ?? 0) : (int) ($classId ?? 0);
        if ($scopeClass > 0) {
            $sql .= ' AND member.class_id = ?';
            $params[] = $scopeClass;
            $types .= 'i';
        }
        $scopeOrg = ($session['attendance_scope'] ?? '') === 'organization'
            ? (int) ($session['scope_id'] ?? 0) : (int) ($organizationId ?? 0);
        if ($scopeOrg > 0) {
            $sql .= ' AND membership.organization_id = ?';
            $params[] = $scopeOrg;
            $types .= 'i';
        }
        if ($search !== '') {
            $sql .= " AND (member.first_name LIKE ? OR member.last_name LIKE ?
                      OR member.middle_name LIKE ? OR member.crn LIKE ?)";
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
            $types .= 'ssss';
        }
        $sql .= ' GROUP BY member.id ORDER BY member.last_name, member.first_name';
        return $this->fetch($sql, $types, $params);
    }

    private function fetch(string $sql, string $types, array $params): array
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}
