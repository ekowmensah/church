<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../services/RoleOfServingReportService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$sessionRoleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $sessionRoleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $sessionRoleIds, true);
if (!$isSuperAdmin && !has_permission('view_role_of_service_report')) {
    http_response_code(403);
    include __DIR__ . '/../../errors/403.php';
    exit;
}

$service = RoleOfServingReportService::fromSession($conn);
$churches = $service->getAllowedChurches();
$roles = $service->getRoles();
$churchId = (int) ($_GET['church_id'] ?? ($churches[0]['id'] ?? 0));
$roleId = !empty($_GET['role_id']) ? (int) $_GET['role_id'] : null;
$organizationId = !empty($_GET['organization_id']) ? (int) $_GET['organization_id'] : null;
$gender = in_array($_GET['gender'] ?? '', ['Male', 'Female', 'Unspecified'], true) ? $_GET['gender'] : null;
$organizations = [];
$report = ['summary' => [], 'members' => [], 'totals' => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0]];
$error = '';
try {
    if ($churchId < 1) throw new RuntimeException('No church is available for your reporting scope.');
    $organizations = $service->getOrganizations($churchId);
    $report = $service->build($churchId, $roleId, $organizationId, $gender);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
$canExport = $isSuperAdmin || has_permission('export_role_of_service_report');
$exportParams = http_build_query([
    'church_id' => $churchId, 'role_id' => $roleId,
    'organization_id' => $organizationId, 'gender' => $gender,
]);
$page_title = 'Role of Serving Report';
ob_start();
?>
<style>
.serving-report-page{background:#f4f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.serving-hero{background:linear-gradient(135deg,#4a2b64,#8155a3);color:#fff;border-radius:15px;padding:1.25rem 1.5rem;box-shadow:0 8px 22px rgba(74,43,100,.22)}.serving-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.metric{background:#fff;border-radius:11px;border-left:4px solid #8155a3;padding:.9rem}.metric strong{display:block;font-size:1.5rem;color:#4a2b64}.serving-table thead th{background:#4a2b64;color:#fff;white-space:nowrap}.summary-row td{font-weight:600;background:#f1eaf6}
</style>
<div class="serving-report-page"><div class="container-fluid">
  <div class="serving-hero mb-3 d-flex flex-wrap justify-content-between align-items-center"><div><h2 class="mb-1"><i class="fas fa-user-tag mr-2"></i>Role of Serving Report</h2><div>Current role holders, gender distribution, and scoped member details</div></div><a href="../../reports.php" class="btn btn-light btn-sm">Back to Reports</a></div>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="card serving-card mb-3"><div class="card-body"><form method="get"><div class="form-row">
    <div class="form-group col-lg-3 col-md-6"><label>Church</label><select class="form-control" name="church_id" required><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= (int) $church['id'] === $churchId ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-3 col-md-6"><label>Role of Serving</label><select class="form-control" name="role_id"><option value="">All roles</option><?php foreach ($roles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= (int) $role['id'] === $roleId ? 'selected' : '' ?>><?= htmlspecialchars($role['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-3 col-md-6"><label>Organization</label><select class="form-control" name="organization_id"><option value="">All organizations</option><?php foreach ($organizations as $organization): ?><option value="<?= (int) $organization['id'] ?>" <?= (int) $organization['id'] === $organizationId ? 'selected' : '' ?>><?= htmlspecialchars($organization['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-2 col-md-4"><label>Gender</label><select class="form-control" name="gender"><option value="">All</option><?php foreach (['Male','Female','Unspecified'] as $option): ?><option <?= $gender === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div>
    <div class="form-group col-lg-1 col-md-2 d-flex align-items-end"><button class="btn btn-primary btn-block">Apply</button></div>
  </div></form></div></div>

  <?php if (!$error): ?>
  <div class="row mb-2"><?php foreach (['male'=>'Male','female'=>'Female','unspecified'=>'Unspecified','total'=>'Unique Members'] as $field=>$label): ?><div class="col-lg-3 col-6 mb-2"><div class="metric"><span class="text-muted small text-uppercase"><?= $label ?></span><strong><?= number_format($report['totals'][$field]) ?></strong></div></div><?php endforeach; ?></div>
  <div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">Counts represent current active members. A member holding multiple offices appears in each applicable role but only once in Unique Members.</span><?php if ($canExport): ?><div class="btn-group"><button class="btn btn-outline-secondary btn-sm" id="copyServingReport">Copy</button><a class="btn btn-outline-success btn-sm" href="role_of_service_report_export.php?format=csv&amp;<?= htmlspecialchars($exportParams) ?>">CSV</a><a class="btn btn-outline-success btn-sm" href="role_of_service_report_export.php?format=excel&amp;<?= htmlspecialchars($exportParams) ?>">Excel</a><a class="btn btn-outline-danger btn-sm" target="_blank" href="role_of_service_report_export.php?format=print&amp;<?= htmlspecialchars($exportParams) ?>">PDF / Print</a></div><?php endif; ?></div>

  <div class="card serving-card mb-3"><div class="card-header bg-white font-weight-bold">Gender Summary by Role</div><div class="table-responsive"><table class="table table-bordered serving-table mb-0" id="servingSummaryTable"><thead><tr><th>Role of Serving</th><th class="text-right">Male</th><th class="text-right">Female</th><th class="text-right">Unspecified</th><th class="text-right">Total</th></tr></thead><tbody><?php if (!$report['summary']): ?><tr><td colspan="5" class="text-center text-muted py-4">No role holders match the filters.</td></tr><?php endif; ?><?php foreach ($report['summary'] as $row): ?><tr class="summary-row"><td><?= htmlspecialchars($row['role_name']) ?></td><td class="text-right"><?= number_format($row['male']) ?></td><td class="text-right"><?= number_format($row['female']) ?></td><td class="text-right"><?= number_format($row['unspecified']) ?></td><td class="text-right"><?= number_format($row['total']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>

  <div class="card serving-card"><div class="card-header bg-white font-weight-bold">Role Holder Details</div><div class="table-responsive"><table class="table table-bordered table-hover serving-table mb-0" id="servingDetailTable"><thead><tr><th>#</th><th>CRN</th><th>Member</th><th>Role</th><th>Gender</th><th>Bible Class</th><th>Organization(s)</th><th>Contact</th></tr></thead><tbody><?php if (!$report['members']): ?><tr><td colspan="8" class="text-center text-muted py-4">No members match the filters.</td></tr><?php endif; ?><?php foreach ($report['members'] as $index=>$member): ?><tr><td><?= $index+1 ?></td><td><?= htmlspecialchars($member['crn'] ?: '-') ?></td><td><?= htmlspecialchars($member['member_name']) ?></td><td><?= htmlspecialchars($member['role_name']) ?></td><td><?= htmlspecialchars($member['gender']) ?></td><td><?= htmlspecialchars($member['class_name'] ?: '-') ?></td><td><?= htmlspecialchars($member['organizations'] ?: '-') ?></td><td><?= htmlspecialchars($member['phone'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>
</div></div>
<script>
(function(){var button=document.getElementById('copyServingReport');if(!button)return;button.addEventListener('click',function(){var tables=['servingSummaryTable','servingDetailTable'].map(function(id){return document.getElementById(id);}).filter(Boolean);var text=tables.map(function(table){return Array.prototype.map.call(table.rows,function(row){return Array.prototype.map.call(row.cells,function(cell){return cell.innerText.trim();}).join('\t');}).join('\n');}).join('\n\n');navigator.clipboard.writeText(text).then(function(){button.textContent='Copied';});});})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../../../includes/layout.php';
?>
