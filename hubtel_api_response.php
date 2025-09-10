<?php
require_once __DIR__.'/config/config.php';
require_once __DIR__.'/helpers/auth.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: '.BASE_URL.'/login.php');
    exit;
}

// Sample Hubtel API response data (like in the screenshot)
$hubtel_response = [
    "message" => "Successful",
    "responseCode" => "0000",
    "data" => [
        "date" => "2025-08-29T11:35:12.074127Z",
        "status" => "Paid",
        "transactionId" => "846d696e872947a9d162cadf3b509bb",
        "externalTransactionId" => "639073151570",
        "paymentMethod" => "mobilemoney",
        "clientReference" => "PAY-68b1905045ef",
        "currencyCode" => null,
        "amount" => 1.010000000000000088817841970012523233890533447265625,
        "charges" => 0.010000000000000002081668171172168513294309377670288085937,
        "amountAfterCharges" => 1,
        "isFulfilled" => null
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hubtel API Response Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 250px;
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            overflow-y: auto;
            z-index: 1000;
        }
        .sidebar .brand {
            padding: 20px;
            font-size: 1.5rem;
            font-weight: bold;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar .nav-item {
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover {
            background-color: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
        }
        .sidebar .nav-link.active {
            background-color: rgba(255,255,255,0.2);
            color: white;
        }
        .sidebar .nav-link i {
            margin-right: 10px;
            width: 20px;
        }
        .response-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
            margin-top: 20px;
        }
        .response-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        .response-header i {
            font-size: 1.5rem;
            color: #28a745;
            margin-right: 15px;
        }
        .response-header h2 {
            margin: 0;
            color: #495057;
            font-weight: 600;
        }
        .json-container {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 25px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            overflow-x: auto;
        }
        .json-key {
            color: #d73a49;
            font-weight: bold;
        }
        .json-string {
            color: #032f62;
        }
        .json-number {
            color: #005cc5;
        }
        .json-null {
            color: #6f42c1;
            font-style: italic;
        }
        .json-bracket {
            color: #24292e;
            font-weight: bold;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">
            <i class="fas fa-church"></i> MyFreeman
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="<?php echo BASE_URL; ?>/views/dashboard.php">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-calendar-check"></i> ATTENDANCE
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-book-open"></i> BIBLE CLASSES
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-church"></i> CHURCHES
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-users"></i> CLASS GROUP
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-calendar-alt"></i> EVENTS
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-comments"></i> FEEDBACK
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-heartbeat"></i> HEALTH
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-users"></i> MEMBERS
            </a>
            <a class="nav-link" href="#">
                <i class="fas fa-building"></i> ORGANIZATIONS
            </a>
            <a class="nav-link active" href="<?php echo BASE_URL; ?>/views/payment_list.php">
                <i class="fas fa-credit-card"></i> PAYMENTS
            </a>
        </nav>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="response-container">
            <div class="response-header">
                <i class="fas fa-code"></i>
                <h2>Hubtel API Response Data</h2>
            </div>
            
            <div class="json-container">
<span class="json-bracket">{</span>
    <span class="json-key">"message"</span>: <span class="json-string">"Successful"</span>,
    <span class="json-key">"responseCode"</span>: <span class="json-string">"0000"</span>,
    <span class="json-key">"data"</span>: <span class="json-bracket">{</span>
        <span class="json-key">"date"</span>: <span class="json-string">"2025-08-29T11:35:12.074127Z"</span>,
        <span class="json-key">"status"</span>: <span class="json-string">"Paid"</span>,
        <span class="json-key">"transactionId"</span>: <span class="json-string">"846d696e872947a9d162cadf3b509bb"</span>,
        <span class="json-key">"externalTransactionId"</span>: <span class="json-string">"639073151570"</span>,
        <span class="json-key">"paymentMethod"</span>: <span class="json-string">"mobilemoney"</span>,
        <span class="json-key">"clientReference"</span>: <span class="json-string">"PAY-68b1905045ef"</span>,
        <span class="json-key">"currencyCode"</span>: <span class="json-null">null</span>,
        <span class="json-key">"amount"</span>: <span class="json-number">1.010000000000000088817841970012523233890533447265625</span>,
        <span class="json-key">"charges"</span>: <span class="json-number">0.010000000000000002081668171172168513294309377670288085937</span>,
        <span class="json-key">"amountAfterCharges"</span>: <span class="json-number">1</span>,
        <span class="json-key">"isFulfilled"</span>: <span class="json-null">null</span>
    <span class="json-bracket">}</span>
<span class="json-bracket">}</span>
            </div>

            <div class="mt-4">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-success text-white">
                                <i class="fas fa-check-circle"></i> Transaction Status
                            </div>
                            <div class="card-body">
                                <h5 class="text-success">✅ Paid</h5>
                                <p class="mb-1"><strong>Reference:</strong> PAY-68b1905045ef</p>
                                <p class="mb-1"><strong>Transaction ID:</strong> 846d696e872947a9d162cadf3b509bb</p>
                                <p class="mb-0"><strong>Method:</strong> Mobile Money</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header bg-info text-white">
                                <i class="fas fa-money-bill-wave"></i> Payment Details
                            </div>
                            <div class="card-body">
                                <h5 class="text-primary">₵1.00</h5>
                                <p class="mb-1"><strong>Charges:</strong> ₵0.01</p>
                                <p class="mb-1"><strong>Total Paid:</strong> ₵1.01</p>
                                <p class="mb-0"><strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime('2025-08-29T11:35:12.074127Z')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <a href="<?php echo BASE_URL; ?>/views/payment_list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Payments
                </a>
                <a href="<?php echo BASE_URL; ?>/test_hubtel_callback.php" class="btn btn-primary">
                    <i class="fas fa-test-tube"></i> Test Callback
                </a>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
