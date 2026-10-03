<?php

function feedback_report_context(array $input, bool $isSuperAdmin): array
{
    $validDate = static function ($value): string {
        $value = trim((string) $value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };
    $filters = [
        'church_id' => max(0, (int) ($input['church_id'] ?? 0)),
        'from_date' => $validDate($input['from_date'] ?? ''),
        'to_date' => $validDate($input['to_date'] ?? ''),
        'search' => substr(trim((string) ($input['search'] ?? '')), 0, 100),
    ];
    if ($filters['from_date'] !== '' && $filters['to_date'] !== '' && $filters['from_date'] > $filters['to_date']) {
        [$filters['from_date'], $filters['to_date']] = [$filters['to_date'], $filters['from_date']];
    }

    $where = ['1=1'];
    $params = [];
    $types = '';
    $actorChurchId = (int) ($_SESSION['church_id'] ?? 0);
    if (!$isSuperAdmin) {
        $filters['church_id'] = $actorChurchId;
        if ($actorChurchId > 0) {
            $where[] = 'm.church_id = ?';
            $params[] = $actorChurchId;
            $types .= 'i';
        } else {
            $where[] = '1=0';
        }
    } elseif ($filters['church_id'] > 0) {
        $where[] = 'm.church_id = ?';
        $params[] = $filters['church_id'];
        $types .= 'i';
    }
    if ($filters['from_date'] !== '') {
        $where[] = 'f.submitted_at >= ?';
        $params[] = $filters['from_date'] . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['to_date'] !== '') {
        $where[] = 'f.submitted_at < ?';
        $params[] = (new DateTimeImmutable($filters['to_date']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        $types .= 's';
    }
    if ($filters['search'] !== '') {
        $needle = '%' . $filters['search'] . '%';
        $where[] = '(f.message LIKE ? OR m.crn LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ?)';
        for ($index = 0; $index < 4; $index++) {
            $params[] = $needle;
            $types .= 's';
        }
    }
    return ['filters' => $filters, 'where_sql' => 'WHERE ' . implode(' AND ', $where), 'params' => $params, 'types' => $types];
}

function feedback_report_bind(mysqli_stmt $statement, string $types, array $params): void
{
    if ($types !== '' && $params !== []) {
        $statement->bind_param($types, ...$params);
    }
}

function feedback_report_query(array $filters, array $extra = []): string
{
    return http_build_query(array_filter(array_merge($filters, $extra), static fn($value) => $value !== '' && $value !== 0 && $value !== null));
}
