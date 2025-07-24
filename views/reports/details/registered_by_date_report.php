<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_registered_by_date_report')) {
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
$can_export = $is_super_admin || has_permission('export_registered_by_date_report');

// Filtering
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : '';
$org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : '';
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
if ($org_id) {
    $where .= " AND mo.organization_id = ?";
    $params[] = $org_id;
    $types .= 'i';
}
if ($date_from) {
    $where .= " AND m.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}
if ($date_to) {
    $where .= " AND m.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

$sql = "SELECT m.*, c.name AS class_name, o.name AS org_name FROM members m
        LEFT JOIN bible_classes c ON m.class_id = c.id
        LEFT JOIN member_organizations mo ON m.id = mo.member_id
        LEFT JOIN organizations o ON mo.organization_id = o.id
        $where
        GROUP BY m.id
        ORDER BY m.created_at DESC";
$stmt = $conn->prepare($types ? $sql . '' : $sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all classes and organizations for dropdowns
$class_options = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");
$org_options = $conn->query("SELECT id, name FROM organizations ORDER BY name");

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'date' => $row['created_at'],
        'member_name' => trim($row['first_name'] . ' ' . $row['last_name']),
        'crn' => $row['crn'],
        'class' => $row['class_name'],
        'org' => $row['org_name'],
    ];
}

// CSV Export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=registered_by_date_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Registration Date','Member Name','CRN','Class','Organization']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['date'],$r['member_name'],$r['crn'],$r['class'],$r['org']]);
    }
    fclose($out);
    exit;
}

ob_start();
?>
<div class="card mt-4">
  <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
    <span>Registered By Date Report</span>
    <form class="form-inline" method="get">
      <label for="class_id" class="mr-2">Class:</label>
      <select name="class_id" id="class_id" class="form-control mr-2">
        <option value="">All Classes</option>
        <?php while($c = $class_options->fetch_assoc()): ?>
          <option value="<?= $c['id'] ?>" <?= $class_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endwhile; ?>
      </select>
      <label for="org_id" class="mr-2">Organization:</label>
      <select name="org_id" id="org_id" class="form-control mr-2">
        <option value="">All Organizations</option>
        <?php while($o = $org_options->fetch_assoc()): ?>
          <option value="<?= $o['id'] ?>" <?= $org_id==$o['id']?'selected':'' ?>><?= htmlspecialchars($o['name']) ?></option>
        <?php endwhile; ?>
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
          <th>Registration Date</th>
          <th>Member Name</th>
          <th>CRN</th>
          <th>Class</th>
          <th>Organization</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['date']) ?></td>
          <td><?= htmlspecialchars($r['member_name']) ?></td>
          <td><?= htmlspecialchars($r['crn']) ?></td>
          <td><?= htmlspecialchars($r['class']) ?></td>
          <td><?= htmlspecialchars($r['org']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($rows)): ?>
        <tr><td colspan="5" class="text-center">No records found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
