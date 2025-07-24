<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (function_exists('has_permission') && !has_permission('edit_paymenttype')) {
        die('No permission to modify payment type');
    }
}

$id = intval($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
if ($id && in_array($action, ['enable', 'disable'])) {
    $active = ($action === 'enable') ? 1 : 0;
    $stmt = $conn->prepare("UPDATE payment_types SET active = ? WHERE id = ?");
    $stmt->bind_param('ii', $active, $id);
    $stmt->execute();
}
header('Location: paymenttype_list.php?updated=1');
exit;
