<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/payment_report_context.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_payment')) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected delete form.');
}
$id = (int) ($_POST['id'] ?? 0);
if ($id) {
    $churchId = payment_report_current_church_id($conn);
    $stmt = $conn->prepare(is_super_admin()
        ? 'DELETE FROM payments WHERE id = ?'
        : 'DELETE FROM payments WHERE id = ? AND church_id = ?');
    if (is_super_admin()) $stmt->bind_param('i', $id); else $stmt->bind_param('ii', $id, $churchId);
    $stmt->execute();
}
header('Location: payment_list.php?deleted=1');
exit;
