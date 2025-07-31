<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$member_id = intval($_SESSION['member_id']);



// Handle date filter
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

$where = 'p.member_id = ? AND p.payment_type_id = 4'; // 4 is harvest payment type
$params = [$member_id];
$types = 'i';

if ($start_date) {
    $where .= ' AND p.payment_date >= ?';
    $params[] = $start_date . ' 00:00:00';
    $types .= 's';
}
if ($end_date) {
    $where .= ' AND p.payment_date <= ?';
    $params[] = $end_date . ' 23:59:59';
    $types .= 's';
}

// Fetch filtered harvest payment history
$sql = "SELECT p.*, pt.name AS payment_type FROM payments p 
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
        WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) 
        ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if (count($params) > 1) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('i', $member_id);
}
$stmt->execute();
$result = $stmt->get_result();



// Fetch summary for harvest payments only
$sum_sql = "SELECT SUM(amount) as total, COUNT(*) as num, MAX(payment_date) as last 
            FROM payments 
            WHERE member_id = ? AND payment_type_id = 4 AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))";
$sum_params = [$member_id];
$sum_types = 'i';

if ($start_date) {
    $sum_sql .= ' AND payment_date >= ?';
    $sum_params[] = $start_date . ' 00:00:00';
    $sum_types .= 's';
}
if ($end_date) {
    $sum_sql .= ' AND payment_date <= ?';
    $sum_params[] = $end_date . ' 23:59:59';
    $sum_types .= 's';
}

$sum_stmt = $conn->prepare($sum_sql);
if (count($sum_params) > 1) {
    $sum_stmt->bind_param($sum_types, ...$sum_params);
} else {
    $sum_stmt->bind_param('i', $member_id);
}
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

$total_harvest = $summary['total'] ? (float)$summary['total'] : 0;
$num_harvest_payments = $summary['num'] ? (int)$summary['num'] : 0;
$last_harvest_payment = $summary['last'] ? $summary['last'] : null;

ob_start();
?>

<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-seedling mr-2"></i>My Harvest Records</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="member_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Harvest Records</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mt-4 mb-4">
    <div class="col-md-4 mb-2">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Harvest Contributions</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800">&#8373;<?= number_format($total_harvest, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Number of Harvest Payments</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $num_harvest_payments ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Most Recent Harvest</div>
                <div class="h6 mb-0 font-weight-bold text-gray-800"><?= $last_harvest_payment ? htmlspecialchars(date('Y-m-d H:i', strtotime($last_harvest_payment))) : 'N/A' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Date Filter -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-filter mr-2"></i>Filter Records</h3>
            </div>
            <div class="card-body">
                <form action="" method="get">
                    <div class="form-row">
                        <div class="form-group col-md-5">
                            <label for="start_date">Start Date:</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                        </div>
                        <div class="form-group col-md-5">
                            <label for="end_date">End Date:</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-search mr-1"></i>Filter
                            </button>
                        </div>
                    </div>
                    <?php if ($start_date || $end_date): ?>
                        <a href="member_harvest_records.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times mr-1"></i>Clear Filter
                        </a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Harvest Records Table -->
<div class="card card-success card-outline">
    <div class="card-header bg-success text-white font-weight-bold">
        <i class="fas fa-seedling mr-2"></i>My Harvest Payment Records
        <?php if ($start_date || $end_date): ?>
            <small class="ml-2">
                (Filtered: <?= $start_date ?: 'Beginning' ?> to <?= $end_date ?: 'Present' ?>)
            </small>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i>
                <?php if ($start_date || $end_date): ?>
                    No harvest payments found for the selected date range.
                <?php else: ?>
                    You have not made any harvest payments yet.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th><i class="fas fa-calendar mr-1"></i>Date & Time</th>
                            <th><i class="fas fa-money-bill-wave mr-1"></i>Amount (₵)</th>
                            <th><i class="fas fa-comment mr-1"></i>Description</th>
                            <th><i class="fas fa-credit-card mr-1"></i>Payment Method</th>
                            <th><i class="fas fa-sms mr-1"></i>SMS Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $table_total = 0;
                    while ($row = $result->fetch_assoc()): 
                        $table_total += (float)$row['amount'];
                    ?>
                        <tr>
                            <td>
                                <span class="font-weight-bold"><?= htmlspecialchars(date('M d, Y', strtotime($row['payment_date']))) ?></span><br>
                                <small class="text-muted"><?= htmlspecialchars(date('h:i A', strtotime($row['payment_date']))) ?></small>
                            </td>
                            <td>
                                <span class="font-weight-bold text-success">&#8373;<?= number_format((float)$row['amount'], 2) ?></span>
                            </td>
                            <td><?= htmlspecialchars($row['description'] ?: 'Harvest Payment') ?></td>
                            <td>
                                <span class="badge badge-<?= $row['mode'] === 'Cash' ? 'secondary' : 'primary' ?>">
                                    <?= htmlspecialchars($row['mode'] ?: 'Cash') ?>
                                </span>
                            </td>
                            <td>
                            <?php
                            $sms_stmt = $conn->prepare('SELECT id, status, sent_at FROM sms_logs WHERE member_id = ? AND ABS(TIMESTAMPDIFF(SECOND, sent_at, ?)) < 120 ORDER BY sent_at DESC LIMIT 1');
                            $sms_stmt->bind_param('is', $row['member_id'], $row['payment_date']);
                            $sms_stmt->execute();
                            $sms_res = $sms_stmt->get_result();
                            if ($sms_row = $sms_res->fetch_assoc()) {
                                $status = $sms_row['status'] ?? '';
                                if (stripos($status, 'fail') !== false) {
                                    echo '<span class="badge badge-danger">Failed</span>';
                                    echo ' <button class="btn btn-sm btn-warning resend-sms-btn ml-2" data-log-id="'.intval($sms_row['id']).'">Resend</button>';
                                } elseif (stripos($status, 'sent') !== false || stripos($status, 'success') !== false) {
                                    echo '<span class="badge badge-success">Sent</span>';
                                } else {
                                    echo '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
                                }
                            } else {
                                echo '<span class="badge badge-light">Not Sent</span>';
                            }
                            ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                    <tfoot>
                        <tr class="font-weight-bold bg-light">
                            <td class="text-right">
                                <strong>Total<?= ($start_date || $end_date) ? ' (Filtered)' : '' ?>:</strong>
                            </td>
                            <td>
                                <strong class="text-success">&#8373;<?= number_format($table_total, 2) ?></strong>
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
        
        <div class="mt-3">
            <a href="member_dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-1"></i>Back to Dashboard
            </a>
            <a href="payment_history.php" class="btn btn-outline-primary ml-2">
                <i class="fas fa-history mr-1"></i>View All Payments
            </a>
        </div>
    </div>
</div>

<script>
// Handle SMS resend functionality
$(document).ready(function() {
    $('.resend-sms-btn').click(function() {
        var logId = $(this).data('log-id');
        var btn = $(this);
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');
        
        $.ajax({
            url: 'ajax_resend_token_sms.php',
            method: 'POST',
            data: { log_id: logId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    btn.removeClass('btn-warning').addClass('btn-success').html('<i class="fas fa-check"></i> Sent');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    btn.prop('disabled', false).html('Resend');
                    alert('Failed to resend SMS: ' + (response.message || 'Unknown error'));
                }
            },
            error: function() {
                btn.prop('disabled', false).html('Resend');
                alert('Error occurred while resending SMS');
            }
        });
    });
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
