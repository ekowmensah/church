<?php

/**
 * Normalize the shared activity/audit-log filters.
 */
function audit_log_view_filters(array $input): array
{
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';

    return [
        'user' => trim((string) ($input['user'] ?? '')),
        'action' => trim((string) ($input['action'] ?? '')),
        'date_from' => preg_match($datePattern, (string) ($input['date_from'] ?? ''))
            ? (string) $input['date_from']
            : '',
        'date_to' => preg_match($datePattern, (string) ($input['date_to'] ?? ''))
            ? (string) $input['date_to']
            : '',
    ];
}

/**
 * Return audit rows with a real actor label. audit_log stores user_id, not a
 * denormalized username, so every viewer and export must resolve users here.
 * Ordinary authorized users are restricted to actors in their own church.
 */
function audit_log_view_rows(
    mysqli $conn,
    array $filters,
    bool $globalScope,
    ?int $churchId
): array {
    $where = [];
    $params = [];
    $types = '';

    if (!$globalScope) {
        if (($churchId ?? 0) < 1) {
            return [];
        }
        $where[] = 'user_account.church_id = ?';
        $params[] = $churchId;
        $types .= 'i';
    }

    if ($filters['user'] !== '') {
        $where[] = '(user_account.name LIKE ? OR user_account.email LIKE ?)';
        $term = '%' . $filters['user'] . '%';
        $params[] = $term;
        $params[] = $term;
        $types .= 'ss';
    }
    if ($filters['action'] !== '') {
        $where[] = 'audit.action LIKE ?';
        $params[] = '%' . $filters['action'] . '%';
        $types .= 's';
    }
    if ($filters['date_from'] !== '') {
        $where[] = 'audit.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['date_to'] !== '') {
        $where[] = 'audit.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
        $types .= 's';
    }

    $sql = "SELECT audit.id, audit.user_id, audit.action, audit.entity_type,
                   audit.entity_id, audit.details, audit.ip_address, audit.created_at,
                   COALESCE(NULLIF(user_account.name, ''), NULLIF(user_account.email, ''),
                            IF(audit.user_id IS NULL, 'System', CONCAT('User #', audit.user_id)))
                       AS actor_name,
                   user_account.email AS actor_email
            FROM audit_log audit
            LEFT JOIN users user_account ON user_account.id = audit.user_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY audit.created_at DESC, audit.id DESC';

    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function audit_log_view_church_id(mysqli $conn): ?int
{
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId > 0) {
        return $churchId;
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId < 1) {
        return null;
    }
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return isset($row['church_id']) ? (int) $row['church_id'] : null;
}

