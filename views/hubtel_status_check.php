<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
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

// Get pending payment intents for display
$pending_stmt = $conn->prepare("
    SELECT pi.*, m.crn, CONCAT(m.first_name, ' ', m.last_name) as member_name, c.name as church_name
    FROM payment_intents pi 
    LEFT JOIN members m ON pi.member_id = m.id 
    LEFT JOIN churches c ON pi.church_id = c.id 
    WHERE pi.status = 'Pending' 
    ORDER BY pi.created_at DESC 
    LIMIT 20
");
$pending_stmt->execute();
$pending_intents = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

ob_start();
?>

<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800">Hubtel Payment Status Check</h1>
            <p class="mb-0 text-muted">Verify and update payment transaction statuses</p>
        </div>
        <div>
            <a href="payment_list.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Payments
            </a>
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
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Single Transaction Status Check</h6>
        </div>
        <div class="card-body">
            <form method="POST" class="row">
                <input type="hidden" name="action" value="check_single">
                <div class="col-md-8 mb-3">
                    <label for="client_reference" class="form-label">Client Reference</label>
                    <input type="text" class="form-control" id="client_reference" name="client_reference" 
                           placeholder="Enter client reference (e.g., PAY-xxxxx)" required>
                    <small class="form-text text-muted">Enter the client reference from the payment intent</small>
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Check Status
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
                
                <?php if (isset($check_result['hubtel_data'])): ?>
                <div class="card border-secondary">
                    <div class="card-header">
                        <i class="fas fa-code"></i> Hubtel API Response Data
                    </div>
                    <div class="card-body">
                        <pre class="bg-light p-3 rounded"><code><?= htmlspecialchars(json_encode($check_result['hubtel_data'], JSON_PRETTY_PRINT)) ?></code></pre>
                    </div>
                </div>
                <?php endif; ?>
                
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
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Bulk Status Check</h6>
        </div>
        <div class="card-body">
            <form method="POST" class="row">
                <input type="hidden" name="action" value="bulk_check">
                <div class="col-md-8 mb-3">
                    <label for="limit" class="form-label">Number of Records to Check</label>
                    <select class="form-control" id="limit" name="limit">
                        <option value="10">10 records</option>
                        <option value="25" selected>25 records</option>
                        <option value="50">50 records</option>
                        <option value="100">100 records</option>
                    </select>
                    <small class="form-text text-muted">Check the most recent pending payment intents</small>
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-sync"></i> Bulk Check
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
                                    <td><?= date('M j, Y H:i', strtotime($detail['created_at'])) ?></td>
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
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Recent Pending Payment Intents</h6>
        </div>
        <div class="card-body">
            <?php if (empty($pending_intents)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle fa-3x mb-3"></i>
                    <p>No pending payment intents found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>Client Reference</th>
                                <th>Member</th>
                                <th>Church</th>
                                <th>Amount</th>
                                <th>Description</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_intents as $intent): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($intent['client_reference']) ?></code></td>
                                <td>
                                    <?php if ($intent['member_name']): ?>
                                        <?= htmlspecialchars($intent['member_name']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($intent['crn']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">Unknown Member</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($intent['church_name'] ?? 'Unknown') ?></td>
                                <td>₵<?= number_format($intent['amount'], 2) ?></td>
                                <td><?= htmlspecialchars($intent['description']) ?></td>
                                <td>
                                    <?= date('M j, Y', strtotime($intent['created_at'])) ?>
                                    <br><small class="text-muted"><?= date('H:i', strtotime($intent['created_at'])) ?></small>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="check_single">
                                        <input type="hidden" name="client_reference" value="<?= htmlspecialchars($intent['client_reference']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Check Status">
                                            <i class="fas fa-sync"></i>
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
