<?php

// This route is consumed by fetch(), so every outcome must be JSON. Loading
// helpers/auth.php here would allow its page-level password-change redirect to
// turn an AJAX response into HTML.
$attendanceQueueBufferLevel = ob_get_level();
ob_start();

function attendance_queue_json_response(array $payload, int $status = 200): void {
    global $attendanceQueueBufferLevel;

    while (ob_get_level() > $attendanceQueueBufferLevel) {
        ob_end_clean();
    }

    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

try {
    require_once __DIR__ . '/../config/config.php';
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';

    $loggedIn = isset($_SESSION['user_id']) || isset($_SESSION['member_id']);
    if (!$loggedIn) {
        attendance_queue_json_response([
            'ok' => false,
            'message' => 'Your session has expired. Refresh the page and sign in again.',
        ], 401);
    }

    if (isset($_SESSION['user_id']) && (int) ($_SESSION['must_change_password'] ?? 0) === 1) {
        attendance_queue_json_response([
            'ok' => false,
            'message' => 'Complete the required password change, then refresh the dashboard.',
        ], 403);
    }

    $search = trim((string) ($_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    if (function_exists('mb_strlen') && mb_strlen($search, 'UTF-8') > 100) {
        $search = mb_substr($search, 0, 100, 'UTF-8');
    } elseif (strlen($search) > 100) {
        $search = substr($search, 0, 100);
    }

    $service = BibleClassAttendanceScheduleService::fromSession($conn);
    $queue = $service->getDueSessionQueueForDate(date('Y-m-d'), $search, $page, 12);
    attendance_queue_json_response(['ok' => true, 'queue' => $queue]);
} catch (Throwable $exception) {
    error_log('Bible Class attendance queue failed: ' . $exception->getMessage());
    attendance_queue_json_response([
        'ok' => false,
        'message' => 'The attendance queue could not be loaded. Refresh the dashboard and try again.',
    ], 500);
}
