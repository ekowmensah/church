<?php
// Script to extract all permission names used in show_if_permitted() in sidebar.php and _nav_sms.php
$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$nav_sms = file_exists(__DIR__ . '/../includes/_nav_sms.php') ? file_get_contents(__DIR__ . '/../includes/_nav_sms.php') : '';

$pattern = "/show_if_permitted\(['\"]([^'\"]+)['\"]\)/";
preg_match_all($pattern, $sidebar, $matches1);
preg_match_all("/has_permission\(['\"]([^'\"]+)['\"]\)/", $nav_sms, $matches2);

$perms = array_merge($matches1[1], $matches2[1]);
$perms = array_unique($perms);
sort($perms);

header('Content-Type: text/plain');
echo "Permissions used in sidebar/_nav_sms:\n";
foreach ($perms as $p) {
    echo "- $p\n";
}
