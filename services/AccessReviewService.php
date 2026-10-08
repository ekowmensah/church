<?php

require_once __DIR__ . '/rbac/RBACServiceFactory.php';
require_once __DIR__ . '/../helpers/rbac_identity.php';

final class AccessReviewAuthorizationException extends RuntimeException {}

/**
 * Church-scoped access certification with immutable evidence snapshots.
 * The review workflow reports access; it never mutates roles or permissions.
 */
final class AccessReviewService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
        RBACServiceFactory::setConnection($conn);
    }

    public function listSubjects(int $actorUserId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
        $scope = $this->resolveScope($actorUserId, 'view_access_reviews', (int) ($filters['church_id'] ?? 0));
        [$whereSql, $types, $params] = $this->buildSubjectWhere($scope, $filters);

        $latestJoin = " LEFT JOIN access_review_certifications latest
                          ON latest.id = (
                              SELECT recent.id FROM access_review_certifications recent
                               WHERE recent.subject_user_id = account.id
                               ORDER BY recent.id DESC LIMIT 1
                          )";
        $countSql = "SELECT COUNT(*) AS total FROM users account
                     LEFT JOIN members member ON member.id = account.member_id
                     {$latestJoin} WHERE {$whereSql}";
        $total = $this->scalarCount($countSql, $types, $params);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT account.id, account.name, account.email, account.status,
                       account.member_id, account.church_id, account.photo,
                       member.crn, church.name AS church_name,
                       latest.id AS latest_review_id, latest.decision AS latest_decision,
                       latest.reviewed_at, latest.valid_until, latest.notes AS latest_notes,
                       reviewer.name AS reviewer_name
                  FROM users account
                  LEFT JOIN members member ON member.id = account.member_id
                  LEFT JOIN churches church ON church.id = account.church_id
                  {$latestJoin}
                  LEFT JOIN users reviewer ON reviewer.id = latest.reviewed_by_user_id
                 WHERE {$whereSql}
                 ORDER BY CASE account.status WHEN 'active' THEN 0 ELSE 1 END,
                          account.name, account.id
                 LIMIT ? OFFSET ?";
        $queryParams = array_merge($params, [$perPage, $offset]);
        $stmt = $this->conn->prepare($sql);
        $queryTypes = $types . 'ii';
        $stmt->bind_param($queryTypes, ...$queryParams);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $this->hydrateAccessSummary($subjects);

        return [
            'subjects' => $subjects,
            'summary' => $this->summary($scope),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
            'scope' => $scope,
        ];
    }

    public function certify(
        int $actorUserId,
        int $subjectUserId,
        string $decision,
        string $notes,
        int $validDays = 90
    ): int {
        if (!in_array($decision, ['certified', 'changes_required'], true)) {
            throw new InvalidArgumentException('Choose a valid review decision.');
        }
        $notes = trim($notes);
        if ($decision === 'changes_required' && strlen($notes) < 10) {
            throw new InvalidArgumentException('Describe the required access changes in at least 10 characters.');
        }
        if (!in_array($validDays, [30, 60, 90, 180, 365], true)) $validDays = 90;
        if ($subjectUserId < 1) throw new InvalidArgumentException('Choose a valid user account.');
        if ($subjectUserId === $actorUserId) {
            throw new AccessReviewAuthorizationException('You cannot certify your own access. Another authorized reviewer must complete it.');
        }

        $account = $this->getAccount($subjectUserId);
        if (!$account) throw new RuntimeException('User account not found.');
        $scope = $this->resolveScope($actorUserId, 'certify_access_reviews', (int) $account['church_id']);
        if (!$scope['is_super_admin'] && $this->isSuperAdministrator($subjectUserId)) {
            throw new AccessReviewAuthorizationException('Only another Super Administrator may review protected administrator access.');
        }

        $snapshot = $this->buildSnapshot($subjectUserId, $account);
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($snapshotJson === false) throw new RuntimeException('The access snapshot could not be encoded.');
        $validUntil = $decision === 'certified'
            ? (new DateTimeImmutable('now'))->modify('+' . $validDays . ' days')->format('Y-m-d H:i:s')
            : null;

        $stmt = $this->conn->prepare(
            'INSERT INTO access_review_certifications
                (subject_user_id, church_id, decision, access_snapshot,
                 notes, reviewed_by_user_id, reviewed_at, valid_until)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)'
        );
        $churchId = (int) $account['church_id'];
        $stmt->bind_param(
            'iisssis',
            $subjectUserId,
            $churchId,
            $decision,
            $snapshotJson,
            $notes,
            $actorUserId,
            $validUntil
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function getHistory(int $actorUserId, int $subjectUserId, int $limit = 20): array
    {
        $account = $this->getAccount($subjectUserId);
        if (!$account) throw new RuntimeException('User account not found.');
        $scope = $this->resolveScope($actorUserId, 'view_access_reviews', (int) $account['church_id']);
        if (!$scope['is_super_admin'] && $this->isSuperAdministrator($subjectUserId)) {
            throw new AccessReviewAuthorizationException('Protected administrator review history is restricted to Super Administrators.');
        }
        $limit = min(50, max(1, $limit));
        $stmt = $this->conn->prepare(
            "SELECT certification.id, certification.decision, certification.notes,
                    certification.reviewed_at, certification.valid_until,
                    reviewer.name AS reviewer_name
               FROM access_review_certifications certification
               LEFT JOIN users reviewer ON reviewer.id = certification.reviewed_by_user_id
              WHERE certification.subject_user_id = ?
              ORDER BY certification.id DESC LIMIT {$limit}"
        );
        $stmt->bind_param('i', $subjectUserId);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return ['account' => $account, 'history' => $history];
    }

    private function resolveScope(int $actorUserId, string $permission, int $requestedChurchId): array
    {
        $stmt = $this->conn->prepare("SELECT church_id FROM users WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $stmt->close();
        if ($actorChurchId < 1) throw new AccessReviewAuthorizationException('Your active account has no church scope.');

        $isSuperAdmin = rbac_identity_is_super_admin($this->conn, $actorUserId);
        $churchId = $requestedChurchId > 0 ? $requestedChurchId : ($isSuperAdmin ? 0 : $actorChurchId);
        if (!$isSuperAdmin && $churchId !== $actorChurchId) {
            throw new AccessReviewAuthorizationException('You cannot review access outside your church.');
        }
        $contextChurchId = $churchId > 0 ? $churchId : $actorChurchId;
        $decision = RBACServiceFactory::getPermissionChecker()->authorize(
            $permission,
            $actorUserId,
            ['church_id' => $contextChurchId],
            true
        );
        if (empty($decision['allowed'])) {
            throw new AccessReviewAuthorizationException('You are not authorized to review user access for this church.');
        }
        return ['church_id' => $churchId, 'actor_church_id' => $actorChurchId, 'is_super_admin' => $isSuperAdmin];
    }

    private function buildSubjectWhere(array $scope, array $filters): array
    {
        $where = ['1=1'];
        $types = '';
        $params = [];
        if ((int) $scope['church_id'] > 0) {
            $where[] = 'account.church_id = ?';
            $types .= 'i';
            $params[] = (int) $scope['church_id'];
        }
        if (!$scope['is_super_admin']) {
            $where[] = "NOT EXISTS (
                SELECT 1 FROM user_roles protected_assignment
                JOIN roles protected_role ON protected_role.id = protected_assignment.role_id
                WHERE protected_assignment.user_id = account.id
                  AND protected_assignment.is_active = 1 AND protected_role.is_active = 1
                  AND LOWER(TRIM(protected_role.name)) IN ('super admin','super administrator')
                  AND (protected_assignment.expires_at IS NULL OR protected_assignment.expires_at > NOW())
            )";
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(account.name LIKE ? OR account.email LIKE ? OR member.crn LIKE ?)';
            $term = '%' . $search . '%';
            $types .= 'sss';
            array_push($params, $term, $term, $term);
        }
        $status = (string) ($filters['status'] ?? 'all');
        if (in_array($status, ['active', 'inactive'], true)) {
            $where[] = 'account.status = ?';
            $types .= 's';
            $params[] = $status;
        }
        $review = (string) ($filters['review'] ?? 'all');
        if ($review === 'due') {
            $where[] = "(latest.id IS NULL OR latest.decision = 'changes_required' OR latest.valid_until < NOW())";
        } elseif ($review === 'current') {
            $where[] = "latest.decision = 'certified' AND latest.valid_until >= NOW()";
        } elseif ($review === 'changes') {
            $where[] = "latest.decision = 'changes_required'";
        }
        return [implode(' AND ', $where), $types, $params];
    }

    private function hydrateAccessSummary(array &$subjects): void
    {
        if (!$subjects) return;
        $ids = array_map('intval', array_column($subjects, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $indexed = [];
        foreach ($subjects as $key => $subject) $indexed[(int) $subject['id']] = $key;

        $stmt = $this->conn->prepare(
            "SELECT assignment.user_id, COUNT(DISTINCT assignment.role_id) AS role_count,
                    GROUP_CONCAT(DISTINCT role.name ORDER BY role.name SEPARATOR ', ') AS role_names,
                    MIN(CASE WHEN assignment.expires_at > NOW() THEN assignment.expires_at END) AS next_role_expiry,
                    GROUP_CONCAT(DISTINCT COALESCE(source.source_type, 'legacy_manual') ORDER BY source.source_type SEPARATOR ', ') AS role_sources
               FROM user_roles assignment
               JOIN roles role ON role.id = assignment.role_id AND role.is_active = 1
               LEFT JOIN user_role_sources source ON source.user_role_id = assignment.id
              WHERE assignment.user_id IN ({$placeholders}) AND assignment.is_active = 1
                AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
              GROUP BY assignment.user_id"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $key = $indexed[(int) $row['user_id']];
            $subjects[$key] = array_merge($subjects[$key], $row);
        }
        $stmt->close();

        $stmt = $this->conn->prepare(
            "SELECT override_row.user_id,
                    COUNT(*) AS override_count,
                    SUM(override_row.allowed = 1) AS allow_override_count,
                    SUM(override_row.allowed = 0) AS deny_override_count
               FROM user_permissions override_row
               JOIN permissions permission ON permission.id = override_row.permission_id AND permission.is_active = 1
              WHERE override_row.user_id IN ({$placeholders})
                AND override_row.is_active = 1
                AND (override_row.expires_at IS NULL OR override_row.expires_at > NOW())
              GROUP BY override_row.user_id"
        );
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $key = $indexed[(int) $row['user_id']];
            $subjects[$key] = array_merge($subjects[$key], $row);
        }
        $stmt->close();

        $cte = "WITH RECURSIVE effective_roles AS (
                    SELECT assignment.user_id, assignment.role_id,
                           CAST(CONCAT(',', assignment.role_id, ',') AS CHAR(1000)) AS path, 0 AS depth
                      FROM user_roles assignment
                      JOIN roles role ON role.id = assignment.role_id AND role.is_active = 1
                     WHERE assignment.user_id IN ({$placeholders})
                       AND assignment.is_active = 1
                       AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
                    UNION ALL
                    SELECT effective_roles.user_id, parent.id,
                           CONCAT(effective_roles.path, parent.id, ','), effective_roles.depth + 1
                      FROM effective_roles
                      JOIN roles child ON child.id = effective_roles.role_id
                      JOIN roles parent ON parent.id = child.parent_id AND parent.is_active = 1
                     WHERE effective_roles.depth < 16
                       AND LOCATE(CONCAT(',', parent.id, ','), effective_roles.path) = 0
                ), effective_permissions AS (
                    SELECT effective_roles.user_id, permission.id, permission.risk_level
                      FROM effective_roles
                      JOIN role_permissions role_grant ON role_grant.role_id = effective_roles.role_id
                      JOIN permissions permission ON permission.id = role_grant.permission_id AND permission.is_active = 1
                     WHERE role_grant.is_active = 1
                       AND (role_grant.expires_at IS NULL OR role_grant.expires_at > NOW())
                       AND NOT EXISTS (
                           SELECT 1 FROM user_permissions denial
                            WHERE denial.user_id = effective_roles.user_id
                              AND denial.permission_id = permission.id AND denial.allowed = 0
                              AND denial.is_active = 1
                              AND (denial.expires_at IS NULL OR denial.expires_at > NOW())
                       )
                    UNION
                    SELECT direct_grant.user_id, permission.id, permission.risk_level
                      FROM user_permissions direct_grant
                      JOIN permissions permission ON permission.id = direct_grant.permission_id AND permission.is_active = 1
                     WHERE direct_grant.user_id IN ({$placeholders})
                       AND direct_grant.allowed = 1 AND direct_grant.is_active = 1
                       AND (direct_grant.expires_at IS NULL OR direct_grant.expires_at > NOW())
                )
                SELECT user_id, COUNT(DISTINCT id) AS effective_permission_count,
                       COUNT(DISTINCT CASE WHEN risk_level IN ('financial','privileged','system_critical') THEN id END) AS high_risk_permission_count
                  FROM effective_permissions GROUP BY user_id";
        $stmt = $this->conn->prepare($cte);
        $doubleIds = array_merge($ids, $ids);
        $doubleTypes = $types . $types;
        $stmt->bind_param($doubleTypes, ...$doubleIds);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $key = $indexed[(int) $row['user_id']];
            $subjects[$key] = array_merge($subjects[$key], $row);
        }
        $stmt->close();

        foreach ($subjects as &$subject) {
            foreach (['role_count', 'override_count', 'allow_override_count', 'deny_override_count', 'effective_permission_count', 'high_risk_permission_count'] as $field) {
                $subject[$field] = (int) ($subject[$field] ?? 0);
            }
            $subject['role_names'] = $subject['role_names'] ?? '';
            $subject['role_sources'] = $subject['role_sources'] ?? '';
            // Accounts without an effective role do not participate in the
            // grouped role query above, so retain an explicit nullable value
            // for the view/service response contract.
            $subject['next_role_expiry'] = $subject['next_role_expiry'] ?? null;
            $subject['review_state'] = $this->reviewState($subject);
        }
        unset($subject);
    }

    private function summary(array $scope): array
    {
        $where = (int) $scope['church_id'] > 0 ? 'WHERE account.church_id = ?' : '';
        $types = (int) $scope['church_id'] > 0 ? 'i' : '';
        $params = (int) $scope['church_id'] > 0 ? [(int) $scope['church_id']] : [];
        if (!$scope['is_super_admin']) {
            $where .= ($where ? ' AND ' : 'WHERE ') . "NOT EXISTS (
                SELECT 1 FROM user_roles sa JOIN roles sr ON sr.id = sa.role_id
                 WHERE sa.user_id = account.id AND sa.is_active = 1 AND sr.is_active = 1
                   AND LOWER(TRIM(sr.name)) IN ('super admin','super administrator')
                   AND (sa.expires_at IS NULL OR sa.expires_at > NOW()))";
        }
        $sql = "SELECT COUNT(*) AS total,
                       SUM(account.status = 'active') AS active,
                       SUM(latest.id IS NULL OR latest.decision = 'changes_required' OR latest.valid_until < NOW()) AS due,
                       SUM(latest.decision = 'certified' AND latest.valid_until >= NOW()) AS current,
                       SUM(latest.decision = 'changes_required') AS changes_required
                  FROM users account
                  LEFT JOIN access_review_certifications latest
                    ON latest.id = (SELECT recent.id FROM access_review_certifications recent
                                     WHERE recent.subject_user_id = account.id ORDER BY recent.id DESC LIMIT 1)
                  {$where}";
        $stmt = $this->conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        foreach (['total', 'active', 'due', 'current', 'changes_required'] as $field) $row[$field] = (int) ($row[$field] ?? 0);
        return $row;
    }

    private function buildSnapshot(int $subjectUserId, array $account): array
    {
        $checker = RBACServiceFactory::getPermissionChecker();
        $roles = array_map(static fn(array $role): array => [
            'id' => (int) $role['id'],
            'name' => $role['name'],
            'is_primary' => (int) ($role['is_primary'] ?? 0),
            'expires_at' => $role['expires_at'] ?? null,
        ], $checker->getUserRoles($subjectUserId));
        $permissions = array_map(static fn(array $permission): array => [
            'id' => (int) $permission['id'],
            'code' => $permission['permission_code'] ?? $permission['name'],
            'risk_level' => $permission['risk_level'] ?? 'standard',
        ], $checker->getUserPermissions($subjectUserId, true));
        $stmt = $this->conn->prepare(
            'SELECT permission_id, allowed, reason, expires_at
               FROM user_permissions
              WHERE user_id = ? AND is_active = 1
                AND (expires_at IS NULL OR expires_at > NOW())
              ORDER BY permission_id'
        );
        $stmt->bind_param('i', $subjectUserId);
        $stmt->execute();
        $overrides = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return [
            'captured_at' => date('c'),
            'account' => [
                'id' => (int) $account['id'],
                'member_id' => isset($account['member_id']) ? (int) $account['member_id'] : null,
                'church_id' => (int) $account['church_id'],
                'status' => $account['status'],
            ],
            'roles' => $roles,
            'effective_permissions' => $permissions,
            'direct_overrides' => $overrides,
        ];
    }

    private function getAccount(int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT account.id, account.member_id, account.church_id, account.name,
                    account.email, account.status, member.crn, church.name AS church_name
               FROM users account
               LEFT JOIN members member ON member.id = account.member_id
               LEFT JOIN churches church ON church.id = account.church_id
              WHERE account.id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function isSuperAdministrator(int $userId): bool
    {
        return rbac_identity_is_super_admin($this->conn, $userId);
    }

    private function reviewState(array $subject): string
    {
        if (empty($subject['latest_review_id'])) return 'due';
        if (($subject['latest_decision'] ?? '') === 'changes_required') return 'changes_required';
        if (empty($subject['valid_until']) || strtotime((string) $subject['valid_until']) < time()) return 'due';
        return 'current';
    }

    private function scalarCount(string $sql, string $types, array $params): int
    {
        $stmt = $this->conn->prepare($sql);
        if ($params) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $count;
    }
}
