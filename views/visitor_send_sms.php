<?php
// visitor_send_sms.php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../includes/sms.php';
header('Content-Type: application/json');

if (!is_logged_in() || !has_permission('manage_visitors')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$ids = isset($_POST['recipient_ids']) ? $_POST['recipient_ids'] : '';
$message = trim($_POST['message'] ?? '');

if ($ids === '' || $message === '') {
    echo json_encode(['success' => false, 'error' => 'Missing recipients or message.']);
    exit;
}

$ids_arr = array_filter(array_map('intval', explode(',', $ids)));
if (empty($ids_arr)) {
    echo json_encode(['success' => false, 'error' => 'No valid recipients.']);
    exit;
}

// Fetch phone numbers
$placeholders = implode(',', array_fill(0, count($ids_arr), '?'));
$sql = "SELECT phone FROM visitors WHERE id IN ($placeholders) AND phone != ''";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('i', count($ids_arr)), ...$ids_arr);
$stmt->execute();
$result = $stmt->get_result();
$phones = [];
while ($row = $result->fetch_assoc()) {
    $phones[] = $row['phone'];
}

if (empty($phones)) {
    echo json_encode(['success' => false, 'error' => 'No valid phone numbers found.']);
    exit;
}

try {
    $resp = send_sms($phones, $message);
    if (is_array($resp) && isset($resp['status']) && $resp['status'] === 'success') {
        echo json_encode(['success' => true]);
    } else {
        // Extract the most informative error message
        $err = 'Failed to send SMS.';
        $debug = $resp;
        if (is_array($resp)) {
            if (!empty($resp['message'])) $err = $resp['message'];
            elseif (!empty($resp['error'])) $err = $resp['error'];
            elseif (!empty($resp['raw_response'])) $err = $resp['raw_response'];
        }
        echo json_encode(['success' => false, 'error' => $err, 'debug' => $debug]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
