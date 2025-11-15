<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: login.php');
    exit;
}

// Get test payment data from session
$testData = $_SESSION['test_payment_data'] ?? null;
$cancelled = isset($_GET['cancelled']) && $_GET['cancelled'] == '1';

// Check for payment status in database
$paymentStatus = null;
$paymentRecord = null;
if ($testData && isset($testData['clientReference'])) {
    try {
        require_once __DIR__.'/../config/database.php';
        $stmt = $conn->prepare('SELECT * FROM payment_intents WHERE client_reference = ?');
        $stmt->bind_param('s', $testData['clientReference']);
        $stmt->execute();
        $result = $stmt->get_result();
        $paymentRecord = $result->fetch_assoc();
        
        if ($paymentRecord) {
            $paymentStatus = $paymentRecord['status'];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hubtel Payment Return</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { padding: 20px; }
        .container { max-width: 800px; }
        .card { margin-bottom: 20px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="mb-4">Hubtel Payment Return</h1>
        
        <?php if ($cancelled): ?>
            <div class="alert alert-warning">
                <strong>Payment Cancelled!</strong> You cancelled the payment process.
            </div>
        <?php elseif ($paymentStatus === 'success' || $paymentStatus === 'completed'): ?>
            <div class="alert alert-success">
                <strong>Payment Successful!</strong> Your payment has been completed.
            </div>
        <?php elseif ($paymentStatus): ?>
            <div class="alert alert-info">
                <strong>Payment Status:</strong> <?php echo htmlspecialchars($paymentStatus); ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <strong>Payment Status Unknown</strong> The payment status has not been updated yet. This could be because:
                <ul>
                    <li>The callback from Hubtel has not been received yet</li>
                    <li>There was an issue processing the callback</li>
                    <li>The payment is still being processed</li>
                </ul>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Payment Details</h5>
            </div>
            <div class="card-body">
                <?php if ($testData): ?>
                    <table class="table">
                        <tr>
                            <th>Client Reference</th>
                            <td><?php echo htmlspecialchars($testData['clientReference'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Amount</th>
                            <td>GHS <?php echo number_format($testData['amount'] ?? 0, 2); ?></td>
                        </tr>
                        <tr>
                            <th>Description</th>
                            <td><?php echo htmlspecialchars($testData['description'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Customer Name</th>
                            <td><?php echo htmlspecialchars($testData['customerName'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Customer Phone</th>
                            <td><?php echo htmlspecialchars($testData['customerPhone'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php if (!empty($testData['customerEmail'])): ?>
                        <tr>
                            <th>Customer Email</th>
                            <td><?php echo htmlspecialchars($testData['customerEmail']); ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> No payment data found in session.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($paymentRecord): ?>
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Database Record</h5>
                </div>
                <div class="card-body">
                    <table class="table">
                        <tr>
                            <th>ID</th>
                            <td><?php echo htmlspecialchars($paymentRecord['id'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td><?php echo htmlspecialchars($paymentRecord['status'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Checkout ID</th>
                            <td><?php echo htmlspecialchars($paymentRecord['checkout_id'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Transaction ID</th>
                            <td><?php echo htmlspecialchars($paymentRecord['transaction_id'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Created At</th>
                            <td><?php echo htmlspecialchars($paymentRecord['created_at'] ?? 'N/A'); ?></td>
                        </tr>
                        <tr>
                            <th>Updated At</th>
                            <td><?php echo htmlspecialchars($paymentRecord['updated_at'] ?? 'N/A'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <a href="test_hubtel_v2.php" class="btn btn-primary">Back to Test Page</a>
            
            <?php if ($paymentRecord): ?>
                <a href="test_hubtel_v2.php?refresh=1" class="btn btn-success">Make Another Test Payment</a>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
