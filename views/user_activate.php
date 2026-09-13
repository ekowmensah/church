<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$allowed = in_array(1, $roleIds, true) || has_permission('activate_user') || has_permission('edit_user');
if (!is_logged_in() || !$allowed) {
    http_response_code(403);
    exit('You do not have permission to activate users.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('A valid form submission is required.');
}
$userId = (int) ($_POST['id'] ?? 0);
$stmt = $conn->prepare(
    "SELECT user_account.status, member.status AS member_status,
            EXISTS(SELECT 1 FROM user_roles WHERE user_id = user_account.id AND is_active = 1) AS has_role
       FROM users user_account
       JOIN members member ON member.id = user_account.member_id
      WHERE user_account.id = ? LIMIT 1"
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$account = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$account) {
    http_response_code(404);
    exit('User account not found.');
}
if ($account['member_status'] !== 'active') {
    http_response_code(409);
    exit('Activate the linked membership before activating back-office access.');
}
if (!(int) $account['has_role']) {
    http_response_code(409);
    exit('Assign mapped or manual access before activating this account.');
}
$stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();
header('Location: user_list.php?activated=1');
exit;
