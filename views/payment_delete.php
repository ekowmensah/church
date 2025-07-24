<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (function_exists('has_permission') && !has_permission('delete_payment')) {
        die('No permission to delete payment');
    }
}

$id = intval($_GET['id'] ?? 0);
if ($id) {
    $stmt = $conn->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}
header('Location: payment_list.php?deleted=1');
exit;
