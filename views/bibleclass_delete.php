<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/church_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_bibleclass')) {
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
    header('Location: bibleclass_list.php?error=invalid');
    exit;
}
$churchId = (int) get_user_church_id($conn);
$scopeStmt = $conn->prepare(is_super_admin()
    ? 'SELECT id FROM bible_classes WHERE id = ? LIMIT 1'
    : 'SELECT id FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
if (is_super_admin()) $scopeStmt->bind_param('i', $id); else $scopeStmt->bind_param('ii', $id, $churchId);
$scopeStmt->execute();
$allowedClass = $scopeStmt->get_result()->fetch_assoc();
$scopeStmt->close();
if (!$allowedClass) {
    http_response_code(404);
    exit('Bible class not found.');
}
// Check for related member_transfers
$stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM member_transfers WHERE from_class_id = ? OR to_class_id = ?');
$stmt->bind_param('ii', $id, $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
if ($row && $row['cnt'] > 0) {
    echo '<div style="max-width:600px;margin:60px auto;padding:32px;border:1px solid #eee;background:#fff;color:#b94a48;font-size:18px;text-align:center;">
    <b>Cannot delete:</b> This Bible Class is referenced in one or more member transfers.<br><br>
    Please remove or update those transfers before deleting this class.<br><br>
    <a href="bibleclass_list.php" style="color:#31708f;text-decoration:underline;">&larr; Back to Bible Class List</a>
    </div>';
    exit;
}
// No dependencies, safe to delete
$stmt = $conn->prepare('DELETE FROM bible_classes WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
header('Location: bibleclass_list.php?deleted=1');
exit;
