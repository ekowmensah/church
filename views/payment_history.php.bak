<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);

// Get member info for display
$member_stmt = $conn->prepare("SELECT first_name, last_name, crn FROM members WHERE id = ?");
$member_stmt->bind_param('i', $member_id);
$member_stmt->execute();
$member_info = $member_stmt->get_result()->fetch_assoc();

// Handle filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$payment_type_filter = $_GET['payment_type'] ?? '';
$search = $_GET['search'] ?? '';

// Build query conditions
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
if ($payment_type_filter) {
    $where .= ' AND p.payment_type_id = ?';
    $params[] = $payment_type_filter;
    $types .= 'i';
}
if ($search) {
    $where .= ' AND (p.description LIKE ? OR pt.name LIKE ?)';
    $search_term = '%' . $search . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

// Fetch payment history with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$sql = "SELECT p.*, pt.name AS payment_type, 
               p.payment_period, p.payment_period_description, p.mode
        FROM payments p 
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
        WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) 
        ORDER BY p.payment_date DESC, p.id DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM payments p 
              LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
              WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL))";
$count_stmt = $conn->prepare($count_sql);
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
$count_types = substr($types, 0, -2);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);


// Fetch summary with filters applied
$sum_where = 'member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))';
$sum_params = [$member_id];
$sum_types = 'i';

if ($start_date) {
    $sum_where .= ' AND payment_date >= ?';
    $sum_params[] = $start_date . ' 00:00:00';
    $sum_types .= 's';
}
if ($end_date) {
    $sum_where .= ' AND payment_date <= ?';
    $sum_params[] = $end_date . ' 23:59:59';
    $sum_types .= 's';
}
if ($payment_type_filter) {
    $sum_where .= ' AND payment_type_id = ?';
    $sum_params[] = $payment_type_filter;
    $sum_types .= 'i';
}

$sum_sql = "SELECT SUM(amount) as total, COUNT(*) as num, MAX(payment_date) as last, 
                   AVG(amount) as avg_amount, MIN(payment_date) as first_payment
            FROM payments WHERE $sum_where";
$sum_stmt = $conn->prepare($sum_sql);
$sum_stmt->bind_param($sum_types, ...$sum_params);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

$total_paid = $summary['total'] ? (float)$summary['total'] : 0;
$num_payments = $summary['num'] ? (int)$summary['num'] : 0;
$last_payment = $summary['last'] ? $summary['last'] : null;
$avg_payment = $summary['avg_amount'] ? (float)$summary['avg_amount'] : 0;
$first_payment = $summary['first_payment'] ? $summary['first_payment'] : null;

