<?php
// Backward-compatible route for older links. The canonical endpoint performs
// authentication, CSRF validation, church scoping and retry audit logging.
if (!isset($_POST['id']) && isset($_POST['log_id'])) {
    $_POST['id'] = $_POST['log_id'];
}
require __DIR__ . '/ajax_resend_sms.php';
