<?php
// reports/payment_total_today.php
// Professional: Daily Payment Total Report

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

if (!$is_super_admin && !has_permission('view_payment_total_today')) {
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
$current_user_id = $_SESSION['user_id'] ?? 0;
$filter_by_user = !$is_super_admin;

// Arrays to store breakdown data
$by_cashier = [];
$by_payment_type = [];
$by_payment_mode = [];

try {
    // 1. Get overall totals
    if ($filter_by_user) {
        $sql = "SELECT SUM(amount) AS total_amount, COUNT(id) AS total_count FROM payments WHERE DATE(payment_date) = ? AND recorded_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } else {
        $sql = "SELECT SUM(amount) AS total_amount, COUNT(id) AS total_count FROM payments WHERE DATE(payment_date) = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
    }
    
    if (!$stmt) {
        throw new Exception('Database error: ' . $conn->error);
    }
    
    $stmt->execute();
    $stmt->bind_result($total_amount, $total_count);
    $stmt->fetch();
    $stmt->close();
    if ($total_amount === null) $total_amount = 0.00;
    if ($total_count === null) $total_count = 0;
    
    // 2. Get breakdown by cashier (only for super admin)
    if ($is_super_admin) {
        $sql = "
            SELECT 
                u.id,
                u.name,
                u.email,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            LEFT JOIN users u ON p.recorded_by = u.id
            WHERE DATE(p.payment_date) = ?
            GROUP BY u.id, u.name, u.email
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $by_cashier[] = $row;
        }
        $stmt->close();
    }
    
    // 3. Get breakdown by payment type
    if ($filter_by_user) {
        $sql = "
            SELECT 
                pt.id,
                pt.name AS payment_type,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY pt.id, pt.name
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } else {
        $sql = "
            SELECT 
                pt.id,
                pt.name AS payment_type,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ?
            GROUP BY pt.id, pt.name
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $by_payment_type[] = $row;
    }
    $stmt->close();
    
    // 4. Get breakdown by payment mode (cash, cheque, momo)
    if ($filter_by_user) {
        $sql = "
            SELECT 
                p.mode,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY p.mode
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } else {
        $sql = "
            SELECT 
                p.mode,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            WHERE DATE(p.payment_date) = ?
            GROUP BY p.mode
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $by_payment_mode[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

ob_start();
?>
<main class="container-fluid px-3" style="background:#f8fafc;min-height:100vh;">
    <div class="row pt-4 pb-2">
        <div class="col-12">
            <?php render_report_filter_bar($date, 'btn-success'); ?>
        </div>
    </div>
    <div class="row">
        <div class="col-12">
            <div class="row mb-3">
                <div class="col-12 mb-2">
                    <?php render_summary_card('Grand Total', '₵' . number_format($total_amount, 2), 'fa-coins', 'success'); ?>
                </div>
            </div>
            <div class="card report-card shadow-lg animate__animated animate__fadeIn my-4">
                <div class="card-header bg-success text-white d-flex align-items-center justify-content-between py-3">
                    <div><i class="fas fa-coins summary-icon mr-2" data-toggle="tooltip" title="Today's Payment Total"></i>
                        <span><?= $is_super_admin ? 'Total Payment' : 'My Payments' ?> for <?= date('l, F j, Y', strtotime($date)) ?></span>
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
                        
                        <!-- Overall Summary -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-chart-line mr-2"></i>Overall Summary</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
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
                        </div>
                        
                        <?php if ($is_super_admin && count($by_cashier) > 0): ?>
                        <!-- Breakdown by Cashier -->
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-user-tie mr-2"></i>Breakdown by Cashier</h5>
                            <div class="table-responsive">
                                <table id="cashierTable" class="table table-striped table-hover table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Cashier</th>
                                        <th>Email</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Total Amount (₵)</th>
                                        <th class="text-right">% of Total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($by_cashier as $idx => $row): 
                                        $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><?= htmlspecialchars($row['name'] ?: 'Unknown') ?></td>
                                        <td><span class="badge badge-secondary"><?= htmlspecialchars($row['email'] ?: 'N/A') ?></span></td>
                                        <td class="text-right"><?= number_format($row['payment_count']) ?></td>
                                        <td class="text-right font-weight-bold">₵<?= number_format($row['total_amount'], 2) ?></td>
                                        <td class="text-right">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $percentage ?>%" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <?= number_format($percentage, 1) ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="thead-light">
                                    <tr>
                                        <th colspan="3" class="text-right">Total:</th>
                                        <th class="text-right"><?= number_format($total_count) ?></th>
                                        <th class="text-right">₵<?= number_format($total_amount, 2) ?></th>
                                        <th class="text-right">100%</th>
                                    </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Breakdown by Payment Type -->
                        <?php if (count($by_payment_type) > 0): ?>
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-tags mr-2"></i>Breakdown by Payment Type</h5>
                            <div class="table-responsive">
                                <table id="paymentTypeTable" class="table table-striped table-hover table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Payment Type</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Total Amount (₵)</th>
                                        <th class="text-right">% of Total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($by_payment_type as $idx => $row): 
                                        $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td><span class="badge badge-primary"><?= htmlspecialchars($row['payment_type']) ?></span></td>
                                        <td class="text-right"><?= number_format($row['payment_count']) ?></td>
                                        <td class="text-right font-weight-bold">₵<?= number_format($row['total_amount'], 2) ?></td>
                                        <td class="text-right">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: <?= $percentage ?>%" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <?= number_format($percentage, 1) ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="thead-light">
                                    <tr>
                                        <th colspan="2" class="text-right">Total:</th>
                                        <th class="text-right"><?= number_format($total_count) ?></th>
                                        <th class="text-right">₵<?= number_format($total_amount, 2) ?></th>
                                        <th class="text-right">100%</th>
                                    </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Breakdown by Payment Mode -->
                        <?php if (count($by_payment_mode) > 0): ?>
                        <div class="mb-4">
                            <h5 class="text-primary mb-3"><i class="fas fa-credit-card mr-2"></i>Breakdown by Payment Mode</h5>
                            <div class="table-responsive">
                                <table id="paymentModeTable" class="table table-striped table-hover table-bordered">
                                    <thead class="thead-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Payment Mode</th>
                                        <th class="text-right">Payments</th>
                                        <th class="text-right">Total Amount (₵)</th>
                                        <th class="text-right">% of Total</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php 
                                    $mode_icons = [
                                        'cash' => 'fa-money-bill-wave text-success',
                                        'cheque' => 'fa-money-check text-info',
                                        'momo' => 'fa-mobile-alt text-warning',
                                        'card' => 'fa-credit-card text-primary'
                                    ];
                                    foreach ($by_payment_mode as $idx => $row): 
                                        $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                                        $mode = strtolower($row['mode']);
                                        $icon = $mode_icons[$mode] ?? 'fa-wallet text-secondary';
                                    ?>
                                    <tr>
                                        <td><?= $idx + 1 ?></td>
                                        <td>
                                            <i class="fas <?= $icon ?> mr-2"></i>
                                            <span class="badge badge-dark"><?= htmlspecialchars(ucfirst($row['mode'])) ?></span>
                                        </td>
                                        <td class="text-right"><?= number_format($row['payment_count']) ?></td>
                                        <td class="text-right font-weight-bold">₵<?= number_format($row['total_amount'], 2) ?></td>
                                        <td class="text-right">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?= $percentage ?>%" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                                    <?= number_format($percentage, 1) ?>%
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="thead-light">
                                    <tr>
                                        <th colspan="2" class="text-right">Total:</th>
                                        <th class="text-right"><?= number_format($total_count) ?></th>
                                        <th class="text-right">₵<?= number_format($total_amount, 2) ?></th>
                                        <th class="text-right">100%</th>
                                    </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                        
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
    <script>
    $(document).ready(function() {
        // Common DataTable configuration
        const commonConfig = {
            dom: 'Bfrtip',
            buttons: [
                { extend: 'copy', className: 'btn btn-sm btn-outline-secondary' },
                { extend: 'csv', className: 'btn btn-sm btn-outline-primary' },
                { extend: 'excel', className: 'btn btn-sm btn-outline-success' },
                { extend: 'pdf', className: 'btn btn-sm btn-outline-danger' },
                { extend: 'print', className: 'btn btn-sm btn-outline-dark' }
            ],
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']]
        };
        
        // Initialize each table if it exists
        <?php if ($is_super_admin && count($by_cashier) > 0): ?>
        $('#cashierTable').DataTable({
            ...commonConfig,
            order: [[4, 'desc']] // Sort by total amount descending
        });
        <?php endif; ?>
        
        <?php if (count($by_payment_type) > 0): ?>
        $('#paymentTypeTable').DataTable({
            ...commonConfig,
            order: [[3, 'desc']] // Sort by total amount descending
        });
        <?php endif; ?>
        
        <?php if (count($by_payment_mode) > 0): ?>
        $('#paymentModeTable').DataTable({
            ...commonConfig,
            order: [[3, 'desc']] // Sort by total amount descending
        });
        <?php endif; ?>
    });
    </script>
</main>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../../includes/layout.php';
?>
