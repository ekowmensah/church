<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && (int) $_SESSION['user_id'] === 3)
    || (isset($_SESSION['role_id']) && (int) $_SESSION['role_id'] === 1);
if (!$is_super_admin && !has_permission('edit_member')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } elseif (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to edit members.</p></div>';
    }
    exit;
}

$memberId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($memberId <= 0) {
    header('Location: member_list.php');
    exit;
}

header('Location: complete_registration_admin.php?id=' . $memberId . '&admin_edit=1');
exit;
