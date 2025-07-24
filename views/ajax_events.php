<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_events')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$events = [];
$res = $conn->query("SELECT id, name, event_date, event_time, location, description, photo FROM events WHERE event_date IS NOT NULL ORDER BY event_date, event_time");
if ($conn->connect_errno) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connection failed: ' . $conn->connect_error]);
    exit;
}
if ($res === false) {
    http_response_code(500);
    echo json_encode(['error' => 'SQL error: ' . $conn->error]);
    exit;
}
if ($res->num_rows > 0) {
    while ($e = $res->fetch_assoc()) {
        $start = $e['event_date'];
        if (!empty($e['event_time'])) {
            $start .= 'T' . $e['event_time'];
        }
        $photo_url = null;
        if (!empty($e['photo']) && file_exists(__DIR__.'/../uploads/events/' . $e['photo'])) {
            $photo_url = BASE_URL . '/uploads/events/' . rawurlencode($e['photo']);
        }
        $registration_url = BASE_URL . '/views/event_register.php?event_id=' . $e['id'];
        $events[] = [
            'id' => $e['id'],
            'title' => $e['name'],
            'start' => $start,
            'description' => $e['description'],
            'location' => $e['location'],
            'photo_url' => $photo_url,
            'registration_url' => $registration_url,
        ];
    }
}
echo json_encode($events);
