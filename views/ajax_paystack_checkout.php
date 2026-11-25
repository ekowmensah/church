<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../services/PaymentGatewayCallbackService.php';

header('Content-Type: application/json');

// Allow authenticated staff and member-portal sessions. Staff still require
// the AJAX capability; a member may initialize only their own payment.
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = is_super_admin();
$is_member_portal = (int) ($_SESSION['member_id'] ?? 0) > 0;
if (!$is_member_portal && !$is_super_admin && !has_permission('access_ajax_paystack_checkout')) {
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
$callback_url = BASE_URL . '/views/paystack_callback.php';

$input = $_POST;
$amount = isset($input['amount']) ? floatval($input['amount']) : 0;
$email = isset($input['customerEmail']) ? trim($input['customerEmail']) : '';
$name = isset($input['customerName']) ? trim($input['customerName']) : '';
$phone = isset($input['customerPhone']) ? trim($input['customerPhone']) : '';
$description = isset($input['description']) ? trim($input['description']) : '';
$bulk_items = $input['bulk_items'] ?? null;
if (is_string($bulk_items) && $bulk_items !== '') {
    $bulk_items = json_decode($bulk_items, true);
}
if (!is_array($bulk_items)) {
    $bulk_items = null;
}

if (!$amount || !$email) {
    echo json_encode(['success'=>false, 'error'=>'Missing amount or email.']);
    exit;
}

// Convert to kobo (Paystack expects amount in lowest currency unit)
$amount_kobo = (int) round($amount * 100);
$clientReference = 'PAYSTACK-' . date('YmdHis') . '-' . bin2hex(random_bytes(8));

// Prepare Paystack API request. Member-portal identity is authoritative from
// the session and its church is resolved from the membership record.
$member_id = $is_member_portal
    ? (int) $_SESSION['member_id']
    : (int) ($input['member_id'] ?? 0);
if ($member_id <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'A payment beneficiary is required.']);
    exit;
}
$memberStmt = $conn->prepare(
    "SELECT church_id, status, is_archived FROM members WHERE id = ? LIMIT 1"
);
$memberStmt->bind_param('i', $member_id);
$memberStmt->execute();
$paymentMember = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();
if (!$paymentMember
    || strtolower(trim((string) ($paymentMember['status'] ?? ''))) !== 'active'
    || (int) ($paymentMember['is_archived'] ?? 0) === 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'The selected member is not eligible for online payment.']);
    exit;
}
$church_id = (int) $paymentMember['church_id'];
$payment_type_id = isset($input['payment_type_id']) ? intval($input['payment_type_id']) : null;

if ($bulk_items) {
    $lineTotal = 0.0;
    foreach ($bulk_items as &$item) {
        $lineAmount = (float) ($item['amount'] ?? 0);
        $lineTypeId = (int) ($item['payment_type_id'] ?? $item['typeId'] ?? 0);
        if ($lineAmount <= 0 || $lineTypeId <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Every payment line requires a type and positive amount.']);
            exit;
        }
        $item['member_id'] = $member_id;
        $item['church_id'] = $church_id;
        $item['payment_type_id'] = $lineTypeId;
        unset($item['sundayschool_id']);
        $lineTotal += $lineAmount;
    }
    unset($item);
    $amount = $lineTotal;
    $amount_kobo = (int) round($amount * 100);
}

$fields = [
    'amount' => $amount_kobo,
    'email' => $email,
    'reference' => $clientReference,
    'callback_url' => $callback_url,
    'metadata' => [
        'name' => $name,
        'phone' => $phone,
        'description' => $description,
        'member_id' => $member_id,
        'church_id' => $church_id,
        'payment_type_id' => $payment_type_id,
        'payment_period' => $input['payment_period'] ?? null,
        'payment_period_description' => $input['payment_period_description'] ?? null,
        'bulk_items' => $bulk_items
    ]
];

