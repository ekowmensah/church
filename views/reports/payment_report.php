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

$page_title = 'Payment Report';
ob_start();

// Fetch filter options
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name");
$classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");
$types = $conn->query("SELECT id, name FROM payment_types ORDER BY name");

// Handle filters
$where = "WHERE 1=1";
$params = [];
$bind_types = '';
if (!empty($_GET['church_id'])) {
    $where .= " AND m.church_id = ?";
    $params[] = intval($_GET['church_id']);
    $bind_types .= 'i';
}
if (!empty($_GET['class_id'])) {
    $where .= " AND m.class_id = ?";
    $params[] = intval($_GET['class_id']);
    $bind_types .= 'i';
}
if (!empty($_GET['type_id'])) {
    $where .= " AND p.payment_type_id = ?";
    $params[] = intval($_GET['type_id']);
    $bind_types .= 'i';
}
if (!empty($_GET['member_crn'])) {
    $where .= " AND m.crn LIKE ?";
    $params[] = '%' . $_GET['member_crn'] . '%';
    $bind_types .= 's';
}
if (!empty($_GET['from_date'])) {
    $where .= " AND p.payment_date >= ?";
    $params[] = $_GET['from_date'];
    $bind_types .= 's';
}
if (!empty($_GET['to_date'])) {
    $where .= " AND p.payment_date <= ?";
    $params[] = $_GET['to_date'];
    $bind_types .= 's';
}
$sql = "SELECT p.*, pt.name AS payment_type, 
    m.crn, m.last_name, m.first_name, m.middle_name, m.class_id, bc.name AS class_name, m.church_id, ch.name AS church_name, 
    ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, ss.class_id AS ss_class_id, ss.church_id AS ss_church_id
FROM payments p 
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
    LEFT JOIN members m ON p.member_id = m.id 
    LEFT JOIN bible_classes bc ON m.class_id = bc.id 
    LEFT JOIN churches ch ON m.church_id = ch.id 
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id 
$where ORDER BY p.payment_date DESC, p.id DESC";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($bind_types, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result();

