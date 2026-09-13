<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/RoleOfServingAccessService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function user_access_json(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in()) {
    user_access_json(401, ['success' => false, 'message' => 'Authentication required.']);
}
if (!is_super_admin() && !has_permission('create_user')) {
    user_access_json(403, ['success' => false, 'message' => 'Permission denied.']);
}

$crn = trim((string) ($_GET['crn'] ?? ''));
if ($crn === '') {
    user_access_json(422, ['success' => false, 'message' => 'Enter a CRN.']);
}
if (strlen($crn) > 50) {
    user_access_json(422, ['success' => false, 'message' => 'Enter a valid CRN.']);
}

try {
    $service = new RoleOfServingAccessService($conn);
    $matches = $service->findMembersForUserAccessByCrn($crn);
    if (!$matches) {
        user_access_json(404, ['success' => false, 'message' => 'No registered member was found with that CRN.']);
    }
    if (count($matches) > 1) {
        user_access_json(409, [
            'success' => false,
            'message' => 'This CRN belongs to more than one member. Resolve the duplicate CRN before creating an account.',
        ]);
    }

    $member = $matches[0];
    if (strtolower((string) $member['status']) !== 'active') {
        user_access_json(409, ['success' => false, 'message' => 'This member is not active and is not eligible for a new user account.']);
    }
    if ($member['existing_user_id'] !== null) {
        user_access_json(409, ['success' => false, 'message' => 'This member already has a back-office user account.']);
    }
    if (trim((string) $member['phone']) === '') {
        user_access_json(409, ['success' => false, 'message' => 'Add a contact number to this member before creating the user account.']);
    }

    unset($member['email'], $member['existing_user_id'], $member['status']);
    user_access_json(200, ['success' => true, 'member' => $member]);
} catch (Throwable $exception) {
    error_log('User access CRN lookup failed: ' . $exception->getMessage());
    user_access_json(500, ['success' => false, 'message' => 'The member lookup could not be completed. Try again.']);
}
