<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow users with a specific permission
if (!has_permission('view_health_type_report')) {
    header('Location: '.BASE_URL.'/login.php');
    exit;
}
?>