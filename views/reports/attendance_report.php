<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_attendance_report')) {
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
$can_export = $is_super_admin || has_permission('export_attendance_report');

$page_title = 'Attendance Report';
ob_start();

// Fetch filter options
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name");
$classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");

// Handle filters
$where = "WHERE 1=1";
$params = [];
$types = '';
if (!empty($_GET['church_id'])) {
    $where .= " AND s.church_id = ?";
    $params[] = intval($_GET['church_id']);
    $types .= 'i';
}
if (!empty($_GET['class_id'])) {
    $where .= " AND m.class_id = ?";
    $params[] = intval($_GET['class_id']);
    $types .= 'i';
}
if (!empty($_GET['status'])) {
    $where .= " AND r.status = ?";
    $params[] = $_GET['status'];
    $types .= 's';
}
if (!empty($_GET['from_date'])) {
    $where .= " AND s.service_date >= ?";
    $params[] = $_GET['from_date'];
    $types .= 's';
}
if (!empty($_GET['to_date'])) {
    $where .= " AND s.service_date <= ?";
    $params[] = $_GET['to_date'];
    $types .= 's';
}
$sql = "SELECT r.*, s.title AS session_title, s.service_date, s.church_id AS session_church_id, ch.name AS church_name, m.crn, m.last_name, m.first_name, m.middle_name, m.class_id, bc.name AS class_name FROM attendance_records r INNER JOIN attendance_sessions s ON r.session_id = s.id LEFT JOIN churches ch ON s.church_id = ch.id LEFT JOIN members m ON r.member_id = m.id LEFT JOIN bible_classes bc ON m.class_id = bc.id $where ORDER BY s.service_date DESC, m.last_name, m.first_name, m.middle_name";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$records = $stmt->get_result();

// For attendance trend chart: count presents per month
$trend_sql = "SELECT DATE_FORMAT(s.service_date, '%Y-%m') AS ym, COUNT(*) AS count FROM attendance_records r INNER JOIN attendance_sessions s ON r.session_id = s.id LEFT JOIN members m ON r.member_id = m.id LEFT JOIN bible_classes bc ON m.class_id = bc.id $where AND r.status = 'present' GROUP BY ym ORDER BY ym";
$trend_stmt = $conn->prepare($trend_sql);
if ($params) {
    $trend_stmt->bind_param($types, ...$params);
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
  <h2 class="mb-4">Attendance Report</h2>
  <form class="form-row mb-3" method="get">
    <div class="form-group col-md-2">
      <label>Church</label>
      <select name="church_id" class="form-control">
        <option value="">All</option>
        <?php if ($churches) while($ch = $churches->fetch_assoc()): ?>
          <option value="<?= $ch['id'] ?>"<?= isset($_GET['church_id']) && $_GET['church_id']==$ch['id'] ? ' selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Bible Class</label>
      <select name="class_id" class="form-control">
        <option value="">All</option>
        <?php if ($classes) while($cl = $classes->fetch_assoc()): ?>
          <option value="<?= $cl['id'] ?>"<?= isset($_GET['class_id']) && $_GET['class_id']==$cl['id'] ? ' selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Status</label>
      <select name="status" class="form-control">
        <option value="">All</option>
        <option value="present"<?= isset($_GET['status']) && $_GET['status']=='present' ? ' selected' : '' ?>>Present</option>
        <option value="absent"<?= isset($_GET['status']) && $_GET['status']=='absent' ? ' selected' : '' ?>>Absent</option>
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
      <strong>Attendance Trend (Present per Month)</strong>
    </div>
    <div class="card-body">
      <canvas id="trendChart" height="60"></canvas>
    </div>
  </div>

  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold text-primary">Attendance Records</h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered" id="attendanceTable" width="100%" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              <th>Session</th>
              <th>Church</th>
              <th>CRN</th>
              <th>Full Name</th>
              <th>Bible Class</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row = $records->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($row['service_date'])?></td>
              <td><?=htmlspecialchars($row['session_title'])?></td>
              <td><?=htmlspecialchars($row['church_name'])?></td>
              <td><?=htmlspecialchars($row['crn'])?></td>
              <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?></td>
              <td><?=htmlspecialchars($row['class_name'])?></td>
              <td><?=htmlspecialchars(ucfirst($row['status']))?></td>
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
    $('#attendanceTable').DataTable({
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
                label: 'Present',
                data: <?= json_encode($trend_counts) ?>,
                backgroundColor: 'rgba(40, 167, 69, 0.2)',
                borderColor: 'rgba(40, 167, 69, 1)',
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
#attendanceTable th, #attendanceTable td { vertical-align: middle !important; }
</style>

<?php
// End output buffering and inject content into layout
$page_content = ob_get_clean();
include_once __DIR__ . '/../../includes/layout.php';
?>
