<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!has_permission('manage_visitors')) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this resource.');
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM visitors WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        header('Location: visitor_list.php?deleted=1');
        exit;
    } else {
        header('Location: visitor_list.php?error=notfound');
        exit;
    }
} else {
    header('Location: visitor_list.php?error=notfound');
    exit;
}
