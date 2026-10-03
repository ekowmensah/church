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
if (!is_super_admin() && !has_permission('manage_menu_items')) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

header('Location: ' . BASE_URL . '/views/menu_management.php');
exit;
