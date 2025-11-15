<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('delete_organization')) {
        die('No permission to delete organization');
    }
}

$id = intval($_GET['id'] ?? 0);
if ($id) {
    $stmt = $conn->prepare("DELETE FROM organizations WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}
header('Location: organization_list.php?deleted=1');
exit;
