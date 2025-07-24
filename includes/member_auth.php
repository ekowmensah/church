<?php
// member_auth.php: Protects member-only pages and invalidates session if member is deleted or inactive
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
global $conn;
// Allow super admin to bypass member session check
if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) {
    return;
}
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
$stmt = $conn->prepare('SELECT status FROM members WHERE id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
if (!$m || (isset($m['status']) && strtolower($m['status']) != 'active')) {
    session_unset();
    session_destroy();
    header('Location: ' . BASE_URL . '/login.php?expired=1');
    exit;
}
