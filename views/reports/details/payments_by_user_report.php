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
                        <th>Payments</th>
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
                        <td>
  <button class="btn btn-link p-0 payment-count-btn" 
          data-user-id="<?= $row['user_id'] ?>" 
          data-user-name="<?= htmlspecialchars($row['user_name']) ?>">
    <?= number_format($row['payment_count']) ?>
  </button>
</td>
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
<!-- User Transactions Modal -->
<div class="modal fade" id="userTransactionsModal" tabindex="-1" role="dialog" aria-labelledby="userTransactionsModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="userTransactionsModalLabel">User Transactions</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <form id="user-transactions-filter" class="form-row mb-3">
          <input type="hidden" id="modal_user_id" name="user_id">
          <div class="form-group col-md-4">
            <label for="modal_payment_type_id">Payment Type</label>
            <select class="form-control" id="modal_payment_type_id" name="payment_type_id">
              <option value="">All</option>
              <?php
              // Re-fetch payment types for modal
              $modal_payment_types = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");
              while($pt = $modal_payment_types->fetch_assoc()):
              ?>
                <option value="<?= $pt['id'] ?>"><?= htmlspecialchars($pt['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="modal_date_from">From</label>
            <input type="date" class="form-control" id="modal_date_from" name="date_from">
          </div>
          <div class="form-group col-md-3">
            <label for="modal_date_to">To</label>
            <input type="date" class="form-control" id="modal_date_to" name="date_to">
          </div>
          <div class="form-group col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary btn-block">Filter</button>
          </div>
        </form>
        <div id="user-transactions-table-area">
          <div class="text-center text-muted py-5">Select a user to view transactions.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
  $('.payment-count-btn').on('click', function() {
    var userId = $(this).data('user-id');
    var userName = $(this).data('user-name');
    $('#modal_user_id').val(userId);
    $('#userTransactionsModalLabel').text('Transactions for ' + userName);
    // Reset filters
    $('#modal_payment_type_id').val('');
    $('#modal_date_from').val('');
    $('#modal_date_to').val('');
    loadUserTransactions(1);
    $('#userTransactionsModal').modal('show');
  });

  $('#user-transactions-filter').on('submit', function(e) {
    e.preventDefault();
    loadUserTransactions(1);
  });

  // Pagination click handler
  $('#user-transactions-table-area').on('click', '.user-transactions-page-link', function(e) {
    e.preventDefault();
    var page = $(this).data('page');
    var perPage = $(this).data('per-page') || 10;
    loadUserTransactions(page, perPage);
  });

  // Export buttons
  $('#user-transactions-table-area').on('click', '#export-transactions-excel', function() {
    exportTableToExcel('user-transactions-table');
  });
  $('#user-transactions-table-area').on('click', '#export-transactions-csv', function() {
    exportTableToCSV('user-transactions-table');
  });

  function loadUserTransactions(page, perPage) {
    var userId = $('#modal_user_id').val();
    var paymentTypeId = $('#modal_payment_type_id').val();
    var dateFrom = $('#modal_date_from').val();
    var dateTo = $('#modal_date_to').val();
    $('#user-transactions-table-area').html('<div class="text-center py-5"><span class="spinner-border"></span> Loading...</div>');
    $.get('ajax_user_transactions.php', {
      user_id: userId,
      payment_type_id: paymentTypeId,
      date_from: dateFrom,
      date_to: dateTo,
      page: page || 1,
      per_page: perPage
    }, function(data) {
      $('#user-transactions-table-area').html(data);
    });
  }

  // Export helpers
  function exportTableToExcel(tableID) {
    if(typeof XLSX === 'undefined') {
      alert('Excel export requires XLSX.js library.');
      return;
    }
    var wb = XLSX.utils.table_to_book(document.getElementById(tableID), {sheet: "Transactions"});
    XLSX.writeFile(wb, "user_transactions.xlsx");
  }
  function exportTableToCSV(tableID) {
    var table = document.getElementById(tableID);
    var rows = Array.from(table.rows);
    var csv = rows.map(row => Array.from(row.cells).map(cell => '"'+cell.innerText.replace(/"/g, '""')+'"').join(',')).join('\n');
    var blob = new Blob([csv], { type: 'text/csv' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = "user_transactions.csv";
    link.click();
  }
});
</script>