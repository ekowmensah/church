<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../helpers/hubtel_payment.php';
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
// Get member CRN (from session or POST)
$crn = $_POST['crn'] ?? ($_SESSION['crn'] ?? '');
// For bulk, concatenate all item descriptions for Hubtel
$bulk_items = isset($_POST['bulk_items']) ? (is_string($_POST['bulk_items']) ? json_decode($_POST['bulk_items'], true) : $_POST['bulk_items']) : null;
$description = '';
if (
    isset($bulk_items) && is_array($bulk_items) && count($bulk_items) > 0
) {
    // Use the already formatted descriptions from frontend
    $desc_parts = [];
    foreach ($bulk_items as $item) {
        $desc_parts[] = $item['desc'] ?? $item['typeName'] ?? '-';
    }
    $description = implode('; ', $desc_parts) . " by $crn";
    // Optionally trim to 255 chars for Hubtel
    $description = mb_substr($description, 0, 255);

} else {
    $period = $_POST['payment_period_description'] ?? '-';
    $type = '-';
    if (!empty($_POST['payment_type_id'])) {
        $type_id = intval($_POST['payment_type_id']);
        $type_res = $conn->query("SELECT name FROM payment_types WHERE id = $type_id");
        if ($type_res && $type_row = $type_res->fetch_assoc()) {
            $type = $type_row['name'];
        }
    }
    $description = "Payment for $period $type by $crn";
}
$customerName = trim($_POST['customerName'] ?? '');
$customerPhone = trim($_POST['customerPhone'] ?? '');
$clientReference = uniqid('PAY-');
$member_id = $_POST['member_id'] ?? ($_SESSION['member_id'] ?? null);
$church_id = $_POST['church_id'] ?? null;
if (isset($_POST['bulk_items'])) {
    if (is_string($_POST['bulk_items'])) {
        $bulk_items = json_decode($_POST['bulk_items'], true);
    } elseif (is_array($_POST['bulk_items'])) {
        $bulk_items = $_POST['bulk_items'];
    } else {
        $bulk_items = null;
    }
} else {
    $bulk_items = null;
}

if ($bulk_items && is_array($bulk_items) && count($bulk_items) > 0) {
    // Bulk payment: validate all items
    foreach ($bulk_items as $item) {
        if (empty($item['typeId']) || empty($item['amount']) || $item['amount'] < 1 || empty($item['date'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields in bulk payment item']);
            exit;
        }
    }
    // Sum all bulk items for Hubtel
    $total_amount = 0;
    foreach ($bulk_items as $item) {
        $total_amount += floatval($item['amount']);
    }
    $amount = $total_amount;
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
    // Debug log what we're getting from Hubtel
    $debugData = [
        'client_reference' => $clientReference,
        'hubtel_transaction_id' => $result['transaction_id'] ?? null,
        'full_result' => $result
    ];
    file_put_contents(__DIR__.'/../logs/hubtel_debug.log', date('c') . " - Payment Creation Debug (v1): " . json_encode($debugData) . "\n", FILE_APPEND);
    
    require_once __DIR__.'/../models/PaymentIntent.php';
    $intentModel = new PaymentIntent();
    $status = 'Pending';
    if ($bulk_items && is_array($bulk_items) && count($bulk_items) > 0) {
        $intentModel->add($conn, [
            'client_reference' => $clientReference,
            'hubtel_transaction_id' => $result['transaction_id'],
            'member_id' => $member_id,
            'amount' => $amount,
            'description' => $description,
            'church_id' => $church_id,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'status' => $status,
            'bulk_breakdown' => json_encode($bulk_items),
            'payment_type_id' => null,
            'payment_period' => null,
            'payment_period_description' => null
        ]);
    } else {
        $intentModel->add($conn, [
            'client_reference' => $clientReference,
            'hubtel_transaction_id' => $result['transaction_id'],
            'member_id' => $member_id,
            'amount' => $amount,
            'description' => $description,
            'church_id' => $church_id,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'status' => $status,
            'payment_type_id' => $_POST['payment_type_id'] ?? null,
            'payment_period' => $_POST['payment_period'] ?? null,
            'payment_period_description' => $_POST['payment_period_description'] ?? null
        ]);
    }
    echo json_encode(['success' => true, 'checkoutUrl' => $result['checkoutUrl']]);
} else {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Unknown error', 'debug' => $result]);
}
