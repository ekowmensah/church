<?php
// Authentication and authorization helpers
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';

function is_logged_in() {
    return isset($_SESSION['user_id']) || isset($_SESSION['member_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function enforce_required_password_change() {
    if (!isset($_SESSION['user_id']) || (int) $_SESSION['user_id'] <= 0
        || (int) ($_SESSION['must_change_password'] ?? 0) !== 1) {
        return;
    }

    $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($currentScript, ['change_user_password.php', 'logout.php'], true)) {
        return;
    }

    header('Location: ' . BASE_URL . '/views/change_user_password.php');
    exit;
}

function get_logged_in_user() {
    if (!is_logged_in()) return null;
    return [
        'user_id' => $_SESSION['user_id'],
        'role_id' => $_SESSION['role_id'],
        'name' => $_SESSION['name'],
        'email' => $_SESSION['email']
    ];
}

enforce_required_password_change();

?>
