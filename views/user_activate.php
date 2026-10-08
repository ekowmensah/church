<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/UserAccessGovernanceService.php';

$allowed = is_super_admin() || has_permission('activate_user') || has_permission('edit_user');
if (!is_logged_in() || !$allowed) {
    http_response_code(403);
    exit('You do not have permission to activate users.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('A valid form submission is required.');
}
$userId = (int) ($_POST['id'] ?? 0);
$activateLinkedMembership = (string) ($_POST['activate_linked_member'] ?? '') === '1';
try {
    $permission = is_super_admin() || has_permission('activate_user')
        ? 'activate_user'
        : 'edit_user';
    (new UserAccessGovernanceService($conn))->changeStatus(
        $userId,
        'active',
        (int) ($_SESSION['user_id'] ?? 0),
        $permission,
        $activateLinkedMembership
            ? 'Linked membership activation and the back-office access prerequisite were reconciled through the governed user administration workflow.'
            : 'User access activated from the user administration workspace.',
        'activated',
        $activateLinkedMembership
    );
} catch (UserAccessAuthorizationException $exception) {
    http_response_code(403);
    exit('You are not authorized to complete this activation.');
} catch (mysqli_sql_exception $exception) {
    error_log('Governed user activation failed: ' . $exception->getMessage());
    $_SESSION['flash_error'] = 'The activation could not be completed. Review the account prerequisites and try again.';
    header('Location: user_list.php?activation=failed');
    exit;
} catch (InvalidArgumentException | RuntimeException $exception) {
    $_SESSION['flash_error'] = $exception->getMessage();
    header('Location: user_list.php?activation=failed');
    exit;
} catch (Throwable $exception) {
    error_log('Unexpected governed user activation failure: ' . $exception->getMessage());
    $_SESSION['flash_error'] = 'The activation could not be completed safely.';
    header('Location: user_list.php?activation=failed');
    exit;
}
header('Location: user_list.php?activated=1' . ($activateLinkedMembership ? '&membership_activated=1' : ''));
exit;
