<?php

$mode = $argv[1] ?? '';
if (!in_array($mode, ['group', 'organization'], true)) {
    fwrite(STDERR, "Usage: php organization_attendance_page_smoke.php group|organization\n");
    exit(2);
}

session_start();
require_once __DIR__ . '/../config/config.php';
$leaderSql = $mode === 'group'
    ? "SELECT leader.member_id, unit.organization_id
         FROM organization_unit_leaders leader
         JOIN organization_units unit ON unit.id = leader.unit_id AND unit.is_active = 1
        WHERE leader.status = 'active' AND (leader.effective_to IS NULL OR leader.effective_to >= CURDATE())
        LIMIT 1"
    : "SELECT COALESCE(leader.member_id, user.member_id) AS member_id, leader.organization_id
         FROM organization_leaders leader
         LEFT JOIN users user ON user.id = leader.user_id
        WHERE leader.status = 'active' AND COALESCE(leader.member_id, user.member_id) IS NOT NULL
        LIMIT 1";
$fixture = $conn->query($leaderSql)->fetch_assoc();
if (!$fixture) {
    fwrite(STDERR, "No suitable leader fixture exists.\n");
    exit(2);
}
$_SESSION = [
    'member_id' => (int) $fixture['member_id'],
    'member_name' => strtoupper($mode) . ' LEADER',
];
$_GET = ['org_id' => (int) $fixture['organization_id']];
$_REQUEST = $_GET;

ob_start();
require __DIR__ . '/../views/my_organization_attendance.php';
$html = ob_get_clean();

if ($mode === 'group') {
    $checks = [
        'group leader page is scoped' => strpos($html, 'Assigned group access only') !== false,
        'group leader cannot see session creation' => strpos($html, 'Create an organization or group session') === false,
    ];
} else {
    $checks = [
        'organization leader has review access' => strpos($html, 'Organization-wide review access') !== false,
        'organization leader can see session creation' => strpos($html, 'Create an organization or group session') !== false,
    ];
}

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
