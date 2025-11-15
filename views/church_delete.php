<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('delete_church')) {
        http_response_code(403);
        include '../views/errors/403.php';
        exit;
    }
}
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid church ID.');
}
$id = intval($_GET['id']);
$stmt = $conn->prepare('DELETE FROM churches WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
header('Location: church_list.php?deleted=1');
exit;
