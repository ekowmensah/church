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
if (!$is_super_admin && !has_permission('access_ajax_paystack_checkout')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// CONFIG: Set your Paystack secret key here or in config.php
$paystack_secret_key = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
if (!$paystack_secret_key) {
    error_log('Paystack secret key is missing!');
    echo json_encode(['success'=>false, 'error'=>'Paystack secret key missing on server.']);
    exit;
}
error_log("Paystack key: [$paystack_secret_key]");
$callback_url = BASE_URL . '/views/paystack_callback.php';

$input = $_POST;
$amount = isset($input['amount']) ? floatval($input['amount']) : 0;
$email = isset($input['customerEmail']) ? trim($input['customerEmail']) : '';
$name = isset($input['customerName']) ? trim($input['customerName']) : '';
$phone = isset($input['customerPhone']) ? trim($input['customerPhone']) : '';
$description = isset($input['description']) ? trim($input['description']) : '';

if (!$amount || !$email) {
    echo json_encode(['success'=>false, 'error'=>'Missing amount or email.']);
    exit;
}

// Convert to kobo (Paystack expects amount in lowest currency unit)
$amount_kobo = intval($amount * 100);

// Prepare Paystack API request
$member_id = isset($input['member_id']) ? intval($input['member_id']) : ($_SESSION['member_id'] ?? null);
$church_id = isset($input['church_id']) ? intval($input['church_id']) : ($_SESSION['church_id'] ?? null);
$payment_type_id = isset($input['payment_type_id']) ? intval($input['payment_type_id']) : (isset($input['payment_type_id']) ? intval($input['payment_type_id']) : null);

$fields = [
    'amount' => $amount_kobo,
    'email' => $email,
    'callback_url' => $callback_url,
    'metadata' => [
        'name' => $name,
        'phone' => $phone,
        'description' => $description,
        'member_id' => $member_id,
        'church_id' => $church_id,
        'payment_type_id' => $payment_type_id
    ]
];

$ch = curl_init('https://api.paystack.co/transaction/initialize');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $paystack_secret_key,
    'Content-Type: application/json',
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 15 second timeout
error_log('Before curl_exec');
$response = curl_exec($ch);
$err = curl_error($ch);
error_log('After curl_exec: response='.print_r($response, true).', curl_error='.print_r($err, true));

// Debug log
if ($err || !$response) {
    file_put_contents(__DIR__.'/../logs/paystack_debug.log', "[".date('Y-m-d H:i:s')."] CURL ERROR: $err\nINPUT: ".json_encode($fields)."\nRESPONSE: ".print_r($response, true)."\n", FILE_APPEND);
    echo json_encode(['success'=>false, 'error'=>'Network or Paystack server error. Please try again later.']);
    exit;
}

if ($err) {
    echo json_encode(['success'=>false, 'error'=>'Curl error: '.$err]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !$data['status']) {
    echo json_encode(['success'=>false, 'error'=>'Paystack error: '.($data['message'] ?? 'Unknown error')]);
    exit;
}

$auth_url = $data['data']['authorization_url'] ?? null;
if ($auth_url) {
    echo json_encode(['success'=>true, 'checkoutUrl'=>$auth_url]);
} else {
    echo json_encode(['success'=>false, 'error'=>'No authorization URL returned by Paystack.']);
}
