<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/church_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_sundayschool')) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected delete form.');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $churchId = (int) get_user_church_id($conn);
    $stmt = $conn->prepare(is_super_admin()
        ? 'DELETE FROM sunday_school WHERE id = ?'
        : 'DELETE FROM sunday_school WHERE id = ? AND church_id = ?');
    if (is_super_admin()) {
        $stmt->bind_param('i', $id);
    } else {
        $stmt->bind_param('ii', $id, $churchId);
    }
    $stmt->execute();
    $stmt->close();
}

header('Location: sundayschool_list.php?deleted=1');
exit;
