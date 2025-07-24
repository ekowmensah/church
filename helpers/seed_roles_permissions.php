<?php
// Script to seed roles and permissions into the database
require_once __DIR__.'/../config/config.php';

// --- FULL RESET: Clean up users, then truncate roles, permissions, and role_permissions ---
$conn->query('UPDATE users SET role_id = NULL');
$conn->query('DELETE FROM role_permissions');
$conn->query('DELETE FROM roles');
$conn->query('DELETE FROM permissions');
$conn->query('ALTER TABLE roles AUTO_INCREMENT = 1');
$conn->query('ALTER TABLE permissions AUTO_INCREMENT = 1');

// Define roles and permissions (from user mapping)
$roles = [
    'Super Admin', 'Admin', 'Steward', 'Rev. Ministers', 'Class Leader', 'Organisational Leader', 'Cashier', 'Health'
];

$permissions = [
    'access_dashboard', // NEW permission for dashboard routing
    'Payment Statistics', 'Class health report', 'Organisational health report', 'Withdrawals',
    'Registration', 'Add Admin', 'Additions', 'Transfer member', 'Church attendance', 'Payment',
    'Overall Payment Statistics', 'Church service', 'Reports', 'Bulk SMS', 'Activities log',
    'Individual statement', 'Class payment report', 'Organizational payment report',
    'Class type report', 'Organizational type report', 'Registered List', 'Manage Members', 'Register Member',
    'Payments', 'Class members report', 'Individual payment report', 'Zero payment report', 'Total payment report',
    'Organizational members report', 'Make Payment', 'Payment History', 'Health Statistics', 'Records', 'Enter records', 'Record history'
];

// Super Admin gets all permissions automatically
$roles_permissions = [
    'Super Admin' => $permissions,
    'Admin' => [
        'access_dashboard',
        'Payment Statistics', 'Class health report', 'Organisational health report', 'Withdrawals',
        'Registration', 'Add Admin', 'Additions', 'Payment', 'Reports', 'Bulk SMS'
    ],
    'Steward' => [
        'access_dashboard',
        'Payment Statistics', 'Withdrawals', 'Registration', 'Payment', 'Reports', 'Bulk SMS'
    ],
    'Rev. Ministers' => [
        'access_dashboard',
        'Individual statement', 'Class payment report', 'Organizational payment report',
        'Class type report', 'Organizational type report'
    ],
    'Class Leader' => [
        'access_dashboard',
        'Payment Statistics', 'Registered List', 'Manage Members', 'Register Member', 'Payments', 'Reports',
        'Class members report', 'Class payment report', 'Individual payment report', 'Zero payment report', 'Total payment report'
    ],
    'Organisational Leader' => [
        'access_dashboard',
        'Payment Statistics', 'Registered List', 'Manage Members', 'Register Member', 'Payments', 'Reports',
        'Organizational members report', 'Organizational payment report', 'Individual payment report', 'Zero payment report', 'Total payment report'
    ],
    'Cashier' => [
        'access_dashboard',
        'Payment Statistics', 'Registered List', 'Payment', 'Make Payment', 'Payment History'
    ],
    'Health' => [
        'access_dashboard',
        'Health Statistics', 'Registered List', 'Records', 'Enter records', 'Record history'
    ]
];

// Insert permissions
foreach ($permissions as $perm) {
    $stmt = $conn->prepare("INSERT INTO permissions (name) VALUES (?)");
    $stmt->bind_param('s', $perm);
    $stmt->execute();
}

// Insert roles
foreach ($roles as $role) {
    $stmt = $conn->prepare("INSERT INTO roles (name) VALUES (?)");
    $stmt->bind_param('s', $role);
    $stmt->execute();
}

// Map role names to IDs
$role_ids = [];
$res = $conn->query("SELECT id, name FROM roles");
while ($row = $res->fetch_assoc()) {
    $role_ids[$row['name']] = $row['id'];
}

// Map permission names to IDs
$perm_ids = [];
$res = $conn->query("SELECT id, name FROM permissions");
while ($row = $res->fetch_assoc()) {
    $perm_ids[$row['name']] = $row['id'];
}

// Insert role_permissions
foreach ($roles_permissions as $role => $perms) {
    if (!isset($role_ids[$role])) {
        continue;
    }
    $role_id = $role_ids[$role];
    foreach ($perms as $perm) {
        if (!isset($perm_ids[$perm])) {
            continue;
        }
        $perm_id = $perm_ids[$perm];
        $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $role_id, $perm_id);
        $stmt->execute();

    }
}
echo "Roles and permissions seeded successfully.";
?>
