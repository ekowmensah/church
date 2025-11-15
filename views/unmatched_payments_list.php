<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log errors to a file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('manage_payments')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_assign = $is_super_admin || has_permission('manage_payments');
$can_view = true; // Already validated above

// Handle assignment action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign') {
    $payment_id = intval($_POST['payment_id']);
    $member_id = intval($_POST['member_id']);
    $user_id = $_SESSION['user_id'];
    
    if ($payment_id && $member_id) {
        // Get unmatched payment details
        $stmt = $conn->prepare('SELECT * FROM unmatched_payments WHERE id = ? AND assigned_member_id IS NULL');
        $stmt->bind_param('i', $payment_id);
        $stmt->execute();
        $unmatched = $stmt->get_result()->fetch_assoc();
        
        if ($unmatched) {
            // Get member details
            $member_stmt = $conn->prepare('SELECT id, CONCAT(first_name, " ", last_name) as full_name, church_id FROM members WHERE id = ? AND status = "active"');
            $member_stmt->bind_param('i', $member_id);
            $member_stmt->execute();
            $member = $member_stmt->get_result()->fetch_assoc();
            
            if ($member) {
                $conn->begin_transaction();
                
                try {
                    // Create payment record
                    require_once __DIR__.'/../models/Payment.php';
                    $paymentModel = new Payment();
                    
                    $payment_data = [
                        'member_id' => $member_id,
                        'amount' => $unmatched['amount'],
                        'description' => "Shortcode Payment (Assigned) - " . $unmatched['description'],
                        'payment_date' => $unmatched['transaction_date'],
                        'client_reference' => $unmatched['reference'],
                        'status' => 'Completed',
                        'church_id' => $member['church_id'],
                        'payment_type_id' => 1, // Default to general offering
                        'recorded_by' => $user_id,
                        'mode' => 'Mobile Money'
                    ];
                    
                    $result = $paymentModel->add($conn, $payment_data);
                    
                    if ($result && isset($result['id'])) {
                        // Mark unmatched payment as assigned
                        $assign_stmt = $conn->prepare('UPDATE unmatched_payments SET assigned_member_id = ?, assigned_by = ?, assigned_at = NOW() WHERE id = ?');
                        $assign_stmt->bind_param('iii', $member_id, $user_id, $payment_id);
                        $assign_stmt->execute();
                        
                        $conn->commit();
                        $success_message = "Payment successfully assigned to {$member['full_name']}";
                    } else {
                        throw new Exception('Failed to create payment record');
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Failed to assign payment: " . $e->getMessage();
                }
            } else {
                $error_message = "Member not found or inactive";
            }
        } else {
            $error_message = "Unmatched payment not found or already assigned";
        }
    }
}

// Get unmatched payments
$filter = $_GET['filter'] ?? 'unassigned';
$search = $_GET['search'] ?? '';

$where_conditions = [];
$params = [];
$param_types = '';

if ($filter === 'unassigned') {
    $where_conditions[] = 'up.assigned_member_id IS NULL';
} elseif ($filter === 'assigned') {
    $where_conditions[] = 'up.assigned_member_id IS NOT NULL';
}

