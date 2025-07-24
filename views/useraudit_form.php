<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Canonical permission check for User Audit Add/Edit
if (!has_permission('user_audit')) {
    http_response_code(403);
    exit('Forbidden: You do not have permission to access this resource.');
}
?>
<!-- useraudit_form.php: Add/Edit User Audit Log Form Scaffold -->
<form method="post">
  <!-- TODO: Add user audit fields -->
</form>
