<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/InAppMessagingService.php';

header('Content-Type: application/json; charset=utf-8');

function chat_drawer_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function chat_drawer_add_photos(array $rows): array
{
    foreach ($rows as &$row) {
        $file = basename((string) ($row['participant_photo'] ?? ''));
        $type = ($row['participant_photo_type'] ?? '') === 'user' ? 'users' : 'members';
        $path = __DIR__ . '/../uploads/' . $type . '/' . $file;
        $row['photo_url'] = $file !== '' && is_file($path)
            ? BASE_URL . '/uploads/' . $type . '/' . rawurlencode($file)
            : BASE_URL . '/assets/img/undraw_profile.svg';
        unset($row['participant_photo'], $row['participant_photo_type']);
    }
    unset($row);
    return $rows;
}

function chat_drawer_add_message_photos(array $rows): array
{
    foreach ($rows as &$row) {
        $file = basename((string) ($row['sender_photo'] ?? ''));
        $type = ($row['sender_photo_type'] ?? '') === 'user' ? 'users' : 'members';
        $path = __DIR__ . '/../uploads/' . $type . '/' . $file;
        $row['sender_photo_url'] = $file !== '' && is_file($path)
            ? BASE_URL . '/uploads/' . $type . '/' . rawurlencode($file)
            : BASE_URL . '/assets/img/undraw_profile.svg';
        unset($row['sender_photo'], $row['sender_photo_type']);
    }
    unset($row);
    return $rows;
}

if (!is_logged_in()) {
    chat_drawer_response(['success' => false, 'message' => 'Your session has expired.'], 401);
}
if (!empty($_SESSION['user_id']) && !is_super_admin() && !has_permission('use_in_app_messages')) {
    chat_drawer_response(['success' => false, 'message' => 'You do not have permission to use Church Chat.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    chat_drawer_response(['success' => false, 'message' => 'Refresh the page and try again.'], 419);
}

try {
    $service = InAppMessagingService::fromSession($conn);
    if (!$service->isAvailable()) {
        throw new RuntimeException('Church Chat is unavailable until the messaging migrations are installed.');
    }
    $action = (string) ($_POST['action'] ?? 'snapshot');
    if ($action === 'snapshot') {
        $service->heartbeat('online');
        $presenceAvailable = $service->isGroupChatAvailable();
        chat_drawer_response([
            'success' => true,
            'threads' => chat_drawer_add_photos(array_slice($service->listThreads(), 0, 15)),
            'online' => $presenceAvailable ? chat_drawer_add_photos($service->onlineContacts(30)) : [],
            'unread' => $service->unreadMessageCount(),
            'presence_available' => $presenceAvailable,
        ]);
    }
    if ($action === 'thread') {
        $threadId = max(0, (int) ($_POST['thread_id'] ?? 0));
        $thread = $service->getThread($threadId);
        $messages = chat_drawer_add_message_photos(array_slice($service->getMessages($threadId), -100));
        $service->markThreadRead($threadId);
        chat_drawer_response(['success' => true, 'thread' => $thread, 'messages' => $messages]);
    }
    if ($action === 'reply') {
        $threadId = max(0, (int) ($_POST['thread_id'] ?? 0));
        $service->reply($threadId, (string) ($_POST['message_text'] ?? ''));
        $thread = $service->getThread($threadId);
        $messages = chat_drawer_add_message_photos(array_slice($service->getMessages($threadId), -100));
        $service->markThreadRead($threadId);
        chat_drawer_response(['success' => true, 'thread' => $thread, 'messages' => $messages]);
    }
    if ($action === 'start') {
        $type = (string) ($_POST['recipient_type'] ?? '');
        $id = max(0, (int) ($_POST['recipient_id'] ?? 0));
        $threadId = $service->startConversation($type, $id, (string) ($_POST['message_text'] ?? ''));
        $thread = $service->getThread($threadId);
        $messages = chat_drawer_add_message_photos(array_slice($service->getMessages($threadId), -100));
        $service->markThreadRead($threadId);
        chat_drawer_response(['success' => true, 'thread' => $thread, 'messages' => $messages]);
    }
    throw new InvalidArgumentException('Unknown chat action.');
} catch (Throwable $exception) {
    chat_drawer_response(['success' => false, 'message' => $exception->getMessage()], 400);
}
