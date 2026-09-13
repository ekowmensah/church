<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

if (!is_super_admin() && !has_permission('edit_organization')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit;
}

if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session token expired. Refresh the page and try again.']);
    exit;
}

$organizationId = (int) ($_POST['org_id'] ?? 0);
$leaderRole = ($_POST['leader_role'] ?? 'primary') === 'assistant' ? 'assistant' : 'primary';
if ($organizationId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing organization ID.']);
    exit;
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        'UPDATE organization_leaders
            SET status = "inactive"
          WHERE organization_id = ? AND leader_role = ? AND status = "active"'
    );
    $stmt->bind_param('is', $organizationId, $leaderRole);
    $stmt->execute();
    $stmt->close();

    if ($leaderRole === 'primary') {
        $stmt = $conn->prepare('UPDATE organizations SET leader_id = NULL WHERE id = ?');
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $stmt->close();
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => ucfirst($leaderRole) . ' organizational leader removed successfully.',
    ]);
} catch (Throwable $exception) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $exception->getMessage()]);
}
