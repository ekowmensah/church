<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Check database connection and if the payment_intents table exists
$tableExists = false;
$dbConnected = false;
$dbError = null;

try {
    // Check if database.php exists
    if (!file_exists(__DIR__.'/../config/database.php')) {
        $dbError = 'Database configuration file not found';
    } else {
        // Try to connect to database
        require_once __DIR__.'/../config/database.php';
        if (!isset($conn) || $conn->connect_error) {
            $dbError = 'Database connection failed: ' . ($conn->connect_error ?? 'Unknown error');
        } else {
            $dbConnected = true;
            // Check if table exists
            $result = $conn->query("SHOW TABLES LIKE 'payment_intents'");
            $tableExists = $result && $result->num_rows > 0;
        }
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Process form submission to create payment_intents table
if (isset($_POST['create_table']) && !$tableExists && $dbConnected) {
    try {
        if (!file_exists(__DIR__.'/../sql/create_payment_intents_table.sql')) {
            $tableError = 'SQL file not found: create_payment_intents_table.sql';
        } else {
            $sql = file_get_contents(__DIR__.'/../sql/create_payment_intents_table.sql');
            $conn->multi_query($sql);
            
            // Clear results
            while ($conn->more_results() && $conn->next_result()) {
                if ($result = $conn->store_result()) {
                    $result->free();
                }
            }
            
            $tableCreated = true;
            $tableExists = true;
        }
    } catch (Exception $e) {
        $tableError = $e->getMessage();
    }
}

// Handle test payment submission
$paymentResult = null;
if (isset($_POST['test_payment']) && $tableExists) {
    $testData = [
        'amount' => $_POST['amount'] ?? 1.00,
        'description' => $_POST['description'] ?? 'Test Payment',
        'customerName' => $_POST['customerName'] ?? 'Test User',
        'customerPhone' => $_POST['customerPhone'] ?? '0555123456',
        'customerEmail' => $_POST['customerEmail'] ?? ''
    ];
    
    // Store test data in session for return page
    $_SESSION['test_payment_data'] = $testData;
    
    // Redirect to the AJAX handler (simulating AJAX call)
    header('Location: ajax_test_hubtel_v2.php?' . http_build_query($testData));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Hubtel Payment Integration V2</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { padding: 20px; }
        .container { max-width: 800px; }
        .card { margin-bottom: 20px; }
        .alert { margin-bottom: 20px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Test Hubtel Payment Integration V2</h1>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Integration Status</h5>
            </div>
            <div class="card-body">
                <h6>Database Setup</h6>
                <?php if (!$dbConnected): ?>
                    <div class="alert alert-danger">
                        <strong>Database Connection Error:</strong> <?php echo htmlspecialchars($dbError ?? 'Unknown error'); ?>
                        <p class="mt-2">Please check your database configuration in <code>config/database.php</code>.</p>
                    </div>
                <?php elseif ($tableExists): ?>
                    <div class="alert alert-success">
                        <strong>Success!</strong> The payment_intents table exists in the database.
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <strong>Warning!</strong> The payment_intents table does not exist in the database.
                        <form method="post" class="mt-3">
                            <button type="submit" name="create_table" class="btn btn-primary">Create Table</button>
                        </form>
                    </div>
                    <?php if (isset($tableError)): ?>
                        <div class="alert alert-danger">
                            <strong>Error:</strong> <?php echo htmlspecialchars($tableError); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($tableCreated)): ?>
                        <div class="alert alert-success">
                            <strong>Success!</strong> The payment_intents table has been created.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <h6 class="mt-4">API Credentials</h6>
                <?php
                // Robust env reading similar to helpers
                $readEnv = function ($key, $constFallback = null) {
                    $val = getenv($key);
                    if ($val === false || $val === null || $val === '') {
                        if (isset($_ENV[$key]) && $_ENV[$key] !== '') $val = $_ENV[$key];
                        elseif (isset($_SERVER[$key]) && $_SERVER[$key] !== '') $val = $_SERVER[$key];
                        elseif ($constFallback && defined($constFallback)) $val = constant($constFallback);
                    }
                    return ($val === false || $val === '') ? null : $val;
                };
                $api_key = $readEnv('HUBTEL_API_KEY', 'HUBTEL_API_KEY');
                $api_secret = $readEnv('HUBTEL_API_SECRET', 'HUBTEL_API_SECRET');
                $merchant_account = $readEnv('HUBTEL_MERCHANT_ACCOUNT', 'HUBTEL_MERCHANT_ACCOUNT');
                ?>
                
                <ul class="list-group">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        HUBTEL_API_KEY
                        <?php if ($api_key): ?>
                            <span class="badge badge-success">Set</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not Set</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        HUBTEL_API_SECRET
                        <?php if ($api_secret): ?>
                            <span class="badge badge-success">Set</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not Set</span>
                        <?php endif; ?>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        HUBTEL_MERCHANT_ACCOUNT
                        <?php if ($merchant_account): ?>
                            <span class="badge badge-success">Set</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not Set</span>
                        <?php endif; ?>
                    </li>
                </ul>
                
                <?php if (!$api_key || !$api_secret || !$merchant_account): ?>
                    <div class="alert alert-danger mt-3">
                        <strong>Error:</strong> Some Hubtel API credentials are missing. Please check your .env file or constants.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($tableExists && $api_key && $api_secret && $merchant_account): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Test Payment</h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="form-group">
                            <label for="amount">Amount (GHS)</label>
                            <input type="number" class="form-control" id="amount" name="amount" value="1.00" step="0.01" min="1.00" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <input type="text" class="form-control" id="description" name="description" value="Test Payment" required>
                        </div>
                        <div class="form-group">
                            <label for="customerName">Customer Name</label>
                            <input type="text" class="form-control" id="customerName" name="customerName" value="Test User" required>
                        </div>
                        <div class="form-group">
                            <label for="customerPhone">Customer Phone</label>
                            <input type="text" class="form-control" id="customerPhone" name="customerPhone" value="0555123456" required>
                        </div>
                        <div class="form-group">
                            <label for="customerEmail">Customer Email (Optional)</label>
                            <input type="email" class="form-control" id="customerEmail" name="customerEmail">
                        </div>
                        <button type="submit" name="test_payment" class="btn btn-success">Make Test Payment</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">Implementation Details</h5>
            </div>
            <div class="card-body">
                <h6>Files Created</h6>
                <ul>
                    <li><code>helpers/hubtel_payment_v2.php</code> - New payment helper using the payproxyapi.hubtel.com endpoint</li>
                    <li><code>views/ajax_hubtel_checkout_v2.php</code> - AJAX handler for the new endpoint</li>
                    <li><code>views/hubtel_callback_v2.php</code> - Callback handler for the new endpoint</li>
                    <li><code>sql/create_payment_intents_table.sql</code> - SQL script to create the payment_intents table</li>
                    <li><code>views/test_hubtel_v2.php</code> - This test page</li>
                    <li><code>views/ajax_test_hubtel_v2.php</code> - Test AJAX handler</li>
                </ul>
                
                <h6 class="mt-4">Key Differences from Original Implementation</h6>
                <ul>
                    <li>New endpoint: <code>https://payproxyapi.hubtel.com/items/initiate</code></li>
                    <li>New parameter names: <code>totalAmount</code> instead of <code>amount</code>, <code>payeeName</code> instead of <code>customerName</code>, etc.</li>
                    <li>New required parameters: <code>merchantAccountNumber</code>, <code>cancellationUrl</code></li>
                    <li>New response fields: <code>checkoutDirectUrl</code>, <code>checkoutId</code></li>
                    <li>Payment tracking via <code>payment_intents</code> table</li>
                </ul>
                
                <h6 class="mt-4">Testing Instructions</h6>
                <ol>
                    <li>Ensure all API credentials are set in your .env file or constants</li>
                    <li>Create the payment_intents table if it doesn't exist</li>
                    <li>Fill out the test payment form and submit</li>
                    <li>You will be redirected to the Hubtel payment page</li>
                    <li>Complete the payment or cancel</li>
                    <li>Check the logs in <code>logs/hubtel_api_v2.log</code> and <code>logs/hubtel_callback_v2.log</code></li>
                </ol>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
