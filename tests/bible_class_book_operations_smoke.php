<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/bible_class_book_helper.php';
require_once __DIR__ . '/../services/BibleClassBookOperationsService.php';

function bcb_operations_assert(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Calendar checks cover the two ordering examples in the approved specification.
[$startDate, $endDate] = bcb_quarter_range(2026, 3);
$sundays = bcb_quarter_sundays($startDate, $endDate);
$mondayDates = bcb_dates_for_weekday($startDate, $endDate, 1);
$wednesdayDates = bcb_dates_for_weekday($startDate, $endDate, 3);
[$mondaySlots] = bcb_build_quarter_slots(2026, 3, $mondayDates, $sundays);
[$wednesdaySlots] = bcb_build_quarter_slots(2026, 3, $wednesdayDates, $sundays);

$mondayKeys = array_column($mondaySlots, 'slot_key');
$wednesdayKeys = array_column($wednesdaySlots, 'slot_key');
bcb_operations_assert(
    array_search('p|2026-07-05', $mondayKeys, true) < array_search('a|2026-07-06', $mondayKeys, true),
    'A Sunday payment must appear before the following Monday attendance.'
);
bcb_operations_assert(
    array_search('a|2026-07-01', $wednesdayKeys, true) < array_search('p|2026-07-05', $wednesdayKeys, true),
    'A Wednesday attendance must appear before the following Sunday payment.'
);

foreach ($sundays as $date) {
    bcb_operations_assert((int) date('w', strtotime($date)) === 0, 'Payment columns must be Sundays.');
}
foreach ($mondayDates as $date) {
    bcb_operations_assert((int) date('w', strtotime($date)) === 1, 'Attendance columns must match the class meeting day.');
}
$sundaysByMonth = array_count_values(array_map(static fn(string $date): int => (int) date('n', strtotime($date)), $sundays));
bcb_operations_assert(($sundaysByMonth[7] ?? 0) === 4, 'July 2026 must have four Sunday payment weeks.');
bcb_operations_assert(($sundaysByMonth[8] ?? 0) === 5, 'August 2026 must have five Sunday payment weeks.');

$fixtureResult = $conn->query(
    "SELECT class.id AS class_id, class.church_id, leader.user_id, leader.member_id
       FROM bible_classes class
       JOIN class_groups class_group ON class_group.id = class.class_group_id
       JOIN bible_class_leaders leader ON leader.class_id = class.id AND leader.status = 'active'
      WHERE class_group.is_active = 1 AND class_group.meeting_day IS NOT NULL
      ORDER BY class.id
      LIMIT 1"
);
$fixture = $fixtureResult ? $fixtureResult->fetch_assoc() : null;
bcb_operations_assert((bool) $fixture, 'No scheduled Bible class with an active leader is available for the operations test.');

$classId = (int) $fixture['class_id'];
$churchId = (int) $fixture['church_id'];
$actorUserId = !empty($fixture['user_id']) ? (int) $fixture['user_id'] : null;
$actorMemberId = !empty($fixture['member_id']) ? (int) $fixture['member_id'] : null;
$service = new BibleClassBookOperationsService($conn, $actorUserId, $actorMemberId);
$testCrn = 'CODEX-BCB-' . strtoupper(bin2hex(random_bytes(5)));
$testMemberId = 0;
$requestId = 0;
$testBookId = 0;
$testBookYear = 0;

try {
    $insertMember = $conn->prepare(
        "INSERT INTO members
            (first_name, last_name, church_id, class_id, crn, status, deactivated_at,
             password_hash, membership_status)
         VALUES ('Codex', 'Bible Book Test', ?, ?, ?, 'active', '', '', 'Full Member')"
    );
    $insertMember->bind_param('iis', $churchId, $classId, $testCrn);
    $insertMember->execute();
    $testMemberId = (int) $conn->insert_id;
    $insertMember->close();

    for ($candidateYear = 2090; $candidateYear <= 2099; $candidateYear++) {
        $bookCheck = $conn->prepare(
            'SELECT COUNT(*) AS book_count FROM bible_class_books
              WHERE church_id = ? AND class_id = ? AND book_year = ? AND book_quarter = 1'
        );
        $bookCheck->bind_param('iii', $churchId, $classId, $candidateYear);
        $bookCheck->execute();
        $bookCount = (int) ($bookCheck->get_result()->fetch_assoc()['book_count'] ?? 0);
        $bookCheck->close();
        if ($bookCount === 0) {
            $testBookYear = $candidateYear;
            break;
        }
    }
    bcb_operations_assert($testBookYear > 0, 'No unused disposable class-book period is available.');
    $snapshotData = bcb_build_quarter_book_data($conn, $churchId, $classId, $testBookYear, 1);
    $snapshot = bcb_upsert_snapshot(
        $conn,
        $churchId,
        $classId,
        $testBookYear,
        1,
        (int) ($actorUserId ?: 0),
        $snapshotData
    );
    bcb_operations_assert(!empty($snapshot['success']), 'The separated attendance/payment snapshot could not be saved.');
    $testBookId = (int) $snapshot['book_id'];
    $entryCheck = $conn->prepare(
        "SELECT entry_type, COUNT(*) AS entry_count
           FROM bible_class_book_entries WHERE book_id = ? GROUP BY entry_type"
    );
    $entryCheck->bind_param('i', $testBookId);
    $entryCheck->execute();
    $entryRows = $entryCheck->get_result()->fetch_all(MYSQLI_ASSOC);
    $entryCheck->close();
    $entryCounts = [];
    foreach ($entryRows as $entryRow) {
        $entryCounts[$entryRow['entry_type']] = (int) $entryRow['entry_count'];
    }
    bcb_operations_assert(($entryCounts['attendance'] ?? 0) > 0, 'The snapshot has no attendance entries.');
    bcb_operations_assert(($entryCounts['payment'] ?? 0) > 0, 'The snapshot has no payment entries.');
    bcb_operations_assert(($entryCounts['legacy_combined'] ?? 0) === 0, 'The snapshot still writes legacy combined entries.');

    $requestId = $service->requestRemoval($classId, $testMemberId, 'Automated workflow verification.');
    bcb_operations_assert($requestId > 0, 'A class leader could not create a removal request.');
    $scopedQueue = $service->listRequestsForClasses([$classId]);
    $queuedRequestIds = array_map('intval', array_column($scopedQueue, 'id'));
    bcb_operations_assert(in_array($requestId, $queuedRequestIds, true), 'The central scoped queue omitted a pending request.');

    $duplicateBlocked = false;
    try {
        $service->requestRemoval($classId, $testMemberId, 'Duplicate request verification.');
    } catch (RuntimeException $exception) {
        $duplicateBlocked = str_contains($exception->getMessage(), 'pending removal request');
    }
    bcb_operations_assert($duplicateBlocked, 'Duplicate pending removal requests were not blocked.');

    $service->reviewRemoval($requestId, $classId, 'approved', 'Approved by automated verification.', true);
    $memberCheck = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
    $memberCheck->bind_param('i', $testMemberId);
    $memberCheck->execute();
    $memberRow = $memberCheck->get_result()->fetch_assoc();
    $memberCheck->close();
    bcb_operations_assert((bool) $memberRow, 'Approval deleted the member record.');
    bcb_operations_assert($memberRow['class_id'] === null, 'Approval did not remove the class assignment.');

    echo "PASS: Bible Class Book calendars and safe class-list removal workflow are operational.\n";
} finally {
    if ($requestId > 0) {
        $deleteRequest = $conn->prepare('DELETE FROM bible_class_member_removal_requests WHERE id = ?');
        $deleteRequest->bind_param('i', $requestId);
        $deleteRequest->execute();
        $deleteRequest->close();
    }
    if ($testBookId > 0) {
        $deleteEntries = $conn->prepare('DELETE FROM bible_class_book_entries WHERE book_id = ?');
        $deleteEntries->bind_param('i', $testBookId);
        $deleteEntries->execute();
        $deleteEntries->close();
        $deleteBook = $conn->prepare('DELETE FROM bible_class_books WHERE id = ? AND book_year = ?');
        $deleteBook->bind_param('ii', $testBookId, $testBookYear);
        $deleteBook->execute();
        $deleteBook->close();
    }
    if ($testMemberId > 0) {
        $deleteMember = $conn->prepare('DELETE FROM members WHERE id = ? AND crn = ?');
        $deleteMember->bind_param('is', $testMemberId, $testCrn);
        $deleteMember->execute();
        $deleteMember->close();
    }
}
