<?php
session_start();
require_once __DIR__.'/helpers/global_audit_log.php';
if (isset($_SESSION['user_id'])) {
    log_activity('logout', 'user', $_SESSION['user_id'], json_encode(['ip'=>$_SERVER['REMOTE_ADDR'], 'time'=>'2025-07-14T16:56:25Z']));
}
session_unset();
session_destroy();
header('Location: login.php');
exit;
?>
