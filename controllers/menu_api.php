<?php
require_once '../config/config.php';
require_once '../helpers/permissions.php';
session_start();

// Check permissions
$is_super_admin = isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3;
if (!$is_super_admin && !has_permission('manage_menu_items')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'create':
        createMenuItem();
        break;
    case 'get':
        getMenuItem();
        break;
    case 'update':
        updateMenuItem();
        break;
    case 'delete':
        deleteMenuItem();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

function createMenuItem() {
    global $conn;
    
    $label = trim($_POST['label'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $menu_group = trim($_POST['menu_group'] ?? '');
    $permission_name = trim($_POST['permission_name'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 1);
    $is_active = intval($_POST['is_active'] ?? 1);
    
    // Validation
    if (empty($label) || empty($url) || empty($menu_group)) {
        echo json_encode(['success' => false, 'message' => 'Required fields are missing']);
        return;
    }
    
    // Check if permission exists (only if permission_name is provided)
    if (!empty($permission_name)) {
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = ?");
        $stmt->bind_param('s', $permission_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Invalid permission name']);
            return;
        }
        $stmt->close();
    }
    
    // Insert menu item
    $stmt = $conn->prepare("INSERT INTO menu_items (label, url, icon, menu_group, permission_name, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('sssssii', $label, $url, $icon, $menu_group, $permission_name, $sort_order, $is_active);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Menu item created successfully', 'id' => $conn->insert_id]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
}

function getMenuItem() {
    global $conn;
    
    $id = intval($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid menu item ID']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT * FROM menu_items WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Menu item not found']);
    }
    $stmt->close();
}

function updateMenuItem() {
    global $conn;
    
    $id = intval($_POST['id'] ?? 0);
    $label = trim($_POST['label'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $menu_group = trim($_POST['menu_group'] ?? '');
    $permission_name = trim($_POST['permission_name'] ?? '');
    $sort_order = intval($_POST['sort_order'] ?? 1);
    $is_active = isset($_POST['is_active']) && $_POST['is_active'] == '1' ? 1 : 0;
    
    // Validation
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid menu item ID']);
        return;
    }
    
    if (empty($label) || empty($url) || empty($menu_group)) {
        echo json_encode(['success' => false, 'message' => 'Required fields are missing']);
        return;
    }
    
    // Check if permission exists (only if permission_name is provided)
    if (!empty($permission_name)) {
        $stmt = $conn->prepare("SELECT id FROM permissions WHERE name = ?");
        $stmt->bind_param('s', $permission_name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Invalid permission name']);
            return;
        }
        $stmt->close();
    }
    
    // Update menu item
    $stmt = $conn->prepare("UPDATE menu_items SET label = ?, url = ?, icon = ?, menu_group = ?, permission_name = ?, sort_order = ?, is_active = ? WHERE id = ?");
    $stmt->bind_param('sssssiii', $label, $url, $icon, $menu_group, $permission_name, $sort_order, $is_active, $id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Menu item updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'No changes made or menu item not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
}

function deleteMenuItem() {
    global $conn;
    
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid menu item ID']);
        return;
    }
    
    // Check if menu item exists
    $stmt = $conn->prepare("SELECT label FROM menu_items WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Menu item not found']);
        return;
    }
    $stmt->close();
    
    // Delete menu item
    $stmt = $conn->prepare("DELETE FROM menu_items WHERE id = ?");
    $stmt->bind_param('i', $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Menu item deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]);
    }
    $stmt->close();
}
?>
