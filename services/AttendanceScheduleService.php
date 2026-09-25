<?php

class AttendanceScheduleService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Create a schedule and materialize every dated occurrence in its bounded
     * date range. The unique schedule/date key makes generation idempotent.
     */
    public function create(array $data, ?int $actorUserId): array
    {
        $normalized = $this->validate($data);
        $this->conn->begin_transaction();

        try {
            $stmt = $this->conn->prepare(
                'INSERT INTO attendance_schedules
                    (church_id, title, attendance_scope, scope_id,
                     attendance_report_category_id, audience_type,
                     role_of_serving_id, schedule_type, start_date, end_date,
                     interval_value, weekday, day_of_month, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'issiisisssiiii',
                $normalized['church_id'],
                $normalized['title'],
                $normalized['attendance_scope'],
                $normalized['scope_id'],
                $normalized['attendance_report_category_id'],
                $normalized['audience_type'],
                $normalized['role_of_serving_id'],
                $normalized['schedule_type'],
                $normalized['start_date'],
                $normalized['end_date'],
                $normalized['interval_value'],
                $normalized['weekday'],
                $normalized['day_of_month'],
                $actorUserId
            );
            $stmt->execute();
            $scheduleId = (int) $stmt->insert_id;
            $stmt->close();

            $created = $this->generate($scheduleId, $actorUserId, false);
            $this->conn->commit();

            return ['schedule_id' => $scheduleId, 'created_sessions' => $created];
        } catch (Throwable $error) {
            $this->conn->rollback();
            throw $error;
        }
    }

    public function generate(int $scheduleId, ?int $actorUserId = null, bool $manageTransaction = true): int
    {
        if ($manageTransaction) {
            $this->conn->begin_transaction();
        }

        try {
            $stmt = $this->conn->prepare(
                "SELECT * FROM attendance_schedules
                 WHERE id = ? AND status IN ('active', 'completed')
                 LIMIT 1 FOR UPDATE"
            );
            $stmt->bind_param('i', $scheduleId);
            $stmt->execute();
            $schedule = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$schedule) {
                throw new RuntimeException('Attendance schedule was not found or cannot be generated.');
            }

            $dates = $this->occurrenceDates($schedule);
            $insert = $this->conn->prepare(
                "INSERT IGNORE INTO attendance_sessions
                    (church_id, title, service_date, is_recurring,
                     recurrence_type, recurrence_day, attendance_scope,
                     attendance_audience, scope_id, role_of_serving_id,
                     schedule_id, schedule_occurrence_date, generated_by_schedule,
                     attendance_report_category_id, classification_source,
                     approval_status, created_by_user_id)
                 VALUES (?, ?, ?, 0, NULL, NULL, ?, ?, ?, ?, ?, ?, 1, ?, 'manual', 'draft', ?)"
            );

            $created = 0;
            foreach ($dates as $date) {
                $insert->bind_param(
                    'issssiiisii',
                    $schedule['church_id'],
                    $schedule['title'],
                    $date,
                    $schedule['attendance_scope'],
                    $schedule['audience_type'],
                    $schedule['scope_id'],
                    $schedule['role_of_serving_id'],
                    $schedule['id'],
                    $date,
                    $schedule['attendance_report_category_id'],
                    $actorUserId
                );
                $insert->execute();
                $created += $insert->affected_rows > 0 ? 1 : 0;
            }
            $insert->close();

            $status = (new DateTimeImmutable($schedule['end_date'])) < new DateTimeImmutable('today')
                ? 'completed'
                : 'active';
            $update = $this->conn->prepare('UPDATE attendance_schedules SET status = ? WHERE id = ?');
            $update->bind_param('si', $status, $scheduleId);
            $update->execute();
            $update->close();

            if ($manageTransaction) {
                $this->conn->commit();
            }
            return $created;
        } catch (Throwable $error) {
            if ($manageTransaction) {
                $this->conn->rollback();
            }
            throw $error;
        }
    }

    /** @return string[] */
    private function occurrenceDates(array $schedule): array
    {
        $start = new DateTimeImmutable($schedule['start_date']);
        $end = new DateTimeImmutable($schedule['end_date']);
        $interval = max(1, (int) $schedule['interval_value']);
        $dates = [];
        $cursor = $start;
        $guard = 0;

        while ($cursor <= $end) {
            if (++$guard > 1100) {
                throw new RuntimeException('Attendance schedule exceeds the safe generation limit.');
            }

            $include = false;
            $daysFromStart = (int) $start->diff($cursor)->format('%a');
            switch ($schedule['schedule_type']) {
                case 'multi_day':
                case 'daily':
                    $include = $daysFromStart % $interval === 0;
                    break;
                case 'weekly':
                    $include = (int) $cursor->format('w') === (int) $schedule['weekday']
                        && intdiv($daysFromStart, 7) % $interval === 0;
                    break;
                case 'monthly':
                    $monthsFromStart = (((int) $cursor->format('Y') - (int) $start->format('Y')) * 12)
                        + ((int) $cursor->format('n') - (int) $start->format('n'));
                    $targetDay = min((int) $schedule['day_of_month'], (int) $cursor->format('t'));
                    $include = $monthsFromStart % $interval === 0
                        && (int) $cursor->format('j') === $targetDay;
                    break;
            }

            if ($include) {
                $dates[] = $cursor->format('Y-m-d');
            }
            $cursor = $cursor->modify('+1 day');
        }

        if (!$dates) {
            throw new RuntimeException('The schedule settings do not produce any attendance dates.');
        }
        return $dates;
    }

    private function validate(array $data): array
    {
        $result = [
            'church_id' => (int) ($data['church_id'] ?? 0),
            'title' => trim((string) ($data['title'] ?? '')),
            'attendance_scope' => (string) ($data['attendance_scope'] ?? 'church'),
            'scope_id' => !empty($data['scope_id']) ? (int) $data['scope_id'] : null,
            'attendance_report_category_id' => !empty($data['attendance_report_category_id'])
                ? (int) $data['attendance_report_category_id'] : null,
            'audience_type' => (string) ($data['audience_type'] ?? 'members'),
            'role_of_serving_id' => !empty($data['role_of_serving_id'])
                ? (int) $data['role_of_serving_id'] : null,
            'schedule_type' => (string) ($data['schedule_type'] ?? ''),
            'start_date' => (string) ($data['start_date'] ?? ''),
            'end_date' => (string) ($data['end_date'] ?? ''),
            'interval_value' => max(1, (int) ($data['interval_value'] ?? 1)),
            'weekday' => isset($data['weekday']) && $data['weekday'] !== '' ? (int) $data['weekday'] : null,
            'day_of_month' => isset($data['day_of_month']) && $data['day_of_month'] !== ''
                ? (int) $data['day_of_month'] : null,
        ];

        if ($result['church_id'] <= 0 || $result['title'] === '') {
            throw new InvalidArgumentException('Church and session title are required.');
        }
        if (!in_array($result['attendance_scope'], ['church','bible_class','organization','event','other'], true)) {
            throw new InvalidArgumentException('Invalid attendance scope.');
        }
        if (!in_array($result['audience_type'], ['members','sunday_school','role_of_serving'], true)) {
            throw new InvalidArgumentException('Invalid attendance audience.');
        }
        if (!in_array($result['schedule_type'], ['daily','weekly','monthly','multi_day'], true)) {
            throw new InvalidArgumentException('Invalid attendance schedule type.');
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $result['start_date']);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $result['end_date']);
        if (!$start || !$end || $end < $start || $start->diff($end)->days > 1095) {
            throw new InvalidArgumentException('Use a valid schedule date range of no more than three years.');
        }
        if ($result['schedule_type'] === 'weekly'
            && ($result['weekday'] === null || $result['weekday'] < 0 || $result['weekday'] > 6)) {
            throw new InvalidArgumentException('Select a valid weekday.');
        }
        if ($result['schedule_type'] === 'monthly'
            && ($result['day_of_month'] === null || $result['day_of_month'] < 1 || $result['day_of_month'] > 31)) {
            throw new InvalidArgumentException('Select a valid day of month.');
        }
        if ($result['audience_type'] !== 'role_of_serving') {
            $result['role_of_serving_id'] = null;
        }
        return $result;
    }
}
