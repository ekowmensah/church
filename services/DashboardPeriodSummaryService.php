<?php

final class DashboardPeriodSummaryService {
    private mysqli $conn;
    private int $userId;
    private bool $superAdmin;
    private bool $cashier;
    private ?int $churchId;
    private ?array $classIds;
    private ?array $organizationIds;

    public function __construct(
        mysqli $conn,
        int $userId,
        bool $superAdmin,
        bool $cashier,
        ?int $churchId,
        ?array $classIds = null,
        ?array $organizationIds = null
    ) {
        $this->conn = $conn;
        $this->userId = $userId;
        $this->superAdmin = $superAdmin;
        $this->cashier = $cashier;
        $this->churchId = $churchId && $churchId > 0 ? $churchId : null;
        $this->classIds = $classIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $classIds))));
        $this->organizationIds = $organizationIds === null ? null : array_values(array_unique(array_filter(array_map('intval', $organizationIds))));
    }

    public function summarize(bool $payments, bool $attendance, bool $health, ?DateTimeImmutable $today = null): array {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $periods = $this->periods($today);
        $result = ['periods' => $periods, 'payments' => [], 'attendance' => [], 'health' => []];
        if ($payments) $result['payments'] = $this->paymentSummary($periods);
        if ($attendance) $result['attendance'] = $this->attendanceSummary($periods);
        if ($health) $result['health'] = $this->healthSummary($periods);
        return $result;
    }

    private function periods(DateTimeImmutable $today): array {
        $tomorrow = $today->modify('+1 day');
        $thisWeek = $today->modify('monday this week');
        $thisMonth = $today->modify('first day of this month');
        $thisYear = $today->setDate((int) $today->format('Y'), 1, 1);
        return [
            'today' => ['label' => 'Today', 'start' => $today, 'end' => $tomorrow],
            'yesterday' => ['label' => 'Yesterday', 'start' => $today->modify('-1 day'), 'end' => $today],
            'this_week' => ['label' => 'This Week', 'start' => $thisWeek, 'end' => $thisWeek->modify('+1 week')],
            'last_week' => ['label' => 'Last Week', 'start' => $thisWeek->modify('-1 week'), 'end' => $thisWeek],
            'this_month' => ['label' => 'This Month', 'start' => $thisMonth, 'end' => $thisMonth->modify('+1 month')],
            'last_month' => ['label' => 'Last Month', 'start' => $thisMonth->modify('-1 month'), 'end' => $thisMonth],
            'this_year' => ['label' => 'This Year', 'start' => $thisYear, 'end' => $thisYear->modify('+1 year')],
            'last_year' => ['label' => 'Last Year', 'start' => $thisYear->modify('-1 year'), 'end' => $thisYear],
            'overall' => ['label' => 'Overall', 'start' => null, 'end' => null],
        ];
    }

    private function periodSql(array $periods): string {
        $rows = [];
        foreach ($periods as $code => $period) {
            $start = $period['start'] ? "'" . $period['start']->format('Y-m-d H:i:s') . "'" : 'NULL';
            $end = $period['end'] ? "'" . $period['end']->format('Y-m-d H:i:s') . "'" : 'NULL';
            $rows[] = "SELECT '{$code}' AS period_code, {$start} AS start_at, {$end} AS end_at";
        }
        return implode(' UNION ALL ', $rows);
    }

    private function paymentSummary(array $periods): array {
        $scope = $this->paymentScope('payment');
        $sql = "SELECT period.period_code, COALESCE(SUM(payment.amount), 0) value
                  FROM ({$this->periodSql($periods)}) period
                  LEFT JOIN v_posted_payments payment
                    ON (period.start_at IS NULL OR payment.payment_date >= period.start_at)
                   AND (period.end_at IS NULL OR payment.payment_date < period.end_at)
                   AND {$scope}
                 GROUP BY period.period_code";
        return $this->keyValues($sql, true);
    }

    private function attendanceSummary(array $periods): array {
        $scope = $this->attendanceScope('session');
        $sql = "SELECT period.period_code, COUNT(record.id) value
                  FROM ({$this->periodSql($periods)}) period
                  LEFT JOIN attendance_sessions session
                    ON (period.start_at IS NULL OR session.service_date >= DATE(period.start_at))
                   AND (period.end_at IS NULL OR session.service_date < DATE(period.end_at))
                   AND session.approval_status = 'approved'
                   AND {$scope}
                  LEFT JOIN attendance_records record
                    ON record.session_id = session.id
                   AND record.status = 'present'
                   AND COALESCE(record.is_draft, 0) = 0
                 GROUP BY period.period_code";
        return $this->keyValues($sql, false);
    }

    private function healthSummary(array $periods): array {
        $scope = $this->healthScope('health');
        $sql = "SELECT period.period_code, COUNT(health.id) value
                  FROM ({$this->periodSql($periods)}) period
                  LEFT JOIN health_records health
                    ON (period.start_at IS NULL OR health.recorded_at >= period.start_at)
                   AND (period.end_at IS NULL OR health.recorded_at < period.end_at)
                   AND {$scope}
                 GROUP BY period.period_code";
        return $this->keyValues($sql, false);
    }

    private function keyValues(string $sql, bool $decimal): array {
        $result = [];
        $query = $this->conn->query($sql);
        while ($row = $query->fetch_assoc()) {
            $result[$row['period_code']] = $decimal ? (float) $row['value'] : (int) $row['value'];
        }
        return $result;
    }

    private function paymentScope(string $alias): string {
        if ($this->superAdmin) return '1=1';
        if ($this->cashier) return $alias . '.recorded_by = ' . $this->userId;
        $assigned = $this->assignmentScope(
            "EXISTS (SELECT 1 FROM members scoped_member WHERE scoped_member.id={$alias}.member_id AND scoped_member.class_id IN (%s)) OR EXISTS (SELECT 1 FROM sunday_school scoped_child WHERE scoped_child.id={$alias}.sundayschool_id AND scoped_child.class_id IN (%s))",
            "EXISTS (SELECT 1 FROM member_organizations scoped_org WHERE scoped_org.member_id={$alias}.member_id AND scoped_org.organization_id IN (%s))"
        );
        if ($assigned !== null) return $assigned;
        return $this->churchId ? $alias . '.church_id = ' . $this->churchId : '1=0';
    }

    private function attendanceScope(string $alias): string {
        if ($this->superAdmin) return '1=1';
        $parts = [];
        if ($this->classIds !== null && $this->classIds) $parts[] = "({$alias}.attendance_scope='bible_class' AND {$alias}.scope_id IN (" . implode(',', $this->classIds) . '))';
        if ($this->organizationIds !== null && $this->organizationIds) $parts[] = "({$alias}.attendance_scope='organization' AND {$alias}.scope_id IN (" . implode(',', $this->organizationIds) . '))';
        if ($this->classIds !== null || $this->organizationIds !== null) return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
        return $this->churchId ? $alias . '.church_id = ' . $this->churchId : '1=0';
    }

    private function healthScope(string $alias): string {
        if ($this->superAdmin) return '1=1';
        $assigned = $this->assignmentScope(
            "EXISTS (SELECT 1 FROM members scoped_member WHERE scoped_member.id={$alias}.member_id AND scoped_member.class_id IN (%s)) OR EXISTS (SELECT 1 FROM sunday_school scoped_child WHERE scoped_child.id={$alias}.sundayschool_id AND scoped_child.class_id IN (%s))",
            "EXISTS (SELECT 1 FROM member_organizations scoped_org WHERE scoped_org.member_id={$alias}.member_id AND scoped_org.organization_id IN (%s))"
        );
        if ($assigned !== null) return $assigned;
        if (!$this->churchId) return '1=0';
        return "(EXISTS (SELECT 1 FROM members scoped_member WHERE scoped_member.id={$alias}.member_id AND scoped_member.church_id={$this->churchId}) OR EXISTS (SELECT 1 FROM sunday_school scoped_child WHERE scoped_child.id={$alias}.sundayschool_id AND scoped_child.church_id={$this->churchId}))";
    }

    private function assignmentScope(string $classTemplate, string $organizationTemplate): ?string {
        if ($this->classIds === null && $this->organizationIds === null) return null;
        $parts = [];
        if ($this->classIds) {
            $ids = implode(',', $this->classIds);
            $parts[] = sprintf($classTemplate, $ids, $ids);
        }
        if ($this->organizationIds) $parts[] = sprintf($organizationTemplate, implode(',', $this->organizationIds));
        return $parts ? '(' . implode(' OR ', $parts) . ')' : '1=0';
    }
}
