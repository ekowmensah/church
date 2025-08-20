<?php
require_once __DIR__.'/../../../config/config.php';
//echo "AFTER CONFIG<br>";
require_once __DIR__.'/../../../helpers/auth.php';

require_once __DIR__.'/../../../helpers/permissions.php';



if (!is_logged_in()) {
  die('STOP');
  header('Location: ' . BASE_URL . '/login.php');
  exit;
}
// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_health_type_report')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../../views/errors/403.php')) {
        include __DIR__.'/../../../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../../errors/403.php')) {
        include __DIR__.'/../../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above
$can_export = $is_super_admin || has_permission('export_health_type_report');

//require_once __DIR__.'/../../../includes/admin_auth.php';
require_once __DIR__.'/../../../config/config.php';

// Filtering
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$health_type = isset($_GET['health_type']) ? trim($_GET['health_type']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Build WHERE clause
$where = "WHERE m.status = 'active'";
$params = [];
$types = '';
if ($class_id) {
    $where .= " AND m.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}
if ($date_from) {
    $where .= " AND hr.recorded_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}
if ($date_to) {
    $where .= " AND hr.recorded_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

// Query health records for active members
$sql = "SELECT hr.*, m.crn, m.first_name, m.last_name, c.name AS class_name FROM health_records hr 
        INNER JOIN members m ON hr.member_id = m.id 
        LEFT JOIN bible_classes c ON m.class_id = c.id 
        $where ORDER BY hr.recorded_at DESC";
$stmt = $conn->prepare($types ? $sql . '' : $sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Collect all possible health types from vitals
$all_types = [];
$rows = [];
while ($row = $result->fetch_assoc()) {
    $vitals = json_decode($row['vitals'], true) ?: [];
    foreach ($vitals as $type => $value) {
        if ($value === '' || $value === null) continue;
        $all_types[$type] = true;
        $rows[] = [
            'health_type' => $type,
            'value' => $value,
            'member_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'crn' => $row['crn'],
            'class' => $row['class_name'],
            'date' => $row['recorded_at']
        ];
    }
}
ksort($all_types);
if ($health_type) {
    $rows = array_filter($rows, function($r) use ($health_type) {
        return $r['health_type'] === $health_type;
    });
}

// CSV Export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=health_type_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Health Type','Value','Member Name','CRN','Class','Date']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['health_type'],$r['value'],$r['member_name'],$r['crn'],$r['class'],$r['date']]);
    }
    fclose($out);
    exit;
}

ob_start();
?>
<div class="card mt-4">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span>Health Type Report</span>
    <form class="form-inline" method="get">
      <label for="class_id" class="mr-2">Class:</label>
      <select name="class_id" id="class_id" class="form-control mr-2">
        <option value="">All Classes</option>
        <?php
        $q = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");
        while($c = $q->fetch_assoc()): ?>
          <option value="<?= $c['id'] ?>" <?= $class_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endwhile; ?>
      </select>
      <label for="health_type" class="mr-2">Health Type:</label>
      <select name="health_type" id="health_type" class="form-control mr-2">
        <option value="">All Types</option>
        <?php foreach(array_keys($all_types) as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>" <?= $health_type==$t?'selected':'' ?>><?= htmlspecialchars(ucwords(str_replace('_',' ',$t))) ?></option>
        <?php endforeach; ?>
      </select>
      <label for="date_from" class="mr-2">From:</label>
      <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control mr-2" />
      <label for="date_to" class="mr-2">To:</label>
      <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control mr-2" />
      <button type="submit" class="btn btn-primary mr-2">Filter</button>
      <?php if ($can_export): ?>
      <button type="submit" name="export" value="csv" class="btn btn-success">Export CSV</button>
      <?php endif; ?>
    </form>
  </div>
  <div class="card-body">
    <div class="table-responsive">
    <table class="table table-bordered table-striped">
      <thead>
        <tr>
          <th>Health Type</th>
          <th>Value</th>
          <th>Member Name</th>
          <th>CRN</th>
          <th>Class</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars(ucwords(str_replace('_',' ',$r['health_type']))) ?></td>
          <td><?= htmlspecialchars($r['value']) ?></td>
          <td><?= htmlspecialchars($r['member_name']) ?></td>
          <td><?= htmlspecialchars($r['crn']) ?></td>
          <td><?= htmlspecialchars($r['class']) ?></td>
          <td><?= htmlspecialchars($r['date']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($rows)): ?>
        <tr><td colspan="6" class="text-center">No records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
