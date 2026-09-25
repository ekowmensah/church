<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/AttendanceScheduleService.php';
require_once __DIR__ . '/../services/AttendanceAudienceService.php';

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$churchId = (int) ($conn->query('SELECT id FROM churches ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
$userId = (int) ($conn->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetch_assoc()['id'] ?? 0);
$categoryId = (int) ($conn->query("SELECT id FROM attendance_report_categories WHERE code = 'leaders_meeting' LIMIT 1")->fetch_assoc()['id'] ?? 0);
expect_true($churchId > 0, 'A church is required for this smoke test.');

$scheduleId = 0;
try {
    $scheduleService = new AttendanceScheduleService($conn);
    $created = $scheduleService->create([
        'church_id' => $churchId,
        'title' => '__PHASE_0025_SMOKE__',
        'attendance_scope' => 'church',
        'attendance_report_category_id' => $categoryId ?: null,
        'audience_type' => 'sunday_school',
        'schedule_type' => 'weekly',
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'interval_value' => 1,
        'weekday' => 5,
    ], $userId ?: null);
    $scheduleId = (int) $created['schedule_id'];
    expect_true((int) $created['created_sessions'] === 5, 'Weekly October schedule should create five Fridays.');
    expect_true($scheduleService->generate($scheduleId, $userId ?: null) === 0, 'Schedule generation must be idempotent.');

    $session = $conn->query(
        'SELECT * FROM attendance_sessions WHERE schedule_id = ' . $scheduleId . ' ORDER BY service_date LIMIT 1'
    )->fetch_assoc();
    expect_true((bool) $session, 'Generated session is missing.');
    expect_true($session['service_date'] === '2026-10-02', 'First generated Friday is incorrect.');

    $audienceService = new AttendanceAudienceService($conn);
    $children = $audienceService->subjects($session);
    if ($children && $userId > 0) {
        $childId = (int) $children[0]['id'];
        $audienceService->save((int) $session['id'], 'sunday_school', $childId, 'present', $userId, false);
        $record = $conn->query(
            'SELECT subject_type, member_id, sunday_school_id FROM attendance_records'
            . ' WHERE session_id = ' . (int) $session['id'] . ' LIMIT 1'
        )->fetch_assoc();
        expect_true($record['subject_type'] === 'sunday_school', 'Sunday School subject type was not saved.');
        expect_true($record['member_id'] === null, 'Sunday School attendance must not fabricate a member ID.');
        expect_true((int) $record['sunday_school_id'] === $childId, 'Sunday School subject ID is incorrect.');
    }

    echo "PASS: Phase 0025 attendance schedules and audience records are operational.\n";
} finally {
    if ($scheduleId > 0) {
        $conn->query(
            'DELETE record FROM attendance_records record'
            . ' JOIN attendance_sessions session ON session.id = record.session_id'
            . ' WHERE session.schedule_id = ' . $scheduleId
        );
        $conn->query('DELETE FROM attendance_sessions WHERE schedule_id = ' . $scheduleId);
        $conn->query('DELETE FROM attendance_schedules WHERE id = ' . $scheduleId);
    }
}
