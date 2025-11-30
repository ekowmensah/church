<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-4"><h4>403 Forbidden</h4><p>You do not have permission to view payment details.</p></div>';
    exit;
}

// Get payment ID and validate
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="alert alert-danger m-4">Invalid payment ID.</div>';
    exit;
}

// Fetch payment data with all related information
$stmt = $conn->prepare("
    SELECT p.*, 
           pt.name AS payment_type,
           m.crn, m.first_name, m.last_name, m.middle_name, m.gender, m.phone, m.email,
           ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name,
           c.name AS church_name,
           bc.name AS class_name,
           org.name AS organization_name,
           u.name AS recorded_by_name,
           u.email AS recorded_by_email,
           approver.name AS approved_by_name,
           reverser.name AS reversed_by_name
    FROM payments p
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations org ON mo.organization_id = org.id
    LEFT JOIN users u ON p.recorded_by = u.id
    LEFT JOIN users approver ON p.reversal_approved_by = approver.id
    LEFT JOIN users reverser ON p.reversal_requested_by = reverser.id
    WHERE p.id = ?
");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$payment = $result->fetch_assoc();

if (!$payment) {
    echo '<div class="alert alert-danger m-4">Payment not found.</div>';
    exit;
}

// Determine payment status
$is_pending_reversal = !empty($payment['reversal_requested_at']) && empty($payment['reversal_approved_at']);
$is_reversed = !empty($payment['reversal_approved_at']) && empty($payment['reversal_undone_at']);
$is_active = empty($payment['reversal_requested_at']) || !empty($payment['reversal_undone_at']);

// Fetch reversal history if any (check if table exists first)
$reversal_history = [];
if (!empty($payment['reversal_requested_at'])) {
    $table_check = $conn->query("SHOW TABLES LIKE 'payment_reversals'");
    if ($table_check && $table_check->num_rows > 0) {
        $rev_stmt = $conn->prepare("
            SELECT * FROM payment_reversals 
            WHERE payment_id = ? 
            ORDER BY created_at DESC
        ");
        $rev_stmt->bind_param('i', $id);
        $rev_stmt->execute();
        $reversal_history = $rev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$page_title = 'Payment Details - TXN-' . str_pad($payment['id'], 6, '0', STR_PAD_LEFT);
ob_start();
?>
<!-- Custom Styles -->
<style>
.payment-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    border-radius: 15px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.payment-header.reversed {
    background: linear-gradient(135deg, #c31432 0%, #240b36 100%);
}

.payment-header.pending {
    background: linear-gradient(135deg, #f2994a 0%, #f2c94c 100%);
}

.transaction-id {
    font-size: 2rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
}

.info-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border-left: 4px solid #1e3c72;
}

.info-card.reversed {
    border-left-color: #c31432;
}

.info-card.pending {
    border-left-color: #f2994a;
}

.info-row {
    display: flex;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f0f0f0;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #555;
    min-width: 180px;
}

.info-value {
    color: #333;
    flex: 1;
}

.amount-display {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2ecc71;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
}

.amount-display.reversed {
    color: #e74c3c;
    text-decoration: line-through;
}

.status-badge {
    padding: 0.5rem 1.5rem;
    border-radius: 25px;
    font-weight: 600;
    font-size: 1rem;
    display: inline-block;
}

.status-badge.active {
    background: #2ecc71;
    color: white;
}

.status-badge.pending {
    background: #f39c12;
    color: white;
}

.status-badge.reversed {
    background: #e74c3c;
    color: white;
}

.action-buttons {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.btn-banking {
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-weight: 600;
    transition: all 0.3s ease;
    border: none;
}

.btn-banking:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 8px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #ddd;
}

.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -26px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #1e3c72;
    border: 3px solid white;
    box-shadow: 0 0 0 2px #1e3c72;
}

.timeline-item.reversed::before {
    background: #e74c3c;
    box-shadow: 0 0 0 2px #e74c3c;
}

.timeline-item.pending::before {
    background: #f39c12;
    box-shadow: 0 0 0 2px #f39c12;
}

@media print {
    .no-print {
        display: none !important;
    }
    .payment-header {
        background: #1e3c72 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<!-- Payment Header -->
<div class="payment-header <?= $is_reversed ? 'reversed' : ($is_pending_reversal ? 'pending' : '') ?>">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="transaction-id">
                <i class="fas fa-receipt mr-3"></i>
                TXN-<?= str_pad($payment['id'], 6, '0', STR_PAD_LEFT) ?>
            </div>
            <div class="mt-3">
                <span class="status-badge <?= $is_reversed ? 'reversed' : ($is_pending_reversal ? 'pending' : 'active') ?>">
                    <?php if ($is_reversed): ?>
                        <i class="fas fa-ban"></i> REVERSED
                    <?php elseif ($is_pending_reversal): ?>
                        <i class="fas fa-clock"></i> REVERSAL PENDING
                    <?php else: ?>
                        <i class="fas fa-check-circle"></i> ACTIVE
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="col-md-4 text-right">
            <div class="amount-display <?= $is_reversed ? 'reversed' : '' ?>">
                ₵<?= number_format($payment['amount'], 2) ?>
            </div>
            <div class="mt-2">
                <small><?= date('F j, Y g:i A', strtotime($payment['payment_date'])) ?></small>
            </div>
        </div>
    </div>
</div>

<!-- Action Buttons -->
<div class="action-buttons no-print mb-4">
    <a href="payment_list.php" class="btn btn-banking btn-secondary">
        <i class="fas fa-arrow-left mr-2"></i>Back to List
    </a>
    <?php if (has_permission('edit_payment') && $is_active): ?>
        <a href="payment_form.php?id=<?= $payment['id'] ?>" class="btn btn-banking btn-primary">
            <i class="fas fa-edit mr-2"></i>Edit Payment
        </a>
    <?php endif; ?>
    <a href="payment_history.php?member_id=<?= $payment['member_id'] ?>" class="btn btn-banking btn-info">
        <i class="fas fa-history mr-2"></i>Payment History
    </a>
    <button onclick="window.print()" class="btn btn-banking btn-success">
        <i class="fas fa-print mr-2"></i>Print Receipt
    </button>
    <?php if ($is_active && !$is_pending_reversal): ?>
        <a href="payment_reverse.php?id=<?= $payment['id'] ?>" 
           class="btn btn-banking btn-warning"
           onclick="return confirm('Request reversal for this payment?');">
            <i class="fas fa-undo mr-2"></i>Request Reversal
        </a>
    <?php endif; ?>
    <?php if ($is_pending_reversal && $is_super_admin): ?>
        <a href="payment_reverse.php?id=<?= $payment['id'] ?>&action=approve" 
           class="btn btn-banking btn-success"
           onclick="return confirm('Approve this payment reversal?');">
            <i class="fas fa-check mr-2"></i>Approve Reversal
        </a>
    <?php endif; ?>
    <?php if ($is_reversed && $is_super_admin): ?>
        <a href="payment_reverse.php?id=<?= $payment['id'] ?>&action=undo" 
           class="btn btn-banking btn-info"
           onclick="return confirm('Undo this payment reversal?');">
            <i class="fas fa-redo mr-2"></i>Undo Reversal
        </a>
    <?php endif; ?>
</div>

<div class="row">
    <!-- Payment Information -->
    <div class="col-md-6">
        <div class="info-card <?= $is_reversed ? 'reversed' : ($is_pending_reversal ? 'pending' : '') ?>">
            <h5 class="mb-3"><i class="fas fa-info-circle mr-2"></i>Payment Information</h5>
            
            <div class="info-row">
                <div class="info-label">Transaction ID:</div>
                <div class="info-value">
                    <strong>TXN-<?= str_pad($payment['id'], 6, '0', STR_PAD_LEFT) ?></strong>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Payment Type:</div>
                <div class="info-value">
                    <span class="badge badge-primary"><?= htmlspecialchars($payment['payment_type']) ?></span>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Amount:</div>
                <div class="info-value">
                    <strong class="text-success" style="font-size: 1.3rem;">
                        ₵<?= number_format($payment['amount'], 2) ?>
                    </strong>
                </div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Payment Date:</div>
                <div class="info-value"><?= date('F j, Y', strtotime($payment['payment_date'])) ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Payment Time:</div>
                <div class="info-value"><?= date('g:i A', strtotime($payment['payment_date'])) ?></div>
            </div>
            
            <div class="info-row">
                <div class="info-label">Payment Mode:</div>
                <div class="info-value">
                    <?php
                    $mode_icons = [
                        'cash' => 'fa-money-bill-wave',
                        'mobile_money' => 'fa-mobile-alt',
                        'ussd' => 'fa-mobile-alt',
                        'bank_transfer' => 'fa-university',
                        'cheque' => 'fa-money-check',
                        'card' => 'fa-credit-card'
                    ];
                    $icon = $mode_icons[$payment['mode']] ?? 'fa-question-circle';
                    ?>
                    <i class="fas <?= $icon ?> mr-2"></i>
                    <?= ucwords(str_replace('_', ' ', $payment['mode'])) ?>
                </div>
            </div>
            
            <?php if (!empty($payment['payment_period'])): ?>
            <div class="info-row">
                <div class="info-label">Payment Period:</div>
                <div class="info-value">
                    <span class="badge badge-info"><?= htmlspecialchars($payment['payment_period']) ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($payment['payment_period_description'])): ?>
            <div class="info-row">
                <div class="info-label">Period Description:</div>
                <div class="info-value"><?= htmlspecialchars($payment['payment_period_description']) ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($payment['description'])): ?>
            <div class="info-row">
                <div class="info-label">Description:</div>
                <div class="info-value"><?= nl2br(htmlspecialchars($payment['description'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Member/Student Information -->
    <div class="col-md-6">
        <div class="info-card">
            <h5 class="mb-3">
                <i class="fas fa-user mr-2"></i>
                <?= $payment['member_id'] ? 'Member Information' : 'Sunday School Student' ?>
            </h5>
            
            <?php if ($payment['member_id']): ?>
                <div class="info-row">
                    <div class="info-label">CRN:</div>
                    <div class="info-value"><strong><?= htmlspecialchars($payment['crn']) ?></strong></div>
                </div>
                
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value">
                        <?= htmlspecialchars($payment['first_name'] . ' ' . ($payment['middle_name'] ?? '') . ' ' . $payment['last_name']) ?>
                    </div>
                </div>
                
                <div class="info-row">
                    <div class="info-label">Gender:</div>
                    <div class="info-value">
                        <i class="fas fa-<?= $payment['gender'] == 'male' ? 'mars' : 'venus' ?> mr-1"></i>
                        <?= ucfirst($payment['gender']) ?>
                    </div>
                </div>
                
                <?php if (!empty($payment['phone'])): ?>
                <div class="info-row">
                    <div class="info-label">Phone:</div>
                    <div class="info-value">
                        <i class="fas fa-phone mr-1"></i>
                        <?= htmlspecialchars($payment['phone']) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['email'])): ?>
                <div class="info-row">
                    <div class="info-label">Email:</div>
                    <div class="info-value">
                        <i class="fas fa-envelope mr-1"></i>
                        <?= htmlspecialchars($payment['email']) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['church_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Church:</div>
                    <div class="info-value"><?= htmlspecialchars($payment['church_name']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['class_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Bible Class:</div>
                    <div class="info-value"><?= htmlspecialchars($payment['class_name']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['organization_name'])): ?>
                <div class="info-row">
                    <div class="info-label">Organization:</div>
                    <div class="info-value"><?= htmlspecialchars($payment['organization_name']) ?></div>
                </div>
                <?php endif; ?>
                
                <div class="mt-3">
                    <a href="member_view.php?id=<?= $payment['member_id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-user-circle mr-1"></i>View Member Profile
                    </a>
                </div>
            <?php else: ?>
                <div class="info-row">
                    <div class="info-label">SRN:</div>
                    <div class="info-value"><strong><?= htmlspecialchars($payment['srn']) ?></strong></div>
                </div>
                
                <div class="info-row">
                    <div class="info-label">Name:</div>
                    <div class="info-value">
                        <?= htmlspecialchars($payment['ss_first_name'] . ' ' . ($payment['ss_middle_name'] ?? '') . ' ' . $payment['ss_last_name']) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recording Information -->
<div class="row">
    <div class="col-md-12">
        <div class="info-card">
            <h5 class="mb-3"><i class="fas fa-clock mr-2"></i>Recording & Timeline</h5>
            
            <div class="timeline">
                <?php if (!empty($payment['created_at'])): ?>
                <div class="timeline-item">
                    <strong>Payment Recorded</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['created_at'])) ?>
                        <?php if (!empty($payment['recorded_by_name'])): ?>
                            by <strong><?= htmlspecialchars($payment['recorded_by_name']) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="timeline-item">
                    <strong>Payment Date</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['payment_date'])) ?>
                        <?php if (!empty($payment['recorded_by_name'])): ?>
                            by <strong><?= htmlspecialchars($payment['recorded_by_name']) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['updated_at']) && (!empty($payment['created_at']) && $payment['updated_at'] != $payment['created_at'])): ?>
                <div class="timeline-item">
                    <strong>Last Updated</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['updated_at'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['reversal_requested_at'])): ?>
                <div class="timeline-item pending">
                    <strong>Reversal Requested</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['reversal_requested_at'])) ?>
                        <?php if (!empty($payment['reversed_by_name'])): ?>
                            by <strong><?= htmlspecialchars($payment['reversed_by_name']) ?></strong>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($payment['reversal_reason'])): ?>
                        <div class="mt-1">
                            <em>Reason: <?= htmlspecialchars($payment['reversal_reason']) ?></em>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['reversal_approved_at'])): ?>
                <div class="timeline-item reversed">
                    <strong>Reversal Approved</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['reversal_approved_at'])) ?>
                        <?php if (!empty($payment['approved_by_name'])): ?>
                            by <strong><?= htmlspecialchars($payment['approved_by_name']) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($payment['reversal_undone_at'])): ?>
                <div class="timeline-item">
                    <strong>Reversal Undone</strong>
                    <div class="text-muted">
                        <?= date('F j, Y g:i A', strtotime($payment['reversal_undone_at'])) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($reversal_history)): ?>
<!-- Reversal History -->
<div class="row">
    <div class="col-md-12">
        <div class="info-card">
            <h5 class="mb-3"><i class="fas fa-history mr-2"></i>Reversal History</h5>
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Action</th>
                            <th>Reason</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reversal_history as $rev): ?>
                        <tr>
                            <td><?= date('M j, Y g:i A', strtotime($rev['created_at'])) ?></td>
                            <td>
                                <span class="badge badge-<?= $rev['action'] == 'approved' ? 'danger' : 'warning' ?>">
                                    <?= ucfirst($rev['action']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($rev['reason'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($rev['user_name'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
?>
