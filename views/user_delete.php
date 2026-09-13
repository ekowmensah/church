<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$allowed = in_array(1, $roleIds, true) || has_permission('delete_user');
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
$stmt = $conn->prepare(
    'SELECT EXISTS(SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1 AND is_active = 1) AS is_super_admin'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$isTargetSuperAdmin = (bool) $stmt->get_result()->fetch_assoc()['is_super_admin'];
$stmt->close();
if ($isTargetSuperAdmin) {
    http_response_code(409);
    exit('Super Administrator accounts cannot be deleted here.');
}

// Database cascades remove role provenance and access-audit entries. The linked
// member is retained because users.member_id points to members, not vice versa.
$stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
$stmt->bind_param('i', $userId);
$stmt->execute();
$deleted = $stmt->affected_rows;
$stmt->close();
if ($deleted !== 1) {
    http_response_code(404);
    exit('User account not found.');
}
header('Location: user_list.php?deleted=1');
exit;
