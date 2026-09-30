<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../services/UnifiedAttendanceReportService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true) || (int) ($_SESSION['user_id'] ?? 0) === 3;
if (!$isSuperAdmin && !has_permission('view_visitor_report')) {
    http_response_code(403);
    include __DIR__ . '/../errors/403.php';
    exit;
}
$canExport = $isSuperAdmin || has_permission('export_visitor_report');

$scope = UnifiedAttendanceReportService::fromSession($conn);
$churches = $scope->getAllowedChurches();
$allowedChurchIds = array_map('intval', array_column($churches, 'id'));
$selectedChurchId = (int) ($_GET['church_id'] ?? 0);
if ($selectedChurchId > 0 && !in_array($selectedChurchId, $allowedChurchIds, true)) $selectedChurchId = 0;

$validDate = static function ($value): string {
    $value = trim((string) $value);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$fromDate = $validDate($_GET['from_date'] ?? '');
$toDate = $validDate($_GET['to_date'] ?? '');
$conversionStatus = in_array($_GET['conversion_status'] ?? '', ['visitor', 'registered'], true) ? (string) $_GET['conversion_status'] : '';
$followUpStatuses = ['not_started','contacted','in_progress','completed','unreachable','declined'];
$followUpStatus = in_array($_GET['follow_up_status'] ?? '', $followUpStatuses, true) ? (string) $_GET['follow_up_status'] : '';
$membershipInterest = in_array($_GET['want_member'] ?? '', ['Yes', 'No'], true) ? (string) $_GET['want_member'] : '';
$dueOnly = isset($_GET['due_only']) && $_GET['due_only'] === '1';

$where = ['visitor.is_duplicate_archived = 0'];
$params = [];
$types = '';
if (!$allowedChurchIds) {
    $where[] = '1 = 0';
} elseif ($selectedChurchId > 0) {
    $where[] = 'visitor.church_id = ?'; $params[] = $selectedChurchId; $types .= 'i';
} else {
    $where[] = 'visitor.church_id IN (' . implode(',', array_fill(0, count($allowedChurchIds), '?')) . ')';
    foreach ($allowedChurchIds as $churchId) { $params[] = $churchId; $types .= 'i'; }
}
if ($fromDate !== '') { $where[] = 'visitor.visit_date >= ?'; $params[] = $fromDate; $types .= 's'; }
if ($toDate !== '') { $where[] = 'visitor.visit_date <= ?'; $params[] = $toDate; $types .= 's'; }
if ($conversionStatus !== '') { $where[] = 'visitor.conversion_status = ?'; $params[] = $conversionStatus; $types .= 's'; }
if ($followUpStatus !== '') { $where[] = 'visitor.follow_up_status = ?'; $params[] = $followUpStatus; $types .= 's'; }
if ($membershipInterest !== '') { $where[] = 'visitor.want_member = ?'; $params[] = $membershipInterest; $types .= 's'; }
if ($dueOnly) $where[] = "visitor.next_follow_up_date IS NOT NULL AND visitor.next_follow_up_date <= CURDATE() AND visitor.follow_up_status NOT IN ('completed','declined')";

$sql = "SELECT visitor.*, church.name AS church_name,
               TRIM(CONCAT_WS(' ', invited.first_name, invited.middle_name, invited.last_name)) AS invited_by_name,
               follow_up_user.name AS follow_up_assigned_name, converted.crn AS converted_crn
          FROM visitors visitor
          LEFT JOIN churches church ON church.id = visitor.church_id
          LEFT JOIN members invited ON invited.id = visitor.invited_by
          LEFT JOIN users follow_up_user ON follow_up_user.id = visitor.follow_up_assigned_to_user_id
          LEFT JOIN members converted ON converted.id = visitor.converted_to_member_id
         WHERE " . implode(' AND ', $where) . '
         ORDER BY visitor.visit_date DESC, visitor.name, visitor.id DESC';
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$trend = [];
$summary = ['total' => count($rows), 'registered' => 0, 'active' => 0, 'due' => 0];
foreach ($rows as $row) {
    $month = substr((string) $row['visit_date'], 0, 7);
    if ($month !== '') $trend[$month] = ($trend[$month] ?? 0) + 1;
    if (($row['conversion_status'] ?? '') === 'registered') $summary['registered']++;
    if (in_array($row['follow_up_status'] ?? '', ['contacted', 'in_progress'], true)) $summary['active']++;
    if (!empty($row['next_follow_up_date']) && $row['next_follow_up_date'] <= date('Y-m-d') && !in_array($row['follow_up_status'] ?? '', ['completed', 'declined'], true)) $summary['due']++;
}
ksort($trend);
$statusLabels = ['not_started'=>'Not Started','contacted'=>'Contacted','in_progress'=>'In Progress','completed'=>'Completed','unreachable'=>'Unreachable','declined'=>'Declined'];

$page_title = 'Visitor Report';
ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><h2 class="mb-2"><i class="fas fa-user-friends mr-2"></i>Visitor Report</h2><a href="../visitor_list.php" class="btn btn-outline-primary btn-sm">Visitor Register</a></div>
  <form class="card card-body mb-3" method="get"><div class="form-row">
    <div class="form-group col-lg-3"><label>Church</label><select name="church_id" class="form-control"><option value="0">All Authorized Churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $selectedChurchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-2"><label>From Date</label><input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>"></div>
    <div class="form-group col-lg-2"><label>To Date</label><input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>"></div>
    <div class="form-group col-lg-2"><label>Visitor Status</label><select name="conversion_status" class="form-control"><option value="">All</option><option value="visitor" <?= $conversionStatus === 'visitor' ? 'selected' : '' ?>>Visitor</option><option value="registered" <?= $conversionStatus === 'registered' ? 'selected' : '' ?>>Registered</option></select></div>
    <div class="form-group col-lg-3"><label>Follow-up Status</label><select name="follow_up_status" class="form-control"><option value="">All</option><?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $followUpStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-2"><label>Wants Membership</label><select name="want_member" class="form-control"><option value="">All</option><option value="Yes" <?= $membershipInterest === 'Yes' ? 'selected' : '' ?>>Yes</option><option value="No" <?= $membershipInterest === 'No' ? 'selected' : '' ?>>No</option></select></div>
    <div class="form-group col-lg-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" value="1" name="due_only" id="due_only" <?= $dueOnly ? 'checked' : '' ?>><label class="form-check-label" for="due_only">Due/overdue follow-ups only</label></div></div>
    <div class="form-group col-lg-2 d-flex align-items-end"><button class="btn btn-primary btn-block">Apply Filters</button></div>
    <div class="form-group col-lg-2 d-flex align-items-end"><a href="visitor_report.php" class="btn btn-outline-secondary btn-block">Reset</a></div>
  </div></form>
  <div class="row mb-3"><?php foreach ([['Total Visitors',$summary['total'],'primary'],['Registered',$summary['registered'],'success'],['Active Follow-up',$summary['active'],'warning'],['Due / Overdue',$summary['due'],'danger']] as $card): ?><div class="col-6 col-lg-3 mb-2"><div class="card border-left-<?= $card[2] ?> shadow-sm h-100"><div class="card-body py-3"><div class="small text-uppercase text-muted"><?= htmlspecialchars($card[0]) ?></div><div class="h4 mb-0"><?= (int) $card[1] ?></div></div></div></div><?php endforeach; ?></div>
  <div class="card mb-4"><div class="card-header bg-light"><strong>Visit Trend</strong></div><div class="card-body"><canvas id="trendChart" height="60"></canvas></div></div>
  <div class="card shadow mb-4"><div class="card-header py-3"><h6 class="m-0 font-weight-bold text-primary">Visitor Records</h6></div><div class="card-body"><div class="table-responsive">
    <table class="table table-bordered table-sm" id="visitorTable" width="100%"><thead><tr><th>Date</th><th>Name</th><th>Church</th><th>Contact</th><th>Purpose / Invited By</th><th>Membership</th><th>Follow-up</th><th>Next Date / Assignee</th></tr></thead><tbody>
      <?php foreach ($rows as $row): ?><tr>
        <td><?= htmlspecialchars($row['visit_date'] ?? '') ?></td><td><?= htmlspecialchars($row['name'] ?? '') ?><br><small class="text-muted"><?= htmlspecialchars($row['gender'] ?? '') ?></small></td><td><?= htmlspecialchars($row['church_name'] ?? '') ?></td>
        <td><?= htmlspecialchars($row['phone'] ?? '') ?><br><small><?= htmlspecialchars($row['email'] ?? '') ?></small><br><small><?= htmlspecialchars($row['address'] ?? '') ?></small></td>
        <td><?= htmlspecialchars($row['purpose'] ?? '') ?><?php if (!empty($row['invited_by_name'])): ?><br><small class="text-muted">Invited by <?= htmlspecialchars($row['invited_by_name']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars(ucfirst($row['conversion_status'] ?? 'visitor')) ?><?php if (!empty($row['converted_crn'])): ?><br><small><?= htmlspecialchars($row['converted_crn']) ?></small><?php endif; ?><br><small>Interested: <?= htmlspecialchars($row['want_member'] ?: '-') ?></small></td>
        <td><?= htmlspecialchars($statusLabels[$row['follow_up_status']] ?? $row['follow_up_status']) ?><?php if (!empty($row['follow_up_summary'])): ?><br><small><?= htmlspecialchars($row['follow_up_summary']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars($row['next_follow_up_date'] ?: '-') ?><?php if (!empty($row['follow_up_assigned_name'])): ?><br><small><?= htmlspecialchars($row['follow_up_assigned_name']) ?></small><?php endif; ?></td>
      </tr><?php endforeach; ?>
    </tbody></table>
  </div></div></div>
</div>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css"><link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script><script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script><script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script><script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script><script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script><script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script><script src="<?= BASE_URL ?>/assets/js/report-export-branding.js"></script><script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>$(function(){$('#visitorTable').DataTable({pageLength:25,order:[[0,'desc']],dom:<?= json_encode($canExport ? 'Bfrtip' : 'frtip') ?>,buttons:<?= json_encode($canExport ? ['copy','csv','excel','pdf','print'] : []) ?>});new Chart(document.getElementById('trendChart').getContext('2d'),{type:'line',data:{labels:<?= json_encode(array_keys($trend)) ?>,datasets:[{label:'Visits',data:<?= json_encode(array_values($trend)) ?>,backgroundColor:'rgba(111,66,193,.2)',borderColor:'rgba(111,66,193,1)',borderWidth:2,fill:true,tension:.3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});});</script>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../../includes/layout.php'; ?>