$intentService = new PaymentGatewayCallbackService($conn);
try {
    $intentService->record([
        'client_reference' => $clientReference,
        'status' => 'Pending',
        'amount' => $amount,
        'description' => $description ?: 'Paystack online payment',
        'customer_name' => $name ?: 'Paystack customer',
        'customer_phone' => $phone,
        'member_id' => $member_id,
        'church_id' => $church_id,
        'payment_type_id' => $payment_type_id,
        'payment_period' => $input['payment_period'] ?? null,
        'payment_period_description' => $input['payment_period_description'] ?? null,
        'bulk_breakdown' => $bulk_items,
        'payment_source' => 'paystack',
        'locally_initialized' => true,
    ]);
} catch (Throwable $error) {
    error_log('Paystack payment baseline could not be persisted: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'The payment could not be safely initialized. No gateway request was made.',
    ]);
    exit;
}

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
if ($err !== '') {
    error_log('Paystack checkout transport error: ' . $err);
}

// Debug log
if ($err || !$response) {
    file_put_contents(__DIR__.'/../logs/paystack_debug.log', "[".date('Y-m-d H:i:s')."] CURL ERROR: $err\nINPUT: ".json_encode($fields)."\nRESPONSE: ".print_r($response, true)."\n", FILE_APPEND);
    try {
        $intentService->record([
            'client_reference' => $clientReference,
            'status' => 'Failed',
            'amount' => $amount,
            'payment_source' => 'paystack',
            'locally_initialized' => true,
            'raw_payload' => json_encode(['transport_error' => $err]),
        ]);
    } catch (Throwable $recordError) {
        error_log('Paystack transport failure could not be recorded: ' . $recordError->getMessage());
    }
    echo json_encode(['success'=>false, 'error'=>'Network or Paystack server error. Please try again later.']);
    exit;
}

if ($err) {
    echo json_encode(['success'=>false, 'error'=>'Curl error: '.$err]);
    exit;
}

$data = json_decode($response, true);
if (!$data || !$data['status']) {
    try {
        $intentService->record([
            'client_reference' => $clientReference,
            'status' => 'Failed',
            'amount' => $amount,
            'payment_source' => 'paystack',
            'locally_initialized' => true,
            'raw_payload' => (string) $response,
        ]);
    } catch (Throwable $recordError) {
        error_log('Paystack initialization rejection could not be recorded: ' . $recordError->getMessage());
    }
    echo json_encode(['success'=>false, 'error'=>'Paystack error: '.($data['message'] ?? 'Unknown error')]);
    exit;
}

$auth_url = $data['data']['authorization_url'] ?? null;
if ($auth_url) {
    $returnedReference = trim((string) ($data['data']['reference'] ?? ''));
    if ($returnedReference === '' || !hash_equals($clientReference, $returnedReference)) {
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'Paystack returned an unexpected transaction reference.']);
        exit;
    }
    try {
        $intentService->record([
            'client_reference' => $clientReference,
            'status' => 'Pending',
            'amount' => $amount,
            'description' => $description ?: 'Paystack online payment',
            'customer_name' => $name ?: 'Paystack customer',
            'customer_phone' => $phone,
            'member_id' => $member_id,
            'church_id' => $church_id,
            'payment_type_id' => $payment_type_id,
            'payment_period' => $input['payment_period'] ?? null,
            'payment_period_description' => $input['payment_period_description'] ?? null,
            'bulk_breakdown' => $bulk_items,
            'payment_source' => 'paystack',
            'locally_initialized' => true,
            'raw_payload' => $response,
        ]);
        echo json_encode(['success'=>true, 'checkoutUrl'=>$auth_url]);
    } catch (Throwable $error) {
        error_log('Paystack initialization could not be persisted: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'The payment was initialized but could not be safely tracked. Please contact support before retrying.']);
    }
} else {
    echo json_encode(['success'=>false, 'error'=>'No authorization URL returned by Paystack.']);
}
