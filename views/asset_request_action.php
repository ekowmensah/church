<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AssetCustodyService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Asset request actions must be submitted by POST.');
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh the page and try again.');
}

$requestId = (int) ($_POST['id'] ?? 0);
$action = trim((string) ($_POST['request_action'] ?? ''));
if ($requestId < 1 || !in_array($action, ['approve', 'reject', 'checkout', 'return', 'cancel'], true)) {
    header('Location: asset_request_list.php?err=' . urlencode('Invalid asset request action.'));
    exit;
}

$isSuper = asset_is_super_admin();
$actorUserId = (int) ($_SESSION['user_id'] ?? 0) ?: null;
$actorMemberId = (int) ($_SESSION['member_id'] ?? 0) ?: null;
$scopeChurchId = $isSuper ? null : asset_current_church_id($conn);

// Cancellation is an owner action. Every other transition is an explicit
// custody-management capability and remains enforced server-side.
if ($action !== 'cancel' && !$isSuper && !has_permission('approve_asset_use_request')) {
    header('Location: asset_request_list.php?err=' . urlencode('You do not have permission to manage asset custody.'));
    exit;
}

try {
    $service = new AssetCustodyService($conn);
    $service->process(
        $requestId,
        $action,
        $_POST,
        $scopeChurchId,
        $isSuper,
        $actorUserId,
        $actorMemberId
    );
    header('Location: asset_request_list.php?done=1');
    exit;
} catch (Throwable $exception) {
    error_log('Asset custody action failed: ' . $exception->getMessage());
    header('Location: asset_request_list.php?err=' . urlencode($exception->getMessage()));
    exit;
}