// For payment trend chart: sum per month
$trend_sql = "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') AS ym, SUM(p.amount) AS total FROM payments p LEFT JOIN members m ON p.member_id = m.id $where GROUP BY ym ORDER BY ym";
$trend_stmt = $conn->prepare($trend_sql);
if ($params) {
    $trend_stmt->bind_param($bind_types, ...$params);
}
$trend_stmt->execute();
$trend_res = $trend_stmt->get_result();
$trend_labels = [];
$trend_totals = [];
while ($row = $trend_res->fetch_assoc()) {
    $trend_labels[] = $row['ym'];
    $trend_totals[] = $row['total'] ? floatval($row['total']) : 0;
}
?>
<div class="container-fluid mt-4">
  <h2 class="mb-4">Payment Report</h2>
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
      <label>Payment Type</label>
      <select name="type_id" class="form-control">
        <option value="">All</option>
        <?php if ($types) while($tp = $types->fetch_assoc()): ?>
          <option value="<?= $tp['id'] ?>"<?= isset($_GET['type_id']) && $_GET['type_id']==$tp['id'] ? ' selected' : '' ?>><?= htmlspecialchars($tp['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group col-md-2">
      <label>CRN</label>
      <input type="text" name="member_crn" class="form-control" placeholder="Search CRN" value="<?= htmlspecialchars($_GET['member_crn'] ?? '') ?>">
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

  <!-- Export Buttons Section -->
  <?php if ($can_export): ?>
  <div class="card mb-4 shadow-sm">
    <div class="card-header bg-gradient-primary text-white">
      <h6 class="m-0 font-weight-bold"><i class="fas fa-file-export"></i> Export Reports</h6>
    </div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-3 mb-2">
          <button onclick="exportByPeriod()" class="btn btn-success btn-block">
            <i class="fas fa-calendar-alt"></i> Export by Period
          </button>
          <small class="text-muted d-block mt-1">Group by payment period/month</small>
        </div>
        <div class="col-md-3 mb-2">
          <button onclick="exportByMonth()" class="btn btn-info btn-block">
            <i class="fas fa-calendar"></i> Export by Month
          </button>
          <small class="text-muted d-block mt-1">Monthly summary with statistics</small>
        </div>
        <div class="col-md-3 mb-2">
          <button onclick="exportByType()" class="btn btn-warning btn-block">
            <i class="fas fa-tags"></i> Export by Payment Type
          </button>
          <small class="text-muted d-block mt-1">Group by payment type</small>
        </div>
        <div class="col-md-3 mb-2">
          <button onclick="exportByChurch()" class="btn btn-primary btn-block">
            <i class="fas fa-church"></i> Export by Church
          </button>
          <small class="text-muted d-block mt-1">Group by church location</small>
        </div>
      </div>
      <div class="alert alert-info mt-3 mb-0">
        <i class="fas fa-info-circle"></i> <strong>Note:</strong> All exports will include the current filter settings applied above.
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header bg-light">
      <strong>Payment Trend (Total per Month)</strong>
    </div>
    <div class="card-body">
      <canvas id="trendChart" height="60"></canvas>
    </div>
  </div>

  <div class="card shadow mb-4">
    <div class="card-header py-3">
      <h6 class="m-0 font-weight-bold text-primary">Payment Records</h6>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-bordered" id="paymentTable" width="100%" cellspacing="0">
          <thead>
            <tr>
              <th>Date</th>
              <th>CRN</th>
              <th>Full Name</th>
              <th>Bible Class</th>
              <th>Church</th>
              <th>Payment Type</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php while($row = $payments->fetch_assoc()): ?>
            <tr>
              <td><?=htmlspecialchars($row['payment_date'])?></td>
              <td>
    <?php if (!empty($row['member_id'])): ?>
        <?=htmlspecialchars($row['crn'])?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?=htmlspecialchars($row['srn'])?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
<td>
    <?php if (!empty($row['member_id'])): ?>
        <?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?=htmlspecialchars(trim(($row['ss_last_name'] ?? '').' '.($row['ss_first_name'] ?? '').' '.($row['ss_middle_name'] ?? '')))?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
              <td>
    <?php if (!empty($row['member_id'])): ?>
        <?=htmlspecialchars($row['class_name'])?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?php 
        // Lookup class name for SRN if not already present
        if (!empty($row['ss_class_id'])) {
            $ss_class_name = '';
            $ss_class_id = $row['ss_class_id'];
            $ss_class_q = $conn->query("SELECT name FROM bible_classes WHERE id=".intval($ss_class_id));
            if ($ss_class_q && $ss_class_q->num_rows > 0) {
                $ss_class_row = $ss_class_q->fetch_assoc();
                $ss_class_name = $ss_class_row['name'];
            }
            echo htmlspecialchars($ss_class_name);
        } else {
            echo '<span class="text-muted">N/A</span>';
        }
        ?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
<td>
    <?php if (!empty($row['member_id'])): ?>
        <?=htmlspecialchars($row['church_name'])?>
    <?php elseif (!empty($row['sundayschool_id'])): ?>
        <?php 
        // Lookup church name for SRN if not already present
        if (!empty($row['ss_church_id'])) {
            $ss_church_name = '';
            $ss_church_id = $row['ss_church_id'];
            $ss_church_q = $conn->query("SELECT name FROM churches WHERE id=".intval($ss_church_id));
            if ($ss_church_q && $ss_church_q->num_rows > 0) {
                $ss_church_row = $ss_church_q->fetch_assoc();
                $ss_church_name = $ss_church_row['name'];
            }
            echo htmlspecialchars($ss_church_name);
        } else {
            echo '<span class="text-muted">N/A</span>';
        }
        ?>
    <?php else: ?>
        <span class="text-muted">N/A</span>
    <?php endif; ?>
</td>
              <td><?=htmlspecialchars($row['payment_type'])?></td>
              <td>₵<?=number_format($row['amount'],2)?></td>
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
    $('#paymentTable').DataTable({
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
                label: 'Total Paid',
                data: <?= json_encode($trend_totals) ?>,
                backgroundColor: 'rgba(255, 193, 7, 0.2)',
                borderColor: 'rgba(255, 193, 7, 1)',
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

// Export functions
function exportByPeriod() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'export_payment_by_period.php?' + params.toString();
}

function exportByMonth() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'export_payment_by_month.php?' + params.toString();
}

function exportByType() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'export_payment_by_type.php?' + params.toString();
}

function exportByChurch() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'export_payment_by_church.php?' + params.toString();
}
</script>
<style>
.btn-xs { padding: 0.14rem 0.34rem !important; font-size: 0.89rem !important; line-height: 1.15 !important; border-radius: 0.22rem !important; }
#paymentTable th, #paymentTable td { vertical-align: middle !important; }
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.card-header h6 i {
    margin-right: 8px;
}
.btn-block {
    transition: all 0.3s ease;
}
.btn-block:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}
</style>

<?php
// End output buffering and inject content into layout
$page_content = ob_get_clean();
include_once __DIR__ . '/../../includes/layout.php';
?>
