<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../services/UnifiedAttendanceReportService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$reportRoleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $reportRoleIds[] = (int) $_SESSION['role_id'];
$reportRoleIds = array_values(array_unique($reportRoleIds));
$reportIsSuperAdmin = in_array(1, $reportRoleIds, true);
if (!$reportIsSuperAdmin && !has_permission('view_attendance_report')) {
    http_response_code(403);
    include __DIR__ . '/../errors/403.php';
    exit;
}

function attendance_report_number($value): string {
    $number = (float) $value;
    return abs($number - round($number)) < 0.001
        ? number_format($number, 0)
        : number_format($number, 2);
}

$service = UnifiedAttendanceReportService::fromSession($conn);
$churches = $service->getAllowedChurches();
$categories = $service->getCategories();
$canExport = $reportIsSuperAdmin || has_permission('export_attendance_report');
$canManageStatisticalEvents = $reportIsSuperAdmin || has_permission('manage_church_statistical_events');
$canViewChurchStatistics = $service->canViewChurchStatistics();
$preset = (string) ($_GET['period'] ?? 'this_month');
$status = (string) ($_GET['status'] ?? 'present');
$categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
$selectedChurchId = isset($_GET['church_id']) ? (int) $_GET['church_id'] : 0;
$selectedChurch = null;
$report = ['summary' => [], 'breakdown' => [], 'totals' => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0]];
$churchStatistics = ['rows' => [], 'totals' => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0]];
$combinedTotals = ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0];
$includeChurchStatistics = false;
$fromDate = '';
$toDate = '';
$error = '';

if ($selectedChurchId === 0 && $churches) $selectedChurchId = (int) $churches[0]['id'];
foreach ($churches as $church) {
    if ((int) $church['id'] === $selectedChurchId) $selectedChurch = $church;
}

