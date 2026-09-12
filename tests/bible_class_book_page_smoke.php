<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

$adminResult = $conn->query(
    'SELECT user.id, user.member_id FROM users user
      JOIN user_roles user_role ON user_role.user_id = user.id
     WHERE user_role.role_id = 1 ORDER BY user.id LIMIT 1'
);
$admin = $adminResult ? $adminResult->fetch_assoc() : null;
$classResult = $conn->query(
    'SELECT class.id FROM bible_classes class
      JOIN class_groups class_group ON class_group.id = class.class_group_id
     WHERE class_group.is_active = 1 AND class_group.meeting_day IS NOT NULL
     ORDER BY class.id LIMIT 1'
);
$class = $classResult ? $classResult->fetch_assoc() : null;
if (!$admin || !$class) {
    throw new RuntimeException('An administrator and scheduled Bible class are required for this page smoke test.');
}

$_SESSION['user_id'] = (int) $admin['id'];
$_SESSION['member_id'] = !empty($admin['member_id']) ? (int) $admin['member_id'] : null;
$_SESSION['role_id'] = 1;
$_SESSION['role_ids'] = [1];
$_SERVER['REQUEST_METHOD'] = 'GET';
$classId = (int) $class['id'];

$target = $argv[1] ?? '';
if ($target === 'book') {
    $_GET = ['class_id' => $classId, 'year' => 2026, 'quarter' => 3];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/views/bible_class_book.php?' . http_build_query($_GET);
    ob_start();
    include __DIR__ . '/../views/bible_class_book.php';
    $html = ob_get_clean();
    if (strpos($html, 'Bible Class Attendance And Tithe Payment') === false
        || strpos($html, 'Removal Requests') === false) {
        throw new RuntimeException('Class book page render assertion failed.');
    }
    echo "PASS: Bible Class Book page rendered for an administrator.\n";
    exit;
}

if ($target === 'removals') {
    $_GET = ['class_id' => $classId];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/views/bible_class_member_removals.php?' . http_build_query($_GET);
    ob_start();
    include __DIR__ . '/../views/bible_class_member_removals.php';
    $html = ob_get_clean();
    if (strpos($html, 'Approval removes the class assignment only') === false) {
        throw new RuntimeException('Removal page render assertion failed.');
    }
    echo "PASS: Removal review page rendered for an administrator.\n";
    exit;
}

if ($target === 'export') {
    $_GET = ['class_id' => $classId, 'year' => 2026, 'quarter' => 3, 'format' => 'xls'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/views/bible_class_book_export.php?' . http_build_query($_GET);
    ob_start();
    include __DIR__ . '/../views/bible_class_book_export.php';
    $html = ob_get_clean();
    if (strpos($html, 'Quarter Payment Total') === false
        || strpos($html, 'MyFreeman Digital NetWorks') === false) {
        throw new RuntimeException('Export render assertion failed.');
    }
    echo "PASS: Excel-compatible export rendered with the standard footer.\n";
    exit;
}

throw new InvalidArgumentException('Use book, removals, or export.');
