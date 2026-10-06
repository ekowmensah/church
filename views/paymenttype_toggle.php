<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('edit_paymenttype')) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected status form.');
}
$id = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
if ($id && in_array($action, ['enable', 'disable'])) {
    $active = ($action === 'enable') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE payment_types SET active = ? WHERE id = ?");
    $stmt->bind_param('ii', $active, $id);
    $stmt->execute();
}
header('Location: paymenttype_list.php?updated=1');
exit;
