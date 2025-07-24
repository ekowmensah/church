<?php
// send_member_message.php: Handles sending a message to a member via SMS (provider-agnostic)
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../includes/sms.php'; // Assumes sms_send($to, $message, $log_context = [])

if (!is_logged_in()) {
    http_response_code(403);
    exit('Not authorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $message_body = isset($_POST['message_body']) ? trim($_POST['message_body']) : '';
    if ($member_id && $phone && $message_body) {
        // Optionally, validate phone format
        $result = log_sms($phone, $message_body, null, 'manual', $_SESSION['user_id'] ?? null, ['member_id'=>$member_id, 'sent_by'=>$_SESSION['user_id']??null]);
        if (isset($result['status']) && $result['status'] === 'success') {
            $_SESSION['flash_success'] = 'Message sent successfully!';
        } else {
            $err = isset($result['message']) ? $result['message'] : 'Failed to send message.';
            $_SESSION['flash_error'] = is_string($err) ? $err : 'Failed to send message.';
        }
    } else {
        $_SESSION['flash_error'] = 'All fields are required.';
    }
    header('Location: member_list.php');
    exit;
} else {
    http_response_code(405);
    exit('Method not allowed');
}
