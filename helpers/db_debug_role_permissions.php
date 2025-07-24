<?php
require_once __DIR__ . '/../config/config.php';

$role_id = 1; // Super Admin
$res = $conn->query("SELECT p.name FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = $role_id");
$has_access_dashboard = false;
echo "Permissions for Super Admin (role_id=1):\n";
while ($row = $res->fetch_assoc()) {
    echo "- {$row['name']}\n";
    if ($row['name'] === 'access_dashboard') $has_access_dashboard = true;
}
echo "access_dashboard present: ".($has_access_dashboard ? 'YES' : 'NO')."\n";
?>
