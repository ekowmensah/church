<?php
require_once '../config/config.php';
require_once '../helpers/permissions.php';
//session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check permissions
$is_super_admin = isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3;
if (!$is_super_admin && !has_permission('manage_menu_items')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

// Debug: Log all POST data
error_log("DEBUG: POST data: " . print_r($_POST, true));

$action = $_REQUEST['action'] ?? '';

if ($action === 'update') {
    updateMenuItem();
} else {
    echo json_encode(['success' => false, 'message' => 'Only update action supported in debug']);
}

function updateMenuItem() {
    global $conn;
    
    // Debug: Log received data
    $debug_data = [
        'id' => $_POST['id'] ?? 'NOT SET',
        'label' => $_POST['label'] ?? 'NOT SET',
        'url' => $_POST['url'] ?? 'NOT SET',
        'icon' => $_POST['icon'] ?? 'NOT SET',
        'menu_group' => $_POST['menu_group'] ?? 'NOT SET',
        'permission_name' => $_POST['permission_name'] ?? 'NOT SET',
        'sort_order' => $_POST['sort_order'] ?? 'NOT SET',
        'is_active' => $_POST['is_active'] ?? 'NOT SET'
    ];
    error_log("DEBUG: Received data: " . print_r($debug_data, true));
    
    $id = intval($_POST['id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $menu_group = trim($_POST['menu_group'] ?? '');
    $permission_name = trim($_POST['permission_name'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0; // Fix checkbox handling
    
    // Debug: Log processed data
    $processed_data = [
        'id' => $id,
        'label' => $label,
        'url' => $url,
        'icon' => $icon,
        'menu_group' => $menu_group,
        'permission_name' => $permission_name,
        'sort_order' => $sort_order,
        'is_active' => $is_active
    ];
    error_log("DEBUG: Processed data: " . print_r($processed_data, true));
    
    // Validation
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid menu item ID: ' . $id]);
        return;
    }
    
    if (empty($label) || empty($url) || empty($menu_group)) {
        echo json_encode(['success' => false, 'message' => 'Required fields are missing. Label: ' . $label . ', URL: ' . $url . ', Group: ' . $menu_group]);
        return;
    }
    
    // Check if permission exists (only if permission_name is provided)
    if (!empty($permission_name)) {
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = ?");
        $stmt->bind_param('s', $permission_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Invalid permission name: ' . $permission_name]);
            return;
        }
        $stmt->close();
    }
    
    // Debug: Check if menu item exists
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Menu item not found with ID: ' . $id]);
        return;
    }
    $existing = $result->fetch_assoc();
    $stmt->close();
    
    error_log("DEBUG: Existing menu item: " . print_r($existing, true));
    
    // Update menu item - Fix the bind_param types
    $stmt = $conn->prepare("UPDATE menu_items SET label = ?, url = ?, icon = ?, menu_group = ?, permission_name = ?, sort_order = ?, is_active = ? WHERE id = ?");
    
    // Correct parameter types: s=string, i=integer
    $stmt->bind_param('sssssiil', $label, $url, $icon, $menu_group, $permission_name, $sort_order, $is_active, $id);
    
    error_log("DEBUG: About to execute UPDATE query");
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
        error_log("DEBUG: Query executed successfully. Affected rows: " . $affected_rows);
        
        if ($affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Menu item updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No changes made (data might be identical)']);
        }
    } else {
        $error = $stmt->error;
        error_log("DEBUG: Query execution failed: " . $error);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $error]);
    }
    $stmt->close();
}
?>
