<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Canonical permission check for Audit Form
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!has_permission('user_audit')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}
// Audit log create/edit form view
?>
