<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';
require_once __DIR__ . '/../helpers/bible_class_book_helper.php';

function schedule_smoke_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixtureResult = $conn->query(
    "SELECT class.id AS class_id, class_group.meeting_day,
            leader.user_id, leader.member_id
       FROM bible_classes class
       JOIN class_groups class_group ON class_group.id = class.class_group_id
       JOIN bible_class_leaders leader ON leader.class_id = class.id AND leader.status = 'active'
       JOIN members member ON member.class_id = class.id AND member.status = 'active'
      WHERE class_group.is_active = 1 AND class_group.meeting_day IS NOT NULL
      GROUP BY class.id, class_group.meeting_day, leader.user_id, leader.member_id
      ORDER BY class.id
      LIMIT 1"
);
$fixture = $fixtureResult ? $fixtureResult->fetch_assoc() : null;
schedule_smoke_assert((bool) $fixture, 'No scheduled Bible class with an active leader and member is available for the smoke test.');

$classId = (int) $fixture['class_id'];
$meetingDay = (int) $fixture['meeting_day'];
$actorUserId = !empty($fixture['user_id']) ? (int) $fixture['user_id'] : null;
$actorMemberId = !empty($fixture['member_id']) ? (int) $fixture['member_id'] : null;
$service = new BibleClassAttendanceScheduleService($conn, $actorUserId, $actorMemberId);
$anonymousService = new BibleClassAttendanceScheduleService($conn, null, null);

schedule_smoke_assert($service->canAccessClass($classId), 'The assigned class leader was denied access.');
schedule_smoke_assert(!$anonymousService->canAccessClass($classId), 'An unassigned actor was granted class access.');

$candidate = new DateTimeImmutable('2010-01-01');
while ((int) $candidate->format('w') !== $meetingDay) {
    $candidate = $candidate->modify('+1 day');
}

$testDate = '';
for ($attempt = 0; $attempt < 52; $attempt++) {
    $date = $candidate->format('Y-m-d');
    $check = $conn->prepare(
        "SELECT
            (SELECT COUNT(*) FROM bible_class_attendance_schedule WHERE class_id = ? AND attendance_date = ?) AS ledger_count,
            (SELECT COUNT(*) FROM attendance_sessions WHERE attendance_scope = 'bible_class' AND scope_id = ? AND service_date = ?) AS session_count"
    );
    $check->bind_param('isis', $classId, $date, $classId, $date);
    $check->execute();
    $counts = $check->get_result()->fetch_assoc();
    $check->close();
    if ((int) $counts['ledger_count'] === 0 && (int) $counts['session_count'] === 0) {
        $testDate = $date;
        break;
    }
    $candidate = $candidate->modify('+7 days');
}
schedule_smoke_assert($testDate !== '', 'Unable to find an unused attendance date for the smoke test.');

$createdSessionId = 0;
try {
    $first = $service->ensureForClassDate($classId, $testDate, 'admin');
    $second = $service->ensureForClassDate($classId, $testDate, 'admin');
    $createdSessionId = (int) $first['session_id'];
    schedule_smoke_assert(!empty($first['created']), 'The first schedule generation did not create a session.');
    schedule_smoke_assert(empty($second['created']), 'The second schedule generation was not idempotent.');
    schedule_smoke_assert($createdSessionId === (int) $second['session_id'], 'Idempotent generation returned a different session.');

    $session = $service->getClassSession($classId, $createdSessionId);
    schedule_smoke_assert($session['attendance_scope'] === 'bible_class', 'Generated session has the wrong scope.');
    schedule_smoke_assert((int) $session['scope_id'] === $classId, 'Generated session targets the wrong class.');
    schedule_smoke_assert((string) $session['service_date'] === $testDate, 'Generated session has the wrong date.');

    $year = (int) substr($testDate, 0, 4);
    $quarter = bcb_quarter_from_month((int) substr($testDate, 5, 2));
    $bookData = bcb_build_quarter_book_data($conn, (int) $session['church_id'], $classId, $year, $quarter);
    $firstMemberRow = $bookData['rows'][0] ?? null;
    $attendanceSlotKey = 'a|' . $testDate;
    schedule_smoke_assert(isset($firstMemberRow['slots'][$attendanceSlotKey]), 'Generated date is missing from the class book.');
    schedule_smoke_assert(
        ($firstMemberRow['slots'][$attendanceSlotKey]['attendance_code'] ?? '') === 'A',
        'An unmarked past scheduled date was not represented as absent.'
    );

    $savedCount = $service->submitAttendance($classId, $createdSessionId, []);
    schedule_smoke_assert($savedCount > 0, 'Attendance submission did not save the class roster.');
    $approved = $service->getClassSession($classId, $createdSessionId);
    schedule_smoke_assert($approved['approval_status'] === 'approved', 'Bible class attendance was not finalized.');

    echo "PASS: Bible class scheduling is idempotent, class-scoped, leader-restricted, and class-book aware.\n";
} finally {
    if ($testDate !== '') {
        $deleteLedger = $conn->prepare('DELETE FROM bible_class_attendance_schedule WHERE class_id = ? AND attendance_date = ?');
        $deleteLedger->bind_param('is', $classId, $testDate);
        $deleteLedger->execute();
        $deleteLedger->close();
    }
    if ($createdSessionId > 0) {
        $deleteSession = $conn->prepare('DELETE FROM attendance_sessions WHERE id = ?');
        $deleteSession->bind_param('i', $createdSessionId);
        $deleteSession->execute();
        $deleteSession->close();
    }
}
