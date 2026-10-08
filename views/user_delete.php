<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/UserAccessGovernanceService.php';

$allowed = is_super_admin() || has_permission('delete_user');
if (!is_logged_in() || !$allowed) {
    http_response_code(403);
    exit('You do not have permission to delete user access.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('A valid form submission is required.');
}
$userId = (int) ($_POST['id'] ?? 0);
if ($userId < 1 || $userId === (int) ($_SESSION['user_id'] ?? 0)) {
    http_response_code(409);
    exit('The current user account cannot be deleted.');
}
try {
    // Account identities and role provenance are evidence. "Delete" therefore
    // retires access without erasing the account or its assignment history.
    (new UserAccessGovernanceService($conn))->changeStatus(
        $userId,
        'inactive',
        (int) ($_SESSION['user_id'] ?? 0),
        'delete_user',
        'User access retired from the user administration workspace.',
        'retired'
    );
} catch (Throwable $exception) {
    http_response_code($exception instanceof UserAccessAuthorizationException
        ? 403
        : ($exception->getMessage() === 'User account not found.' ? 404 : 409));
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
header('Location: user_list.php?retired=1');
exit;
