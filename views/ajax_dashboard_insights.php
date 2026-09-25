<?php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/role_based_filter.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/DashboardInsightsService.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$isSuperAdmin = function_exists('is_super_admin')
    ? is_super_admin()
    : (((int) ($_SESSION['user_id'] ?? 0) === 3) || ((int) ($_SESSION['role_id'] ?? 0) === 1));
if (!$isSuperAdmin && !has_permission('use_dashboard_insights')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to use dashboard insights.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired session token. Refresh the page and try again.']);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$question = (string) ($_POST['question'] ?? '');

try {
    $limit = $conn->prepare(
        'SELECT COUNT(*) AS total FROM dashboard_insight_query_audit'
        . ' WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 MINUTE'
    );
    $limit->bind_param('i', $userId);
    $limit->execute();
    $recentQueries = (int) ($limit->get_result()->fetch_assoc()['total'] ?? 0);
    $limit->close();
    if ($recentQueries >= 20) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many questions. Please wait a minute and try again.']);
        exit;
    }

    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();

    $isCashier = false;
    foreach ((function_exists('get_user_roles') ? get_user_roles() : []) as $role) {
        if (strtolower((string) ($role['name'] ?? '')) === 'cashier') {
            $isCashier = true;
            break;
        }
    }

    $permissions = [
        'payments' => $isSuperAdmin || has_permission('view_dashboard_payment_summary'),
        'attendance' => $isSuperAdmin || has_permission('view_dashboard_attendance_summary'),
        'health' => $isSuperAdmin || has_permission('view_dashboard_health_summary'),
        'membership' => $isSuperAdmin || has_permission('view_dashboard_membership_summary'),
        'events' => $isSuperAdmin || has_permission('view_dashboard_event_summary'),
        'birthdays' => $isSuperAdmin || has_permission('view_birthdays'),
    ];

    $service = new DashboardInsightsService(
        $conn,
        $userId,
        $isSuperAdmin,
        $isCashier,
        $churchId ?: null,
        get_user_class_ids($userId),
        get_user_organization_ids($userId),
        $permissions
    );
    $result = $service->ask($question);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Dashboard insights failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Dashboard insights are temporarily unavailable.']);
}