// Get payment types for filter dropdown
$pt_stmt = $conn->prepare("SELECT DISTINCT pt.id, pt.name FROM payment_types pt 
                          JOIN payments p ON pt.id = p.payment_type_id 
                          WHERE p.member_id = ? ORDER BY pt.name");
$pt_stmt->bind_param('i', $member_id);
$pt_stmt->execute();
$payment_types = $pt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

ob_start();
?>
<style>
.payment-card {
    transition: all 0.3s ease;
    border-radius: 15px;
    overflow: hidden;
}
.payment-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.15);
}
.gradient-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.gradient-success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.gradient-warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
.gradient-info { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.gradient-secondary { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
.filter-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 15px;
    border: none;
}
.table-modern {
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}
.badge-modern {
    padding: 8px 12px;
    border-radius: 20px;
    font-weight: 500;
}
</style>

<!-- Member Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card payment-card gradient-primary text-white">
            <div class="card-body text-center py-4">
                <h3 class="mb-1"><?= htmlspecialchars($member_info['first_name'] . ' ' . $member_info['last_name']) ?></h3>
                <p class="mb-0 opacity-75">CRN: <?= htmlspecialchars($member_info['crn']) ?></p>
                <h5 class="mt-2 mb-0"><i class="fas fa-history mr-2"></i>Payment History</h5>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Summary Cards -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card payment-card gradient-info text-white h-100">
            <div class="card-body text-center">
                <i class="fas fa-money-bill-wave fa-2x mb-2"></i>
                <h6 class="text-uppercase mb-1">Total Paid</h6>
                <h4 class="mb-0">₵<?= number_format($total_paid, 2) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card payment-card gradient-success text-white h-100">
            <div class="card-body text-center">
                <i class="fas fa-list-ol fa-2x mb-2"></i>
                <h6 class="text-uppercase mb-1">Payments</h6>
                <h4 class="mb-0"><?= $num_payments ?></h4>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card payment-card gradient-warning text-white h-100">
            <div class="card-body text-center">
                <i class="fas fa-chart-line fa-2x mb-2"></i>
                <h6 class="text-uppercase mb-1">Average</h6>
                <h4 class="mb-0">₵<?= number_format($avg_payment, 2) ?></h4>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="card payment-card gradient-secondary text-dark h-100">
            <div class="card-body text-center">
                <i class="fas fa-calendar-check fa-2x mb-2"></i>
                <h6 class="text-uppercase mb-1">Last Payment</h6>
                <p class="mb-0 small"><?= $last_payment ? date('M j, Y', strtotime($last_payment)) : 'N/A' ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="card payment-card gradient-primary text-white h-100">
            <div class="card-body text-center">
                <i class="fas fa-calendar-plus fa-2x mb-2"></i>
                <h6 class="text-uppercase mb-1">First Payment</h6>
                <p class="mb-0 small"><?= $first_payment ? date('M j, Y', strtotime($first_payment)) : 'N/A' ?></p>
            </div>
        </div>
    </div>
</div>
<!-- Advanced Filters -->
<div class="card filter-card mb-4">
    <div class="card-body">
        <h5 class="card-title mb-3"><i class="fas fa-filter mr-2"></i>Advanced Filters</h5>
        <form action="" method="get" id="filterForm">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="payment_type" class="form-label">Payment Type</label>
                    <select class="form-control" id="payment_type" name="payment_type">
                        <option value="">All Types</option>
                        <?php foreach ($payment_types as $pt): ?>
                            <option value="<?= $pt['id'] ?>" <?= $payment_type_filter == $pt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           placeholder="Description or type..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <button type="submit" class="btn btn-primary mr-2">
                        <i class="fas fa-search mr-1"></i>Apply Filters
                    </button>
                    <a href="payment_history.php" class="btn btn-outline-secondary mr-2">
                        <i class="fas fa-times mr-1"></i>Clear Filters
                    </a>
                    <button type="button" class="btn btn-success" onclick="exportData()">
                        <i class="fas fa-download mr-1"></i>Export CSV
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<!-- Payment History Table -->
<div class="card table-modern">
    <div class="card-header bg-gradient-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-table mr-2"></i>Payment Records</h5>
            <span class="badge badge-light"><?= $total_records ?> total records</span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($result->num_rows === 0): ?>
            <div class="text-center py-5">
                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No payments found</h5>
                <p class="text-muted">Try adjusting your filters or make your first payment.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="border-0">Payment Date</th>
                        <th class="border-0">Period</th>
                        <th class="border-0">Type</th>
                        <th class="border-0">Amount</th>
                        <th class="border-0">Payment Mode</th>
                        <th class="border-0">Description</th>
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
                            <div class="d-flex flex-column">
                                <strong><?= date('M j, Y', strtotime($row['payment_date'])) ?></strong>
                                <small class="text-muted"><?= date('g:i A', strtotime($row['payment_date'])) ?></small>
                            </div>
                        </td>
                        <td>
                            <?php if ($row['payment_period'] && $row['payment_period'] != $row['payment_date']): ?>
                                <span class="badge badge-info badge-modern">
                                    <?= $row['payment_period_description'] ?: date('M Y', strtotime($row['payment_period'])) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">Current</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-primary badge-modern">
                                <?= htmlspecialchars($row['payment_type']) ?>
                            </span>
                        </td>
                        <td>
                            <strong class="text-success">₵<?= number_format((float)$row['amount'], 2) ?></strong>
                        </td>
                        <td>
                            <?php 
                            $payment_mode = $row['mode'] ?? 'cash';
                            $mode_class = '';
                            $mode_icon = '';
                            
                            switch(strtolower($payment_mode)) {
                                case 'mobile_money':
                                case 'momo':
                                    $mode_class = 'badge-warning';
                                    $mode_icon = 'fas fa-mobile-alt';
                                    $payment_mode = 'Mobile Money';
                                    break;
                                case 'bank_transfer':
                                case 'transfer':
                                    $mode_class = 'badge-info';
                                    $mode_icon = 'fas fa-university';
                                    $payment_mode = 'Bank Transfer';
                                    break;
                                case 'cheque':
                                case 'check':
                                    $mode_class = 'badge-secondary';
                                    $mode_icon = 'fas fa-money-check';
                                    $payment_mode = 'Cheque';
                                    break;
                                case 'card':
                                case 'credit_card':
                                case 'debit_card':
                                    $mode_class = 'badge-primary';
                                    $mode_icon = 'fas fa-credit-card';
                                    $payment_mode = 'Card';
                                    break;
                                case 'cash':
                                default:
                                    $mode_class = 'badge-success';
                                    $mode_icon = 'fas fa-money-bill-wave';
                                    $payment_mode = 'Cash';
                                    break;
                            }
                            ?>
                            <span class="badge <?= $mode_class ?> badge-modern">
                                <i class="<?= $mode_icon ?> mr-1"></i><?= htmlspecialchars($payment_mode) ?>
                            </span>
                        </td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($row['description']) ?>">
                                <?= htmlspecialchars($row['description']) ?>
                            </span>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <small class="text-muted">
                        Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= $total_records ?> records
                    </small>
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Summary Footer -->
        <div class="card-footer bg-gradient-info text-white">
            <div class="row text-center">
                <div class="col-md-6">
                    <strong>Filtered Total: ₵<?= number_format($table_total, 2) ?></strong>
                </div>
                <div class="col-md-6">
                    <strong>Records on Page: <?= $result->num_rows ?></strong>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Action Buttons -->
<div class="row mt-4">
    <div class="col-md-12 text-center">
        <a href="member_dashboard.php" class="btn btn-outline-primary mr-2">
            <i class="fas fa-arrow-left mr-1"></i>Back to Dashboard
        </a>
        <button type="button" class="btn btn-outline-success" onclick="window.print()">
            <i class="fas fa-print mr-1"></i>Print History
        </button>
    </div>
</div>

<script>
function exportData() {
    const form = document.getElementById('filterForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.append('export', 'csv');
    window.location.href = 'export_payment_history.php?' + params.toString();
}

// Auto-submit form on filter change
document.addEventListener('DOMContentLoaded', function() {
    const filters = ['start_date', 'end_date', 'payment_type'];
    filters.forEach(id => {
        document.getElementById(id).addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
