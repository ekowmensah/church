<?php
// Seeder for assigning the new comprehensive permissions to roles, based on your mapping
require_once __DIR__.'/../config/config.php';

// Map role names to permissions (edit as needed for your policy)
$roles_permissions = [
    'Super Admin' => 'ALL', // gets all permissions automatically
    'Admin' => [
        'access_dashboard',
        'Payment Statistics', 'Class health report', 'Organisational health report', 'Withdrawals',
        'Registration', 'Add Admin', 'Additions', 'Payment', 'Reports', 'sms_templates', 'send_bulk_sms', 'manage_sms_templates', 'bulk_sms'
    ],
    'Steward' => [
        'access_dashboard',
        'Payment Statistics', 'Withdrawals', 'Registration', 'Payment', 'Reports', 'bulk_sms'
    ],
    'Rev. Ministers' => [
        'Individual statement', 'Class payment report', 'Organizational payment report',
        'Class type report', 'Organizational type report'
    ],
    'Class Leader' => [
        'access_dashboard', 'Payment Statistics', 'Registered List', 'Manage Members', 'Register Member',
        'Payments', 'Reports', 'Class members report', 'Class payment report', 'Individual payment report',
        'Zero payment report', 'Total payment report'
    ],
    'Organisational Leader' => [
        'access_dashboard', 'Payment Statistics', 'Registered List', 'Manage Members', 'Register Member',
        'Payments', 'Reports', 'Organizational members report', 'Organizational payment report',
        'Individual payment report', 'Zero payment report', 'Total payment report'
    ],
    'Cashier' => [
        'access_dashboard', 'Payment Statistics', 'Registered List', 'Payment', 'Make Payment', 'Payment History'
    ],
    'Health' => [
        'access_dashboard', 'Health Statistics', 'Registered List', 'Records', 'Enter records', 'Record history'
    ],
    'sms_provider_settings' => [
        'access_dashboard', 'sms_provider_settings', 'manage_sms_templates', 'send_bulk_sms', 'bulk_sms'
    ],
];

// Debug file for writing all output
$debug_file = __DIR__.'/../seed_role_debug.txt';
file_put_contents($debug_file, "\n--- roles_permissions array ---\n".var_export($roles_permissions, true)."\n", 0);
$role_ids = [];
$res = $conn->query("SELECT id, name FROM roles");
file_put_contents($debug_file, "\n--- Role IDs ---\n", FILE_APPEND);
while ($row = $res->fetch_assoc()) {
    file_put_contents($debug_file, "Role: {$row['name']} (id: {$row['id']})\n", FILE_APPEND);
    $role_ids[$row['name']] = $row['id'];
}

file_put_contents($debug_file, "\n--- Permission IDs ---\n", FILE_APPEND);
$perm_ids = [];
$res = $conn->query("SELECT id, name FROM permissions");
while ($row = $res->fetch_assoc()) {
    file_put_contents($debug_file, "Perm: {$row['name']} (id: {$row['id']})\n", FILE_APPEND);
    $perm_ids[$row['name']] = $row['id'];
}
file_put_contents($debug_file, "\n--- role_ids array ---\n".var_export($role_ids, true)."\n", FILE_APPEND);

// Remove all old role_permissions
$conn->query('DELETE FROM role_permissions');

// Assign permissions to roles
foreach ($roles_permissions as $role => $perms) {
    file_put_contents($debug_file, "\nAssigning permissions for role: $role (role_id: ".$role_ids[$role].")\n", FILE_APPEND);
    file_put_contents($debug_file, "  [DEBUG] perms for $role: ".var_export($perms, true)." (type: ".gettype($perms).")\n", FILE_APPEND);
    if (!isset($role_ids[$role])) {
        file_put_contents($debug_file, "[WARN] Role '$role' not found in roles table.\n", FILE_APPEND);
        continue;
    }
    $role_id = $role_ids[$role];
    if ($perms === 'ALL') {
        file_put_contents($debug_file, "  [DEBUG] (ALL branch) perms for $role: ".var_export($perms, true)." (type: ".gettype($perms).")\n", FILE_APPEND);
        if ($role !== 'Super Admin') {
            file_put_contents($debug_file, "[ERROR] Role '$role' is being assigned ALL permissions! Skipping.\n", FILE_APPEND);
            continue;
        }
        // Assign all permissions
        foreach ($perm_ids as $perm_name => $perm_id) {
            file_put_contents($debug_file, "Assigning ALL permission: $perm_name (id: $perm_id) to role_id: $role_id\n", FILE_APPEND);
            $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $role_id, $perm_id);
            $stmt->execute();
        }
    } else if (is_array($perms)) {
        file_put_contents($debug_file, "  [DEBUG] (array branch) perms for $role: ".var_export($perms, true)." (type: ".gettype($perms).")\n", FILE_APPEND);
        foreach ($perms as $perm) {
            if (!isset($perm_ids[$perm])) {
                file_put_contents($debug_file, "[WARN] Permission '$perm' not found in permissions table.\n", FILE_APPEND);
                continue;
            }
            $perm_id = $perm_ids[$perm];
            file_put_contents($debug_file, "Assigning permission: $perm (id: $perm_id) to role_id: $role_id\n", FILE_APPEND);
            $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $role_id, $perm_id);
            $stmt->execute();
        }
    } else {
        file_put_contents($debug_file, "[ERROR] Permissions for role '$role' are not an array or 'ALL'. Skipping. perms value: ".var_export($perms, true)." type: ".gettype($perms)."\n", FILE_APPEND);
        continue;
    }
}
echo "Role-permission assignments updated for all roles.\n";
