<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (function_exists('has_permission') && !has_permission('delete_classgroup')) {
        die('No permission to delete class group');
    }
}
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Invalid class group ID.');
}
$id = intval($_GET['id']);
$stmt = $conn->prepare('DELETE FROM class_groups WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
header('Location: classgroup_list.php?deleted=1');
exit;
