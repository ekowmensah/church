<?php
// Cross-reference permissions in DB vs sidebar/menu code

// 1. Get sidebar/_nav_sms permissions
$sidebar = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$nav_sms = file_exists(__DIR__ . '/../includes/_nav_sms.php') ? file_get_contents(__DIR__ . '/../includes/_nav_sms.php') : '';

preg_match_all("/show_if_permitted\(['\"]([^'\"]+)['\"]\)/", $sidebar, $matches1);
preg_match_all("/has_permission\(['\"]([^'\"]+)['\"]\)/", $nav_sms, $matches2);
$sidebar_perms = array_unique(array_merge($matches1[1], $matches2[1]));

// 2. Get DB permissions
require_once __DIR__ . '/../config/config.php';
$res = $conn->query("SELECT name FROM permissions");
$db_perms = [];
while ($row = $res->fetch_assoc()) {
    $db_perms[] = $row['name'];
}
$db_perms = array_unique($db_perms);

// 3. Calculate differences
$missing_in_db = array_diff($sidebar_perms, $db_perms);
$dangling_in_db = array_diff($db_perms, $sidebar_perms);

// 4. Output
header('Content-Type: text/plain');
echo "=== Permissions used in sidebar/menu but MISSING in DB ===\n";
foreach ($missing_in_db as $p) echo "- $p\n";
if (empty($missing_in_db)) echo "(none)\n";

echo "\n=== Permissions in DB but NOT USED in sidebar/menu ===\n";
foreach ($dangling_in_db as $p) echo "- $p\n";
if (empty($dangling_in_db)) echo "(none)\n";
