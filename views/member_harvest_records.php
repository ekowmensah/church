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
$search = $_GET['search'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';

// Build query conditions
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
if ($search) {
    $where .= ' AND p.description LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}
if ($amount_min) {
    $where .= ' AND p.amount >= ?';
    $params[] = $amount_min;
    $types .= 'd';
}
if ($amount_max) {
    $where .= ' AND p.amount <= ?';
    $params[] = $amount_max;
    $types .= 'd';
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Fetch filtered harvest payment history with pagination
$sql = "SELECT p.*, pt.name AS payment_type, p.payment_period, p.payment_period_description, p.mode
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
              WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL))";
$count_stmt = $conn->prepare($sql);
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
$count_types = substr($types, 0, -2);
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);



// Fetch enhanced summary with filters applied
$sum_where = 'member_id = ? AND payment_type_id = 4 AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))';
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
if ($search) {
    $sum_where .= ' AND description LIKE ?';
    $sum_params[] = '%' . $search . '%';
    $sum_types .= 's';
}
if ($amount_min) {
    $sum_where .= ' AND amount >= ?';
    $sum_params[] = $amount_min;
    $sum_types .= 'd';
}
if ($amount_max) {
    $sum_where .= ' AND amount <= ?';
    $sum_params[] = $amount_max;
    $sum_types .= 'd';
}

$sum_sql = "SELECT SUM(amount) as total, COUNT(*) as num, MAX(payment_date) as last, 
                   AVG(amount) as avg_amount, MIN(payment_date) as first_harvest
            FROM payments WHERE $sum_where";
$sum_stmt = $conn->prepare($sum_sql);
$sum_stmt->bind_param($sum_types, ...$sum_params);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

$total_harvest = $summary['total'] ? (float)$summary['total'] : 0;
$num_harvest_payments = $summary['num'] ? (int)$summary['num'] : 0;
$last_harvest_payment = $summary['last'] ? $summary['last'] : null;
$avg_harvest = $summary['avg_amount'] ? (float)$summary['avg_amount'] : 0;
$first_harvest = $summary['first_harvest'] ? $summary['first_harvest'] : null;

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

