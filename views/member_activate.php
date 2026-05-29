<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/bible_class_capacity.php';
if (!is_logged_in() || !(isset($_SESSION['role_id']) && ($_SESSION['role_id'] == 1 || has_permission('edit_member') || has_permission('activate_member')))) {
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

if (!isset($_GET['id'])) {
    $_SESSION['flash_error'] = 'Missing member ID.';
    header('Location: ' . $redirect);
    exit;
}
$member_id = intval($_GET['id']);
$stmt = $conn->prepare('SELECT id, class_id, status, deactivated_at FROM members WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
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

$update = $conn->prepare("UPDATE members SET status = 'active', deactivated_at = NULL WHERE id = ?");
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
