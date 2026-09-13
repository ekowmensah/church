<?php

final class UnifiedAttendanceReportService {
    private mysqli $conn;
    private ?int $userId;
    private ?int $memberId;
    private array $roleIds;
    private ?array $leaderScopes = null;

    public function __construct(mysqli $conn, ?int $userId, ?int $memberId, array $roleIds = []) {
        $this->conn = $conn;
        $this->userId = $userId && $userId > 0 ? $userId : null;
        $this->memberId = $memberId && $memberId > 0 ? $memberId : null;
        $this->roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds))));

        if ($this->userId !== null) {
            if ($this->memberId === null) {
                $stmt = $this->conn->prepare('SELECT member_id FROM users WHERE id = ? LIMIT 1');
                $stmt->bind_param('i', $this->userId);
                $stmt->execute();
                $this->memberId = (int) ($stmt->get_result()->fetch_assoc()['member_id'] ?? 0) ?: null;
                $stmt->close();
            }
            $stmt = $this->conn->prepare('SELECT role_id FROM user_roles WHERE user_id = ?');
            $stmt->bind_param('i', $this->userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $this->roleIds[] = (int) $row['role_id'];
            }
            $stmt->close();
            $this->roleIds = array_values(array_unique($this->roleIds));
        }
    }

    public static function fromSession(mysqli $conn): self {
        $roles = (array) ($_SESSION['role_ids'] ?? []);
        if (isset($_SESSION['role_id'])) {
            $roles[] = (int) $_SESSION['role_id'];
        }
        return new self(
            $conn,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null,
            $roles
        );
    }

    public function resolvePeriod(string $preset, ?string $fromDate = null, ?string $toDate = null): array {
        $today = new DateTimeImmutable('today');
        switch ($preset) {
            case 'today':
                $start = $end = $today;
                break;
            case 'yesterday':
                $start = $end = $today->modify('-1 day');
                break;
            case 'this_week':
                $start = $today->modify('monday this week');
                $end = $start->modify('+6 days');
                break;
            case 'last_week':
                $start = $today->modify('monday last week');
                $end = $start->modify('+6 days');
                break;
            case 'this_month':
                $start = $today->modify('first day of this month');
                $end = $today->modify('last day of this month');
                break;
            case 'last_month':
                $start = $today->modify('first day of last month');
                $end = $today->modify('last day of last month');
                break;
            case 'q1':
            case 'q2':
            case 'q3':
            case 'q4':
                $quarter = (int) substr($preset, 1, 1);
                $startMonth = (($quarter - 1) * 3) + 1;
                $start = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $today->format('Y'), $startMonth));
                $end = $start->modify('+2 months')->modify('last day of this month');
                break;
            case 'this_year':
                $start = new DateTimeImmutable($today->format('Y') . '-01-01');
                $end = new DateTimeImmutable($today->format('Y') . '-12-31');
                break;
            case 'last_year':
                $year = (int) $today->format('Y') - 1;
                $start = new DateTimeImmutable($year . '-01-01');
                $end = new DateTimeImmutable($year . '-12-31');
                break;
            case 'custom':
                $start = $this->parseDate($fromDate);
                $end = $this->parseDate($toDate);
                break;
            default:
                throw new InvalidArgumentException('Choose a valid reporting period.');
        }
        if ($start > $end) {
            throw new InvalidArgumentException('The From date cannot be after the To date.');
        }
        if ($start->diff($end)->days > 1826) {
            throw new InvalidArgumentException('Attendance reports are limited to five years per request.');
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    public function getAllowedChurches(): array {
        if ($this->isSuperAdministrator()) {
            $result = $this->conn->query('SELECT id, name FROM churches ORDER BY name');
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        $churchIds = [];
        if (($this->hasBroadChurchAccess() || $this->hasSundaySchoolAccess()) && $this->memberId !== null) {
            $stmt = $this->conn->prepare('SELECT church_id FROM members WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $this->memberId);
            $stmt->execute();
            $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
            $stmt->close();
            if ($churchId > 0) $churchIds[$churchId] = true;
        } else {
            $scopes = $this->getLeaderScopes();
            if ($scopes['class_ids']) {
                $result = $this->conn->query('SELECT DISTINCT church_id FROM bible_classes WHERE id IN (' . implode(',', $scopes['class_ids']) . ')');
                while ($result && ($row = $result->fetch_assoc())) $churchIds[(int) $row['church_id']] = true;
            }
            if ($scopes['organization_ids']) {
                $result = $this->conn->query('SELECT DISTINCT church_id FROM organizations WHERE id IN (' . implode(',', $scopes['organization_ids']) . ')');
                while ($result && ($row = $result->fetch_assoc())) $churchIds[(int) $row['church_id']] = true;
            }
            if ($scopes['unit_ids']) {
                $result = $this->conn->query(
                    'SELECT DISTINCT organization.church_id
                       FROM organization_units unit
                       JOIN organizations organization ON organization.id = unit.organization_id
                      WHERE unit.id IN (' . implode(',', $scopes['unit_ids']) . ')'
                );
                while ($result && ($row = $result->fetch_assoc())) $churchIds[(int) $row['church_id']] = true;
            }
        }
        if (!$churchIds) return [];
        $result = $this->conn->query('SELECT id, name FROM churches WHERE id IN (' . implode(',', array_keys($churchIds)) . ') ORDER BY name');
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getCategories(): array {
        $result = $this->conn->query(
            "SELECT category.id, category.code, category.name, category.parent_id,
                    parent.name AS parent_name, category.aggregation_method
               FROM attendance_report_categories category
               LEFT JOIN attendance_report_categories parent ON parent.id = category.parent_id
              WHERE category.is_active = 1
              ORDER BY COALESCE(parent.sort_order, category.sort_order),
                       category.parent_id IS NOT NULL, category.sort_order"
        );
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function canViewChurchStatistics(): bool {
        return $this->hasBroadChurchAccess();
    }

    public function buildChurchStatistics(
        int $churchId,
        string $fromDate,
        string $toDate,
        ?string $eventType = null
    ): array {
        if (!$this->canViewChurchStatistics()) {
            throw new RuntimeException('Your reporting role is limited to assigned attendance scopes.');
        }
        $this->assertChurchAllowed($churchId);
        $start = $this->parseDate($fromDate);
        $end = $this->parseDate($toDate);
        if ($start > $end) throw new InvalidArgumentException('The From date cannot be after the To date.');

        $labels = [
            'new_member' => 'New Members',
            'visitor' => 'Visitors',
            'naming' => 'Naming',
            'baptism' => 'Baptism',
            'confirmation' => 'Confirmation',
            'death' => 'Death',
            'transferred' => 'Transferred',
        ];
        if ($eventType !== null && !isset($labels[$eventType])) {
            throw new InvalidArgumentException('Choose a valid statistical event type.');
        }

        $sql = "SELECT event_type,
                       SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) AS male,
                       SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) AS female,
                       SUM(CASE WHEN gender NOT IN ('Male', 'Female') OR gender IS NULL THEN 1 ELSE 0 END) AS unspecified,
                       COUNT(*) AS total
                  FROM v_church_statistical_events
                 WHERE church_id = ? AND event_date BETWEEN ? AND ?";
        $params = [$churchId, $fromDate, $toDate];
        $types = 'iss';
        if ($eventType !== null) {
            $sql .= ' AND event_type = ?';
            $params[] = $eventType;
            $types .= 's';
        }
        $sql .= " GROUP BY event_type
                  ORDER BY FIELD(event_type, 'new_member', 'visitor', 'naming',
                                 'baptism', 'confirmation', 'death', 'transferred')";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $totals = ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0];
        foreach ($rows as &$row) {
            $row['label'] = $labels[$row['event_type']] ?? ucwords(str_replace('_', ' ', $row['event_type']));
            foreach (array_keys($totals) as $field) {
                $row[$field] = (int) $row[$field];
                $totals[$field] += $row[$field];
            }
        }
        unset($row);
        return ['rows' => $rows, 'totals' => $totals, 'from_date' => $fromDate, 'to_date' => $toDate];
    }

    public function buildReport(
        int $churchId,
        string $fromDate,
        string $toDate,
        string $status = 'present',
        ?int $categoryId = null
    ): array {
        $this->assertChurchAllowed($churchId);
        $start = $this->parseDate($fromDate);
        $end = $this->parseDate($toDate);
        if ($start > $end) throw new InvalidArgumentException('The From date cannot be after the To date.');

        $allowedStatuses = ['present', 'absent', 'sick', 'permission', 'distance', 'invalid', 'all'];
        if (!in_array($status, $allowedStatuses, true)) $status = 'present';

        $where = [
            "session.service_date BETWEEN ? AND ?",
            "session.service_date <> '0000-00-00'",
            'session.church_id = ?',
            'record.is_draft = 0',
            "session.approval_status = 'approved'",
        ];
        $params = [$fromDate, $toDate, $churchId];
        $types = 'ssi';
        if ($status !== 'all') {
            $where[] = 'record.status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($categoryId !== null && $categoryId > 0) {
            $where[] = '(category.id = ? OR category.parent_id = ?)';
            $params[] = $categoryId;
            $params[] = $categoryId;
            $types .= 'ii';
        }
        $scopeCondition = $this->scopeCondition('session');
        if ($scopeCondition !== '') $where[] = $scopeCondition;

        $sql = "SELECT session.id AS session_id, session.title, session.service_date,
                       session.attendance_scope, session.scope_id, session.organization_unit_id,
                       category.id AS category_id, category.code AS category_code,
                       category.name AS category_name, category.aggregation_method,
                       parent.id AS parent_category_id, parent.code AS parent_code,
                       parent.name AS parent_name, parent.sort_order AS parent_sort_order,
                       category.sort_order AS category_sort_order,
                       bible_class.name AS bible_class_name,
                       organization.name AS organization_name,
                       unit.name AS organization_unit_name,
                       SUM(CASE WHEN LOWER(TRIM(member.gender)) = 'male' THEN 1 ELSE 0 END) AS male_count,
                       SUM(CASE WHEN LOWER(TRIM(member.gender)) = 'female' THEN 1 ELSE 0 END) AS female_count,
                       SUM(CASE WHEN member.gender IS NULL OR LOWER(TRIM(member.gender)) NOT IN ('male','female') THEN 1 ELSE 0 END) AS unspecified_count,
                       COUNT(*) AS total_count
                  FROM attendance_sessions session
                  JOIN attendance_records record ON record.session_id = session.id
                  JOIN members member ON member.id = record.member_id
                  JOIN attendance_report_categories category ON category.id = session.attendance_report_category_id
                  LEFT JOIN attendance_report_categories parent ON parent.id = category.parent_id
                  LEFT JOIN bible_classes bible_class
                    ON session.attendance_scope = 'bible_class' AND bible_class.id = session.scope_id
                  LEFT JOIN organizations organization
                    ON session.attendance_scope = 'organization' AND organization.id = session.scope_id
                  LEFT JOIN organization_units unit ON unit.id = session.organization_unit_id
                 WHERE " . implode(' AND ', $where) . "
                 GROUP BY session.id, session.title, session.service_date, session.attendance_scope,
                          session.scope_id, session.organization_unit_id, category.id, category.code,
                          category.name, category.aggregation_method, parent.id, parent.code,
                          parent.name, parent.sort_order, category.sort_order,
                          bible_class.name, organization.name, unit.name
                 ORDER BY session.service_date, session.id";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $sessionRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $breakdown = [];
        $weeklyBuckets = [];
        foreach ($sessionRows as $row) {
            $mainId = (int) ($row['parent_category_id'] ?: $row['category_id']);
            $mainCode = (string) ($row['parent_code'] ?: $row['category_code']);
            $mainName = (string) ($row['parent_name'] ?: $row['category_name']);
            $breakdownName = $this->breakdownName($row);
            $key = $mainId . '|' . $breakdownName;
            $counts = [
                'male' => (float) $row['male_count'],
                'female' => (float) $row['female_count'],
                'unspecified' => (float) $row['unspecified_count'],
                'total' => (float) $row['total_count'],
                'sessions' => 1,
            ];
            $meta = [
                'main_id' => $mainId,
                'main_code' => $mainCode,
                'main_name' => $mainName,
                'main_sort' => (int) ($row['parent_sort_order'] ?: $row['category_sort_order']),
                'breakdown_name' => $breakdownName,
                'aggregation_method' => (string) $row['aggregation_method'],
            ];

            if ($row['aggregation_method'] === 'weekly_average') {
                $weekStart = (new DateTimeImmutable($row['service_date']))->modify('monday this week')->format('Y-m-d');
                $bucketKey = $key . '|' . $weekStart;
                if (!isset($weeklyBuckets[$bucketKey])) {
                    $weeklyBuckets[$bucketKey] = $meta + $counts + ['dates' => []];
                } else {
                    foreach (['male', 'female', 'unspecified', 'total', 'sessions'] as $field) {
                        $weeklyBuckets[$bucketKey][$field] += $counts[$field];
                    }
                }
                $weeklyBuckets[$bucketKey]['dates'][$row['service_date']] = true;
                continue;
            }
            $this->accumulate($breakdown, $key, $meta, $counts);
        }

        foreach ($weeklyBuckets as $bucket) {
            $divisor = max(1, count($bucket['dates']));
            $counts = ['sessions' => (int) $bucket['sessions']];
            foreach (['male', 'female', 'unspecified', 'total'] as $field) {
                $counts[$field] = round($bucket[$field] / $divisor, 2);
            }
            unset($bucket['dates'], $bucket['male'], $bucket['female'], $bucket['unspecified'], $bucket['total'], $bucket['sessions']);
            $key = $bucket['main_id'] . '|' . $bucket['breakdown_name'];
            $this->accumulate($breakdown, $key, $bucket, $counts);
        }

        uasort($breakdown, static function (array $a, array $b): int {
            return [$a['main_sort'], $a['breakdown_name']] <=> [$b['main_sort'], $b['breakdown_name']];
        });
        $summary = [];
        foreach ($breakdown as $row) {
            $key = (string) $row['main_id'];
            $meta = [
                'main_id' => $row['main_id'], 'main_code' => $row['main_code'],
                'main_name' => $row['main_name'], 'main_sort' => $row['main_sort'],
            ];
            $counts = array_intersect_key($row, array_flip(['male', 'female', 'unspecified', 'total', 'sessions']));
            $this->accumulate($summary, $key, $meta, $counts);
        }
        uasort($summary, static fn(array $a, array $b): int => $a['main_sort'] <=> $b['main_sort']);

        $totals = ['male' => 0.0, 'female' => 0.0, 'unspecified' => 0.0, 'total' => 0.0];
        foreach ($summary as $row) {
            foreach (array_keys($totals) as $field) $totals[$field] += (float) $row[$field];
        }
        return [
            'from_date' => $fromDate, 'to_date' => $toDate, 'status' => $status,
            'summary' => array_values($summary), 'breakdown' => array_values($breakdown),
            'sessions' => $sessionRows, 'totals' => $totals,
        ];
    }

    private function parseDate(?string $date): DateTimeImmutable {
        $value = trim((string) $date);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$parsed || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Enter valid From and To dates.');
        }
        return $parsed;
    }

    private function isSuperAdministrator(): bool {
        return in_array(1, $this->roleIds, true);
    }

    private function hasBroadChurchAccess(): bool {
        return (bool) array_intersect([1, 2, 3, 4, 11], $this->roleIds);
    }

    private function hasSundaySchoolAccess(): bool {
        return in_array(10, $this->roleIds, true);
    }

    private function assertChurchAllowed(int $churchId): void {
        foreach ($this->getAllowedChurches() as $church) {
            if ((int) $church['id'] === $churchId) return;
        }
        throw new RuntimeException('You cannot report attendance for that church.');
    }

    private function getLeaderScopes(): array {
        if ($this->leaderScopes !== null) return $this->leaderScopes;
        $userId = (int) ($this->userId ?: 0);
        $memberId = (int) ($this->memberId ?: 0);
        $scopes = ['class_ids' => [], 'organization_ids' => [], 'unit_ids' => []];

        $stmt = $this->conn->prepare(
            "SELECT DISTINCT class_id FROM bible_class_leaders
              WHERE status = 'active' AND ((user_id = ? AND ? > 0) OR (member_id = ? AND ? > 0))"
        );
        $stmt->bind_param('iiii', $userId, $userId, $memberId, $memberId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $scopes['class_ids'][] = (int) $row['class_id'];
        $stmt->close();

        $stmt = $this->conn->prepare(
            "SELECT DISTINCT organization_id FROM organization_leaders
              WHERE status = 'active' AND ((user_id = ? AND ? > 0) OR (member_id = ? AND ? > 0))"
        );
        $stmt->bind_param('iiii', $userId, $userId, $memberId, $memberId);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $scopes['organization_ids'][] = (int) $row['organization_id'];
        $stmt->close();

        if ($memberId > 0) {
            $stmt = $this->conn->prepare(
                "SELECT DISTINCT leader.unit_id
                   FROM organization_unit_leaders leader
                   JOIN organization_units unit ON unit.id = leader.unit_id
                  WHERE leader.member_id = ? AND leader.status = 'active'
                    AND (leader.effective_to IS NULL OR leader.effective_to >= CURDATE())"
            );
            $stmt->bind_param('i', $memberId);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $scopes['unit_ids'][] = (int) $row['unit_id'];
            }
            $stmt->close();
        }
        foreach ($scopes as $key => $ids) $scopes[$key] = array_values(array_unique(array_filter($ids)));
        return $this->leaderScopes = $scopes;
    }

    private function scopeCondition(string $alias): string {
        if ($this->hasBroadChurchAccess()) return '';
        $scopes = $this->getLeaderScopes();
        $conditions = [];
        if ($this->hasSundaySchoolAccess()) {
            $conditions[] = "{$alias}.attendance_report_category_id = (SELECT id FROM attendance_report_categories WHERE code = 'sunday_school_service' LIMIT 1)";
        }
        if ($scopes['class_ids']) {
            $conditions[] = "({$alias}.attendance_scope = 'bible_class' AND {$alias}.scope_id IN (" . implode(',', $scopes['class_ids']) . '))';
        }
        if ($scopes['organization_ids']) {
            $conditions[] = "({$alias}.attendance_scope = 'organization' AND {$alias}.scope_id IN (" . implode(',', $scopes['organization_ids']) . '))';
        }
        if ($scopes['unit_ids']) {
            $conditions[] = "{$alias}.organization_unit_id IN (" . implode(',', $scopes['unit_ids']) . ')';
        }
        return $conditions ? '(' . implode(' OR ', $conditions) . ')' : '0 = 1';
    }

    private function breakdownName(array $row): string {
        if ($row['attendance_scope'] === 'bible_class') {
            return trim((string) ($row['bible_class_name'] ?: $row['title']));
        }
        if ($row['attendance_scope'] === 'organization') {
            $name = trim((string) ($row['organization_name'] ?: $row['title']));
            if (!empty($row['organization_unit_name'])) $name .= ' - ' . $row['organization_unit_name'];
            return $name;
        }
        if (!empty($row['parent_category_id'])) return (string) $row['category_name'];
        return trim((string) ($row['title'] ?: $row['category_name']));
    }

    private function accumulate(array &$target, string $key, array $meta, array $counts): void {
        if (!isset($target[$key])) {
            $target[$key] = $meta + ['male' => 0.0, 'female' => 0.0, 'unspecified' => 0.0, 'total' => 0.0, 'sessions' => 0];
        }
        foreach (['male', 'female', 'unspecified', 'total', 'sessions'] as $field) {
            $target[$key][$field] += $counts[$field] ?? 0;
        }
    }
}
