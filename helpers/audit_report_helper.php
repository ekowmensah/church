<?php

function audit_report_date(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function audit_report_context(mysqli $conn, array $input, bool $isSuperAdmin): array
{
    $filters = [
        'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
        'church_id' => max(0, (int) ($input['church_id'] ?? 0)),
        'action' => substr(trim((string) ($input['action'] ?? '')), 0, 32),
        'entity_type' => substr(trim((string) ($input['entity_type'] ?? '')), 0, 32),
        'from_date' => audit_report_date((string) ($input['from_date'] ?? '')),
        'to_date' => audit_report_date((string) ($input['to_date'] ?? '')),
        'search' => substr(trim((string) ($input['search'] ?? '')), 0, 100),
    ];

    if ($filters['from_date'] !== '' && $filters['to_date'] !== ''
        && $filters['from_date'] > $filters['to_date']) {
        [$filters['from_date'], $filters['to_date']] = [$filters['to_date'], $filters['from_date']];
    }

    $where = ['1=1'];
    $params = [];
    $types = '';
    $actorChurchId = (int) ($_SESSION['church_id'] ?? 0);

    if (!$isSuperAdmin) {
        if ($actorChurchId > 0) {
            $where[] = 'u.church_id = ?';
            $params[] = $actorChurchId;
            $types .= 'i';
        } else {
            $where[] = '1=0';
        }
        $filters['church_id'] = $actorChurchId;
    } elseif ($filters['church_id'] > 0) {
        $where[] = 'u.church_id = ?';
        $params[] = $filters['church_id'];
        $types .= 'i';
    }

    if ($filters['user_id'] > 0) {
        $where[] = 'a.user_id = ?';
        $params[] = $filters['user_id'];
        $types .= 'i';
    }
    if ($filters['action'] !== '') {
        $where[] = 'a.action = ?';
        $params[] = $filters['action'];
        $types .= 's';
    }
    if ($filters['entity_type'] !== '') {
        $where[] = 'a.entity_type = ?';
        $params[] = $filters['entity_type'];
        $types .= 's';
    }
    if ($filters['from_date'] !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = $filters['from_date'] . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['to_date'] !== '') {
        $nextDay = (new DateTimeImmutable($filters['to_date']))->modify('+1 day')->format('Y-m-d');
        $where[] = 'a.created_at < ?';
        $params[] = $nextDay . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['search'] !== '') {
        $needle = '%' . $filters['search'] . '%';
        $where[] = '(u.name LIKE ? OR a.action LIKE ? OR a.entity_type LIKE ? OR a.details LIKE ? OR a.ip_address LIKE ?)';
        for ($index = 0; $index < 5; $index++) {
            $params[] = $needle;
            $types .= 's';
        }
    }

    return [
        'filters' => $filters,
        'where_sql' => 'WHERE ' . implode(' AND ', $where),
        'params' => $params,
        'types' => $types,
        'actor_church_id' => $actorChurchId,
    ];
}

function audit_report_bind(mysqli_stmt $statement, string $types, array $params): void
{
    if ($types !== '' && $params !== []) {
        $statement->bind_param($types, ...$params);
    }
}

function audit_report_query(array $filters, array $overrides = []): string
{
    $query = [];
    foreach (array_merge($filters, $overrides) as $key => $value) {
        if ($value === '' || $value === 0 || $value === null) {
            continue;
        }
        $query[$key] = $value;
    }
    return http_build_query($query);
}

function audit_report_details_text(?string $details): string
{
    $details = trim((string) $details);
    if ($details === '') {
        return 'No additional details were recorded.';
    }
    $decoded = json_decode($details, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return $details;
}

function audit_report_entity_label(string $entityType, $entityId): string
{
    $label = trim(str_replace('_', ' ', $entityType));
    $label = $label !== '' ? ucwords($label) : 'System';
    return $entityId !== null && $entityId !== '' ? $label . ' #' . (int) $entityId : $label;
}
