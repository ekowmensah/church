<?php

require_once __DIR__ . '/rbac/RBACServiceFactory.php';
require_once __DIR__ . '/../helpers/rbac_identity.php';
require_once __DIR__ . '/../helpers/bible_class_capacity.php';

final class UserAccessAuthorizationException extends RuntimeException {}

/**
 * Governs back-office account lifecycle actions independently of page UI.
 * Every mutation is capability checked, church scoped and audit preserved.
 */
final class UserAccessGovernanceService {
    private mysqli $conn;

    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        RBACServiceFactory::setConnection($conn);
    }

    public function assertAuthorized(string $permission, int $actorUserId, int $churchId): void {
        if ($actorUserId < 1 || $churchId < 1) {
            throw new UserAccessAuthorizationException('A valid actor and church context are required.');
        }
        $decision = RBACServiceFactory::getPermissionChecker()->authorize(
            $permission,
            $actorUserId,
            ['church_id' => $churchId],
            true
        );
        if (empty($decision['allowed'])) {
            throw new UserAccessAuthorizationException('You are not authorized to manage user access for this church.');
        }
        if (rbac_identity_is_super_admin($this->conn, $actorUserId)) return;

        $stmt = $this->conn->prepare(
            "SELECT church_id FROM users WHERE id = ? AND status = 'active' LIMIT 1"
        );
        $stmt->bind_param('i', $actorUserId);
        $stmt->execute();
        $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $stmt->close();
        if ($actorChurchId !== $churchId) {
            throw new UserAccessAuthorizationException('You cannot manage a user account outside your church.');
        }
    }

    public function getAccount(int $userId, bool $forUpdate = false): ?array {
        if ($userId < 1) return null;
        $sql = "SELECT account.id, account.member_id, account.church_id, account.name,
                       account.email, account.phone, account.status,
                       member.status AS member_status,
                       member.is_archived AS member_is_archived,
                       member.class_id AS member_class_id,
                       member.crn AS member_crn,
                       member.first_name AS member_first_name,
                       member.middle_name AS member_middle_name,
                       member.last_name AS member_last_name,
                       member.church_id AS member_church_id
                  FROM users account
                  LEFT JOIN members member ON member.id = account.member_id
                 WHERE account.id = ? LIMIT 1";
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    public function changeStatus(
        int $targetUserId,
        string $newStatus,
        int $actorUserId,
        string $permission,
        string $reason,
        string $action,
        bool $activateLinkedMembership = false
    ): void {
        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Choose a valid account status.');
        }
        if ($targetUserId === $actorUserId && $newStatus !== 'active') {
            throw new RuntimeException('You cannot deactivate your current account.');
        }

        $this->conn->begin_transaction();
        try {
            $before = $this->getAccount($targetUserId, true);
            if (!$before) throw new RuntimeException('User account not found.');
            $churchId = (int) ($before['church_id'] ?? 0);
            $this->assertAuthorized($permission, $actorUserId, $churchId);

            $coordinatingMembership = $newStatus === 'active'
                && $activateLinkedMembership
                && ($before['member_status'] ?? '') !== 'active';
            if (($before['status'] ?? '') === $newStatus && !$coordinatingMembership) {
                throw new RuntimeException('The user account is already ' . $newStatus . '.');
            }

            if ($newStatus === 'inactive' && $this->isActiveSuperAdministrator($targetUserId)) {
                throw new RuntimeException('Super Administrator accounts cannot be deactivated here.');
            }
            if ($newStatus === 'active') {
                if (!$this->hasEffectiveRoleAssignment($targetUserId)) {
                    throw new RuntimeException('Assign mapped or manual access before activating this account.');
                }
                if (($before['member_status'] ?? '') !== 'active') {
                    if (!$activateLinkedMembership) {
                        throw new RuntimeException(
                            'The linked membership is not active. Use “Activate membership & access” or activate the membership first.'
                        );
                    }
                    $this->activateLinkedMembership($before, $actorUserId);
                }
            }

            $stmt = $this->conn->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $newStatus, $targetUserId);
            $stmt->execute();
            $stmt->close();

            if ($action === 'retired') {
                $stmt = $this->conn->prepare(
                    'UPDATE user_roles SET is_active = 0 WHERE user_id = ? AND is_active = 1'
                );
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();
                $stmt->close();

                $stmt = $this->conn->prepare(
                    'UPDATE user_permissions
                        SET is_active = 0, revoked_by_user_id = ?, revoked_at = NOW()
                      WHERE user_id = ? AND is_active = 1'
                );
                $stmt->bind_param('ii', $actorUserId, $targetUserId);
                $stmt->execute();
                $stmt->close();
            }

            $after = $before;
            $after['status'] = $newStatus;
            if ($newStatus === 'active' && $activateLinkedMembership) {
                $after['member_status'] = 'active';
                $after['member_is_archived'] = 0;
            }
            $recordedAction = (($before['status'] ?? '') === $newStatus && $coordinatingMembership)
                ? 'updated'
                : $action;
            $this->recordChange(
                $targetUserId,
                isset($before['member_id']) ? (int) $before['member_id'] : null,
                $churchId,
                $recordedAction,
                $before,
                $after,
                $reason,
                $actorUserId
            );
            $this->conn->commit();
        } catch (Throwable $exception) {
            $this->conn->rollback();
            throw $exception;
        }
    }

    public function recordChange(
        ?int $targetUserId,
        ?int $targetMemberId,
        int $churchId,
        string $action,
        ?array $before,
        ?array $after,
        string $reason,
        ?int $actorUserId
    ): void {
        $allowedActions = ['created', 'updated', 'activated', 'deactivated', 'retired'];
        if (!in_array($action, $allowedActions, true)) {
            throw new InvalidArgumentException('Unsupported user-access audit action.');
        }
        $reason = trim($reason);
        if ($reason === '') throw new InvalidArgumentException('An audit reason is required.');
        $beforeJson = $before === null ? null : json_encode($this->safeSnapshot($before), JSON_UNESCAPED_SLASHES);
        $afterJson = $after === null ? null : json_encode($this->safeSnapshot($after), JSON_UNESCAPED_SLASHES);
        $stmt = $this->conn->prepare(
            'INSERT INTO user_access_change_audit
                (target_user_id, target_member_id, church_id, action,
                 previous_snapshot, new_snapshot, reason, changed_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'iiissssi',
            $targetUserId,
            $targetMemberId,
            $churchId,
            $action,
            $beforeJson,
            $afterJson,
            $reason,
            $actorUserId
        );
        $stmt->execute();
        $stmt->close();
    }

    private function safeSnapshot(array $account): array {
        $safe = [];
        foreach (['id', 'member_id', 'church_id', 'name', 'email', 'phone', 'status',
                  'member_status', 'member_is_archived', 'manual_role_ids'] as $field) {
            if (array_key_exists($field, $account)) $safe[$field] = $account[$field];
        }
        return $safe;
    }

    private function activateLinkedMembership(array $account, int $actorUserId): void {
        $memberId = (int) ($account['member_id'] ?? 0);
        $churchId = (int) ($account['church_id'] ?? 0);
        if ($memberId < 1) {
            throw new RuntimeException('This account has no linked membership to activate.');
        }
        if ((int) ($account['member_church_id'] ?? 0) !== $churchId) {
            throw new RuntimeException('The linked membership does not belong to the account church.');
        }
        if ((int) ($account['member_is_archived'] ?? 0) === 1) {
            throw new RuntimeException('Restore the archived membership before activating back-office access.');
        }
        $memberStatus = (string) ($account['member_status'] ?? '');
        if (!in_array($memberStatus, ['pending', 'de-activated'], true)) {
            throw new RuntimeException('The linked membership is not eligible for activation.');
        }

        // Coordinated activation is deliberately dual-authorized: user-access
        // authority alone cannot change the official membership lifecycle.
        $this->assertAuthorized('activate_member', $actorUserId, $churchId);
        $capacity = bible_class_validate_capacity(
            $this->conn,
            (int) ($account['member_class_id'] ?? 0),
            $memberId
        );
        if (empty($capacity['allowed'])) {
            throw new RuntimeException('Membership activation blocked: ' . bible_class_capacity_error_message());
        }

        $stmt = $this->conn->prepare(
            "UPDATE members
                SET status = 'active', deactivated_at = NULL,
                    deactivation_reason = NULL, deactivation_reason_code = NULL,
                    deactivated_by_user_id = NULL
              WHERE id = ? AND church_id = ? AND is_archived = 0
                AND status IN ('pending','de-activated')"
        );
        $stmt->bind_param('ii', $memberId, $churchId);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('The linked membership changed before activation. Refresh and try again.');
        }
        $stmt->close();

        $memberName = trim(implode(' ', array_filter([
            $account['member_first_name'] ?? '',
            $account['member_middle_name'] ?? '',
            $account['member_last_name'] ?? '',
        ], static fn($part) => trim((string) $part) !== '')));
        if ($memberName === '') $memberName = 'Member #' . $memberId;
        $memberCrn = (string) ($account['member_crn'] ?? '');
        $auditReason = 'Membership activated as an explicit prerequisite to governed back-office access activation.';
        $stmt = $this->conn->prepare(
            "INSERT INTO member_lifecycle_audit
                (member_id, original_member_id, church_id, member_crn, member_name,
                 action, reason, performed_by_user_id)
             VALUES (?, ?, ?, ?, ?, 'activated', ?, ?)"
        );
        $stmt->bind_param(
            'iiisssi',
            $memberId,
            $memberId,
            $churchId,
            $memberCrn,
            $memberName,
            $auditReason,
            $actorUserId
        );
        $stmt->execute();
        $stmt->close();

        $stmt = $this->conn->prepare(
            "UPDATE user_account_policy_review
                SET resolved = 1, resolved_by_user_id = ?, resolved_at = NOW()
              WHERE user_id = ? AND issue_type = 'inactive_member' AND resolved = 0"
        );
        $accountId = (int) $account['id'];
        $stmt->bind_param('ii', $actorUserId, $accountId);
        $stmt->execute();
        $stmt->close();
    }

    private function isActiveSuperAdministrator(int $userId): bool {
        return rbac_identity_is_super_admin($this->conn, $userId);
    }

    private function hasEffectiveRoleAssignment(int $userId): bool {
        $stmt = $this->conn->prepare(
            'SELECT 1
               FROM user_roles assignment
               JOIN roles role ON role.id = assignment.role_id
              WHERE assignment.user_id = ?
                AND assignment.is_active = 1 AND role.is_active = 1
                AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
              LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }
}
