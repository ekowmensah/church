<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/hubtel_payment.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_hubtel_checkout')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$amount = floatval($_POST['amount'] ?? 0);
$description = trim($_POST['description'] ?? '');
$customerName = trim($_POST['customerName'] ?? '');
$customerPhone = trim($_POST['customerPhone'] ?? '');
$clientReference = uniqid('PAY-');

if ($amount < 1 || !$description || !$customerName || !$customerPhone) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$callbackUrl = BASE_URL . '/views/hubtel_callback.php';
$returnUrl = BASE_URL . '/views/make_payment.php?hubtel_return=1';

$params = [
    'amount' => $amount,
    'description' => $description,
    'callbackUrl' => $callbackUrl,
    'returnUrl' => $returnUrl,
    'customerName' => $customerName,
    'customerPhone' => $customerPhone,
    'clientReference' => $clientReference,
];

$result = create_hubtel_checkout($params);
if ($result['success']) {
    // Optionally: Save payment intent to DB with $clientReference, $amount, etc.
    echo json_encode(['success' => true, 'checkoutUrl' => $result['checkoutUrl']]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unknown error', 'debug' => $result]);
}
