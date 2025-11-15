<?php
// Edit member redirects to member_form.php in edit mode
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Permission check for managing members
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('manage_members')) {
        die('No permission to edit members.');
    }
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id > 0) {
    header('Location: member_form.php?id=' . $id);
    exit;
} else {
    header('Location: member_list.php');
    exit;
}
