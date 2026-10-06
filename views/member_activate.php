<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/church_helper.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';
if (!is_logged_in() || (!is_super_admin() && !has_permission('edit_member') && !has_permission('activate_member'))) {
    http_response_code(403);
    exit('Forbidden');
}

function resolve_activation_redirect(): string {
    $default = 'member_list.php';
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!$ref) {
        return $default;
    }
    $path = parse_url($ref, PHP_URL_PATH);
    if (!is_string($path)) {
        return $default;
    }
    $file = basename($path);
    if (in_array($file, ['pending_member_list.php', 'pending_members_list.php', 'member_list.php'], true)) {
        return $file;
    }
    return $default;
}

$redirect = resolve_activation_redirect();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected activation form.');
}
if (!isset($_POST['id'])) {
    $_SESSION['flash_error'] = 'Missing member ID.';
    header('Location: ' . $redirect);
    exit;
}
$member_id = (int) $_POST['id'];
$churchId = (int) get_user_church_id($conn);
$stmt = $conn->prepare(is_super_admin()
    ? 'SELECT id, class_id, status, deactivated_at FROM members WHERE id = ? LIMIT 1'
    : 'SELECT id, class_id, status, deactivated_at FROM members WHERE id = ? AND church_id = ? LIMIT 1');
if (is_super_admin()) $stmt->bind_param('i', $member_id); else $stmt->bind_param('ii', $member_id, $churchId);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
if (!$member) {
    $_SESSION['flash_error'] = 'Member not found.';
    header('Location: ' . $redirect);
    exit;
}
if ($member['status'] !== 'pending' && $member['status'] !== 'de-activated' && empty($member['deactivated_at'])) {
    $_SESSION['flash_error'] = 'Member is not eligible for activation.';
    header('Location: ' . $redirect);
    exit;
}

$target_class_id = (int) ($member['class_id'] ?? 0);
$capacity = bible_class_validate_capacity($conn, $target_class_id, $member_id);
if (!$capacity['allowed']) {
    $_SESSION['flash_error'] = 'Activation blocked: ' . bible_class_capacity_error_message();
    header('Location: ' . $redirect);
    exit;
}

$update = $conn->prepare("UPDATE members SET status = 'active', deactivated_at = NULL WHERE id = ? AND is_archived = 0");
$update->bind_param('i', $member_id);

if ($update->execute()) {
    $_SESSION['flash_success'] = 'Member activated successfully.';
    header('Location: member_list.php');
    exit;
}

if (is_bible_class_capacity_error($update->error)) {
    $_SESSION['flash_error'] = 'Activation blocked: ' . bible_class_capacity_error_message();
} else {
    $_SESSION['flash_error'] = 'Activation failed. Please try again.';
}

header('Location: ' . $redirect);
exit;
