<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Super admin and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payments_by_user_report') && !has_permission('view_payment_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../errors/403.php')) {
        include __DIR__.'/../../errors/403.php';
    } else if (file_exists(__DIR__.'/../../../views/errors/403.php')) {
        include __DIR__.'/../../../views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Filters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$user_id = $_GET['user_id'] ?? '';
$payment_type_id = $_GET['payment_type_id'] ?? '';

// Build filter SQL
$where = [];
$params = [];
$types = '';
if ($date_from) {
    $where[] = 'p.payment_date >= ?';
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where[] = 'p.payment_date <= ?';
    $params[] = $date_to;
    $types .= 's';
}
if ($user_id) {
    $where[] = 'u.id = ?';
    $params[] = $user_id;
    $types .= 'i';
}
if ($payment_type_id) {
    $where[] = 'p.payment_type_id = ?';
    $params[] = $payment_type_id;
    $types .= 'i';
}

$sql = "SELECT u.id AS user_id, u.name AS user_name, u.email AS user_email, COUNT(p.id) AS payment_count, 
               SUM(p.amount) AS total_amount, GROUP_CONCAT(DISTINCT pt.name) AS payment_types
        FROM users u
        LEFT JOIN payments p ON p.recorded_by = u.id
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
        ".($where ? 'WHERE '.implode(' AND ', $where) : '')."
        GROUP BY u.id
        ORDER BY total_amount DESC, payment_count DESC, u.name ASC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Fetch filter options
$users = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
$payment_types = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-user-circle mr-2"></i>Payments by User Report</h1>
    <button class="btn btn-success" onclick="exportToExcel()"><i class="fas fa-file-excel mr-1"></i> Export to Excel</button>
</div>
<form method="get" class="form-row align-items-end mb-4" id="filterForm">
    <div class="form-group col-md-3 mb-2">
        <label for="user_id" class="font-weight-bold">User</label>
        <select class="form-control" id="user_id" name="user_id">
            <option value="">All</option>
            <?php while($u = $users->fetch_assoc()): ?>
                <option value="<?= $u['id'] ?>" <?= ($user_id==$u['id']?'selected':'') ?>><?= htmlspecialchars($u['name']) ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="form-group col-md-3 mb-2">
        <label for="payment_type_id" class="font-weight-bold">Payment Type</label>
        <select class="form-control" id="payment_type_id" name="payment_type_id">
            <option value="">All</option>
            <?php while($pt = $payment_types->fetch_assoc()): ?>
                <option value="<?= $pt['id'] ?>" <?= ($payment_type_id==$pt['id']?'selected':'') ?>><?= htmlspecialchars($pt['name']) ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="form-group col-md-2 mb-2">
        <label for="date_from" class="font-weight-bold">From</label>
        <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
    </div>
    <div class="form-group col-md-2 mb-2">
        <label for="date_to" class="font-weight-bold">To</label>
        <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
    </div>
    <div class="form-group col-md-2 mb-2">
        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Filter</button>
    </div>
</form>
<div class="card shadow mb-4">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" id="reportTable">
                <thead class="thead-light">
                    <tr>
                        <th>User</th>
                        <th>Email</th>
                        <th>Payment Count</th>
                        <th>Total Amount</th>
                        <th>Payment Types</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $grand_total = 0;
                $grand_count = 0;
                if ($result && $result->num_rows > 0):
                    while($row = $result->fetch_assoc()):
                        $grand_total += $row['total_amount'];
                        $grand_count += $row['payment_count'];
                ?>
                    <tr>
                        <td><?= htmlspecialchars($row['user_name']) ?></td>
                        <td><?= htmlspecialchars($row['user_email']) ?></td>
                        <td><?= number_format($row['payment_count']) ?></td>
                        <td><span class="text-success font-weight-bold">₵<?= number_format($row['total_amount'], 2) ?></span></td>
                        <td><?= htmlspecialchars($row['payment_types']) ?></td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center">No data found for the selected filters.</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="font-weight-bold">
                    <tr>
                        <td colspan="2" class="text-right">TOTAL</td>
                        <td><?= number_format($grand_count) ?></td>
                        <td><span class="text-success">₵<?= number_format($grand_total, 2) ?></span></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script>
function exportToExcel() {
    const table = document.getElementById('reportTable');
    const wb = XLSX.utils.table_to_book(table, {sheet: 'Payments by User'});
    const filename = 'payments_by_user_' + new Date().toISOString().split('T')[0] + '.xlsx';
    XLSX.writeFile(wb, filename);
}
</script>
<style>
#reportTable th, #reportTable td { vertical-align: middle; }
</style>
<?php $page_content = ob_get_clean(); include '../../../includes/layout.php'; ?>