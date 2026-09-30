<?php

final class IdentityOrganizationGovernanceService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $churchId = null;
    private bool $superAdmin;

    public function __construct(mysqli $conn, ?int $userId, bool $superAdmin = false) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->superAdmin = $superAdmin;
        if ($this->userId !== null) {
            $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $this->churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0) ?: null;
            $stmt->close();
        }
    }

    public static function fromSession(mysqli $conn): self {
        $roles = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
        if (isset($_SESSION['role_id'])) $roles[] = (int) $_SESSION['role_id'];
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            !empty($_SESSION['is_super_admin']) || in_array(1, $roles, true)
        );
    }

    public function listCrnIssues(): array {
        $where = ['review.resolved = 0'];
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'member.church_id = ?';
            $types .= 'i';
            $params[] = $this->churchId;
        }
        $sql = "SELECT review.*, member.crn, member.first_name, member.middle_name,
                       member.last_name, member.church_id, church.name AS church_name,
                       class.name AS class_name
                  FROM member_crn_integrity_review review
                  JOIN members member ON member.id = review.member_id
                  LEFT JOIN churches church ON church.id = member.church_id
                  LEFT JOIN bible_classes class ON class.id = member.class_id
                 WHERE " . implode(' AND ', $where) . '
                 ORDER BY review.issue_type, church.name, member.last_name, member.first_name';
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function assignNextCrn(int $reviewId, string $reason): string {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') throw new RuntimeException('Enter the reason for this CRN repair.');

        $lockName = null;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "SELECT review.*, member.crn, member.church_id, member.class_id,
                        church.church_code, church.circuit_code, class.code AS class_code
                   FROM member_crn_integrity_review review
                   JOIN members member ON member.id = review.member_id
                   JOIN churches church ON church.id = member.church_id
                   JOIN bible_classes class ON class.id = member.class_id
                  WHERE review.id = ? AND review.resolved = 0 FOR UPDATE"
            );
            $stmt->bind_param('i', $reviewId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) throw new RuntimeException('The CRN review is no longer open.');
            $this->assertChurchAccess((int) $row['church_id']);

            $churchCode = strtoupper(trim((string) $row['church_code']));
            $classCode = strtoupper(trim((string) $row['class_code']));
            $circuitCode = strtoupper(trim((string) $row['circuit_code']));
            if ($churchCode === '' || $classCode === '' || $circuitCode === '') {
                throw new RuntimeException('The church and Bible Class codes must be completed before allocating a CRN.');
            }

            $lockName = 'myfreeman-crn-' . (int) $row['church_id'] . '-' . (int) $row['class_id'];
            $lockStmt = $this->conn->prepare('SELECT GET_LOCK(?, 10) AS acquired');
            $lockStmt->bind_param('s', $lockName);
            $lockStmt->execute();
            $acquired = (int) ($lockStmt->get_result()->fetch_assoc()['acquired'] ?? 0);
            $lockStmt->close();
            if ($acquired !== 1) throw new RuntimeException('The CRN allocator is busy. Try again.');

            $prefix = $churchCode . '-' . $classCode;
            $suffix = '-' . $circuitCode;
            $max = 0;
            $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)' . preg_quote($suffix, '/') . '$/i';
            foreach (['SELECT crn AS reference_value FROM members WHERE church_id = ?',
                      'SELECT srn AS reference_value FROM sunday_school WHERE church_id = ?'] as $sql) {
                $scan = $this->conn->prepare($sql);
                $churchId = (int) $row['church_id'];
                $scan->bind_param('i', $churchId);
                $scan->execute();
                $result = $scan->get_result();
                while ($reference = $result->fetch_assoc()) {
                    if (preg_match($pattern, trim((string) $reference['reference_value']), $match)) {
                        $max = max($max, (int) $match[1]);
                    }
                }
                $scan->close();
            }
            $newCrn = $prefix . ($max + 1) . $suffix;
            $duplicate = $this->conn->prepare('SELECT COUNT(*) AS total FROM members WHERE crn = ? AND id <> ?');
            $memberId = (int) $row['member_id'];
            $duplicate->bind_param('si', $newCrn, $memberId);
            $duplicate->execute();
            if ((int) $duplicate->get_result()->fetch_assoc()['total'] > 0) {
                throw new RuntimeException('The generated CRN was claimed by another record. Try again.');
            }
            $duplicate->close();

            $oldCrn = trim((string) $row['crn']);
            $update = $this->conn->prepare('UPDATE members SET crn = ? WHERE id = ?');
            $update->bind_param('si', $newCrn, $memberId);
            $update->execute();
            $update->close();

            $action = $oldCrn === '' ? 'assigned_new' : 'renumbered';
            $audit = $this->conn->prepare(
                'INSERT INTO member_crn_change_audit
                    (member_id, church_id, old_crn, new_crn, reason, integrity_review_id, performed_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $churchId = (int) $row['church_id'];
            $actor = $this->userId;
            $audit->bind_param('iisssii', $memberId, $churchId, $oldCrn, $newCrn, $reason, $reviewId, $actor);
            $audit->execute();
            $audit->close();

            $resolve = $this->conn->prepare(
                'UPDATE member_crn_integrity_review
                    SET resolved = 1, resolution_action = ?, resolved_crn = ?,
                        resolution_notes = ?, resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE id = ?'
            );
            $resolve->bind_param('sssii', $action, $newCrn, $reason, $actor, $reviewId);
            $resolve->execute();
            $resolve->close();

            if ($oldCrn !== '') {
                $count = $this->conn->prepare('SELECT COUNT(*) AS total FROM members WHERE TRIM(crn) = ? AND is_archived = 0');
                $count->bind_param('s', $oldCrn);
                $count->execute();
                $remaining = (int) $count->get_result()->fetch_assoc()['total'];
                $count->close();
                if ($remaining <= 1) {
                    $cleanup = $this->conn->prepare(
                        "UPDATE member_crn_integrity_review review
                         JOIN members member ON member.id = review.member_id
                            SET review.resolved = 1, review.resolution_action = 'renumbered',
                                review.resolved_crn = member.crn,
                                review.resolution_notes = 'Duplicate CRN conflict cleared by a governed renumbering.',
                                review.resolved_by_user_id = ?, review.resolved_at = NOW()
                          WHERE review.resolved = 0 AND review.issue_type = 'duplicate_crn'
                            AND review.conflicting_crn = ?"
                    );
                    $cleanup->bind_param('is', $actor, $oldCrn);
                    $cleanup->execute();
                    $cleanup->close();
                }
            }
            $this->conn->commit();
            $this->releaseLock($lockName);
            return $newCrn;
        } catch (Throwable $e) {
            $this->conn->rollback();
            if ($lockName !== null) $this->releaseLock($lockName);
            throw $e;
        }
    }

    public function listEmailIssues(): array {
        $where = ['review.resolved = 0'];
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'user_account.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $sql = "SELECT review.*, user_account.name, user_account.email, user_account.status,
                       user_account.church_id, member.crn, member.status AS member_status
                  FROM user_account_policy_review review
                  JOIN users user_account ON user_account.id = review.user_id
                  JOIN members member ON member.id = user_account.member_id
                 WHERE " . implode(' AND ', $where) . '
                 ORDER BY review.issue_type, user_account.name';
        $stmt = $this->conn->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function updateOfficialEmail(int $userId, string $email): void {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@myfreeman.org')) {
            throw new RuntimeException('Enter an approved @myfreeman.org email address.');
        }
        $stmt = $this->conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) throw new RuntimeException('The user account was not found.');
        $this->assertChurchAccess((int) $user['church_id']);

        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare('UPDATE users SET email = ? WHERE id = ?');
            $stmt->bind_param('si', $email, $userId);
            if (!$stmt->execute()) throw new RuntimeException($stmt->errno === 1062 ? 'That email is already assigned.' : $stmt->error);
            $stmt->close();
            $stmt = $this->conn->prepare(
                "UPDATE user_account_policy_review
                    SET resolved = 1, resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE user_id = ? AND issue_type IN ('missing_official_email','non_official_email')"
            );
            $actor = $this->userId;
            $stmt->bind_param('ii', $actor, $userId);
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function deactivateIneligibleUser(int $userId, string $reason): void {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') throw new RuntimeException('Enter a reason for deactivating the account.');
        if ($this->userId === $userId) throw new RuntimeException('You cannot deactivate the account currently in use.');
        $stmt = $this->conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) throw new RuntimeException('The user account was not found.');
        $this->assertChurchAccess((int) $user['church_id']);
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare(
                "UPDATE user_account_policy_review
                    SET resolved = 1, details = CONCAT(details, ' Account deactivated: ', ?),
                        resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE user_id = ? AND issue_type = 'inactive_member'"
            );
            $actor = $this->userId;
            $stmt->bind_param('sii', $reason, $actor, $userId);
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
        } catch (Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    public function getEmailPolicy(): array {
        return $this->conn->query('SELECT * FROM official_email_security_policy WHERE id = 1')
            ->fetch_assoc() ?: ['enforcement_enabled' => 0];
    }

    public function setEmailEnforcement(bool $enabled): void {
        if (!$this->superAdmin) throw new RuntimeException('Only a Super Admin can change the login email policy.');
        if ($enabled) {
            $open = $this->conn->query(
                "SELECT COUNT(DISTINCT user_account.id) AS total
                   FROM users user_account
                   JOIN user_roles user_role ON user_role.user_id = user_account.id AND user_role.is_active = 1
                   JOIN members member ON member.id = user_account.member_id
                  WHERE user_account.status = 'active'
                    AND (user_account.email IS NULL OR LOWER(TRIM(user_account.email)) NOT LIKE '%@myfreeman.org'
                         OR member.status <> 'active' OR member.is_archived = 1)"
            )->fetch_assoc();
            if ((int) ($open['total'] ?? 0) > 0) {
                throw new RuntimeException('Resolve every active back-office account email/member policy issue before enabling enforcement.');
            }
        }
        $flag = $enabled ? 1 : 0;
        $actor = $this->userId;
        $stmt = $this->conn->prepare(
            'UPDATE official_email_security_policy
                SET enforcement_enabled = ?, activated_by_user_id = ?, activated_at = IF(? = 1, NOW(), NULL)
              WHERE id = 1'
        );
        $stmt->bind_param('iii', $flag, $actor, $flag);
        $stmt->execute();
        $stmt->close();
    }

    public function listBrigadeOrganizations(): array {
        $where = ["organization.assignment_strategy = 'balanced_plus_section'"];
        $types = '';
        $params = [];
        if (!$this->superAdmin) {
            if ($this->churchId === null) return [];
            $where[] = 'organization.church_id = ?';
            $types = 'i';
            $params[] = $this->churchId;
        }
        $stmt = $this->conn->prepare(
            'SELECT organization.id, organization.name, organization.church_id, church.name AS church_name
               FROM organizations organization JOIN churches church ON church.id = organization.church_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY church.name, organization.name'
        );
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function listBrigadeMembers(int $organizationId): array {
        $organization = $this->getBrigadeOrganization($organizationId);
        $stmt = $this->conn->prepare(
            "SELECT member.id, member.crn, member.first_name, member.middle_name,
                    member.last_name, member.gender, eligibility.officer_branch,
                    eligibility.rank_or_level, eligibility.status, eligibility.confirmation_notes
               FROM member_organizations membership
               JOIN members member ON member.id = membership.member_id
               LEFT JOIN brigade_officer_eligibility eligibility
                      ON eligibility.organization_id = membership.organization_id
                     AND eligibility.member_id = membership.member_id
              WHERE membership.organization_id = ? AND member.is_archived = 0
              ORDER BY member.last_name, member.first_name"
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function certifyBrigadeOfficer(
        int $organizationId,
        int $memberId,
        string $branch,
        string $rank,
        string $notes
    ): void {
        if (!in_array($branch, ['boys', 'girls', 'both'], true)) {
            throw new RuntimeException('Choose Boys, Girls, or Both officer eligibility.');
        }
        $rank = mb_substr(trim($rank), 0, 100);
        $notes = mb_substr(trim($notes), 0, 500);
        if ($rank === '' || $notes === '') throw new RuntimeException('Rank/level and confirmation evidence are required.');
        $organization = $this->getBrigadeOrganization($organizationId);
        $stmt = $this->conn->prepare(
            'SELECT member.gender FROM member_organizations membership
             JOIN members member ON member.id = membership.member_id
             WHERE membership.organization_id = ? AND membership.member_id = ? AND member.is_archived = 0 LIMIT 1'
        );
        $stmt->bind_param('ii', $organizationId, $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) throw new RuntimeException('The officer must be an active member of this Brigade organization.');
        if (in_array($branch, ['girls', 'both'], true)
            && strtolower(trim((string) $member['gender'])) !== 'female') {
            throw new RuntimeException('Girls Brigade officer eligibility is restricted to female members by the update document.');
        }
        $actor = $this->userId;
        $stmt = $this->conn->prepare(
            "INSERT INTO brigade_officer_eligibility
                (organization_id, member_id, officer_branch, rank_or_level, status,
                 effective_from, effective_to, confirmation_notes, confirmed_by_user_id)
             VALUES (?, ?, ?, ?, 'active', CURDATE(), NULL, ?, ?)
             ON DUPLICATE KEY UPDATE officer_branch = VALUES(officer_branch),
                 rank_or_level = VALUES(rank_or_level), status = 'active',
                 effective_from = CURDATE(), effective_to = NULL,
                 confirmation_notes = VALUES(confirmation_notes),
                 confirmed_by_user_id = VALUES(confirmed_by_user_id)"
        );
        $stmt->bind_param('iisssi', $organizationId, $memberId, $branch, $rank, $notes, $actor);
        $stmt->execute();
        $stmt->close();

        $resolve = $this->conn->prepare(
            'UPDATE brigade_leader_eligibility_review review
             JOIN organization_unit_leaders leader ON leader.id = review.organization_unit_leader_id
             JOIN organization_units unit ON unit.id = leader.unit_id
                SET review.resolved = 1, review.resolved_by_user_id = ?, review.resolved_at = NOW()
              WHERE leader.member_id = ? AND unit.organization_id = ?
                AND (review.issue_type = \'missing_officer_evidence\'
                     OR (review.issue_type = \'girls_section_gender_conflict\' AND ? IN (\'girls\',\'both\')))'
        );
        $resolve->bind_param('iiis', $actor, $memberId, $organizationId, $branch);
        $resolve->execute();
        $resolve->close();
    }

    public function revokeBrigadeOfficer(int $organizationId, int $memberId, string $reason): void {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') throw new RuntimeException('Enter the reason for revoking eligibility.');
        $this->getBrigadeOrganization($organizationId);
        $stmt = $this->conn->prepare(
            "UPDATE brigade_officer_eligibility SET status = 'inactive', effective_to = CURDATE(),
                    confirmation_notes = CONCAT(confirmation_notes, '\nRevoked: ', ?), confirmed_by_user_id = ?
              WHERE organization_id = ? AND member_id = ? AND status = 'active'"
        );
        $actor = $this->userId;
        $stmt->bind_param('siii', $reason, $actor, $organizationId, $memberId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) throw new RuntimeException('No active officer eligibility was found.');
        $stmt->close();
    }

    private function getBrigadeOrganization(int $organizationId): array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM organizations WHERE id = ? AND assignment_strategy = 'balanced_plus_section' LIMIT 1"
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $organization = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$organization) throw new RuntimeException('The Brigade organization was not found.');
        $this->assertChurchAccess((int) $organization['church_id']);
        return $organization;
    }

    private function assertChurchAccess(int $churchId): void {
        if (!$this->superAdmin && ($this->churchId === null || $this->churchId !== $churchId)) {
            throw new RuntimeException('This record is outside your church scope.');
        }
    }

    private function releaseLock(string $lockName): void {
        $stmt = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $lockName);
        $stmt->execute();
        $stmt->close();
    }
}
