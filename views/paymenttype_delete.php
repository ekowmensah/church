<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('delete_paymenttype')) {
        die('No permission to delete payment type');
    }
}

$id = intval($_GET['id'] ?? 0);
if ($id) {
    $stmt = $conn->prepare("DELETE FROM payment_types WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}
header('Location: paymenttype_list.php?deleted=1');
exit;