<!-- Member Info Header -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-gradient-success text-white">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-user-circle fa-3x"></i>
                    </div>
                    <div>
                        <h4 class="mb-1"><?= htmlspecialchars($member_info['first_name'] . ' ' . $member_info['last_name']) ?></h4>
                        <p class="mb-0"><strong>CRN:</strong> <?= htmlspecialchars($member_info['crn']) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Summary Cards -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-gradient-success text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-seedling fa-2x"></i>
                </div>
                <div class="text-xs font-weight-bold text-uppercase mb-1">Total Harvest</div>
                <div class="h5 mb-0 font-weight-bold">₵<?= number_format($total_harvest, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-gradient-info text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-calculator fa-2x"></i>
                </div>
                <div class="text-xs font-weight-bold text-uppercase mb-1">Total Payments</div>
                <div class="h5 mb-0 font-weight-bold"><?= $num_harvest_payments ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
        <div class="card bg-gradient-warning text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-chart-line fa-2x"></i>
                </div>
                <div class="text-xs font-weight-bold text-uppercase mb-1">Average Payment</div>
                <div class="h6 mb-0 font-weight-bold">₵<?= number_format($avg_harvest, 2) ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="card bg-gradient-primary text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-clock fa-2x"></i>
                </div>
                <div class="text-xs font-weight-bold text-uppercase mb-1">Latest Payment</div>
                <div class="h6 mb-0 font-weight-bold"><?= $last_harvest_payment ? date('M d, Y', strtotime($last_harvest_payment)) : 'N/A' ?></div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-sm-6 mb-3">
        <div class="card bg-gradient-secondary text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-calendar-alt fa-2x"></i>
                </div>
                <div class="text-xs font-weight-bold text-uppercase mb-1">First Payment</div>
                <div class="h6 mb-0 font-weight-bold"><?= $first_harvest ? date('M d, Y', strtotime($first_harvest)) : 'N/A' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Advanced Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter mr-2"></i>Advanced Filters</h5>
            </div>
            <div class="card-body">
                <form action="" method="get" id="filterForm">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="search" class="form-label">Search Description</label>
                            <input type="text" class="form-control" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search in descriptions...">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="amount_min" class="form-label">Min Amount (₵)</label>
                            <input type="number" class="form-control" id="amount_min" name="amount_min" value="<?= htmlspecialchars($amount_min) ?>" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="amount_max" class="form-label">Max Amount (₵)</label>
                            <input type="number" class="form-control" id="amount_max" name="amount_max" value="<?= htmlspecialchars($amount_max) ?>" step="0.01" min="0">
                        </div>
                        <div class="col-md-9 mb-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary mr-2">
                                <i class="fas fa-search mr-1"></i>Apply Filters
                            </button>
                            <?php if ($start_date || $end_date || $search || $amount_min || $amount_max): ?>
                                <a href="member_harvest_records.php" class="btn btn-outline-secondary mr-2">
                                    <i class="fas fa-times mr-1"></i>Clear All
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-success mr-2" onclick="exportCSV()">
                                <i class="fas fa-download mr-1"></i>Export CSV
                            </button>
                            <button type="button" class="btn btn-info" onclick="printTable()">
                                <i class="fas fa-print mr-1"></i>Print
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Harvest Records Table -->
<div class="card shadow">
    <div class="card-header bg-gradient-success text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-seedling mr-2"></i>Harvest Payment Records
                <?php if ($start_date || $end_date || $search || $amount_min || $amount_max): ?>
                    <small class="ml-2 badge badge-light text-dark">
                        Filtered Results (<?= $total_records ?> of total)
                    </small>
                <?php endif; ?>
            </h5>
            <span class="badge badge-light text-dark">
                Showing <?= min($per_page, $total_records) ?> of <?= $total_records ?> records
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info m-3">
                <i class="fas fa-info-circle mr-2"></i>
                <?php if ($start_date || $end_date || $search || $amount_min || $amount_max): ?>
                    No harvest payments found matching your filter criteria.
                <?php else: ?>
                    You have not made any harvest payments yet.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="harvestTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0"><i class="fas fa-calendar mr-1"></i>Date & Time</th>
                            <th class="border-0"><i class="fas fa-money-bill-wave mr-1"></i>Amount</th>
                            <th class="border-0"><i class="fas fa-calendar-week mr-1"></i>Period</th>
                            <th class="border-0"><i class="fas fa-comment mr-1"></i>Description</th>
                            <th class="border-0"><i class="fas fa-credit-card mr-1"></i>Payment Mode</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $table_total = 0;
                    while ($row = $result->fetch_assoc()): 
                        $table_total += (float)$row['amount'];
                    ?>
                        <tr class="hover-highlight">
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="font-weight-bold text-dark"><?= date('M d, Y', strtotime($row['payment_date'])) ?></span>
                                    <small class="text-muted"><?= date('h:i A', strtotime($row['payment_date'])) ?></small>
                                </div>
                            </td>
                            <td>
                                <span class="font-weight-bold text-success h6 mb-0">₵<?= number_format((float)$row['amount'], 2) ?></span>
                            </td>
                            <td>
                                <?php if ($row['payment_period']): ?>
                                    <span class="badge badge-info"><?= htmlspecialchars($row['payment_period']) ?></span>
                                    <?php if ($row['payment_period_description']): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($row['payment_period_description']) ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-dark"><?= htmlspecialchars($row['description'] ?: 'Harvest Payment') ?></span>
                            </td>
                            <td>
                                <?php
                                $mode = $row['mode'] ?: 'Cash';
                                $mode_class = 'secondary';
                                $mode_icon = 'fas fa-money-bill';
                                
                                switch (strtolower($mode)) {
                                    case 'mobile money':
                                        $mode_class = 'success';
                                        $mode_icon = 'fas fa-mobile-alt';
                                        break;
                                    case 'bank transfer':
                                        $mode_class = 'primary';
                                        $mode_icon = 'fas fa-university';
                                        break;
                                    case 'cheque':
                                        $mode_class = 'warning';
                                        $mode_icon = 'fas fa-file-invoice';
                                        break;
                                    case 'card':
                                        $mode_class = 'info';
                                        $mode_icon = 'fas fa-credit-card';
                                        break;
                                }
                                ?>
                                <span class="badge badge-<?= $mode_class ?>">
                                    <i class="<?= $mode_icon ?> mr-1"></i><?= htmlspecialchars($mode) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                    <tfoot class="bg-light">
                        <tr class="font-weight-bold">
                            <td class="text-right border-0">
                                <strong>Page Total:</strong>
                            </td>
                            <td class="border-0">
                                <strong class="text-success h6 mb-0">₵<?= number_format($table_total, 2) ?></strong>
                            </td>
                            <td colspan="3" class="border-0"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= $total_records ?> entries
                            </small>
                        </div>
                        <nav aria-label="Harvest records pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
    </div>
    <div class="card-footer bg-light">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <a href="member_dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-1"></i>Back to Dashboard
                </a>
                <a href="payment_history.php" class="btn btn-outline-primary ml-2">
                    <i class="fas fa-history mr-1"></i>View All Payments
                </a>
            </div>
            <div>
                <small class="text-muted">
                    <i class="fas fa-info-circle mr-1"></i>
                    Total Harvest Contributions: <strong class="text-success">₵<?= number_format($total_harvest, 2) ?></strong>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
.hover-highlight:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

.bg-gradient-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
}

.bg-gradient-info {
    background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%) !important;
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%) !important;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #6610f2 100%) !important;
}

.bg-gradient-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
}

.card.shadow-lg {
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    
    .bg-gradient-success,
    .bg-gradient-info,
    .bg-gradient-warning,
    .bg-gradient-primary,
    .bg-gradient-secondary {
        background: #f8f9fa !important;
        color: #000 !important;
    }
}
</style>

<script>
// Document ready functions
$(document).ready(function() {
    // Any initialization code can go here
});

// Export to CSV functionality
function exportCSV() {
    const table = document.getElementById('harvestTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    // Add header
    const headerCells = rows[0].querySelectorAll('th');
    let headerRow = [];
    headerCells.forEach(cell => {
        headerRow.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
    });
    csv.push(headerRow.join(','));
    
    // Add data rows (skip header and footer)
    for (let i = 1; i < rows.length - 1; i++) {
        const cells = rows[i].querySelectorAll('td');
        let row = [];
        cells.forEach(cell => {
            // Clean up cell content
            let content = cell.textContent.trim().replace(/\s+/g, ' ').replace(/"/g, '""');
            row.push('"' + content + '"');
        });
        csv.push(row.join(','));
    }
    
    // Create and download file
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'harvest_records_' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Print functionality
function printTable() {
    const printContent = document.querySelector('.card').outerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <html>
        <head>
            <title>Harvest Records - Print</title>
            <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
            <style>
                @media print {
                    .no-print { display: none !important; }
                    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
                    .bg-gradient-success, .bg-gradient-info, .bg-gradient-warning, 
                    .bg-gradient-primary, .bg-gradient-secondary { 
                        background: #f8f9fa !important; color: #000 !important; 
                    }
                }
            </style>
        </head>
        <body>
            <div class="container-fluid">
                <h2 class="text-center mb-4">Harvest Payment Records</h2>
                ${printContent}
            </div>
        </body>
        </html>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
