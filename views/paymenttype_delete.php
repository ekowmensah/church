<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_paymenttype')) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected delete form.');
}
$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    $stmt = $conn->prepare("DELETE FROM payment_types WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
}
header('Location: paymenttype_list.php?deleted=1');
exit;
