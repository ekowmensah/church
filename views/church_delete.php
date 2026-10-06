<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_church')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected delete form.');
}
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: church_list.php?error=invalid');
    exit;
}
$stmt = $conn->prepare('DELETE FROM churches WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
header('Location: church_list.php?deleted=1');
exit;
