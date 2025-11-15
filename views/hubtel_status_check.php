<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/hubtel_status.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check permissions - only super admin or users with payment management permissions
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('manage_payments')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

$message = '';
$message_type = '';
$check_result = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'check_single':
                $client_reference = trim($_POST['client_reference'] ?? '');
                if ($client_reference) {
                    $check_result = check_transaction_by_reference($conn, $client_reference);
                    if ($check_result['success']) {
                        $message = 'Status check completed successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Status check failed: ' . $check_result['error'];
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Please enter a client reference.';
                    $message_type = 'warning';
                }
                break;
                
            case 'bulk_check':
                $limit = intval($_POST['limit'] ?? 50);
                $limit = max(1, min(100, $limit)); // Limit between 1-100
                
                $bulk_result = bulk_check_pending_payments($conn, $limit);
                $message = "Bulk check completed. Checked: {$bulk_result['total_checked']}, Updated: {$bulk_result['updated_count']}, Failed: {$bulk_result['failed_count']}";
                $message_type = $bulk_result['failed_count'] > 0 ? 'warning' : 'success';
                $check_result = $bulk_result;
                break;
        }
    }
}

// Get pending payment intents for display - debug the created_at issue
$pending_stmt = $conn->prepare("
    SELECT pi.*, m.crn, CONCAT(m.first_name, ' ', m.last_name) as member_name, c.name as church_name,
           pi.created_at as debug_created_at,
           UNIX_TIMESTAMP(pi.created_at) as created_timestamp
    FROM payment_intents pi 
    LEFT JOIN members m ON pi.member_id = m.id 
    LEFT JOIN churches c ON pi.church_id = c.id 
    WHERE pi.status = 'Pending' 
    ORDER BY pi.created_at DESC 
    LIMIT 20
");
$pending_stmt->execute();
$pending_intents = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Debug: Log the first few records to see what's in created_at
if (!empty($pending_intents)) {
    error_log("DEBUG: First payment intent created_at: " . print_r($pending_intents[0]['created_at'], true));
    error_log("DEBUG: First payment intent debug_created_at: " . print_r($pending_intents[0]['debug_created_at'], true));
    error_log("DEBUG: First payment intent timestamp: " . print_r($pending_intents[0]['created_timestamp'], true));
}

ob_start();
?>

<div class="container-fluid">
    <!-- Modern Header with Gradient Background -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-lg" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="card-body text-white py-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="h2 mb-2 font-weight-bold">
                                <i class="fas fa-chart-line mr-3"></i>Hubtel Payment Status
                            </h1>
                            <p class="mb-0 opacity-75 lead">Real-time payment verification and status management</p>
                        </div>
                        <div class="text-right">
                            <a href="payment_list.php" class="btn btn-light btn-lg shadow-sm">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Payments
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <?php endif; ?>

    <!-- Single Status Check -->
    <div class="card border-0 shadow-lg mb-4">
        <div class="card-header border-0 py-4" style="background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);">
            <div class="d-flex align-items-center">
                <div class="icon-circle bg-white text-primary mr-3" style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-search fa-lg"></i>
                </div>
                <div>
                    <h5 class="m-0 font-weight-bold text-white">Single Transaction Check</h5>
                    <p class="m-0 text-white opacity-75">Verify individual payment status</p>
                </div>
            </div>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row">
                <input type="hidden" name="action" value="check_single">
                <div class="col-md-8 mb-4">
                    <label for="client_reference" class="form-label font-weight-bold text-dark">Payment Reference</label>
                    <div class="input-group input-group-lg">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-light border-right-0">
                                <i class="fas fa-hashtag text-muted"></i>
                            </span>
                        </div>
                        <input type="text" class="form-control border-left-0 shadow-sm" id="client_reference" name="client_reference" 
                               placeholder="Enter payment reference (e.g., PAY-xxxxx)" required style="font-family: 'Courier New', monospace;">
                    </div>
                    <small class="form-text text-muted mt-2">
                        <i class="fas fa-info-circle mr-1"></i>Enter the client reference from the payment transaction
                    </small>
                </div>
                <div class="col-md-4 mb-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-lg btn-primary shadow-sm w-100" style="background: linear-gradient(45deg, #667eea, #764ba2); border: none;">
                        <i class="fas fa-search mr-2"></i>Check Status
                    </button>
                </div>
            </form>
            
            <?php if ($check_result && isset($_POST['action']) && $_POST['action'] === 'check_single'): ?>
            <div class="mt-4">
                <h6 class="mb-3">Status Check Result:</h6>
                <?php
                $status_color = 'secondary';
                $status_icon = 'fas fa-question-circle';
                
                if (isset($check_result['current_status'])) {
                    switch (strtolower($check_result['current_status'])) {
                        case 'completed':
                            $status_color = 'success';
                            $status_icon = 'fas fa-check-circle';
                            break;
                        case 'failed':
                            $status_color = 'danger';
                            $status_icon = 'fas fa-times-circle';
                            break;
                        case 'pending':
                            $status_color = 'warning';
                            $status_icon = 'fas fa-clock';
                            break;
                    }
                }
                
                $method_color = ($check_result['method'] ?? '') === 'hubtel_api' ? 'primary' : 'info';
                $method_icon = ($check_result['method'] ?? '') === 'hubtel_api' ? 'fas fa-cloud' : 'fas fa-database';
                ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-<?= $status_color ?> mb-3">
                            <div class="card-header bg-<?= $status_color ?> text-white">
                                <i class="<?= $status_icon ?>"></i> Payment Status
                            </div>
                            <div class="card-body">
                                <h5 class="card-title text-<?= $status_color ?>">
                                    <?= htmlspecialchars($check_result['current_status'] ?? 'Unknown') ?>
                                </h5>
                                <p class="card-text">
                                    <strong>Transaction ID:</strong><br>
                                    <code><?= htmlspecialchars($check_result['transaction_id'] ?? 'N/A') ?></code>
                                </p>
                                
                                <?php if (isset($check_result['hubtel_data']['data'])): 
                                    $hubtel_data = $check_result['hubtel_data']['data']; ?>
                                <div class="mt-3">
                                    <h6 class="text-muted">Payment Details</h6>
                                    <table class="table table-sm table-borderless">
                                        <?php if (isset($hubtel_data['amount'])): ?>
                                        <tr>
                                            <td><strong>Amount:</strong></td>
                                            <td>GHS <?= number_format($hubtel_data['amount'], 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($hubtel_data['charges']) && $hubtel_data['charges'] > 0): ?>
                                        <tr>
                                            <td><strong>Charges:</strong></td>
                                            <td>GHS <?= number_format($hubtel_data['charges'], 2) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Net Amount:</strong></td>
                                            <td>GHS <?= number_format($hubtel_data['amountAfterCharges'] ?? $hubtel_data['amount'], 2) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($hubtel_data['paymentMethod'])): ?>
                                        <tr>
                                            <td><strong>Payment Method:</strong></td>
                                            <td><?= ucfirst(str_replace('mobilemoney', 'Mobile Money', $hubtel_data['paymentMethod'])) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($hubtel_data['externalTransactionId'])): ?>
                                        <tr>
                                            <td><strong>Provider Ref:</strong></td>
                                            <td><code><?= htmlspecialchars($hubtel_data['externalTransactionId']) ?></code></td>
                                        </tr>
                                        <?php endif; ?>
                                        
                                        <?php if (isset($hubtel_data['date'])): ?>
                                        <tr>
                                            <td><strong>Payment Date:</strong></td>
                                            <td><?= date('M j, Y H:i', strtotime($hubtel_data['date'])) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($check_result['status_updated']) && $check_result['status_updated']): ?>
                                <div class="alert alert-info alert-sm">
                                    <i class="fas fa-sync-alt"></i> Status updated from 
                                    <strong><?= htmlspecialchars($check_result['old_status']) ?></strong> to 
                                    <strong><?= htmlspecialchars($check_result['new_status']) ?></strong>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card border-<?= $method_color ?> mb-3">
                            <div class="card-header bg-<?= $method_color ?> text-white">
                                <i class="<?= $method_icon ?>"></i> Check Method
                            </div>
                            <div class="card-body">
                                <h6 class="card-title text-<?= $method_color ?>">
                                    <?php
                                    $method_display = [
                                        'hubtel_api' => 'Hubtel API',
                                        'database_only' => 'Database Only'
                                    ];
                                    echo $method_display[$check_result['method'] ?? 'unknown'] ?? 'Unknown';
                                    ?>
                                </h6>
                                <?php if (isset($check_result['note'])): ?>
                                <p class="card-text text-muted">
                                    <i class="fas fa-info-circle"></i> <?= htmlspecialchars($check_result['note']) ?>
                                </p>
                                <?php endif; ?>
                                
                                <?php if (isset($check_result['api_error'])): ?>
                                <div class="alert alert-warning alert-sm">
                                    <i class="fas fa-exclamation-triangle"></i> 
                                    <strong>API Note:</strong> <?= htmlspecialchars($check_result['api_error']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                
                <!-- Success/Failure Summary -->
                <?php if ($check_result['success']): ?>
                <div class="alert alert-success mt-3">
                    <i class="fas fa-check-circle"></i> 
                    <strong>Check Completed Successfully</strong>
                    <?php if (isset($check_result['status_updated']) && $check_result['status_updated']): ?>
                    - Status was updated in the database
                    <?php else: ?>
                    - No status changes needed
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-danger mt-3">
                    <i class="fas fa-times-circle"></i> 
                    <strong>Check Failed:</strong> <?= htmlspecialchars($check_result['error'] ?? 'Unknown error') ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bulk Status Check -->
    <div class="card border-0 shadow-lg mb-4">
        <div class="card-header border-0 py-4" style="background: linear-gradient(90deg, #fa709a 0%, #fee140 100%);">
            <div class="d-flex align-items-center">
                <div class="icon-circle bg-white text-warning mr-3" style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-sync fa-lg"></i>
                </div>
                <div>
                    <h5 class="m-0 font-weight-bold text-white">Bulk Status Check</h5>
                    <p class="m-0 text-white opacity-75">Update multiple pending payments</p>
                </div>
            </div>
        </div>
        <div class="card-body p-4">
            <form method="POST" class="row">
                <input type="hidden" name="action" value="bulk_check">
                <div class="col-md-8 mb-4">
                    <label for="limit" class="form-label font-weight-bold text-dark">Batch Size</label>
                    <div class="input-group input-group-lg">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-light border-right-0">
                                <i class="fas fa-list-ol text-muted"></i>
                            </span>
                        </div>
                        <select class="form-control border-left-0 shadow-sm" id="limit" name="limit">
                            <option value="10">🔢 10 records</option>
                            <option value="25" selected>🔢 25 records (Recommended)</option>
                            <option value="50">🔢 50 records</option>
                            <option value="100">🔢 100 records</option>
                        </select>
                    </div>
                    <small class="form-text text-muted mt-2">
                        <i class="fas fa-clock mr-1"></i>Process the most recent pending payment transactions
                    </small>
                </div>
                <div class="col-md-4 mb-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-lg btn-warning shadow-sm w-100" style="background: linear-gradient(45deg, #fa709a, #fee140); border: none; color: white;">
                        <i class="fas fa-sync mr-2"></i>Start Bulk Check
                    </button>
                </div>
            </form>
            
            <?php if ($check_result && isset($_POST['action']) && $_POST['action'] === 'bulk_check'): ?>
            <div class="mt-4">
                <h6>Bulk Check Summary:</h6>
                <div class="row">
                    <div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h4><?= $check_result['total_checked'] ?></h4>
                                <small>Total Checked</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h4><?= $check_result['updated_count'] ?></h4>
                                <small>Updated</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body text-center">
                                <h4><?= $check_result['failed_count'] ?></h4>
                                <small>Failed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h4><?= $check_result['total_checked'] - $check_result['updated_count'] - $check_result['failed_count'] ?></h4>
                                <small>No Change</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($check_result['details'])): ?>
                <div class="mt-3">
                    <h6>Detailed Results:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Client Reference</th>
                                    <th>Created</th>
                                    <th>Status</th>
                                    <th>Result</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($check_result['details'] as $detail): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($detail['client_reference']) ?></code></td>
                                    <td>
                                        <?php if (!empty($detail['created_at']) && $detail['created_at'] !== '0000-00-00 00:00:00'): ?>
                                            <?= date('M j, Y H:i', strtotime($detail['created_at'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted font-italic">No Date</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($detail['result']['success']): ?>
                                            <?php if ($detail['result']['status_updated'] ?? false): ?>
                                                <span class="badge badge-success">Updated</span>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($detail['result']['old_status']) ?> → 
                                                    <?= htmlspecialchars($detail['result']['new_status']) ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="badge badge-info">No Change</span>
                                                <small class="text-muted"><?= htmlspecialchars($detail['result']['current_status']) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$detail['result']['success']): ?>
                                            <small class="text-danger"><?= htmlspecialchars($detail['result']['error']) ?></small>
                                        <?php else: ?>
                                            <small class="text-success">OK</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending Payment Intents -->
    <div class="card border-0 shadow-lg mb-4">
        <div class="card-header border-0 py-4" style="background: linear-gradient(90deg, #a8edea 0%, #fed6e3 100%);">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center">
                    <div class="icon-circle bg-white text-info mr-3" style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-clock fa-lg"></i>
                    </div>
                    <div>
                        <h5 class="m-0 font-weight-bold text-dark">Pending Payments</h5>
                        <p class="m-0 text-muted">Recent transactions awaiting confirmation</p>
                    </div>
                </div>
                <div class="badge badge-pill badge-primary badge-lg px-3 py-2">
                    <?= count($pending_intents) ?> pending
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <?php if (empty($pending_intents)): ?>
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h4 class="text-success font-weight-bold">All Clear!</h4>
                    <p class="text-muted lead">No pending payment transactions found.</p>
                    <div class="mt-4">
                        <span class="badge badge-success badge-pill px-4 py-2">
                            <i class="fas fa-thumbs-up mr-2"></i>System Up to Date
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead style="background: linear-gradient(90deg, #f8f9fa 0%, #e9ecef 100%);">
                            <tr>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-hashtag mr-2 text-primary"></i>Reference
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-user mr-2 text-success"></i>Member
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-church mr-2 text-info"></i>Church
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-money-bill mr-2 text-warning"></i>Amount
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-file-alt mr-2 text-secondary"></i>Description
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3">
                                    <i class="fas fa-calendar mr-2 text-danger"></i>Created
                                </th>
                                <th class="border-0 font-weight-bold text-dark py-3 text-center">
                                    <i class="fas fa-cogs mr-2 text-dark"></i>Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_intents as $intent): ?>
                            <tr class="border-left-0 border-right-0" style="border-top: 1px solid #f1f3f4;">
                                <td class="py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-primary text-white rounded-circle mr-3" style="width: 8px; height: 8px;"></div>
                                        <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($intent['client_reference']) ?></code>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <?php if ($intent['member_name']): ?>
                                        <div class="font-weight-bold text-dark"><?= htmlspecialchars($intent['member_name']) ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-id-card mr-1"></i><?= htmlspecialchars($intent['crn']) ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted font-italic">
                                            <i class="fas fa-user-slash mr-1"></i>Unknown Member
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3">
                                    <span class="badge badge-info badge-pill px-3 py-2">
                                        <?= htmlspecialchars($intent['church_name'] ?? 'Unknown') ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <div class="font-weight-bold text-success h5 mb-0">
                                        ₵<?= number_format($intent['amount'], 2) ?>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <div class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($intent['description']) ?>">
                                        <?= htmlspecialchars($intent['description']) ?>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <?php if (!empty($intent['created_at']) && $intent['created_at'] !== '0000-00-00 00:00:00'): ?>
                                        <div class="font-weight-bold text-dark"><?= date('M j, Y', strtotime($intent['created_at'])) ?></div>
                                        <small class="text-muted">
                                            <i class="fas fa-clock mr-1"></i><?= date('H:i', strtotime($intent['created_at'])) ?>
                                        </small>
                                    <?php else: ?>
                                        <div class="text-muted font-italic">
                                            <i class="fas fa-question-circle mr-1"></i>No Date
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-center">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="check_single">
                                        <input type="hidden" name="client_reference" value="<?= htmlspecialchars($intent['client_reference']) ?>">
                                        <button type="submit" class="btn btn-sm btn-primary shadow-sm rounded-pill" title="Check Status">
                                            <i class="fas fa-sync mr-1"></i>Check
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
