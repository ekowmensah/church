<?php

/**
 * Resolve privileged identity from current database state. Session role IDs
 * are intentionally never accepted as authorization evidence.
 */
function rbac_identity_is_super_admin(mysqli $conn, ?int $userId): bool
{
    $userId = (int) ($userId ?? 0);
    if ($userId < 1) return false;

    $stmt = $conn->prepare(
        "SELECT 1
           FROM users account
           JOIN user_roles assignment ON assignment.user_id = account.id
           JOIN roles role ON role.id = assignment.role_id
          WHERE account.id = ? AND account.status = 'active'
            AND assignment.is_active = 1 AND role.is_active = 1
            AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
            AND LOWER(TRIM(role.name)) = 'super admin'
          LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $allowed;
}

/**
 * Match active, unexpired database role assignments by normalized role name.
 * Use permissions for capabilities; this helper exists for legacy workflow
 * routing where role identity itself is the business rule.
 */
function rbac_identity_has_any_role(mysqli $conn, ?int $userId, array $roleNames): bool
{
    $userId = (int) ($userId ?? 0);
    $names = array_values(array_unique(array_filter(array_map(
        static fn($name): string => strtolower(trim((string) $name)),
        $roleNames
    ))));
    if ($userId < 1 || !$names) return false;

    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $types = 'i' . str_repeat('s', count($names));
    $params = array_merge([$userId], $names);
    $stmt = $conn->prepare(
        "SELECT 1
           FROM users account
           JOIN user_roles assignment ON assignment.user_id = account.id
           JOIN roles role ON role.id = assignment.role_id
          WHERE account.id = ? AND account.status = 'active'
            AND assignment.is_active = 1 AND role.is_active = 1
            AND (assignment.expires_at IS NULL OR assignment.expires_at > NOW())
            AND LOWER(TRIM(role.name)) IN ({$placeholders})
          LIMIT 1"
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    return $allowed;
}
