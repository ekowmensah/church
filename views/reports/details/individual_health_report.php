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

if (!$is_super_admin && !has_permission('view_individual_health_report')) {
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
$can_export = $is_super_admin || has_permission('export_individual_health_report');

// Filtering
$member_search = isset($_GET['member_search']) ? trim($_GET['member_search']) : '';
$health_type = isset($_GET['health_type']) ? trim($_GET['health_type']) : '';
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Build WHERE clause
$where = "WHERE m.status = 'active'";
$params = [];
$types = '';
if ($member_search) {
    $where .= " AND (m.crn LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?)";
    $search = "%$member_search%";
    $params = array_merge($params, [$search, $search, $search, $search]);
    $types .= 'ssss';
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
        $where ORDER BY m.last_name, m.first_name, hr.recorded_at DESC";
$stmt = $conn->prepare($types ? $sql . '' : $sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Collect all possible health types from vitals and group them per member
$all_types = [];
$grouped_rows = [];
$member_ids = [];
$health_type_counts = [];
$all_member_records = [];
while ($row = $result->fetch_assoc()) {
    $vitals = json_decode($row['vitals'], true) ?: [];
    $member_id = (int) $row['member_id'];
    $member_ids[$member_id] = true;

    if (!isset($grouped_rows[$member_id])) {
        $grouped_rows[$member_id] = [
            'member_id' => $member_id,
            'member_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'crn' => $row['crn'],
            'class' => $row['class_name'],
            'date' => $row['recorded_at'],
            'health_items' => [],
            'records' => [],
        ];
    }

    foreach ($vitals as $type => $value) {
        if ($value === '' || $value === null) continue;
        $all_types[$type] = true;
        $health_type_counts[$type] = ($health_type_counts[$type] ?? 0) + 1;
        $grouped_rows[$member_id]['health_items'][$type] = (string) $value;
        $grouped_rows[$member_id]['records'][] = [
            'health_type' => $type,
            'value' => (string) $value,
            'date' => $row['recorded_at'],
        ];
    }
}
ksort($all_types);
$rows = array_values($grouped_rows);
if ($health_type) {
    $rows = array_values(array_filter($rows, function($r) use ($health_type) {
        return array_key_exists($health_type, $r['health_items']);
    }));
}

$summary_records = count($rows);
$summary_members = count($member_ids);
$summary_health_types = count($all_types);
$summary_date_label = 'All available records';
if ($date_from || $date_to) {
    $summary_date_label = ($date_from ? date('M d, Y', strtotime($date_from)) : 'Start') . ' to ' . ($date_to ? date('M d, Y', strtotime($date_to)) : 'Present');
}

// CSV Export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=individual_health_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member Name','CRN','Health Snapshot','Bible Class','Date']);
    foreach ($rows as $r) {
        $health_snapshot = [];
        foreach ($r['health_items'] as $type => $value) {
            $health_snapshot[] = ucwords(str_replace('_', ' ', $type)) . ': ' . $value;
        }
        fputcsv($out, [$r['member_name'],$r['crn'],implode(' | ', $health_snapshot),$r['class'],$r['date']]);
    }
    fclose($out);
    exit;
}

ob_start();
$page_title = 'Individual Health Report';
?>
<div class="container-fluid py-3">
  <div class="card shadow-sm border-0">
    <div class="card-header bg-gradient-primary text-white py-4">
      <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <div>
          <h3 class="mb-1"><i class="fas fa-heartbeat mr-2"></i>Individual Health Report</h3>
          <p class="mb-0 text-white-50">A concise view of member health snapshots, filtered by member, health indicator, and date range.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <?php if ($can_export): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-light btn-sm">
            <i class="fas fa-file-csv mr-1"></i>Export CSV
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card-body">
      <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
          <div class="border rounded p-3 h-100 bg-light">
            <div class="text-muted small text-uppercase">Total Records</div>
            <div class="h3 mb-0 font-weight-bold text-primary"><?= (int) $summary_records ?></div>
          </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
          <div class="border rounded p-3 h-100 bg-light">
            <div class="text-muted small text-uppercase">Unique Members</div>
            <div class="h3 mb-0 font-weight-bold text-success"><?= (int) $summary_members ?></div>
          </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
          <div class="border rounded p-3 h-100 bg-light">
            <div class="text-muted small text-uppercase">Health Types</div>
            <div class="h3 mb-0 font-weight-bold text-info"><?= (int) $summary_health_types ?></div>
          </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
          <div class="border rounded p-3 h-100 bg-light">
            <div class="text-muted small text-uppercase">Date Range</div>
            <div class="h6 mb-0 font-weight-bold text-dark"><?= htmlspecialchars($summary_date_label) ?></div>
          </div>
        </div>
      </div>

      <form method="get" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <div class="col-md-3">
              <label for="member_search" class="font-weight-bold small text-uppercase text-muted">Member</label>
              <input type="text" name="member_search" id="member_search" value="<?= htmlspecialchars($member_search) ?>" placeholder="Search by name or CRN" class="form-control" />
            </div>
            <div class="col-md-3">
              <label for="health_type" class="font-weight-bold small text-uppercase text-muted">Health Type</label>
              <select name="health_type" id="health_type" class="form-control">
                <option value="">All Types</option>
                <?php foreach (array_keys($all_types) as $t): ?>
                  <option value="<?= htmlspecialchars($t) ?>" <?= $health_type == $t ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $t))) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2">
              <label for="date_from" class="font-weight-bold small text-uppercase text-muted">From</label>
              <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control" />
            </div>
            <div class="col-md-2">
              <label for="date_to" class="font-weight-bold small text-uppercase text-muted">To</label>
              <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control" />
            </div>
            <div class="col-md-2">
              <label class="d-block small text-white">Actions</label>
              <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter mr-1"></i>Apply</button>
                <a href="<?= htmlspecialchars(basename($_SERVER['PHP_SELF'])) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-undo mr-1"></i>Reset</a>
              </div>
            </div>
          </div>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-hover table-bordered align-middle mb-0">
          <thead class="thead-light">
            <tr>
              <th style="width: 30px;"></th>
              <th>Member Name</th>
              <th>CRN</th>
              <th>Bible Class</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
            <tr class="align-middle">
              <td>
                <button class="btn btn-sm btn-outline-primary" type="button" data-toggle="collapse" data-target="#details-<?= (int) $r['member_id'] ?>" aria-expanded="false" aria-controls="details-<?= (int) $r['member_id'] ?>">
                  <i class="fas fa-plus"></i>
                </button>
              </td>
              <td><?= htmlspecialchars($r['member_name']) ?></td>
              <td><?= htmlspecialchars($r['crn']) ?></td>
              <td><?= htmlspecialchars($r['class']) ?></td>
              <td><?= htmlspecialchars($r['date']) ?></td>
            </tr>
            <tr>
              <td colspan="6" class="p-0 border-top-0">
                <div class="collapse" id="details-<?= (int) $r['member_id'] ?>">
                  <div class="p-3 bg-light">
                    <h6 class="font-weight-bold mb-3">Full Health Records</h6>
                    <?php if (!empty($r['records'])): ?>
                      <div class="table-responsive">
                        <table class="table table-sm mb-0">
                          <thead class="thead-light">
                            <tr>
                              <th>Date</th>
                              <?php foreach ($all_types as $type => $value): ?>
                                <th><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type))) ?></th>
                              <?php endforeach; ?>
                            </tr>
                          </thead>
                          <tbody>
                            <?php
                            $grouped_by_date = [];
                            foreach ($r['records'] as $record) {
                                $grouped_by_date[$record['date']][] = $record;
                            }
                            krsort($grouped_by_date);
                            foreach ($grouped_by_date as $date => $entries):
                            ?>
                            <tr>
                              <td><?= htmlspecialchars($date) ?></td>
                              <?php foreach ($all_types as $type => $value): ?>
                                <td>
                                  <?php
                                  $detail_value = '';
                                  foreach ($entries as $entry) {
                                      if ($entry['health_type'] === $type) {
                                          $detail_value = (string) $entry['value'];
                                          break;
                                      }
                                  }
                                  ?>
                                  <?= $detail_value !== '' ? htmlspecialchars($detail_value) : '<span class="text-muted">—</span>' ?>
                                </td>
                              <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <div class="text-muted">No detailed health history available.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4">No records found for the selected filters.</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
