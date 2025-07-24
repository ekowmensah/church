<?php
// List permissions with no corresponding menu/feature in sidebar.php or _nav_sms.php
$permissions = [
    'Activities log', 'Add Admin', 'Additions', 'Audit', 'Bulk SMS', 'Church attendance', 'Church service', 'Class health report', 'Class members report', 'Class payment report', 'Class type report', 'access_dashboard', 'Enter records', 'Feedback', 'Health Statistics', 'Individual payment report', 'Individual statement', 'Make Payment', 'Manage Members', 'Organisational health report', 'Organizational members report', 'Organizational payment report', 'Organizational type report', 'Overall Payment Statistics', 'Payment', 'Payment History', 'Payment Statistics', 'Payments', 'Permission', 'Record history', 'Records', 'Register Member', 'Registered List', 'Registration', 'Reports', 'Role', 'SMS Provider Settings', 'SMS Templates', 'Transfer member', 'User', 'User Audit', 'Withdrawals', 'Zero payment report', 'Total payment report',
];

// Get sidebar/_nav_sms permissions
$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$nav_sms = file_exists(__DIR__ . '/../includes/_nav_sms.php') ? file_get_contents(__DIR__ . '/../includes/_nav_sms.php') : '';
preg_match_all("/show_if_permitted\(['\"]([^'\"]+)['\"]\)/", $sidebar, $matches1);
preg_match_all("/has_permission\(['\"]([^'\"]+)['\"]\)/", $nav_sms, $matches2);
$sidebar_perms = array_unique(array_merge($matches1[1], $matches2[1]));

// Find permissions not enforced in sidebar/menu
$missing = array_diff($permissions, $sidebar_perms);

header('Content-Type: text/plain');
echo "Permissions with NO corresponding menu/feature in sidebar/_nav_sms (build these features):\n";
foreach ($missing as $p) echo "- $p\n";
if (empty($missing)) echo "(All permissions have features/menus!)\n";
