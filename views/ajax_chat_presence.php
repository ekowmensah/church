<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/InAppMessagingService.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in()) { http_response_code(401); echo json_encode(['success' => false]); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419); echo json_encode(['success' => false]); exit;
}
try {
    $service = InAppMessagingService::fromSession($conn);
    if (!$service->isGroupChatAvailable()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Run database Phase 0050 to enable online presence.']);
        exit;
    }
    $service->heartbeat((string) ($_POST['status'] ?? 'online'));
    $participants = [];
    $threadId = max(0, (int) ($_POST['thread_id'] ?? 0));
    if ($threadId > 0) {
        $participants = $service->threadParticipants($threadId);
    }
    echo json_encode(['success' => true, 'participants' => $participants]);
} catch (Throwable $exception) {
    error_log('Chat presence heartbeat failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Presence could not be updated.']);
}
