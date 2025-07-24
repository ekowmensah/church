<?php
// Restrict access to admin-only pages
require_once __DIR__.'/../config/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Only allow users with admin or super admin role (role_id 1 or 2, adjust as needed)
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1,2])) {
    header('Location: '.BASE_URL.'/login.php');
    exit;
}
?>
