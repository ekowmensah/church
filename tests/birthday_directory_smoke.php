<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/BirthdayDirectoryService.php';

function birthday_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$admin = $conn->query(
    'SELECT user_account.id, user_account.member_id, user_account.church_id
       FROM users user_account
       JOIN user_roles user_role ON user_role.user_id = user_account.id
      WHERE user_role.role_id = 1 ORDER BY user_account.id LIMIT 1'
)->fetch_assoc();
if (!$admin) throw new RuntimeException('A Super Admin fixture is required.');

$superService = new BirthdayDirectoryService(
    $conn, (int) $admin['id'], (int) $admin['member_id'], [1]
);
$summary = $superService->getSummary();
$expectedToday = (int) $conn->query(
    "SELECT COUNT(*) AS total FROM members
      WHERE status = 'active' AND dob >= '1900-01-01'
        AND DATE_FORMAT(dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')"
)->fetch_assoc()['total'];
birthday_assert($summary['today'] === $expectedToday, 'Super Admin birthday count does not match active member data.');

$class = $conn->query(
    'SELECT id, church_id FROM bible_classes
      WHERE church_id IS NOT NULL ORDER BY id LIMIT 1'
)->fetch_assoc();
if (!$class) throw new RuntimeException('A Bible Class fixture is required.');

$conn->begin_transaction();
try {
    $leaderMemberId = (int) $admin['member_id'];
    $classId = (int) $class['id'];
    $churchId = (int) $class['church_id'];
    $stmt = $conn->prepare(
        "INSERT INTO bible_class_leaders
            (class_id, member_id, leader_role, assigned_date, assigned_by, status, notes)
         VALUES (?, ?, 'primary', CURDATE(), ?, 'active', 'Birthday scope smoke test')"
    );
    $actorId = (int) $admin['id'];
    $stmt->bind_param('iii', $classId, $leaderMemberId, $actorId);
    $stmt->execute();
    $stmt->close();

    $suffix = random_int(100000, 999999);
    $todayDob = '1990-' . date('m-d');
    $phone = '059' . random_int(1000000, 9999999);
    $status = 'active';
    $empty = '';
    $passwordHash = password_hash('BirthdaySmoke!2026', PASSWORD_DEFAULT);
    $gender = 'Female';
    $insertMember = $conn->prepare(
        'INSERT INTO members
            (first_name, last_name, church_id, class_id, crn, gender, phone,
             status, deactivated_at, password_hash, dob)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $first = 'Scoped';
    $last = 'Birthday ' . $suffix;
    $crn = 'BD' . $suffix;
    $insertMember->bind_param(
        'ssiisssssss', $first, $last, $churchId, $classId, $crn, $gender,
        $phone, $status, $empty, $passwordHash, $todayDob
    );
    $insertMember->execute();
    $scopedMemberId = (int) $insertMember->insert_id;

    $first = 'Outside';
    $last = 'Birthday ' . $suffix;
    $outsideClassId = null;
    $crn = 'BO' . $suffix;
    $insertMember->bind_param(
        'ssiisssssss', $first, $last, $churchId, $outsideClassId, $crn, $gender,
        $phone, $status, $empty, $passwordHash, $todayDob
    );
    $insertMember->execute();
    $outsideMemberId = (int) $insertMember->insert_id;
    $insertMember->close();

    $leaderService = new BirthdayDirectoryService($conn, null, $leaderMemberId, [5]);
    $leaderBirthdays = $leaderService->getMembers('today');
    $visibleIds = array_map('intval', array_column($leaderBirthdays, 'id'));
    birthday_assert(in_array($scopedMemberId, $visibleIds, true), 'Class leader could not see an assigned-class birthday.');
    birthday_assert(!in_array($outsideMemberId, $visibleIds, true), 'Class leader could see an out-of-scope birthday.');

    echo "PASS: birthday summaries use active records and Bible Class leaders are restricted to their assignment.\n";
} finally {
    $conn->rollback();
}
