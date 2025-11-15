<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_audit_report')) {
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
$can_export = $is_super_admin || has_permission('export_audit_report');

$page_title = 'Audit & Security Report';
// Fetch filter options
// No church filter: audit_log does not have church_id
$users = $conn->query("SELECT id, name FROM users ORDER BY name");
$actions = $conn->query("SELECT DISTINCT action FROM audit_log WHERE action IS NOT NULL AND action <> '' ORDER BY action");
$where = "WHERE 1=1";
$params = [];
$bind_types = '';

if (!empty($_GET['user_id'])) {
    $where .= " AND a.user_id = ?";
    $params[] = intval($_GET['user_id']);
    $bind_types .= 'i';
}
if (!empty($_GET['action'])) {
    $where .= " AND a.action = ?";
    $params[] = $_GET['action'];
    $bind_types .= 's';
}
if (!empty($_GET['status'])) {
    $where .= " AND a.status = ?";
    $params[] = $_GET['status'];
    $bind_types .= 's';
}
if (!empty($_GET['from_date'])) {
    $where .= " AND a.created_at >= ?";
    $params[] = $_GET['from_date'];
    $bind_types .= 's';
}
if (!empty($_GET['to_date'])) {
    $where .= " AND a.created_at <= ?";
    $params[] = $_GET['to_date'];
    $bind_types .= 's';
}
$sql = "SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON a.user_id = u.id $where ORDER BY a.created_at DESC, a.id DESC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($bind_types, ...$params);
}
$stmt->execute();
$auditlogs = $stmt->get_result();
// Trend chart data
$trend_sql = "SELECT DATE_FORMAT(a.created_at, '%Y-%m') AS ym, COUNT(*) AS count FROM audit_log a $where GROUP BY ym ORDER BY ym";
$trend_stmt = $conn->prepare($trend_sql);
if ($params) {
    $trend_stmt->bind_param($bind_types, ...$params);
}
$trend_stmt->execute();
$trend_res = $trend_stmt->get_result();
$trend_labels = [];
$trend_counts = [];
while ($row = $trend_res->fetch_assoc()) {
    $trend_labels[] = $row['ym'];
    $trend_counts[] = $row['count'];
}
?>
<div class="container-fluid mt-4">
  <h2 class="mb-4">Audit & Security Report</h2>
  <form class="form-row mb-3" method="get">
    
    <div class="form-group col-md-2">
      <label>User</label>
      <select name="user_id" class="form-control">
        <option value="">All</option>
        <?php if ($users) while($u = $users->fetch_assoc()): ?>
          <option value="<?= $u['id'] ?>"<?= isset($_GET['user_id']) && $_GET['user_id']==$u['id'] ? ' selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Action</label>
      <select name="action" class="form-control">
        <option value="">All</option>
        <?php if ($actions) while($ac = $actions->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($ac['action']) ?>"<?= isset($_GET['action']) && $_GET['action']==$ac['action'] ? ' selected' : '' ?>><?= htmlspecialchars($ac['action']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Status</label>
      <select name="status" class="form-control">
        <option value="">All</option>
        <option value="success"<?= isset($_GET['status']) && $_GET['status']=='success' ? ' selected' : '' ?>>Success</option>
        <option value="failed"<?= isset($_GET['status']) && $_GET['status']=='failed' ? ' selected' : '' ?>>Failed</option>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>From Date</label>
      <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($_GET['from_date'] ?? '') ?>">
    </div>
    <div class="form-group col-md-2">
      <label>To Date</label>
      <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($_GET['to_date'] ?? '') ?>">
    </div>
    <div class="form-group col-md-2 align-self-end">
      <button type="submit" class="btn btn-primary btn-block">Filter</button>
    </div>
  </form>
  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong>Audit Trend (Events per Month)</strong>
    </div>
    <div class="card-body">
      <canvas id="trendChart" height="60"></canvas>
    </div>
  </div>
  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold text-primary">Audit & Security Records</h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered" id="auditTable" width="100%" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              
              <th>User</th>
              <th>Action</th>
              <th>Description</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row = $auditlogs->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($row['created_at'])?></td>
              
              <td><?=htmlspecialchars($row['user_name'] ?? '-')?></td>
              <td><?=htmlspecialchars($row['action'])?></td>
              <td><?=htmlspecialchars(mb_strimwidth($row['description'] ?? '-', 0, 50, '...'))?></td>
              <td><?=htmlspecialchars(ucfirst($row['status'] ?? '-'))?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<!-- DataTables and Chart.js scripts -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function() {
    $('#auditTable').DataTable({
        dom: 'Bfrtip',
        buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
    });
    var ctx = document.getElementById('trendChart').getContext('2d');
    var trendChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_labels) ?>,
            datasets: [{
                label: 'Events',
                data: <?= json_encode($trend_counts) ?>,
                backgroundColor: 'rgba(23, 162, 184, 0.2)',
                borderColor: 'rgba(23, 162, 184, 1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                title: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
});
</script>
<style>
.btn-xs { padding: 0.14rem 0.34rem !important; font-size: 0.89rem !important; line-height: 1.15 !important; border-radius: 0.22rem !important; }
#auditTable th, #auditTable td { vertical-align: middle !important; }
</style>
<?php
$page_content = ob_get_clean();
include_once __DIR__ . '/../../includes/layout.php';
