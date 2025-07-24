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

if (!$is_super_admin && !has_permission('view_sms_report')) {
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
$can_export = $is_super_admin || has_permission('export_sms_report');

$page_title = 'SMS & Communication Report';
// Filter options
$where = 'WHERE 1=1';
$params = [];
$bind_types = '';
if (!empty($_GET['phone'])) {
    $where .= ' AND l.phone = ?';
    $params[] = $_GET['phone'];
    $bind_types .= 's';
}
if (!empty($_GET['sender'])) {
    $where .= ' AND l.sender = ?';
    $params[] = $_GET['sender'];
    $bind_types .= 's';
}
if (!empty($_GET['type'])) {
    $where .= ' AND l.type = ?';
    $params[] = $_GET['type'];
    $bind_types .= 's';
}
if (!empty($_GET['status'])) {
    $where .= ' AND l.status = ?';
    $params[] = $_GET['status'];
    $bind_types .= 's';
}
if (!empty($_GET['from_date'])) {
    $where .= ' AND l.sent_at >= ?';
    $params[] = $_GET['from_date'];
    $bind_types .= 's';
}
if (!empty($_GET['to_date'])) {
    $where .= ' AND l.sent_at <= ?';
    $params[] = $_GET['to_date'];
    $bind_types .= 's';
}
// Main query
$sql = "SELECT l.*, m.first_name, m.last_name FROM sms_logs l LEFT JOIN members m ON l.phone = m.phone $where ORDER BY l.sent_at DESC, l.id DESC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($bind_types, ...$params);
}
$stmt->execute();
$smslogs = $stmt->get_result();
// Trend chart data
$trend_sql = "SELECT DATE_FORMAT(l.sent_at, '%Y-%m') AS ym, COUNT(*) AS count FROM sms_logs l $where GROUP BY ym ORDER BY ym";
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
  <h2 class="mb-4">SMS & Communication Report</h2>
  <form class="form-row mb-3" method="get">
    <div class="form-group col-md-2">
      <label>Phone</label>
      <input type="text" name="phone" value="<?=htmlspecialchars($_GET['phone']??'')?>" class="form-control" placeholder="Phone">
    </div>
    <div class="form-group col-md-2">
      <label>Sender</label>
      <input type="text" name="sender" value="<?=htmlspecialchars($_GET['sender']??'')?>" class="form-control" placeholder="Sender">
    </div>
    <div class="form-group col-md-2">
      <label>Type</label>
      <select name="type" class="form-control">
        <option value="">All</option>
        <?php foreach(["general","registration","transfer","payment","bulk"] as $type): ?>
          <option value="<?=$type?>"<?=isset($_GET['type'])&&$_GET['type']==$type?' selected':''?>><?=ucfirst($type)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>Status</label>
      <select name="status" class="form-control">
        <option value="">All</option>
        <?php foreach(["sent","fail","success"] as $status): ?>
          <option value="<?=$status?>"<?=isset($_GET['status'])&&$_GET['status']==$status?' selected':''?>><?=ucfirst($status)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>From</label>
      <input type="date" name="from_date" value="<?=htmlspecialchars($_GET['from_date']??'')?>" class="form-control">
    </div>
    <div class="form-group col-md-2">
      <label>To</label>
      <input type="date" name="to_date" value="<?=htmlspecialchars($_GET['to_date']??'')?>" class="form-control">
    </div>
    <div class="form-group col-md-12 mt-2">
      <button class="btn btn-primary"><i class="fa fa-filter mr-1"></i>Filter</button>
      <a href="sms_report.php" class="btn btn-secondary ml-2">Reset</a>
      <?php if ($can_export): ?>
      <a href="../export_sms_logs.php?<?php
        $qs = [];
        foreach(['phone','sender','type','status','from_date','to_date'] as $k) if (!empty($_GET[$k])) $qs[] = "$k=".urlencode($_GET[$k]);
        echo implode('&',$qs);
      ?>" class="btn btn-outline-success ml-2"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
      <?php endif; ?>
    </div>
  </form>
  <div class="card mb-4">
    <div class="card-body">
      <canvas id="trendChart" height="60"></canvas>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered table-hover" id="smsTable">
      <thead class="thead-light">
        <tr>
          <th>Date/Time</th>
          <th>Phone</th>
          <th>Recipient</th>
          <th>Message</th>
          <th>Sender</th>
          <th>Type</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php while($row = $smslogs->fetch_assoc()): ?>
        <tr>
          <td><?=htmlspecialchars($row['sent_at'])?></td>
          <td><?=htmlspecialchars($row['phone'])?></td>
          <td><?=htmlspecialchars(trim(($row['first_name']??'').' '.($row['last_name']??'')))?></td>
          <td style="max-width:350px;overflow:auto;word-break:break-word;">
            <?=htmlspecialchars($row['message'])?>
          </td>
          <td><?=htmlspecialchars($row['sender']??'')?></td>
          <td><?=htmlspecialchars($row['type']??'')?></td>
          <td>
            <?php
            $status = $row['status'] ?? '';
            if (stripos($status, 'fail') !== false) {
              echo '<span class="badge badge-danger">Failed</span>';
            } elseif (stripos($status, 'sent') !== false || stripos($status, 'success') !== false) {
              echo '<span class="badge badge-success">Sent</span>';
            } else {
              echo '<span class="badge badge-secondary">' . htmlspecialchars($status) . '</span>';
            }
            ?>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4/dist/Chart.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" />
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" />
<script>
$(function(){
  $('#smsTable').DataTable({
    dom: 'Bfrtip',
    buttons: ['copy', 'csv', 'excel', 'print'],
    order: [[0, 'desc']]
  });
  var ctx = document.getElementById('trendChart').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?=json_encode($trend_labels)?>,
      datasets: [{
        label: 'SMS Sent',
        data: <?=json_encode($trend_counts)?>,
        borderColor: '#007bff',
        backgroundColor: 'rgba(0,123,255,0.1)',
        fill: true,
        lineTension: 0.2
      }]
    },
    options: {
      legend: {display: false},
      scales: {yAxes: [{ticks: {beginAtZero: true}}]},
      responsive: true
    }
  });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__.'/../../includes/layout.php';
