<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../helpers/hubtel_payment_v2.php';
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$amount = floatval($_POST['amount'] ?? 0);
$description = trim($_POST['description'] ?? '');
$customerName = trim($_POST['customerName'] ?? '');
$customerPhone = trim($_POST['customerPhone'] ?? '');
$customerEmail = trim($_POST['customerEmail'] ?? ''); // New parameter for email
// Generate a robust unique client reference (max ~24-28 chars)
try {
    $clientReference = 'CH-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
} catch (Exception $e) {
    $clientReference = 'CH-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
}

if ($amount < 1 || !$description || !$customerName || !$customerPhone) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$callbackUrl = BASE_URL . '/views/hubtel_callback_v2.php';
$returnUrl = BASE_URL . '/views/make_payment.php?hubtel_return=1';
$cancellationUrl = BASE_URL . '/views/make_payment.php?hubtel_cancelled=1'; // New parameter for cancellation URL

$params = [
    'amount' => $amount,
    'description' => $description,
    'callbackUrl' => $callbackUrl,
    'returnUrl' => $returnUrl,
    'cancellationUrl' => $cancellationUrl,
    'customerName' => $customerName,
    'customerPhone' => $customerPhone,
    'clientReference' => $clientReference,
];

// Add email if provided
if (!empty($customerEmail)) {
    $params['customerEmail'] = $customerEmail;
}

$result = create_hubtel_checkout_v2($params);
if ($result['success']) {
    // Save payment intent to DB with $clientReference, $amount, etc.
    // This is important to track the payment status later
    try {
        require_once __DIR__.'/../config/database.php';
        $stmt = $conn->prepare('INSERT INTO payment_intents (client_reference, hubtel_transaction_id, amount, description, customer_name, customer_phone, checkout_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $checkoutId = $result['checkoutId'] ?? '';
        $hubtelTransactionId = $result['transaction_id'] ?? null;
        $stmt->bind_param('ssdsss', $clientReference, $hubtelTransactionId, $amount, $description, $customerName, $customerPhone, $checkoutId);
        $stmt->execute();
    } catch (Exception $e) {
        // Log error but continue - don't block payment flow due to DB issues
        file_put_contents(__DIR__.'/../logs/payment_intent_error.log', date('c')."\n".$e->getMessage()."\n", FILE_APPEND);
    }
    
    echo json_encode([
        'success' => true, 
        'checkoutUrl' => $result['checkoutUrl'],
        'checkoutDirectUrl' => $result['checkoutDirectUrl'] ?? null,
        'clientReference' => $result['clientReference'] ?? $clientReference
    ]);
} else {
    echo json_encode([
        'success' => false, 
        'error' => $result['error'] ?? 'Unknown error', 
        'debug' => $result
    ]);
}
