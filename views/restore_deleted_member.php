<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/MemberLifecycleService.php';

if (!is_logged_in() || ((int) ($_SESSION['role_id'] ?? 0) !== 1 && !has_permission('restore_deleted_member'))) {
    http_response_code(403);
    die('You do not have permission to restore archived members.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    header('Location: deleted_members_list.php?error=' . urlencode('Use the restore form with a valid session token.'));
    exit;
}
try {
    MemberLifecycleService::fromSession($conn)->restoreMember(
        (int) ($_POST['id'] ?? 0),
        (string) ($_POST['reason'] ?? '')
    );
    header('Location: deleted_members_list.php?info=' . urlencode('Member restored as pending; the reason was recorded.'));
} catch (Throwable $e) {
    header('Location: deleted_members_list.php?error=' . urlencode($e->getMessage()));
}
exit;
