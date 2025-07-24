<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
// Handle date filter
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$where = 'p.member_id = ?';
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
// Fetch filtered payment history
$sql = "SELECT p.*, pt.name AS payment_type FROM payments p LEFT JOIN payment_types pt ON p.payment_type_id = pt.id WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) ORDER BY p.payment_date DESC, p.id DESC";
$stmt = $conn->prepare($sql);
if (count($params) > 1) {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param('i', $member_id);
}
$stmt->execute();
$result = $stmt->get_result();
// Fetch summary
$sum_sql = "SELECT SUM(amount) as total, COUNT(*) as num, MAX(payment_date) as last FROM payments WHERE member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))";
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
$total_paid = $summary['total'] ? (float)$summary['total'] : 0;
$num_payments = $summary['num'] ? (int)$summary['num'] : 0;
$last_payment = $summary['last'] ? $summary['last'] : null;
ob_start();
?>
<!-- Summary Cards -->
<div class="row mt-4 mb-4">
    <div class="col-md-4 mb-2">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Paid</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800">&#8373;<?= number_format($total_paid, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Number of Payments</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $num_payments ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Most Recent Payment</div>
                <div class="h6 mb-0 font-weight-bold text-gray-800"><?= $last_payment ? htmlspecialchars(date('Y-m-d H:i', strtotime($last_payment))) : 'N/A' ?></div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <form action="" method="get">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="start_date">Start Date:</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="form-group col-md-6">
                    <label for="end_date">End Date:</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Filter</button>
        </form>
    </div>
</div>
<div class="card card-primary card-outline">
    <div class="card-header bg-primary text-white font-weight-bold">
        <i class="fas fa-history mr-2"></i>My Payment History
    </div>
    <div class="card-body">
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info">You have not made any payments yet.</div>
        <?php else: ?>
        <a href="export_sms_logs.php?member_id=<?=$member_id?>" class="btn btn-outline-success mb-2"><i class="fas fa-file-csv mr-1"></i>Export SMS Logs (CSV)</a>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Amount (₵)</th>
                        <th>Description</th>
                        <th>SMS Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $table_total = 0;
                while ($row = $result->fetch_assoc()): 
                    $table_total += (float)$row['amount'];
                ?>
                    <tr>
                        <td><?=htmlspecialchars(date('Y-m-d H:i', strtotime($row['payment_date'])))?></td>
                        <td><?=htmlspecialchars($row['payment_type'])?></td>
                        <td>&#8373;<?=number_format((float)$row['amount'], 2)?></td>
                        <td><?=htmlspecialchars($row['description'])?></td>
                        <td>
                        <?php
                        $sms_stmt = $conn->prepare('SELECT id, status, sent_at FROM sms_logs WHERE member_id = ? AND ABS(TIMESTAMPDIFF(SECOND, sent_at, ?)) < 120 ORDER BY sent_at DESC LIMIT 1');
// Try to match SMS sent within 2 minutes of payment date
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
                        <td colspan="2" class="text-right">Total:</td>
                        <td>&#8373;<?= number_format($table_total, 2) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
        <a href="member_dashboard.php" class="btn btn-secondary mt-3"><i class="fas fa-arrow-left mr-1"></i>Back to Dashboard</a>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
