<?php

final class RoleOfServingReportService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private array $roleIds;

    public function __construct(mysqli $conn, ?int $userId, ?int $memberId, array $roleIds) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        if ($this->userId !== null) {
            $stmt = $this->conn->prepare(
                "SELECT user_account.member_id, user_role.role_id
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
                if (!empty($row['role_id'])) $this->roleIds[] = (int) $row['role_id'];
            }
            $stmt->close();
            $this->roleIds = array_values(array_unique($this->roleIds));
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

    public function getAllowedChurches(): array {
        if ($this->isSuperAdmin()) {
            $result = $this->conn->query('SELECT id, name FROM churches ORDER BY name');
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        if ($this->memberId === null) return [];
        $stmt = $this->conn->prepare(
            'SELECT church.id, church.name
               FROM members member JOIN churches church ON church.id = member.church_id
              WHERE member.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $this->memberId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? [$row] : [];
    }

    public function getRoles(): array {
        $result = $this->conn->query(
            "SELECT id, name FROM roles_of_serving
              WHERE name NOT LIKE 'LEGACY %REVIEW REQUIRED'
              ORDER BY name"
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getOrganizations(int $churchId): array {
        $this->assertChurchAllowed($churchId);
        $stmt = $this->conn->prepare('SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name');
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function build(int $churchId, ?int $servingRoleId = null, ?int $organizationId = null, ?string $gender = null): array {
        $this->assertChurchAllowed($churchId);
        if ($gender !== null && !in_array($gender, ['Male', 'Female', 'Unspecified'], true)) {
            throw new InvalidArgumentException('Choose a valid gender filter.');
        }
        if ($servingRoleId !== null && $servingRoleId < 1) $servingRoleId = null;
        if ($organizationId !== null && $organizationId < 1) $organizationId = null;

        $where = ["member.church_id = ?", "member.status = 'active'", $this->scopeCondition('member')];
        $params = [$churchId];
        $types = 'i';
        if ($servingRoleId !== null) {
            $where[] = 'serving_role.id = ?';
            $params[] = $servingRoleId;
            $types .= 'i';
        }
        if ($organizationId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM member_organizations filter_membership WHERE filter_membership.member_id = member.id AND filter_membership.organization_id = ?)';
            $params[] = $organizationId;
            $types .= 'i';
        }
        if ($gender !== null) {
            if ($gender === 'Unspecified') {
                $where[] = "(member.gender NOT IN ('Male', 'Female') OR member.gender IS NULL)";
            } else {
                $where[] = 'member.gender = ?';
                $params[] = $gender;
                $types .= 's';
            }
        }
        $whereSql = implode(' AND ', array_filter($where));

        $summarySql = "SELECT serving_role.id AS role_id, serving_role.name AS role_name,
                              COUNT(DISTINCT CASE WHEN member.gender = 'Male' THEN member.id END) AS male,
                              COUNT(DISTINCT CASE WHEN member.gender = 'Female' THEN member.id END) AS female,
                              COUNT(DISTINCT CASE WHEN member.gender NOT IN ('Male', 'Female') OR member.gender IS NULL THEN member.id END) AS unspecified,
                              COUNT(DISTINCT member.id) AS total
                         FROM member_roles_of_serving member_role
                         JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
                         JOIN members member ON member.id = member_role.member_id
                        WHERE {$whereSql}
                        GROUP BY serving_role.id, serving_role.name
                        HAVING total > 0
                        ORDER BY serving_role.name";
        $stmt = $this->conn->prepare($summarySql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $detailSql = "SELECT member.id AS member_id, member.crn,
                             TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS member_name,
                             CASE WHEN member.gender IN ('Male', 'Female') THEN member.gender ELSE 'Unspecified' END AS gender,
                             member.phone, member.email, bible_class.name AS class_name,
                             serving_role.id AS role_id, serving_role.name AS role_name,
                             GROUP_CONCAT(DISTINCT organization.name ORDER BY organization.name SEPARATOR ', ') AS organizations
                        FROM member_roles_of_serving member_role
                        JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
                        JOIN members member ON member.id = member_role.member_id
                        LEFT JOIN bible_classes bible_class ON bible_class.id = member.class_id
                        LEFT JOIN member_organizations membership ON membership.member_id = member.id
                        LEFT JOIN organizations organization ON organization.id = membership.organization_id
                       WHERE {$whereSql}
                       GROUP BY member.id, member.crn, member.first_name, member.middle_name, member.last_name,
                                member.gender, member.phone, member.email, bible_class.name,
                                serving_role.id, serving_role.name
                       ORDER BY serving_role.name, member.last_name, member.first_name, member.middle_name";
        $stmt = $this->conn->prepare($detailSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totals = ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0];
        $uniqueMembers = [];
        foreach ($members as $member) {
            $uniqueMembers[(int) $member['member_id']] = $member['gender'];
        }
        foreach ($uniqueMembers as $memberGender) {
            $field = $memberGender === 'Male' ? 'male' : ($memberGender === 'Female' ? 'female' : 'unspecified');
            $totals[$field]++;
            $totals['total']++;
        }
        foreach ($summary as &$row) {
            foreach (['male', 'female', 'unspecified', 'total'] as $field) $row[$field] = (int) $row[$field];
        }
        unset($row);
        return ['summary' => $summary, 'members' => $members, 'totals' => $totals];
    }

    private function assertChurchAllowed(int $churchId): void {
        foreach ($this->getAllowedChurches() as $church) {
            if ((int) $church['id'] === $churchId) return;
        }
        throw new RuntimeException('The selected church is outside your reporting scope.');
    }

    private function scopeCondition(string $memberAlias): string {
        if ($this->hasBroadAccess()) return '1 = 1';
        if ($this->userId === null && $this->memberId === null) return '0 = 1';
        $user = $this->userId !== null ? $this->userId : 0;
        $member = $this->memberId !== null ? $this->memberId : 0;
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
