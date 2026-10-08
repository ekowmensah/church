<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/UserAccessGovernanceService.php';

$allowed = is_super_admin() || has_permission('deactivate_user') || has_permission('edit_user');
if (!is_logged_in() || !$allowed) {
    http_response_code(403);
    exit('You do not have permission to deactivate users.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('A valid form submission is required.');
}
$userId = (int) ($_POST['id'] ?? 0);
try {
    $permission = is_super_admin() || has_permission('deactivate_user')
        ? 'deactivate_user'
        : 'edit_user';
    (new UserAccessGovernanceService($conn))->changeStatus(
        $userId,
        'inactive',
        (int) ($_SESSION['user_id'] ?? 0),
        $permission,
        'User access deactivated from the user administration workspace.',
        'deactivated'
    );
} catch (Throwable $exception) {
    http_response_code($exception instanceof UserAccessAuthorizationException
        ? 403
        : ($exception->getMessage() === 'User account not found.' ? 404 : 409));
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
header('Location: user_list.php?deactivated=1');
exit;
