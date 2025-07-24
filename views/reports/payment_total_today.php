<?php
// reports/payment_total_today.php
// Professional: Daily Payment Total Report

require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions.php';
require_once __DIR__.'/../../includes/report_ui_helpers.php';


// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_report')) {
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

// Get date from GET or default to today
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

$total_amount = 0.00;
$total_count = 0;
$error = '';

try {
    $sql = "SELECT SUM(amount) AS total_amount, COUNT(id) AS total_count FROM payments WHERE DATE(payment_date) = ?";
    if (!$stmt = $conn->prepare($sql)) {
        throw new Exception('Database error: ' . $conn->error);
    }
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $stmt->bind_result($total_amount, $total_count);
    $stmt->fetch();
    $stmt->close();
    if ($total_amount === null) $total_amount = 0.00;
    if ($total_count === null) $total_count = 0;
} catch (Exception $e) {
    $error = $e->getMessage();
}

ob_start();
?>
<main class="container-fluid px-0" style="background:#f8fafc;min-height:100vh;">
    <div class="row pt-4 pb-2 justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <?php render_report_filter_bar($date, 'btn-success'); ?>
        </div>
    </div>
    <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6">
            <div class="row mb-3">
                <div class="col-12 mb-2">
                    <?php render_summary_card('Grand Total', '₵' . number_format($total_amount, 2), 'fa-coins', 'success'); ?>
                </div>
            </div>
            <div class="card report-card shadow-lg animate__animated animate__fadeIn my-4">
                <div class="card-header bg-success text-white d-flex align-items-center justify-content-between py-3">
                    <div><i class="fas fa-coins summary-icon mr-2" data-toggle="tooltip" title="Today's Payment Total"></i>
                        <span>Total Payment for <?= date('l, F j, Y', strtotime($date)) ?></span>
                    </div>
                    <div>
                        <?php if ($can_export): ?>
                            <?php render_print_button('Print report'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body pb-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger text-center my-5"><i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($error) ?></div>
                    <?php elseif ($total_count > 0): ?>
                        <div class="table-responsive mb-4">
                            <table id="totalTable" class="table table-striped table-hover table-bordered">
                                <thead class="thead-light">
                                <tr>
                                    <th>Total Amount (₵)</th>
                                    <th>Total Payments</th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td><span class="badge badge-success p-2 px-3 font-weight-bold">₵<?= number_format($total_amount, 2) ?></span></td>
                                    <td><span class="badge badge-info p-2 px-3 font-weight-bold"><?= $total_count ?></span></td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info my-5 animate__animated animate__fadeIn">
                            <i class="fas fa-info-circle mr-2"></i> No payments recorded for this date.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php include_datatables_scripts(); ?>
    <?php datatables_init_script('totalTable', [
        'dom' => 'Bfrtip',
        'buttons' => [
            [ 'extend' => 'copy', 'className' => 'btn btn-sm btn-outline-secondary' ],
            [ 'extend' => 'csv', 'className' => 'btn btn-sm btn-outline-primary' ],
            [ 'extend' => 'excel', 'className' => 'btn btn-sm btn-outline-success' ],
            [ 'extend' => 'pdf', 'className' => 'btn btn-sm btn-outline-danger' ],
            [ 'extend' => 'print', 'className' => 'btn btn-sm btn-outline-dark' ]
        ],
        'responsive' => true,
        'pageLength' => 1,
        'searching' => false,
        'ordering' => false
    ]); ?>
</main>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../../includes/layout.php';
?>