if ($search) {
    $where_conditions[] = '(up.phone LIKE ? OR up.reference LIKE ? OR up.description LIKE ?)';
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
    $param_types .= 'sss';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "
    SELECT up.*, 
           CONCAT(m.first_name, ' ', m.last_name) as assigned_member_name,
           m.crn as assigned_member_crn,
           CONCAT(u.name) as assigned_by_name
    FROM unmatched_payments up
    LEFT JOIN members m ON up.assigned_member_id = m.id
    LEFT JOIN users u ON up.assigned_by = u.id
    $where_clause
    ORDER BY up.created_at DESC
";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$unmatched_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Page title setup
$page_title = 'Unmatched Shortcode Payments';

// Start output buffering for content
ob_start();
?>

<!-- Content Header (Page header) -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">
                    <i class="fas fa-mobile-alt text-primary me-2"></i>
                    Unmatched Shortcode Payments
                </h1>
                <p class="text-muted mb-0">Manage shortcode payments that couldn't be automatically matched to members</p>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>"><i class="fas fa-home"></i> Home</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>/views/payment_list.php">Payments</a></li>
                    <li class="breadcrumb-item active">Unmatched Payments</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
            
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo count(array_filter($unmatched_payments, function($p) { return !$p['assigned_member_id']; })); ?></h3>
                            <p>Unassigned Payments</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo count(array_filter($unmatched_payments, function($p) { return $p['assigned_member_id']; })); ?></h3>
                            <p>Assigned Payments</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3>₵<?php echo number_format(array_sum(array_column(array_filter($unmatched_payments, function($p) { return !$p['assigned_member_id']; }), 'amount')), 2); ?></h3>
                            <p>Unassigned Amount</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                    </div>
                </div>
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-primary">
                        <div class="inner">
                            <h3><?php echo count($unmatched_payments); ?></h3>
                            <p>Total Shortcode Payments</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Card -->
            <div class="card card-outline card-primary">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-list me-2"></i>
                        Shortcode Payments Management
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-tool" data-card-widget="collapse">
                            <i class="fas fa-minus"></i>
                        </button>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Filters -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <form method="GET" class="row g-3">
                                <div class="col-md-3">
                                    <label for="filter" class="form-label">Status Filter</label>
                                    <select name="filter" id="filter" class="form-control" onchange="this.form.submit()">
                                        <option value="unassigned" <?php echo $filter === 'unassigned' ? 'selected' : ''; ?>>🔍 Unassigned Only</option>
                                        <option value="assigned" <?php echo $filter === 'assigned' ? 'selected' : ''; ?>>✅ Assigned Only</option>
                                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>📋 All Payments</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="search" class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" name="search" id="search" class="form-control" 
                                               placeholder="Search phone, reference, description..." 
                                               value="<?php echo htmlspecialchars($search); ?>">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid">
                                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                            <i class="fas fa-undo"></i> Clear Filters
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Payments Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead class="thead-dark">
                                <tr>
                                    <th><i class="fas fa-calendar"></i> Date</th>
                                    <th><i class="fas fa-phone"></i> Phone</th>
                                    <th><i class="fas fa-money-bill"></i> Amount</th>
                                    <th><i class="fas fa-hashtag"></i> Reference</th>
                                    <th><i class="fas fa-info-circle"></i> Description</th>
                                    <th><i class="fas fa-flag"></i> Status</th>
                                    <th><i class="fas fa-user"></i> Assigned To</th>
                                    <th><i class="fas fa-cogs"></i> Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($unmatched_payments)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-5">
                                            <div class="empty-state">
                                                <i class="fas fa-inbox fa-3x mb-3 text-muted"></i><br>
                                                <h5 class="text-muted">No unmatched payments found</h5>
                                                <p class="text-muted">All shortcode payments have been successfully matched to members.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($unmatched_payments as $payment): ?>
                                        <tr>
                                            <td>
                                                <div class="text-nowrap">
                                                    <strong><?php echo date('M j, Y', strtotime($payment['transaction_date'])); ?></strong><br>
                                                    <small class="text-muted"><?php echo date('H:i', strtotime($payment['transaction_date'])); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <i class="fas fa-phone fa-xs"></i>
                                                    <?php echo htmlspecialchars($payment['phone']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong class="text-success">₵<?php echo number_format($payment['amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <code class="small"><?php echo htmlspecialchars($payment['reference']); ?></code>
                                            </td>
                                            <td>
                                                <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($payment['description']); ?>">
                                                    <?php echo htmlspecialchars($payment['description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $payment['status'] === 'Completed' ? 'success' : 'warning'; ?>">
                                                    <i class="fas fa-<?php echo $payment['status'] === 'Completed' ? 'check' : 'clock'; ?>"></i>
                                                    <?php echo $payment['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($payment['assigned_member_id']): ?>
                                                    <div class="assigned-member">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <i class="fas fa-user-check text-success me-1"></i>
                                                            <strong><?php echo htmlspecialchars($payment['assigned_member_name']); ?></strong>
                                                        </div>
                                                        <small class="text-muted d-block">
                                                            <i class="fas fa-id-card fa-xs"></i> <?php echo htmlspecialchars($payment['assigned_member_crn']); ?>
                                                        </small>
                                                        <small class="text-info d-block">
                                                            <i class="fas fa-user-tie fa-xs"></i> by <?php echo htmlspecialchars($payment['assigned_by_name']); ?>
                                                        </small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge badge-warning">
                                                        <i class="fas fa-exclamation-triangle"></i> Unassigned
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!$payment['assigned_member_id']): ?>
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            onclick="showAssignModal(<?php echo $payment['id']; ?>, '<?php echo htmlspecialchars($payment['phone']); ?>', '<?php echo number_format($payment['amount'], 2); ?>')">
                                                        <i class="fas fa-user-plus"></i> Assign Member
                                                    </button>
                                                <?php else: ?>
                                                    <div class="text-center">
                                                        <span class="badge badge-success">
                                                            <i class="fas fa-check-circle"></i> Assigned
                                                        </span>
                                                        <br>
                                                        <small class="text-muted"><?php echo date('M j', strtotime($payment['assigned_at'])); ?></small>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    </div>
</section>

<!-- Assignment Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="assignModalLabel">
                        <i class="fas fa-user-plus"></i> Assign Payment to Member
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="payment_id" id="assignPaymentId">
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> Payment Details</h6>
                        <div class="row">
                            <div class="col-6">
                                <strong><i class="fas fa-phone"></i> Phone:</strong><br>
                                <span class="badge badge-info" id="assignPhone"></span>
                            </div>
                            <div class="col-6">
                                <strong><i class="fas fa-money-bill"></i> Amount:</strong><br>
                                <span class="text-success font-weight-bold">₵<span id="assignAmount"></span></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="memberSearch" class="form-label">Search Member</label>
                        <input type="text" class="form-control" id="memberSearch" placeholder="Type member name or CRN...">
                        <div id="memberResults" class="mt-2"></div>
                    </div>
                    
                    <input type="hidden" name="member_id" id="selectedMemberId">
                    <div id="selectedMember" class="alert alert-success" style="display: none;">
                        <strong>Selected Member:</strong> <span id="selectedMemberName"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" id="assignButton" disabled>
                        <i class="fas fa-user-plus"></i> Assign Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showAssignModal(paymentId, phone, amount) {
    document.getElementById('assignPaymentId').value = paymentId;
    document.getElementById('assignPhone').textContent = phone;
    document.getElementById('assignAmount').textContent = amount;
    document.getElementById('selectedMemberId').value = '';
    document.getElementById('selectedMember').style.display = 'none';
    document.getElementById('assignButton').disabled = true;
    document.getElementById('memberSearch').value = '';
    document.getElementById('memberResults').innerHTML = '';
    
    $('#assignModal').modal('show');
}

// Member search functionality
let searchTimeout;
document.getElementById('memberSearch').addEventListener('input', function() {
    const query = this.value.trim();
    
    clearTimeout(searchTimeout);
    
    if (query.length < 2) {
        document.getElementById('memberResults').innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch(`<?php echo BASE_URL; ?>/views/ajax_search_members.php?q=${encodeURIComponent(query)}`)
            .then(response => response.json())
            .then(data => {
                const resultsDiv = document.getElementById('memberResults');
                
                if (data.success && data.members.length > 0) {
                    let html = '<div class="list-group">';
                    data.members.forEach(member => {
                        html += `
                            <button type="button" class="list-group-item list-group-item-action" 
                                    onclick="selectMember(${member.id}, '${member.full_name}', '${member.crn}')">
                                <strong>${member.full_name}</strong><br>
                                <small class="text-muted">CRN: ${member.crn} | Phone: ${member.phone || 'N/A'}</small>
                            </button>
                        `;
                    });
                    html += '</div>';
                    resultsDiv.innerHTML = html;
                } else {
                    resultsDiv.innerHTML = '<div class="alert alert-warning">No members found</div>';
                }
            })
            .catch(error => {
                console.error('Search error:', error);
                document.getElementById('memberResults').innerHTML = '<div class="alert alert-danger">Search failed</div>';
            });
    }, 300);
});

function selectMember(memberId, memberName, memberCrn) {
    document.getElementById('selectedMemberId').value = memberId;
    document.getElementById('selectedMemberName').textContent = `${memberName} (${memberCrn})`;
    document.getElementById('selectedMember').style.display = 'block';
    document.getElementById('assignButton').disabled = false;
    document.getElementById('memberResults').innerHTML = '';
    document.getElementById('memberSearch').value = memberName;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__.'/../includes/template.php';
?>
