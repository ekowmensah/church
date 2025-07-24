<?php
require_once __DIR__ . '/../config/config.php';
session_start();

if (!isset($_SESSION['role_id'])) {
    echo "Not logged in or no role_id set in session.\n";
    exit;
}
$role_id = $_SESSION['role_id'];

// Show current user role
$res = $conn->query("SELECT name FROM roles WHERE id = $role_id");
$role = $res && $res->num_rows ? $res->fetch_assoc()['name'] : 'Unknown';
echo "Current user role_id: $role_id ($role)\n";

// Show permissions for this role
$res = $conn->query("SELECT p.name FROM role_permissions rp JOIN permissions p ON rp.permission_id = p.id WHERE rp.role_id = $role_id");
$has_access_dashboard = false;
echo "Permissions for this role:\n";
while ($row = $res->fetch_assoc()) {
    echo "- {$row['name']}\n";
    if ($row['name'] === 'access_dashboard') $has_access_dashboard = true;
}
echo "access_dashboard present: ".($has_access_dashboard ? 'YES' : 'NO')."\n";
?>
