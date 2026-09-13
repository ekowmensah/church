<?php

final class BirthdayDirectoryService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private ?int $churchId;
    private array $roleIds;

    public function __construct(mysqli $conn, ?int $userId, ?int $memberId, array $roleIds) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->churchId = null;
        $this->roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));

        if ($this->userId !== null) {
            $stmt = $this->conn->prepare(
                "SELECT user_account.member_id, user_account.church_id, user_role.role_id
                   FROM users user_account
                   LEFT JOIN user_roles user_role
                     ON user_role.user_id = user_account.id AND user_role.is_active = 1
                    AND (user_role.expires_at IS NULL OR user_role.expires_at > NOW())
                  WHERE user_account.id = ?"
            );
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                if ($this->memberId === null && !empty($row['member_id'])) $this->memberId = (int) $row['member_id'];
                if ($this->churchId === null && !empty($row['church_id'])) $this->churchId = (int) $row['church_id'];
                if (!empty($row['role_id'])) $this->roleIds[] = (int) $row['role_id'];
            }
            $stmt->close();
            $this->roleIds = array_values(array_unique($this->roleIds));
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
        $roles = (array) ($_SESSION['role_ids'] ?? []);
        if (isset($_SESSION['role_id'])) $roles[] = (int) $_SESSION['role_id'];
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
            $roles
        );
    }

    public function getSummary(?DateTimeImmutable $today = null): array {
        $today = $today ?: new DateTimeImmutable('today');
        return [
            'yesterday' => $this->countForBucket('yesterday', $today),
            'today' => $this->countForBucket('today', $today),
            'tomorrow' => $this->countForBucket('tomorrow', $today),
            'month' => $this->countForBucket('month', $today),
        ];
    }

    public function getMembers(string $bucket, ?DateTimeImmutable $today = null, ?int $limit = null): array {
        $today = $today ?: new DateTimeImmutable('today');
        [$dateSql, $dateParam, $dateType] = $this->dateCondition($bucket, $today);
        $where = [
            "member.status = 'active'",
            "member.dob IS NOT NULL",
            "member.dob >= '1900-01-01'",
            $dateSql,
            $this->scopeCondition('member'),
        ];
        $params = [$dateParam];
        $types = $dateType;
        if (!$this->isSuperAdmin()) {
            if ($this->churchId === null) return [];
            $where[] = 'member.church_id = ?';
            $params[] = $this->churchId;
            $types .= 'i';
        }

        $sql = "SELECT member.id, member.crn,
                       TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS full_name,
                       member.dob, TIMESTAMPDIFF(YEAR, member.dob, CURDATE()) AS current_age,
                       member.photo, member.phone, bible_class.name AS class_name,
                       church.name AS church_name,
                       GROUP_CONCAT(DISTINCT organization.name ORDER BY organization.name SEPARATOR ', ') AS organizations
                  FROM members member
                  LEFT JOIN churches church ON church.id = member.church_id
                  LEFT JOIN bible_classes bible_class ON bible_class.id = member.class_id
                  LEFT JOIN member_organizations membership ON membership.member_id = member.id
                  LEFT JOIN organizations organization ON organization.id = membership.organization_id
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY member.id, member.crn, member.first_name, member.middle_name, member.last_name,
                          member.dob, member.photo, member.phone, bible_class.name, church.name
                 ORDER BY DATE_FORMAT(member.dob, '%m-%d'), member.last_name, member.first_name, member.middle_name";
        if ($limit !== null && $limit > 0) $sql .= ' LIMIT ' . (int) $limit;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['current_age'] = (int) $row['current_age'];
            $row['organizations'] = trim((string) $row['organizations']);
        }
        unset($row);
        return $rows;
    }

    private function countForBucket(string $bucket, DateTimeImmutable $today): int {
        [$dateSql, $dateParam, $dateType] = $this->dateCondition($bucket, $today);
        $where = [
            "member.status = 'active'",
            "member.dob IS NOT NULL",
            "member.dob >= '1900-01-01'",
            $dateSql,
            $this->scopeCondition('member'),
        ];
        $params = [$dateParam];
        $types = $dateType;
        if (!$this->isSuperAdmin()) {
            if ($this->churchId === null) return 0;
            $where[] = 'member.church_id = ?';
            $params[] = $this->churchId;
            $types .= 'i';
        }
        $stmt = $this->conn->prepare(
            'SELECT COUNT(DISTINCT member.id) AS total FROM members member WHERE ' . implode(' AND ', $where)
        );
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }

    private function dateCondition(string $bucket, DateTimeImmutable $today): array {
        if ($bucket === 'yesterday') {
            return ["DATE_FORMAT(member.dob, '%m-%d') = ?", $today->modify('-1 day')->format('m-d'), 's'];
        }
        if ($bucket === 'today') {
            return ["DATE_FORMAT(member.dob, '%m-%d') = ?", $today->format('m-d'), 's'];
        }
        if ($bucket === 'tomorrow') {
            return ["DATE_FORMAT(member.dob, '%m-%d') = ?", $today->modify('+1 day')->format('m-d'), 's'];
        }
        if ($bucket === 'month') {
            return ['MONTH(member.dob) = ?', (int) $today->format('n'), 'i'];
        }
        throw new InvalidArgumentException('Choose a valid birthday group.');
    }

    private function scopeCondition(string $memberAlias): string {
        if ($this->hasBroadAccess()) return '1 = 1';
        if ($this->userId === null && $this->memberId === null) return '0 = 1';
        $user = $this->userId ?: 0;
        $member = $this->memberId ?: 0;
        return "(
            EXISTS (
                SELECT 1 FROM bible_class_leaders class_leader
                WHERE class_leader.class_id = {$memberAlias}.class_id AND class_leader.status = 'active'
                  AND (class_leader.user_id = {$user} OR class_leader.member_id = {$member})
            )
            OR EXISTS (
                SELECT 1 FROM member_organizations scoped_membership
                JOIN organization_leaders organization_leader
                  ON organization_leader.organization_id = scoped_membership.organization_id
                 AND organization_leader.status = 'active'
                WHERE scoped_membership.member_id = {$memberAlias}.id
                  AND (organization_leader.user_id = {$user} OR organization_leader.member_id = {$member})
            )
            OR EXISTS (
                SELECT 1 FROM member_organizations unit_membership
                JOIN organization_unit_assignments unit_assignment
                  ON unit_assignment.member_organization_id = unit_membership.id
                JOIN organization_unit_leaders unit_leader
                  ON unit_leader.unit_id = unit_assignment.unit_id AND unit_leader.status = 'active'
                 AND (unit_leader.effective_to IS NULL OR unit_leader.effective_to >= CURDATE())
                WHERE unit_membership.member_id = {$memberAlias}.id
                  AND unit_leader.member_id = {$member}
            )
        )";
    }

    private function isSuperAdmin(): bool {
        return in_array(1, $this->roleIds, true);
    }

    private function hasBroadAccess(): bool {
        return (bool) array_intersect([1, 2, 3, 4, 11], $this->roleIds);
    }
}
