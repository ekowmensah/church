<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('manage_menu_items')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}
?>
require_once '../config/config.php';

echo "<h2>Setting up Menu Management</h2>";

try {
    // Add permission for menu management
    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (name, description) VALUES (?, ?)");
    $stmt->bind_param('ss', $name, $desc);
    $name = 'manage_menu_items';
    $desc = 'Manage menu items (create, edit, delete, reorder)';
    $stmt->execute();
    echo "✓ Added permission: manage_menu_items<br>";
    $stmt->close();

    // Add menu item for Menu Management
    $stmt = $conn->prepare("INSERT IGNORE INTO menu_items (label, url, icon, menu_group, permission_name, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssii', $label, $url, $icon, $group, $perm, $sort, $active);
    $label = 'Menu Management';
    $url = 'views/menu_management.php';
    $icon = 'fas fa-bars';
    $group = 'System';
    $perm = 'manage_menu_items';
    $sort = 1;
    $active = 1;
    $stmt->execute();
    echo "✓ Added menu item: Menu Management<br>";
    $stmt->close();

    // Assign the permission to Super Admin role (role_id=1)
    $stmt = $conn->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions WHERE name = ?");
    $stmt->bind_param('s', $perm_name);
    $perm_name = 'manage_menu_items';
    $stmt->execute();
    echo "✓ Assigned permission to Super Admin role<br>";
    $stmt->close();

    echo "<br><strong>Menu Management setup completed successfully!</strong><br>";
    echo "<a href='views/menu_management.php'>Go to Menu Management</a>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
