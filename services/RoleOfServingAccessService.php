<?php

final class RoleOfServingAccessService {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
    }

    public function getMappings(): array {
        $result = $this->conn->query(
            "SELECT mapping.id, mapping.role_of_serving_id, serving_role.name AS serving_role_name,
                    mapping.access_role_id, access_role.name AS access_role_name,
                    mapping.is_active, mapping.updated_at,
                    COUNT(DISTINCT member_role.member_id) AS assigned_members,
                    COUNT(DISTINCT user_account.id) AS linked_users
               FROM role_of_serving_access_mappings mapping
               JOIN roles_of_serving serving_role ON serving_role.id = mapping.role_of_serving_id
               JOIN roles access_role ON access_role.id = mapping.access_role_id
               LEFT JOIN member_roles_of_serving member_role
                      ON member_role.role_id = mapping.role_of_serving_id
               LEFT JOIN users user_account ON user_account.member_id = member_role.member_id
              GROUP BY mapping.id, mapping.role_of_serving_id, serving_role.name,
                       mapping.access_role_id, access_role.name, mapping.is_active, mapping.updated_at
              ORDER BY serving_role.name, access_role.name"
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function saveMapping(int $servingRoleId, int $accessRoleId, bool $isActive, ?int $actorUserId): int {
        $this->assertRoleExists('roles_of_serving', $servingRoleId, 'Role of Serving');
        $this->assertRoleExists('roles', $accessRoleId, 'system access role');
        $active = $isActive ? 1 : 0;
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                "INSERT INTO role_of_serving_access_mappings
                    (role_of_serving_id, access_role_id, is_active, created_by_user_id, updated_by_user_id)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_active = VALUES(is_active),
                    updated_by_user_id = VALUES(updated_by_user_id)"
            );
            $stmt->bind_param('iiiii', $servingRoleId, $accessRoleId, $active, $actorUserId, $actorUserId);
            $stmt->execute();
            $mappingId = (int) ($stmt->insert_id ?: 0);
            $stmt->close();
            if ($mappingId === 0) {
                $stmt = $this->conn->prepare(
                    'SELECT id FROM role_of_serving_access_mappings WHERE role_of_serving_id = ? AND access_role_id = ?'
                );
                $stmt->bind_param('ii', $servingRoleId, $accessRoleId);
                $stmt->execute();
                $mappingId = (int) $stmt->get_result()->fetch_assoc()['id'];
                $stmt->close();
            }
            $this->syncServingRoleMembers($servingRoleId, $actorUserId, 'Access mapping updated.');
            $this->conn->commit();
            return $mappingId;
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    public function syncAll(?int $actorUserId, string $reason = 'Administrator requested full synchronization.'): array {
        $result = $this->conn->query('SELECT id FROM users WHERE member_id IS NOT NULL ORDER BY id');
        $processed = 0;
        $granted = 0;
        $revoked = 0;
        while ($result && ($row = $result->fetch_assoc())) {
            $outcome = $this->syncMemberByUserId((int) $row['id'], $actorUserId, $reason);
            $processed++;
            $granted += $outcome['granted'];
            $revoked += $outcome['revoked'];
        }
        return compact('processed', 'granted', 'revoked');
    }

    public function deleteMapping(int $mappingId, ?int $actorUserId): array {
        $this->conn->begin_transaction();
        try {
            $stmt = $this->conn->prepare(
                'SELECT id FROM role_of_serving_access_mappings WHERE id = ? FOR UPDATE'
            );
            $stmt->bind_param('i', $mappingId);
            $stmt->execute();
            $exists = (bool) $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) throw new RuntimeException('Mapping not found.');
            $stmt = $this->conn->prepare(
                'UPDATE role_of_serving_access_mappings SET is_active = 0, updated_by_user_id = ? WHERE id = ?'
            );
            $stmt->bind_param('ii', $actorUserId, $mappingId);
            $stmt->execute();
            $stmt->close();
            $outcome = $this->syncAll($actorUserId, 'Role-of-Serving access mapping removed.');
            $stmt = $this->conn->prepare('DELETE FROM role_of_serving_access_mappings WHERE id = ?');
            $stmt->bind_param('i', $mappingId);
            $stmt->execute();
            $stmt->close();
            $this->conn->commit();
            return $outcome;
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    public function syncMember(int $memberId, ?int $actorUserId, string $reason = 'Member access synchronized.'): array {
        $stmt = $this->conn->prepare('SELECT id FROM users WHERE member_id = ? LIMIT 1');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $userId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        $stmt->close();
        if ($userId < 1) return ['granted' => 0, 'revoked' => 0, 'retained' => 0];
        return $this->syncMemberByUserId($userId, $actorUserId, $reason);
    }

    public function getMappedAccessRolesForMember(int $memberId): array {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT access_role.id, access_role.name,
                    GROUP_CONCAT(DISTINCT serving_role.name ORDER BY serving_role.name SEPARATOR ', ') AS serving_roles
               FROM member_roles_of_serving member_role
               JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
               JOIN role_of_serving_access_mappings mapping
                 ON mapping.role_of_serving_id = member_role.role_id AND mapping.is_active = 1
               JOIN roles access_role ON access_role.id = mapping.access_role_id AND access_role.is_active = 1
              WHERE member_role.member_id = ?
              GROUP BY access_role.id, access_role.name
              ORDER BY access_role.name"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function getEligibleMembers(?int $includeMemberId = null): array {
        $sql = "SELECT member.id, member.crn,
                       TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS full_name,
                       member.email, member.phone, member.church_id, church.name AS church_name,
                       GROUP_CONCAT(DISTINCT serving_role.name ORDER BY serving_role.name SEPARATOR ', ') AS serving_roles
                  FROM members member
                  JOIN churches church ON church.id = member.church_id
                  LEFT JOIN users user_account ON user_account.member_id = member.id
                  LEFT JOIN member_roles_of_serving member_role ON member_role.member_id = member.id
                  LEFT JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
                 WHERE member.status = 'active'
                   AND (user_account.id IS NULL";
        if ($includeMemberId !== null && $includeMemberId > 0) $sql .= ' OR member.id = ' . (int) $includeMemberId;
        $sql .= ") GROUP BY member.id, member.crn, member.first_name, member.middle_name,
                            member.last_name, member.email, member.phone, member.church_id, church.name
                   ORDER BY member.last_name, member.first_name, member.middle_name";
        $result = $this->conn->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function findMembersForUserAccessByCrn(string $crn): array {
        $crn = trim($crn);
        if ($crn === '') return [];

        $stmt = $this->conn->prepare(
            "SELECT member.id, member.crn,
                    TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS full_name,
                    member.email, member.phone, member.status, member.church_id,
                    church.name AS church_name, user_account.id AS existing_user_id,
                    GROUP_CONCAT(DISTINCT serving_role.name ORDER BY serving_role.name SEPARATOR ', ') AS serving_roles
               FROM members member
               LEFT JOIN churches church ON church.id = member.church_id
               LEFT JOIN users user_account ON user_account.member_id = member.id
               LEFT JOIN member_roles_of_serving member_role ON member_role.member_id = member.id
               LEFT JOIN roles_of_serving serving_role ON serving_role.id = member_role.role_id
              WHERE member.crn = ?
              GROUP BY member.id, member.crn, member.first_name, member.middle_name,
                       member.last_name, member.email, member.phone, member.status,
                       member.church_id, church.name, user_account.id
              ORDER BY member.id"
        );
        $stmt->bind_param('s', $crn);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['church_id'] = (int) $row['church_id'];
            $row['existing_user_id'] = $row['existing_user_id'] === null
                ? null
                : (int) $row['existing_user_id'];
            $row['serving_roles'] = trim((string) $row['serving_roles']);
            $row['mapped_roles'] = $this->getMappedAccessRolesForMember((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function getMemberAccessProfile(int $memberId): ?array {
        if ($memberId < 1) return null;
        $stmt = $this->conn->prepare('SELECT crn FROM members WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $crn = (string) ($stmt->get_result()->fetch_assoc()['crn'] ?? '');
        $stmt->close();
        if ($crn === '') return null;

        foreach ($this->findMembersForUserAccessByCrn($crn) as $member) {
            if ((int) $member['id'] === $memberId) return $member;
        }
        return null;
    }

    public function getUserAccount(int $userId): ?array {
        $stmt = $this->conn->prepare(
            "SELECT user_account.*, member.crn, member.first_name, member.middle_name, member.last_name,
                    member.status AS member_status, member.phone AS member_phone,
                    church.name AS church_name
               FROM users user_account
               JOIN members member ON member.id = user_account.member_id
               LEFT JOIN churches church ON church.id = member.church_id
              WHERE user_account.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function getManualAccessRoleIds(int $userId): array {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT user_role.role_id
               FROM user_roles user_role
               JOIN user_role_sources source ON source.user_role_id = user_role.id
              WHERE user_role.user_id = ?
                AND source.source_type IN ('legacy_manual', 'manual')"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ids = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'role_id'));
        $stmt->close();
        return $ids;
    }

    public function saveUserAccount(
        ?int $userId,
        int $memberId,
        string $officialEmail,
        string $password,
        string $status,
        array $manualRoleIds,
        ?int $actorUserId
    ): array {
        $officialEmail = strtolower(trim($officialEmail));
        if (!filter_var($officialEmail, FILTER_VALIDATE_EMAIL)
            || !preg_match('/@myfreeman\.org$/i', $officialEmail)) {
            throw new RuntimeException('Use an official @myfreeman.org email address.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) $status = 'inactive';
        $manualRoleIds = array_values(array_unique(array_filter(array_map('intval', $manualRoleIds))));

        $stmt = $this->conn->prepare(
            "SELECT member.id, member.church_id, member.phone, member.status,
                    TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS full_name,
                    member.first_name
               FROM members member WHERE member.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$member) throw new RuntimeException('Select an existing registered member.');
        if ($userId === null) {
            $stmt = $this->conn->prepare('SELECT id FROM users WHERE member_id = ? LIMIT 1');
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            $existingUserId = (int) ($stmt->get_result()->fetch_assoc()['id'] ?? 0);
            $stmt->close();
            if ($existingUserId > 0) {
                throw new RuntimeException('This member already has a back-office user account.');
            }
            if ($member['status'] !== 'active') {
                throw new RuntimeException('Only an active member can receive a new back-office account.');
            }
        }
        if ($member['status'] !== 'active' && $status === 'active') {
            throw new RuntimeException('An inactive or pending member cannot have an active back-office account.');
        }
        if (trim((string) $member['phone']) === '') throw new RuntimeException('The member must have a contact number.');
        if (trim((string) $member['full_name']) === '') throw new RuntimeException('The member must have a registered name.');
        if ($userId === null && strlen($password) < 8) {
            throw new RuntimeException('A new account password must contain at least eight characters.');
        }

        $mappedRoles = $this->getMappedAccessRolesForMember($memberId);
        if (!$mappedRoles && !$manualRoleIds) {
            throw new RuntimeException('This member has no mapped Role of Serving or manually selected system role.');
        }

        $this->conn->begin_transaction();
        try {
            if ($userId === null) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO users
                        (member_id, church_id, name, email, phone, password_hash,
                         must_change_password, password_changed_at, status)
                     VALUES (?, ?, ?, ?, ?, ?, 1, NULL, ?)"
                );
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmt->bind_param(
                    'iisssss', $memberId, $member['church_id'], $member['full_name'],
                    $officialEmail, $member['phone'], $passwordHash, $status
                );
                $stmt->execute();
                $userId = (int) $stmt->insert_id;
                $stmt->close();
                $created = true;
            } else {
                $existing = $this->getUserAccount($userId);
                if (!$existing || (int) $existing['member_id'] !== $memberId) {
                    throw new RuntimeException('A user account cannot be moved to another member.');
                }
                $sql = 'UPDATE users SET church_id = ?, name = ?, email = ?, phone = ?, status = ?';
                $params = [(int) $member['church_id'], $member['full_name'], $officialEmail, $member['phone'], $status];
                $types = 'issss';
                if ($password !== '') {
                    if (strlen($password) < 8) throw new RuntimeException('The new password must contain at least eight characters.');
                    $sql .= ', password_hash = ?, must_change_password = 1, password_changed_at = NULL';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                    $types .= 's';
                }
                $sql .= ' WHERE id = ?';
                $params[] = $userId;
                $types .= 'i';
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
                $created = false;
            }

            $this->replaceManualRoles($userId, $memberId, $manualRoleIds, $actorUserId);
            $sync = $this->syncMemberByUserId($userId, $actorUserId, 'User account saved.');
            $this->resolvePolicyIssues($userId, $officialEmail, $member['status'], $actorUserId);
            $this->conn->commit();
            return [
                'user_id' => $userId, 'created' => $created, 'first_name' => $member['first_name'],
                'full_name' => $member['full_name'], 'phone' => $member['phone'],
                'email' => $officialEmail, 'mapped_roles' => $mappedRoles, 'sync' => $sync,
            ];
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    private function syncServingRoleMembers(int $servingRoleId, ?int $actorUserId, string $reason): void {
        $stmt = $this->conn->prepare(
            'SELECT DISTINCT user_account.id
               FROM member_roles_of_serving member_role
               JOIN users user_account ON user_account.member_id = member_role.member_id
              WHERE member_role.role_id = ?'
        );
        $stmt->bind_param('i', $servingRoleId);
        $stmt->execute();
        $userIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        $stmt->close();
        foreach ($userIds as $userId) $this->syncMemberByUserId($userId, $actorUserId, $reason);
    }

    private function syncMemberByUserId(int $userId, ?int $actorUserId, string $reason): array {
        $stmt = $this->conn->prepare('SELECT member_id FROM users WHERE id = ? AND member_id IS NOT NULL LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $memberId = (int) ($stmt->get_result()->fetch_assoc()['member_id'] ?? 0);
        $stmt->close();
        if ($memberId < 1) return ['granted' => 0, 'revoked' => 0, 'retained' => 0];

        $stmt = $this->conn->prepare(
            "SELECT mapping.access_role_id, mapping.role_of_serving_id
               FROM member_roles_of_serving member_role
               JOIN role_of_serving_access_mappings mapping
                 ON mapping.role_of_serving_id = member_role.role_id AND mapping.is_active = 1
               JOIN roles access_role ON access_role.id = mapping.access_role_id AND access_role.is_active = 1
              WHERE member_role.member_id = ?"
        );
        $stmt->bind_param('i', $memberId);
        $stmt->execute();
        $desiredRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $desired = [];
        foreach ($desiredRows as $row) $desired[(int) $row['access_role_id']][(int) $row['role_of_serving_id']] = true;

        $stmt = $this->conn->prepare(
            "SELECT source.id AS source_id, source.user_role_id, source.role_of_serving_id,
                    user_role.role_id AS access_role_id
               FROM user_role_sources source
               JOIN user_roles user_role ON user_role.id = source.user_role_id
              WHERE user_role.user_id = ? AND source.source_type = 'role_of_serving'"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $existingSources = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $granted = $retained = $revoked = 0;
        foreach ($desired as $accessRoleId => $servingRoles) {
            $stmt = $this->conn->prepare(
                "INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at, is_primary, is_active)
                 VALUES (?, ?, ?, NOW(), 0, 1)
                 ON DUPLICATE KEY UPDATE is_active = 1, expires_at = NULL"
            );
            $stmt->bind_param('iii', $userId, $accessRoleId, $actorUserId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare('SELECT id FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1');
            $stmt->bind_param('ii', $userId, $accessRoleId);
            $stmt->execute();
            $userRoleId = (int) $stmt->get_result()->fetch_assoc()['id'];
            $stmt->close();

            foreach (array_keys($servingRoles) as $servingRoleId) {
                $stmt = $this->conn->prepare(
                    "INSERT IGNORE INTO user_role_sources
                        (user_role_id, source_type, role_of_serving_id, assigned_by_user_id)
                     VALUES (?, 'role_of_serving', ?, ?)"
                );
                $stmt->bind_param('iii', $userRoleId, $servingRoleId, $actorUserId);
                $stmt->execute();
                $action = $stmt->affected_rows === 1 ? 'granted' : 'retained';
                $action === 'granted' ? $granted++ : $retained++;
                $stmt->close();
                $this->writeAudit($userId, $memberId, $accessRoleId, $servingRoleId, $action, $reason, $actorUserId);
            }
        }

        foreach ($existingSources as $source) {
            $accessRoleId = (int) $source['access_role_id'];
            $servingRoleId = (int) $source['role_of_serving_id'];
            if (isset($desired[$accessRoleId][$servingRoleId])) continue;
            $sourceId = (int) $source['source_id'];
            $userRoleId = (int) $source['user_role_id'];
            $stmt = $this->conn->prepare('DELETE FROM user_role_sources WHERE id = ?');
            $stmt->bind_param('i', $sourceId);
            $stmt->execute();
            $stmt->close();
            $this->deleteUnsourcedUserRole($userRoleId);
            $revoked++;
            $this->writeAudit($userId, $memberId, $accessRoleId, $servingRoleId, 'revoked', $reason, $actorUserId);
        }
        return compact('granted', 'revoked', 'retained');
    }

    private function replaceManualRoles(int $userId, int $memberId, array $roleIds, ?int $actorUserId): void {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT user_role.id, user_role.role_id
               FROM user_roles user_role
               JOIN user_role_sources source ON source.user_role_id = user_role.id
              WHERE user_role.user_id = ?
                AND source.source_type IN ('legacy_manual', 'manual')"
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($existing as $row) {
            if (in_array((int) $row['role_id'], $roleIds, true)) continue;
            $userRoleId = (int) $row['id'];
            $stmt = $this->conn->prepare(
                "DELETE FROM user_role_sources
                  WHERE user_role_id = ? AND source_type IN ('legacy_manual', 'manual')"
            );
            $stmt->bind_param('i', $userRoleId);
            $stmt->execute();
            $stmt->close();
            $this->deleteUnsourcedUserRole($userRoleId);
        }
        foreach ($roleIds as $roleId) {
            $this->assertRoleExists('roles', $roleId, 'system access role');
            $stmt = $this->conn->prepare(
                "INSERT INTO user_roles (user_id, role_id, assigned_by, assigned_at, is_primary, is_active)
                 VALUES (?, ?, ?, NOW(), 0, 1)
                 ON DUPLICATE KEY UPDATE is_active = 1, expires_at = NULL"
            );
            $stmt->bind_param('iii', $userId, $roleId, $actorUserId);
            $stmt->execute();
            $stmt->close();
            $stmt = $this->conn->prepare('SELECT id FROM user_roles WHERE user_id = ? AND role_id = ? LIMIT 1');
            $stmt->bind_param('ii', $userId, $roleId);
            $stmt->execute();
            $userRoleId = (int) $stmt->get_result()->fetch_assoc()['id'];
            $stmt->close();
            $stmt = $this->conn->prepare(
                "INSERT INTO user_role_sources (user_role_id, source_type, role_of_serving_id, assigned_by_user_id)
                 SELECT ?, 'manual', NULL, ? FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM user_role_sources
                     WHERE user_role_id = ? AND source_type IN ('legacy_manual', 'manual')
                 )"
            );
            $stmt->bind_param('iii', $userRoleId, $actorUserId, $userRoleId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function deleteUnsourcedUserRole(int $userRoleId): void {
        $stmt = $this->conn->prepare(
            'DELETE user_role FROM user_roles user_role
              LEFT JOIN user_role_sources source ON source.user_role_id = user_role.id
             WHERE user_role.id = ? AND source.id IS NULL'
        );
        $stmt->bind_param('i', $userRoleId);
        $stmt->execute();
        $stmt->close();
    }

    private function resolvePolicyIssues(int $userId, string $email, string $memberStatus, ?int $actorUserId): void {
        $types = [];
        if (preg_match('/@myfreeman\.org$/i', $email)) {
            $types[] = 'missing_official_email';
            $types[] = 'non_official_email';
        }
        if ($memberStatus === 'active') $types[] = 'inactive_member';
        foreach ($types as $type) {
            $stmt = $this->conn->prepare(
                'UPDATE user_account_policy_review
                    SET resolved = 1, resolved_by_user_id = ?, resolved_at = NOW()
                  WHERE user_id = ? AND issue_type = ? AND resolved = 0'
            );
            $stmt->bind_param('iis', $actorUserId, $userId, $type);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function writeAudit(
        int $userId, int $memberId, int $accessRoleId, ?int $servingRoleId,
        string $action, string $reason, ?int $actorUserId
    ): void {
        $stmt = $this->conn->prepare(
            'INSERT INTO role_access_sync_audit
                (user_id, member_id, access_role_id, role_of_serving_id, action, reason, performed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiiissi', $userId, $memberId, $accessRoleId, $servingRoleId, $action, $reason, $actorUserId);
        $stmt->execute();
        $stmt->close();
    }

    private function assertRoleExists(string $table, int $id, string $label): void {
        if (!in_array($table, ['roles', 'roles_of_serving'], true) || $id < 1) {
            throw new RuntimeException('Choose a valid ' . $label . '.');
        }
        $stmt = $this->conn->prepare("SELECT id FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) throw new RuntimeException('Choose a valid ' . $label . '.');
    }
}
