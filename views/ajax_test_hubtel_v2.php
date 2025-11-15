<?php
require_once __DIR__.'/../helpers/hubtel_payment_v2.php';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Get parameters from GET (simulating AJAX POST)
$amount = floatval($_GET['amount'] ?? 1.00);
$description = trim($_GET['description'] ?? 'Test Payment');
$customerName = trim($_GET['customerName'] ?? 'Test User');
$customerPhone = trim($_GET['customerPhone'] ?? '0555123456');
$customerEmail = trim($_GET['customerEmail'] ?? '');
// Generate a robust unique client reference (max ~24 chars)
try {
    $clientReference = 'CH-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)); // e.g., CH-20250815XXXXXX-abcdef
} catch (Exception $e) {
    // Fallback if random_bytes unavailable
    $clientReference = 'CH-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
}

// Validate parameters
if ($amount < 1 || !$description || !$customerName || !$customerPhone) {
    echo "Error: Missing required fields";
    exit;
}

// Set up callback and return URLs
$callbackUrl = BASE_URL . '/views/hubtel_callback_v2.php';
$returnUrl = BASE_URL . '/views/test_hubtel_return.php';
$cancellationUrl = BASE_URL . '/views/test_hubtel_return.php?cancelled=1';

// Prepare parameters for Hubtel checkout
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

// Call the Hubtel checkout function
$result = create_hubtel_checkout_v2($params);

// Save test data in session for return page
$_SESSION['test_payment_data'] = [
    'amount' => $amount,
    'description' => $description,
    'customerName' => $customerName,
    'customerPhone' => $customerPhone,
    'customerEmail' => $customerEmail,
    'clientReference' => $clientReference,
    'result' => $result
];

// If successful, redirect to the checkout URL
if ($result['success']) {
    // Save payment intent to DB
    try {
        require_once __DIR__.'/../config/database.php';
        $stmt = $conn->prepare('INSERT INTO payment_intents (client_reference, amount, description, customer_name, customer_phone, customer_email, checkout_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $checkoutId = $result['checkoutId'] ?? '';
        $stmt->bind_param('sdssss', $clientReference, $amount, $description, $customerName, $customerPhone, $customerEmail, $checkoutId);
        $stmt->execute();
    } catch (Exception $e) {
        // Log error but continue
        file_put_contents(__DIR__.'/../logs/payment_intent_error.log', date('c')."\n".$e->getMessage()."\n", FILE_APPEND);
    }
    
    // Redirect to Hubtel checkout
    header('Location: ' . $result['checkoutUrl']);
    exit;
} else {
    // Display error
    echo "<h1>Error</h1>";
    echo "<p>Failed to create Hubtel checkout: " . ($result['error'] ?? 'Unknown error') . "</p>";
    echo "<pre>" . print_r($result['debug'] ?? [], true) . "</pre>";
    echo "<p><a href='test_hubtel_v2.php'>Back to Test Page</a></p>";
}
