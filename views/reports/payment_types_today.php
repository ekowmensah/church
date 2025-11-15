<?php
// reports/payment_types_today.php
// Modern rewrite: Total Payment Types for Today
session_start();
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';
require_once __DIR__.'/../../includes/report_ui_helpers.php';


// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_types_today')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../views/errors/403.php')) {
        include __DIR__.'/../../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../errors/403.php')) {
        include __DIR__.'/../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above
$can_export = $is_super_admin || has_permission('export_payment_report');

$date = date('Y-m-d');

// Get current user ID for filtering
$current_user_id = $_SESSION['user_id'] ?? 0;

// Fetch payment types summary for today
// Super admin sees all payments, regular users see only their own payments
if ($is_super_admin) {
    $sql = "SELECT pt.name AS payment_type, COUNT(p.id) AS total_count, SUM(p.amount) AS total_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ?
            GROUP BY pt.id
            ORDER BY total_amount DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $date);
} else {
    $sql = "SELECT pt.name AS payment_type, COUNT(p.id) AS total_count, SUM(p.amount) AS total_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY pt.id
            ORDER BY total_amount DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $date, $current_user_id);
}

$stmt->execute();
$result = $stmt->get_result();
$types = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch the total sum for all types for a summary footer
// Apply same user filtering logic
if ($is_super_admin) {
    $total_sql = "SELECT SUM(amount) AS total_amount FROM payments WHERE DATE(payment_date) = ?";
    $total_stmt = $conn->prepare($total_sql);
    $total_stmt->bind_param('s', $date);
} else {
    $total_sql = "SELECT SUM(amount) AS total_amount FROM payments WHERE DATE(payment_date) = ? AND recorded_by = ?";
    $total_stmt = $conn->prepare($total_sql);
    $total_stmt->bind_param('si', $date, $current_user_id);
}

$total_stmt->execute();
$total_stmt->bind_result($grand_total);
$total_stmt->fetch();
$total_stmt->close();

ob_start();
?>
<main class="container-fluid px-0" style="background:#f8fafc;min-height:100vh;">
    <div class="row pt-4 pb-2 justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <?php render_report_filter_bar($date, 'btn-primary'); ?>
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-12 col-md-10 col-lg-8">
            <div class="row mb-3">
                <div class="col-sm-6 mb-2">
                    <?php render_summary_card('Payment Types', count($types), 'fa-list-alt', 'primary'); ?>
                </div>
                <div class="col-sm-6 mb-2">
                    <?php render_summary_card('Grand Total', '₵' . number_format($grand_total, 2), 'fa-coins', 'success'); ?>
                </div>
            </div>
            <div class="card report-card shadow-lg animate__animated animate__fadeIn my-4">
                <div class="card-header bg-primary text-white d-flex align-items-center justify-content-between py-3">
                    <div><i class="fas fa-list-alt fa-lg mr-2" data-toggle="tooltip" title="Today's Payment Types"></i>
                        <span>Total Payment Type(s) for <?= date('l, F j, Y', strtotime($date)) ?><?= !$is_super_admin ? ' (My Payments)' : '' ?></span>
                    </div>
                    <div>
                        <?php render_print_button('Print report'); ?>
                    </div>
                </div>
                <div class="card-body pb-4">
                    <?php if ($types && count($types) > 0): ?>
                        <div class="table-responsive mb-4">
                            <table id="typesTable" class="table table-striped table-hover table-bordered">
                                <thead class="thead-light">
                                <tr>
                                    <th>#</th>
                                    <th>Payment Type</th>
                                    <th>Count</th>
                                    <th>Total Amount (₵)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($types as $i => $row): ?>
                                    <tr>
                                        <td><?= $i+1 ?></td>
                                        <td><?= htmlspecialchars($row['payment_type']) ?></td>
                                        <td><span class="badge badge-info p-2 px-3 font-weight-bold"><?= $row['total_count'] ?></span></td>
                                        <td><span class="badge badge-success p-2 px-3 font-weight-bold">₵<?= number_format($row['total_amount'], 2) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                <tr class="font-weight-bold bg-light">
                                    <td colspan="2" class="text-right">Grand Total</td>
                                    <td><?= array_sum(array_column($types, 'total_count')) ?></td>
                                    <td>₵<?= number_format($grand_total, 2) ?></td>
                                </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info text-center my-5">
                            <i class="fas fa-info-circle mr-2"></i> No payments recorded for this date.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php include_datatables_scripts(); ?>
    <?php datatables_init_script('typesTable', [
        'dom' => 'Bfrtip',
        'buttons' => [
            [ 'extend' => 'copy', 'className' => 'btn btn-sm btn-outline-secondary' ],
            [ 'extend' => 'csv', 'className' => 'btn btn-sm btn-outline-primary' ],
            [ 'extend' => 'excel', 'className' => 'btn btn-sm btn-outline-success' ],
            [ 'extend' => 'pdf', 'className' => 'btn btn-sm btn-outline-danger' ],
            [ 'extend' => 'print', 'className' => 'btn btn-sm btn-outline-dark' ]
        ],
        'responsive' => true,
        'pageLength' => 10,
        'order' => [[3, 'desc']]
    ]); ?>
</main>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../../includes/layout.php';
?>
