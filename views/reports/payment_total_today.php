<?php
// reports/payment_total_today.php
// Account Reconciliation Report - Daily Payment Analysis

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

// Get cashier filter if provided
$filter_cashier = isset($_GET['cashier']) && is_numeric($_GET['cashier']) ? intval($_GET['cashier']) : null;

$total_amount = 0.00;
$total_count = 0;
$error = '';
$current_user_id = $_SESSION['user_id'] ?? 0;
$filter_by_user = !$is_super_admin;

// Arrays to store breakdown data
$by_cashier = [];
$by_payment_type = [];
$by_payment_mode = [];
$cashier_payment_type_matrix = [];
$cashier_payment_mode_matrix = [];
$recent_transactions = [];
$cashier_list = [];

try {
    // 0. Get list of all cashiers for filter dropdown (super admin only)
    if ($is_super_admin) {
        $sql = "SELECT DISTINCT u.id, u.name FROM users u 
                INNER JOIN payments p ON u.id = p.recorded_by 
                WHERE DATE(p.payment_date) = ? 
                ORDER BY u.name";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cashier_list[] = $row;
        }
        $stmt->close();
    }
    
    // 1. Get overall totals
    if ($filter_by_user) {
        $sql = "SELECT SUM(amount) AS total_amount, COUNT(id) AS total_count FROM payments WHERE DATE(payment_date) = ? AND recorded_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } elseif ($filter_cashier) {
        $sql = "SELECT SUM(amount) AS total_amount, COUNT(id) AS total_count FROM payments WHERE DATE(payment_date) = ? AND recorded_by = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $filter_cashier);
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
    
    // 2. Get breakdown by cashier (only for super admin, unless filtered)
    if ($is_super_admin && !$filter_cashier) {
        $sql = "
            SELECT 
                u.id,
                u.name,
                u.email,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount,
                MIN(p.payment_date) AS first_payment_time,
                MAX(p.payment_date) AS last_payment_time,
                COUNT(DISTINCT p.mode) AS modes_used,
                COUNT(DISTINCT p.payment_type_id) AS payment_types_used
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
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount,
                MIN(p.amount) AS min_amount,
                MAX(p.amount) AS max_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY pt.id, pt.name
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } elseif ($filter_cashier) {
        $sql = "
            SELECT 
                pt.id,
                pt.name AS payment_type,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount,
                MIN(p.amount) AS min_amount,
                MAX(p.amount) AS max_amount
            FROM payments p
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY pt.id, pt.name
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $filter_cashier);
    } else {
        $sql = "
            SELECT 
                pt.id,
                pt.name AS payment_type,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount,
                MIN(p.amount) AS min_amount,
                MAX(p.amount) AS max_amount
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
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount
            FROM payments p
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY p.mode
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $current_user_id);
    } elseif ($filter_cashier) {
        $sql = "
            SELECT 
                p.mode,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount
            FROM payments p
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            GROUP BY p.mode
            ORDER BY total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $filter_cashier);
    } else {
        $sql = "
            SELECT 
                p.mode,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount,
                AVG(p.amount) AS avg_amount
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
    
    // 5. Get cashier x payment type matrix (super admin only, no cashier filter)
    if ($is_super_admin && !$filter_cashier) {
        $sql = "
            SELECT 
                u.id AS cashier_id,
                u.name AS cashier_name,
                pt.name AS payment_type,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            LEFT JOIN users u ON p.recorded_by = u.id
            JOIN payment_types pt ON p.payment_type_id = pt.id
            WHERE DATE(p.payment_date) = ?
            GROUP BY u.id, u.name, pt.name
            ORDER BY u.name, total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cashier_payment_type_matrix[] = $row;
        }
        $stmt->close();
    }
    
    // 6. Get cashier x payment mode matrix (super admin only, no cashier filter)
    if ($is_super_admin && !$filter_cashier) {
        $sql = "
            SELECT 
                u.id AS cashier_id,
                u.name AS cashier_name,
                p.mode,
                COUNT(p.id) AS payment_count,
                SUM(p.amount) AS total_amount
            FROM payments p
            LEFT JOIN users u ON p.recorded_by = u.id
            WHERE DATE(p.payment_date) = ?
            GROUP BY u.id, u.name, p.mode
            ORDER BY u.name, total_amount DESC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $date);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $cashier_payment_mode_matrix[] = $row;
        }
        $stmt->close();
    }
    
    // 7. Get recent transactions for audit trail
    $limit = 50;
    if ($filter_by_user) {
        $sql = "
            SELECT 
                p.id,
                p.payment_date,
                p.amount,
                p.mode,
                p.description,
                pt.name AS payment_type,
                COALESCE(m.first_name, ss.first_name, 'N/A') AS payer_first_name,
                COALESCE(m.last_name, ss.last_name, '') AS payer_last_name,
                COALESCE(m.crn, ss.srn, 'N/A') AS payer_id,
                u.name AS recorded_by_name
            FROM payments p
            LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
            LEFT JOIN members m ON p.member_id = m.id
            LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
            LEFT JOIN users u ON p.recorded_by = u.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $date, $current_user_id, $limit);
    } elseif ($filter_cashier) {
        $sql = "
            SELECT 
                p.id,
                p.payment_date,
                p.amount,
                p.mode,
                p.description,
                pt.name AS payment_type,
                COALESCE(m.first_name, ss.first_name, 'N/A') AS payer_first_name,
                COALESCE(m.last_name, ss.last_name, '') AS payer_last_name,
                COALESCE(m.crn, ss.srn, 'N/A') AS payer_id,
                u.name AS recorded_by_name
            FROM payments p
            LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
            LEFT JOIN members m ON p.member_id = m.id
            LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
            LEFT JOIN users u ON p.recorded_by = u.id
            WHERE DATE(p.payment_date) = ? AND p.recorded_by = ?
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sii', $date, $filter_cashier, $limit);
    } else {
        $sql = "
            SELECT 
                p.id,
                p.payment_date,
                p.amount,
                p.mode,
                p.description,
                pt.name AS payment_type,
                COALESCE(m.first_name, ss.first_name, 'N/A') AS payer_first_name,
                COALESCE(m.last_name, ss.last_name, '') AS payer_last_name,
                COALESCE(m.crn, ss.srn, 'N/A') AS payer_id,
                u.name AS recorded_by_name
            FROM payments p
            LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
            LEFT JOIN members m ON p.member_id = m.id
            LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
            LEFT JOIN users u ON p.recorded_by = u.id
            WHERE DATE(p.payment_date) = ?
            ORDER BY p.payment_date DESC, p.id DESC
            LIMIT ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('si', $date, $limit);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_transactions[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

ob_start();
?>
<style>
.reconciliation-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 10px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}
.stat-card {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border-left: 4px solid;
}
.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
}
.stat-card.success { border-left-color: #28a745; }
.stat-card.info { border-left-color: #17a2b8; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.primary { border-left-color: #007bff; }
.stat-value {
    font-size: 2rem;
    font-weight: bold;
    margin: 0.5rem 0;
}
.stat-label {
    color: #6c757d;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.section-header {
    background: linear-gradient(to right, #f8f9fa, #e9ecef);
    padding: 1rem 1.5rem;
    border-left: 4px solid #007bff;
    margin: 2rem 0 1rem 0;
    border-radius: 5px;
}
.matrix-table th {
    background: #343a40;
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
}
.transaction-row:hover {
    background: #f8f9fa;
    cursor: pointer;
}
.badge-mode {
    padding: 0.5rem 1rem;
    font-size: 0.9rem;
}
</style>

<main class="container-fluid px-3" style="background:#f8fafc;min-height:100vh;">
    <!-- Reconciliation Header -->
    <div class="reconciliation-header">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h2 class="mb-2"><i class="fas fa-calculator mr-2"></i>Account Reconciliation Report</h2>
                <p class="mb-0 opacity-75">Daily Payment Analysis & Cashier Performance</p>
            </div>
            <div class="col-md-4 text-right">
                <h3 class="mb-0"><?= date('l, F j, Y', strtotime($date)) ?></h3>
                <?php if ($can_export): ?>
                    <button onclick="window.print()" class="btn btn-light btn-sm mt-2">
                        <i class="fas fa-print mr-1"></i> Print Report
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Filter Bar -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <form method="get" class="form-inline">
                        <div class="form-group mr-3">
                            <label class="mr-2"><i class="fas fa-calendar mr-1"></i> Date:</label>
                            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>" required>
                        </div>
                        <?php if ($is_super_admin && count($cashier_list) > 0): ?>
                        <div class="form-group mr-3">
                            <label class="mr-2"><i class="fas fa-user-tie mr-1"></i> Cashier:</label>
                            <select name="cashier" class="form-control">
                                <option value="">All Cashiers</option>
                                <?php foreach ($cashier_list as $cashier): ?>
                                    <option value="<?= $cashier['id'] ?>" <?= $filter_cashier == $cashier['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cashier['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter mr-1"></i> Apply Filter
                        </button>
                        <?php if ($filter_cashier): ?>
                        <a href="?date=<?= $date ?>" class="btn btn-secondary ml-2">
                            <i class="fas fa-times mr-1"></i> Clear Filter
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php if ($error): ?>
        <div class="alert alert-danger text-center my-5"><i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($error) ?></div>
    <?php elseif ($total_count > 0): ?>
        
        <!-- Summary Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card success">
                    <div class="stat-label"><i class="fas fa-coins mr-1"></i> Total Collections</div>
                    <div class="stat-value text-success">₵<?= number_format($total_amount, 2) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card info">
                    <div class="stat-label"><i class="fas fa-receipt mr-1"></i> Total Transactions</div>
                    <div class="stat-value text-info"><?= number_format($total_count) ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card warning">
                    <div class="stat-label"><i class="fas fa-chart-line mr-1"></i> Average Amount</div>
                    <div class="stat-value text-warning">₵<?= $total_count > 0 ? number_format($total_amount / $total_count, 2) : '0.00' ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card primary">
                    <div class="stat-label"><i class="fas fa-users mr-1"></i> Active Cashiers</div>
                    <div class="stat-value text-primary"><?= count($by_cashier) > 0 ? count($by_cashier) : ($filter_by_user ? 1 : 0) ?></div>
                </div>
            </div>
        </div>
                        
        <?php if ($is_super_admin && count($by_cashier) > 0): ?>
        <!-- Cashier Performance Analysis -->
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-user-tie mr-2"></i>Cashier Performance Analysis</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="cashierTable" class="table table-hover table-bordered matrix-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Cashier</th>
                            <th>Email</th>
                            <th class="text-center">Transactions</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-center">Avg Amount</th>
                            <th class="text-center">Payment Types</th>
                            <th class="text-center">Payment Modes</th>
                            <th class="text-center">% of Total</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($by_cashier as $idx => $row): 
                            $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                            $avg_amount = $row['payment_count'] > 0 ? $row['total_amount'] / $row['payment_count'] : 0;
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><strong><?= htmlspecialchars($row['name'] ?: 'Unknown') ?></strong></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($row['email'] ?: 'N/A') ?></span></td>
                            <td class="text-center"><span class="badge badge-info badge-pill"><?= number_format($row['payment_count']) ?></span></td>
                            <td class="text-right"><strong class="text-success">₵<?= number_format($row['total_amount'], 2) ?></strong></td>
                            <td class="text-center">₵<?= number_format($avg_amount, 2) ?></td>
                            <td class="text-center"><span class="badge badge-primary"><?= $row['payment_types_used'] ?></span></td>
                            <td class="text-center"><span class="badge badge-warning"><?= $row['modes_used'] ?></span></td>
                            <td class="text-center">
                                <div class="progress" style="height: 25px; min-width: 80px;">
                                    <div class="progress-bar bg-gradient-success" role="progressbar" style="width: <?= $percentage ?>%">
                                        <strong><?= number_format($percentage, 1) ?>%</strong>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center">
                                <a href="?date=<?= $date ?>&cashier=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-light">
                        <tr>
                            <th colspan="3" class="text-right">Grand Total:</th>
                            <th class="text-center"><strong><?= number_format($total_count) ?></strong></th>
                            <th class="text-right"><strong class="text-success">₵<?= number_format($total_amount, 2) ?></strong></th>
                            <th colspan="3"></th>
                            <th class="text-center"><strong>100%</strong></th>
                            <th></th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
                        
        <!-- Payment Type Analysis -->
        <?php if (count($by_payment_type) > 0): ?>
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-tags mr-2"></i>Payment Type Analysis</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="paymentTypeTable" class="table table-hover table-bordered matrix-table">
                        <thead>
                        <tr>
                            <th>#</th>
                            <th>Payment Type</th>
                            <th class="text-center">Count</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-right">Avg Amount</th>
                            <th class="text-right">Min Amount</th>
                            <th class="text-right">Max Amount</th>
                            <th class="text-center">% of Total</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($by_payment_type as $idx => $row): 
                            $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                        ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><span class="badge badge-primary badge-pill"><?= htmlspecialchars($row['payment_type']) ?></span></td>
                            <td class="text-center"><strong><?= number_format($row['payment_count']) ?></strong></td>
                            <td class="text-right"><strong class="text-success">₵<?= number_format($row['total_amount'], 2) ?></strong></td>
                            <td class="text-right">₵<?= number_format($row['avg_amount'], 2) ?></td>
                            <td class="text-right text-muted">₵<?= number_format($row['min_amount'], 2) ?></td>
                            <td class="text-right text-muted">₵<?= number_format($row['max_amount'], 2) ?></td>
                            <td class="text-center">
                                <div class="progress" style="height: 25px; min-width: 80px;">
                                    <div class="progress-bar bg-gradient-info" role="progressbar" style="width: <?= $percentage ?>%">
                                        <strong><?= number_format($percentage, 1) ?>%</strong>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-light">
                        <tr>
                            <th colspan="2" class="text-right">Total:</th>
                            <th class="text-center"><strong><?= number_format($total_count) ?></strong></th>
                            <th class="text-right"><strong class="text-success">₵<?= number_format($total_amount, 2) ?></strong></th>
                            <th colspan="3"></th>
                            <th class="text-center"><strong>100%</strong></th>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
                        
        <!-- Payment Mode Analysis -->
        <?php if (count($by_payment_mode) > 0): ?>
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-credit-card mr-2"></i>Payment Mode Analysis</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="row mb-3">
                    <?php 
                    $mode_colors = [
                        'cash' => 'success',
                        'cheque' => 'info',
                        'momo' => 'warning',
                        'card' => 'primary'
                    ];
                    $mode_icons = [
                        'cash' => 'fa-money-bill-wave',
                        'cheque' => 'fa-money-check',
                        'momo' => 'fa-mobile-alt',
                        'card' => 'fa-credit-card'
                    ];
                    foreach ($by_payment_mode as $row): 
                        $mode = strtolower($row['mode']);
                        $color = $mode_colors[$mode] ?? 'secondary';
                        $icon = $mode_icons[$mode] ?? 'fa-wallet';
                        $percentage = $total_amount > 0 ? ($row['total_amount'] / $total_amount) * 100 : 0;
                    ?>
                    <div class="col-md-3 mb-3">
                        <div class="card border-<?= $color ?> h-100">
                            <div class="card-body text-center">
                                <i class="fas <?= $icon ?> fa-3x text-<?= $color ?> mb-3"></i>
                                <h5 class="text-uppercase"><?= htmlspecialchars($row['mode']) ?></h5>
                                <h3 class="text-<?= $color ?> mb-2">₵<?= number_format($row['total_amount'], 2) ?></h3>
                                <p class="mb-1"><strong><?= number_format($row['payment_count']) ?></strong> transactions</p>
                                <p class="text-muted mb-0">Avg: ₵<?= number_format($row['avg_amount'], 2) ?></p>
                                <div class="progress mt-2" style="height: 8px;">
                                    <div class="progress-bar bg-<?= $color ?>" style="width: <?= $percentage ?>%"></div>
                                </div>
                                <small class="text-muted"><?= number_format($percentage, 1) ?>% of total</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
                        
        <!-- Recent Transactions Audit Trail -->
        <?php if (count($recent_transactions) > 0): ?>
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-list-alt mr-2"></i>Recent Transactions (Last <?= count($recent_transactions) ?>)</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="transactionsTable" class="table table-hover table-sm">
                        <thead class="thead-light">
                        <tr>
                            <th>ID</th>
                            <th>Time</th>
                            <th>Payer</th>
                            <th>ID/CRN</th>
                            <th>Payment Type</th>
                            <th>Mode</th>
                            <th class="text-right">Amount</th>
                            <th>Recorded By</th>
                            <th>Description</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recent_transactions as $txn): 
                            $mode = strtolower($txn['mode']);
                            $mode_color = ['cash' => 'success', 'cheque' => 'info', 'momo' => 'warning', 'card' => 'primary'][$mode] ?? 'secondary';
                        ?>
                        <tr class="transaction-row">
                            <td><small class="text-muted">#<?= $txn['id'] ?></small></td>
                            <td><small><?= date('h:i A', strtotime($txn['payment_date'])) ?></small></td>
                            <td><?= htmlspecialchars($txn['payer_first_name'] . ' ' . $txn['payer_last_name']) ?></td>
                            <td><span class="badge badge-secondary"><?= htmlspecialchars($txn['payer_id']) ?></span></td>
                            <td><small><?= htmlspecialchars($txn['payment_type']) ?></small></td>
                            <td><span class="badge badge-<?= $mode_color ?> badge-mode"><?= htmlspecialchars(ucfirst($txn['mode'])) ?></span></td>
                            <td class="text-right"><strong class="text-success">₵<?= number_format($txn['amount'], 2) ?></strong></td>
                            <td><small><?= htmlspecialchars($txn['recorded_by_name']) ?></small></td>
                            <td><small class="text-muted"><?= htmlspecialchars($txn['description'] ?: '-') ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Cashier x Payment Type Matrix -->
        <?php if ($is_super_admin && !$filter_cashier && count($cashier_payment_type_matrix) > 0): ?>
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-th mr-2"></i>Cashier × Payment Type Matrix</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-dark">
                        <tr>
                            <th>Cashier</th>
                            <th>Payment Type</th>
                            <th class="text-center">Count</th>
                            <th class="text-right">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $current_cashier = '';
                        foreach ($cashier_payment_type_matrix as $row): 
                            $is_new_cashier = $current_cashier !== $row['cashier_name'];
                            $current_cashier = $row['cashier_name'];
                        ?>
                        <tr <?= $is_new_cashier ? 'class="table-active"' : '' ?>>
                            <td><?= $is_new_cashier ? '<strong>' . htmlspecialchars($row['cashier_name']) . '</strong>' : '' ?></td>
                            <td><span class="badge badge-primary"><?= htmlspecialchars($row['payment_type']) ?></span></td>
                            <td class="text-center"><?= number_format($row['payment_count']) ?></td>
                            <td class="text-right">₵<?= number_format($row['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Cashier x Payment Mode Matrix -->
        <?php if ($is_super_admin && !$filter_cashier && count($cashier_payment_mode_matrix) > 0): ?>
        <div class="section-header">
            <h4 class="mb-0"><i class="fas fa-table mr-2"></i>Cashier × Payment Mode Matrix</h4>
        </div>
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="thead-dark">
                        <tr>
                            <th>Cashier</th>
                            <th>Payment Mode</th>
                            <th class="text-center">Count</th>
                            <th class="text-right">Amount</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php 
                        $current_cashier = '';
                        foreach ($cashier_payment_mode_matrix as $row): 
                            $is_new_cashier = $current_cashier !== $row['cashier_name'];
                            $current_cashier = $row['cashier_name'];
                            $mode = strtolower($row['mode']);
                            $mode_color = ['cash' => 'success', 'cheque' => 'info', 'momo' => 'warning', 'card' => 'primary'][$mode] ?? 'secondary';
                        ?>
                        <tr <?= $is_new_cashier ? 'class="table-active"' : '' ?>>
                            <td><?= $is_new_cashier ? '<strong>' . htmlspecialchars($row['cashier_name']) . '</strong>' : '' ?></td>
                            <td><span class="badge badge-<?= $mode_color ?>"><?= htmlspecialchars(ucfirst($row['mode'])) ?></span></td>
                            <td class="text-center"><?= number_format($row['payment_count']) ?></td>
                            <td class="text-right">₵<?= number_format($row['total_amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="alert alert-info text-center my-5">
            <i class="fas fa-info-circle fa-3x mb-3 d-block"></i>
            <h4>No Payments Recorded</h4>
            <p class="mb-0">There are no payment transactions for the selected date<?= $filter_cashier ? ' and cashier' : '' ?>.</p>
        </div>
    <?php endif; ?>
    <?php include_datatables_scripts(); ?>
    <script>
    $(document).ready(function() {
        // Common DataTable configuration
        const commonConfig = {
            dom: 'Bfrtip',
            buttons: [
                { extend: 'copy', className: 'btn btn-sm btn-outline-secondary', text: '<i class="fas fa-copy"></i> Copy' },
                { extend: 'csv', className: 'btn btn-sm btn-outline-primary', text: '<i class="fas fa-file-csv"></i> CSV' },
                { extend: 'excel', className: 'btn btn-sm btn-outline-success', text: '<i class="fas fa-file-excel"></i> Excel' },
                { extend: 'pdf', className: 'btn btn-sm btn-outline-danger', text: '<i class="fas fa-file-pdf"></i> PDF' },
                { extend: 'print', className: 'btn btn-sm btn-outline-dark', text: '<i class="fas fa-print"></i> Print' }
            ],
            responsive: true,
            pageLength: 25,
            order: [[0, 'asc']],
            language: {
                search: '<i class="fas fa-search"></i>',
                searchPlaceholder: 'Search records...'
            }
        };
        
        // Initialize each table if it exists
        <?php if ($is_super_admin && count($by_cashier) > 0): ?>
        $('#cashierTable').DataTable({
            ...commonConfig,
            order: [[4, 'desc']], // Sort by total amount descending
            columnDefs: [
                { targets: [9], orderable: false } // Disable sorting on action column
            ]
        });
        <?php endif; ?>
        
        <?php if (count($by_payment_type) > 0): ?>
        $('#paymentTypeTable').DataTable({
            ...commonConfig,
            order: [[3, 'desc']] // Sort by total amount descending
        });
        <?php endif; ?>
        
        <?php if (count($recent_transactions) > 0): ?>
        $('#transactionsTable').DataTable({
            ...commonConfig,
            order: [[0, 'desc']], // Sort by ID descending (most recent first)
            pageLength: 50
        });
        <?php endif; ?>
        
        // Add smooth scroll to sections
        $('a[href^="#"]').on('click', function(e) {
            e.preventDefault();
            $('html, body').animate({
                scrollTop: $($(this).attr('href')).offset().top - 100
            }, 500);
        });
    });
    </script>
</main>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../../includes/layout.php';
?>
