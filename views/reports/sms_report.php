<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('view_sms_report') && !has_permission('view_sms_logs')) {
    http_response_code(403);
    include __DIR__ . '/../errors/403.php';
    exit;
}

// Phase 0056 introduced the auditable SMS Delivery Centre. Keep this legacy
// report route as a compatibility redirect so bookmarks and menu links land
// in the maintained, scoped and retry-aware workspace.
$query = [];
foreach (['phone', 'sender', 'type', 'status'] as $key) {
    if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') {
        $query[$key] = trim((string) $_GET[$key]);
    }
}
if (!empty($_GET['from_date'])) {
    $query['date_from'] = (string) $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $query['date_to'] = (string) $_GET['to_date'];
}

$target = BASE_URL . '/views/sms_logs.php';
if ($query) {
    $target .= '?' . http_build_query($query);
}
header('Location: ' . $target, true, 302);
exit;
