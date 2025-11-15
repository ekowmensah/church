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
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}
?>
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/admin_auth.php';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id) {
    $stmt = $conn->prepare('DELETE FROM sunday_school WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}
header('Location: sundayschool_list.php');
exit;
