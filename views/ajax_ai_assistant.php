<?php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/role_based_filter.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AiAssistantChatService.php';

header('Content-Type: application/json; charset=utf-8');

function assistant_json_error(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!is_logged_in() || empty($_SESSION['user_id'])) {
    assistant_json_error(401, 'A back-office account is required to use the assistant.');
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('use_dashboard_insights')) {
    assistant_json_error(403, 'You do not have permission to use the assistant.');
}

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? 'list');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    assistant_json_error(419, 'Invalid or expired session token. Refresh the page and try again.');
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !in_array($action, ['list', 'history'], true)) {
    assistant_json_error(405, 'This assistant action requires a protected POST request.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, ['ask', 'archive'], true)) {
    assistant_json_error(400, 'Invalid assistant action.');
}

$userId = (int) $_SESSION['user_id'];

try {
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();

    $isCashier = false;
    foreach (get_user_roles() as $role) {
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
    $local = new DashboardInsightsService(
        $conn,
        $userId,
        $isSuperAdmin,
        $isCashier,
        $churchId ?: null,
        get_user_class_ids($userId),
        get_user_organization_ids($userId),
        $permissions
    );
    $service = new AiAssistantChatService($conn, $userId, $churchId ?: null, $local);

    if ($action === 'ask') {
        $config = (new AiAssistantSettingsService($conn))->get();
        $limit = max(1, (int) $config['requests_per_user_per_minute']);
        $rate = $conn->prepare(
            "SELECT COUNT(*) AS total FROM ai_assistant_messages message"
            . " JOIN ai_assistant_conversations conversation ON conversation.id = message.conversation_id"
            . " WHERE conversation.user_id = ? AND message.sender = 'user'"
            . ' AND message.created_at >= NOW() - INTERVAL 1 MINUTE'
        );
        $rate->bind_param('i', $userId);
        $rate->execute();
        $recent = (int) ($rate->get_result()->fetch_assoc()['total'] ?? 0);
        $rate->close();
        if ($recent >= $limit) {
            assistant_json_error(429, 'Too many messages. Please wait a minute and try again.');
        }
        $result = $service->ask(
            !empty($_POST['conversation_id']) ? (int) $_POST['conversation_id'] : null,
            (string) ($_POST['question'] ?? '')
        );
        echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'history') {
        echo json_encode([
            'success' => true,
            'messages' => $service->history((int) ($_GET['conversation_id'] ?? 0)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'archive') {
        $service->archive((int) ($_POST['conversation_id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => true, 'conversations' => $service->conversations()], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    assistant_json_error(422, $exception->getMessage());
} catch (DomainException $exception) {
    assistant_json_error(404, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('AI assistant request failed: ' . $exception->getMessage());
    assistant_json_error(500, 'The assistant is temporarily unavailable.');
}
