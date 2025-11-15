<?php
require_once __DIR__ . '/../../config/config.php';

echo "Cleaning up partial migration 004...\n";

// Check and remove columns from role_permissions if they exist
$columns_rp = ['id', 'granted_by', 'granted_at', 'expires_at', 'conditions', 'is_active'];
foreach ($columns_rp as $col) {
    $result = $conn->query("SHOW COLUMNS FROM role_permissions LIKE '$col'");
    if ($result->num_rows > 0) {
        echo "  Removing column role_permissions.$col\n";
        if ($col === 'granted_by') {
            // Remove foreign key first
            $conn->query("ALTER TABLE role_permissions DROP FOREIGN KEY fk_rp_granted_by");
        }
        $conn->query("ALTER TABLE role_permissions DROP COLUMN $col");
    }
}

// Check and remove columns from user_roles if they exist
$columns_ur = ['id', 'assigned_by', 'assigned_at', 'expires_at', 'is_primary', 'is_active'];
foreach ($columns_ur as $col) {
    $result = $conn->query("SHOW COLUMNS FROM user_roles LIKE '$col'");
    if ($result->num_rows > 0) {
        echo "  Removing column user_roles.$col\n";
        if ($col === 'assigned_by') {
            // Remove foreign key first
            $conn->query("ALTER TABLE user_roles DROP FOREIGN KEY fk_ur_assigned_by");
        }
        $conn->query("ALTER TABLE user_roles DROP COLUMN $col");
    }
}

// Remove indexes from role_permissions if they exist
$indexes_rp = ['idx_granted_by', 'idx_granted_at', 'idx_expires', 'idx_rp_active'];
foreach ($indexes_rp as $idx) {
    $result = $conn->query("SHOW INDEX FROM role_permissions WHERE Key_name = '$idx'");
    if ($result->num_rows > 0) {
        echo "  Removing index role_permissions.$idx\n";
        $conn->query("ALTER TABLE role_permissions DROP INDEX $idx");
    }
}

// Remove indexes from user_roles if they exist
$indexes_ur = ['idx_assigned_by', 'idx_assigned_at', 'idx_expires', 'idx_primary', 'idx_ur_active'];
foreach ($indexes_ur as $idx) {
    $result = $conn->query("SHOW INDEX FROM user_roles WHERE Key_name = '$idx'");
    if ($result->num_rows > 0) {
        echo "  Removing index user_roles.$idx\n";
        $conn->query("ALTER TABLE user_roles DROP INDEX $idx");
    }
}

echo "\nCleanup complete. Ready to re-run migration 004.\n";