try {
    if (!$selectedChurch) throw new RuntimeException('No church is available for your reporting role.');
    [$fromDate, $toDate] = $service->resolvePeriod(
        $preset,
        isset($_GET['from_date']) ? (string) $_GET['from_date'] : null,
        isset($_GET['to_date']) ? (string) $_GET['to_date'] : null
    );
    $report = $service->buildReport($selectedChurchId, $fromDate, $toDate, $status, $categoryId);
    $status = $report['status'];
    if ($canViewChurchStatistics) {
        $churchStatistics = $service->buildChurchStatistics($selectedChurchId, $fromDate, $toDate);
    }
    // Lifecycle events are counts rather than attendance statuses. Include them
    // in the unified sample-style total only on an unfiltered Present report.
    $includeChurchStatistics = $canViewChurchStatistics && $status === 'present' && $categoryId === null;
    foreach (array_keys($combinedTotals) as $field) {
        $combinedTotals[$field] = (float) $report['totals'][$field]
            + ($includeChurchStatistics ? (int) $churchStatistics['totals'][$field] : 0);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$exportParams = http_build_query([
    'church_id' => $selectedChurchId,
    'period' => $preset,
    'from_date' => $fromDate,
    'to_date' => $toDate,
    'status' => $status,
    'category_id' => $categoryId,
]);
$statusLabels = [
    'present' => 'Present', 'absent' => 'Absent', 'sick' => 'Sick',
    'permission' => 'Permission', 'distance' => 'Distance',
    'invalid' => 'Invalid', 'all' => 'All marked statuses',
];
$periodLabels = [
    'today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week',
    'last_week' => 'Last week', 'this_month' => 'This month', 'last_month' => 'Last month',
    'q1' => 'Quarter 1', 'q2' => 'Quarter 2', 'q3' => 'Quarter 3', 'q4' => 'Quarter 4',
    'this_year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Custom range',
];

$page_title = 'Unified Attendance Report';
ob_start();
?>
<style>
    .attendance-report-page { background:#f4f7fb; min-height:calc(100vh - 70px); padding:1rem 0 2rem; }
    .report-hero { background:linear-gradient(135deg,#123d63,#22689b); color:#fff; border-radius:16px; padding:1.3rem 1.5rem; box-shadow:0 8px 24px rgba(18,61,99,.22); }
    .report-hero h1 { font-size:1.55rem; margin:0 0 .25rem; }
    .report-filter, .report-card { border:0; border-radius:14px; box-shadow:0 5px 18px rgba(30,54,82,.09); }
    .metric-card { background:#fff; border-radius:12px; padding:1rem; border-left:4px solid #22689b; height:100%; }
    .metric-card .value { font-size:1.6rem; font-weight:700; color:#163d5d; }
    .report-table thead th { background:#173f60; color:#fff; border-color:#2c5779; white-space:nowrap; }
    .report-table .main-row td { background:#eaf2f8; font-weight:700; color:#173f60; }
    .report-table .breakdown-name { padding-left:2rem; }
    .report-meta { color:#dcebf6; font-size:.92rem; }
    .average-badge { font-size:.72rem; background:#fff3cd; color:#765b00; padding:.18rem .4rem; border-radius:10px; }
    .empty-state { padding:2.7rem 1rem; text-align:center; color:#6c757d; }
    @media print { .no-print, .sidebar, nav { display:none !important; } .attendance-report-page { background:#fff; } }
</style>

<div class="attendance-report-page">
  <div class="container-fluid">
    <div class="report-hero mb-3 d-flex flex-wrap justify-content-between align-items-center">
      <div>
        <h1><i class="fas fa-chart-bar mr-2"></i>Unified Attendance Report</h1>
        <div class="report-meta">Approved attendance, grouped by ministry and meeting type</div>
      </div>
      <?php if ($selectedChurch): ?>
        <div class="text-right mt-2 mt-md-0"><strong><?= htmlspecialchars($selectedChurch['name']) ?></strong><br><em><?= htmlspecialchars($fromDate) ?> to <?= htmlspecialchars($toDate) ?></em></div>
      <?php endif; ?>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card report-filter mb-3 no-print">
      <div class="card-body">
        <form method="get" id="attendanceReportFilters">
          <div class="form-row">
            <div class="form-group col-lg-3 col-md-6">
              <label for="church_id">Church</label>
              <select name="church_id" id="church_id" class="form-control" required>
                <?php foreach ($churches as $church): ?>
                  <option value="<?= (int) $church['id'] ?>" <?= (int) $church['id'] === $selectedChurchId ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-lg-3 col-md-6">
              <label for="period">Period</label>
              <select name="period" id="period" class="form-control">
                <?php foreach ($periodLabels as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $preset === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-lg-3 col-md-6 custom-date-field">
              <label for="from_date">From</label>
              <input type="date" class="form-control" name="from_date" id="from_date" value="<?= htmlspecialchars($fromDate) ?>">
            </div>
            <div class="form-group col-lg-3 col-md-6 custom-date-field">
              <label for="to_date">To</label>
              <input type="date" class="form-control" name="to_date" id="to_date" value="<?= htmlspecialchars($toDate) ?>">
            </div>
            <div class="form-group col-lg-4 col-md-6">
              <label for="category_id">Attendance Type</label>
              <select name="category_id" id="category_id" class="form-control">
                <option value="">All non-zero categories</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['parent_name'] ? $category['parent_name'] . ' — ' . $category['name'] : $category['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-lg-3 col-md-6">
              <label for="status">Attendance Status</label>
              <select name="status" id="status" class="form-control">
                <?php foreach ($statusLabels as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group col-lg-2 col-md-6 d-flex align-items-end">
              <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Run Report</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php if (!$error): ?>
      <div class="row mb-3">
        <?php foreach (['male' => 'Male', 'female' => 'Female', 'unspecified' => 'Unspecified', 'total' => 'Total'] as $field => $label): ?>
          <div class="col-lg-3 col-6 mb-2"><div class="metric-card"><div class="text-muted small text-uppercase"><?= $label ?></div><div class="value"><?= attendance_report_number($combinedTotals[$field]) ?></div></div></div>
        <?php endforeach; ?>
      </div>

      <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 no-print">
        <div class="text-muted small">Zero-count types are hidden. Weekly-average types divide each week's total by its distinct meeting days. Membership and pastoral events are included in the unfiltered Present report.</div>
        <?php if ($canExport): ?>
          <div class="btn-group mt-2 mt-md-0">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="copyReport"><i class="far fa-copy mr-1"></i>Copy</button>
            <a class="btn btn-outline-success btn-sm" href="attendance_report_export.php?format=csv&amp;<?= htmlspecialchars($exportParams) ?>"><i class="fas fa-file-csv mr-1"></i>CSV</a>
            <a class="btn btn-outline-success btn-sm" href="attendance_report_export.php?format=excel&amp;<?= htmlspecialchars($exportParams) ?>"><i class="fas fa-file-excel mr-1"></i>Excel</a>
            <a class="btn btn-outline-danger btn-sm" target="_blank" href="attendance_report_export.php?format=print&amp;<?= htmlspecialchars($exportParams) ?>"><i class="fas fa-file-pdf mr-1"></i>PDF / Print</a>
          </div>
        <?php endif; ?>
      </div>

      <div class="card report-card">
        <div class="card-body p-0">
          <?php if (!$report['summary'] && !($includeChurchStatistics && $churchStatistics['rows'])): ?>
            <div class="empty-state"><i class="far fa-calendar-times fa-2x mb-2"></i><div>No approved attendance records match this period and filter.</div></div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover report-table mb-0" id="unifiedAttendanceTable">
                <thead><tr><th>Attendance Type / Breakdown</th><th class="text-right">Male</th><th class="text-right">Female</th><th class="text-right">Unspecified</th><th class="text-right">Total</th><th class="text-right">Sessions</th></tr></thead>
                <tbody>
                <?php foreach ($report['summary'] as $main): ?>
                  <tr class="main-row"><td><?= htmlspecialchars($main['main_name']) ?></td><td class="text-right"><?= attendance_report_number($main['male']) ?></td><td class="text-right"><?= attendance_report_number($main['female']) ?></td><td class="text-right"><?= attendance_report_number($main['unspecified']) ?></td><td class="text-right"><?= attendance_report_number($main['total']) ?></td><td class="text-right"><?= number_format((int) $main['sessions']) ?></td></tr>
                  <?php foreach ($report['breakdown'] as $detail): if ((int) $detail['main_id'] !== (int) $main['main_id']) continue; ?>
                    <tr><td class="breakdown-name">↳ <?= htmlspecialchars($detail['breakdown_name']) ?> <?php if ($detail['aggregation_method'] === 'weekly_average'): ?><span class="average-badge">weekly average</span><?php endif; ?></td><td class="text-right"><?= attendance_report_number($detail['male']) ?></td><td class="text-right"><?= attendance_report_number($detail['female']) ?></td><td class="text-right"><?= attendance_report_number($detail['unspecified']) ?></td><td class="text-right"><?= attendance_report_number($detail['total']) ?></td><td class="text-right"><?= number_format((int) $detail['sessions']) ?></td></tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($includeChurchStatistics && $churchStatistics['rows']): ?>
                  <tr class="main-row"><td colspan="6">Membership &amp; Pastoral Events</td></tr>
                  <?php foreach ($churchStatistics['rows'] as $statistic): ?>
                    <tr><td><?= htmlspecialchars($statistic['label']) ?></td><td class="text-right"><?= number_format($statistic['male']) ?></td><td class="text-right"><?= number_format($statistic['female']) ?></td><td class="text-right"><?= number_format($statistic['unspecified']) ?></td><td class="text-right"><?= number_format($statistic['total']) ?></td><td></td></tr>
                  <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <tfoot><tr class="font-weight-bold"><td>Grand Total</td><td class="text-right"><?= attendance_report_number($combinedTotals['male']) ?></td><td class="text-right"><?= attendance_report_number($combinedTotals['female']) ?></td><td class="text-right"><?= attendance_report_number($combinedTotals['unspecified']) ?></td><td class="text-right"><?= attendance_report_number($combinedTotals['total']) ?></td><td></td></tr></tfoot>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($canManageStatisticalEvents): ?><div class="text-right mt-3 no-print"><a class="btn btn-outline-primary btn-sm" href="../church_statistical_events.php"><i class="fas fa-clipboard-list mr-1"></i>Manage Naming &amp; Death</a></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var period = document.getElementById('period');
  function toggleDates() {
    var custom = period && period.value === 'custom';
    document.querySelectorAll('.custom-date-field').forEach(function (field) { field.style.display = custom ? '' : 'none'; });
    ['from_date','to_date'].forEach(function (id) { var input = document.getElementById(id); if (input) input.required = custom; });
  }
  if (period) { period.addEventListener('change', toggleDates); toggleDates(); }
  var copy = document.getElementById('copyReport');
  if (copy) copy.addEventListener('click', function () {
    var tables = ['unifiedAttendanceTable', 'churchStatisticsTable']
      .map(function (id) { return document.getElementById(id); })
      .filter(Boolean);
    if (!tables.length) return;
    var sections = tables.map(function (table) {
      return Array.prototype.map.call(table.rows, function (row) {
        return Array.prototype.map.call(row.cells, function (cell) { return cell.innerText.trim(); }).join('\t');
      }).join('\n');
    });
    navigator.clipboard.writeText(sections.join('\n\n')).then(function () { copy.innerHTML = '<i class="fas fa-check mr-1"></i>Copied'; });
  });
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../../includes/layout.php';
?>
