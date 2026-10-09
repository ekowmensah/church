<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

asset_require_permission('view_asset_register');

$isSuper = asset_is_super_admin();
$hasLifecycle = asset_can_use_lifecycle($conn);
$hasGroups = asset_can_use_groups($conn);
$hasAcquisitionMode = asset_column_exists($conn, 'assets', 'acquisition_mode');
$hasCustody = asset_column_exists($conn, 'asset_items', 'custody_status');
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$departmentId = isset($_GET['department_id']) && (int) $_GET['department_id'] > 0 ? (int) $_GET['department_id'] : null;
$assetGroupId = isset($_GET['asset_group_id']) && (int) $_GET['asset_group_id'] > 0 ? (int) $_GET['asset_group_id'] : null;
$condition = trim((string) ($_GET['condition_status'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$lifecycle = trim((string) ($_GET['lifecycle_status'] ?? ''));
$custody = trim((string) ($_GET['custody_status'] ?? ''));
$acquisitionMode = trim((string) ($_GET['acquisition_mode'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$conditions = asset_condition_options();
$lifecycleOptions = asset_lifecycle_options();
$acquisitionModes = asset_acquisition_mode_options();
$canCreate = $isSuper || has_permission('create_asset');
$canEdit = $isSuper || has_permission('edit_asset');
$canTransfer = $isSuper || has_permission('transfer_asset');
$canExport = $isSuper || has_permission('export_asset_register');
$canViewDetail = $isSuper || has_permission('view_asset_detail') || has_permission('view_asset_register');

if (!asset_item_tracking_available($conn)) {
    http_response_code(503);
    exit('Physical asset tracking is not available. Run the asset lifecycle migrations first.');
}

$departments = asset_fetch_departments($conn, $churchId, true);
$groups = $hasGroups ? asset_fetch_groups($conn, $churchId, true) : [];
$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$fromSql = "
    FROM asset_items item
    INNER JOIN assets a ON a.id = item.asset_id
    LEFT JOIN asset_departments d ON d.id = item.department_id
    LEFT JOIN churches c ON c.id = a.church_id
    LEFT JOIN (
        SELECT asset_id, COUNT(*) AS unit_count
        FROM asset_items
        GROUP BY asset_id
    ) unit_total ON unit_total.asset_id = a.id
    " . ($hasGroups ? "LEFT JOIN asset_groups g ON g.id = a.asset_group_id" : "");
$whereSql = ' WHERE 1';
$types = '';
$params = [];

if ($churchId !== null) {
    $whereSql .= ' AND a.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($departmentId !== null) {
    $whereSql .= ' AND item.department_id = ?';
    $types .= 'i';
    $params[] = $departmentId;
}
if ($hasGroups && $assetGroupId !== null) {
    $whereSql .= ' AND a.asset_group_id = ?';
    $types .= 'i';
    $params[] = $assetGroupId;
}
if ($condition !== '' && in_array($condition, $conditions, true)) {
    $whereSql .= ' AND item.condition_status = ?';
    $types .= 's';
    $params[] = $condition;
}
if ($status !== '' && in_array($status, ['active', 'disposed'], true)) {
    $whereSql .= ' AND item.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($hasCustody && $custody !== '' && in_array($custody, ['available', 'reserved', 'issued'], true)) {
    $whereSql .= ' AND item.custody_status = ?';
    $types .= 's';
    $params[] = $custody;
}
if ($hasLifecycle && $lifecycle !== '' && in_array($lifecycle, $lifecycleOptions, true)) {
    $whereSql .= ' AND item.lifecycle_status = ?';
    $types .= 's';
    $params[] = $lifecycle;
}
if ($hasAcquisitionMode && $acquisitionMode !== '' && array_key_exists($acquisitionMode, $acquisitionModes)) {
    $whereSql .= ' AND a.acquisition_mode = ?';
    $types .= 's';
    $params[] = $acquisitionMode;
}
if ($q !== '') {
    $whereSql .= ' AND (a.asset_code LIKE ? OR item.item_number LIKE ? OR item.serial_number LIKE ? OR a.item_name LIKE ? OR a.item_group LIKE ? OR a.receipt_or_serial_number LIKE ?';
    if (asset_column_exists($conn, 'assets', 'receipt_number')) {
        $whereSql .= ' OR a.receipt_number LIKE ?';
    }
    if (asset_column_exists($conn, 'assets', 'serial_number')) {
        $whereSql .= ' OR a.serial_number LIKE ?';
    }
    if ($hasGroups) {
        $whereSql .= ' OR g.name LIKE ? OR g.group_code LIKE ?';
    }
    $whereSql .= ')';
    $types .= 'ssssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if (asset_column_exists($conn, 'assets', 'receipt_number')) {
        $types .= 's';
        $params[] = $like;
    }
    if (asset_column_exists($conn, 'assets', 'serial_number')) {
        $types .= 's';
        $params[] = $like;
    }
    if ($hasGroups) {
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
}

$sql = "SELECT a.id AS asset_id, item.id AS asset_item_id,
               a.asset_code, a.item_name, a.item_group, a.asset_group_id,
               a.purchase_date, a.amount, a.acquisition_mode,
               a.receipt_number, a.receipt_or_serial_number,
               item.item_number, item.serial_number,
               item.condition_status, item.status, item.lifecycle_status,
               item.department_id, item.created_at AS unit_created_at,
               " . ($hasCustody ? "item.custody_status" : "'available' AS custody_status") . ",
               CASE WHEN COALESCE(unit_total.unit_count, 0) > 0
                    THEN COALESCE(a.amount, 0) / unit_total.unit_count
                    ELSE COALESCE(a.amount, 0) END AS allocated_unit_value,
               COALESCE(unit_total.unit_count, 1) AS sibling_unit_count,
               d.name AS department_name, c.name AS church_name"
     . ($hasGroups ? ", g.name AS asset_group_name, g.group_code AS asset_group_code" : "")
     . $fromSql . $whereSql;

$summarySql = 'SELECT COUNT(*) AS physical_units,
                      COUNT(DISTINCT a.id) AS register_entries,
                      SUM(item.status = "active") AS active_units,
                      SUM(item.status = "disposed") AS disposed_units,
                      ' . ($hasCustody
                          ? 'SUM(item.custody_status = "available") AS available_units,
                             SUM(item.custody_status = "reserved") AS reserved_units,
                             SUM(item.custody_status = "issued") AS issued_units,'
                          : 'SUM(item.status = "active") AS available_units,
                             0 AS reserved_units,
                             0 AS issued_units,') . '
                      SUM(item.lifecycle_status = "under_maintenance" OR item.condition_status = "Under Maintenance") AS maintenance_units,
                      COALESCE(SUM(CASE WHEN COALESCE(unit_total.unit_count, 0) > 0
                           THEN COALESCE(a.amount, 0) / unit_total.unit_count
                           ELSE COALESCE(a.amount, 0) END), 0) AS total_amount'
              . $fromSql . $whereSql;
$summaryStmt = $conn->prepare($summarySql);
if ($types !== '') $summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();
$registerSummary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

$pageData = report_paginate_query($conn, $sql . ' ORDER BY item.created_at DESC, item.id DESC', $types, $params, 25);
$assets = $pageData['result']->fetch_all(MYSQLI_ASSOC);
$pageData['statement']->close();
$physicalUnits = (int) ($registerSummary['physical_units'] ?? 0);
$registerEntries = (int) ($registerSummary['register_entries'] ?? 0);
$availableUnits = (int) ($registerSummary['available_units'] ?? 0);
$reservedUnits = (int) ($registerSummary['reserved_units'] ?? 0);
$issuedUnits = (int) ($registerSummary['issued_units'] ?? 0);
$underMaintenanceAssets = (int) ($registerSummary['maintenance_units'] ?? 0);
$totalAmount = (float) ($registerSummary['total_amount'] ?? 0);
$activeFilterCount = count(array_filter([
    $isSuper ? $churchId : null, $departmentId, $assetGroupId, $condition, $status,
    $lifecycle, $custody, $acquisitionMode, $q,
], static fn($value): bool => $value !== null && $value !== ''));

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<style>
.asset-register-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:.8rem}
.asset-register-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem}
.asset-guide-step{display:flex;gap:.75rem;align-items:flex-start;padding:.85rem 1rem;border:1px solid var(--asset-border);border-radius:13px;background:#fff}
.asset-guide-number{display:grid;place-items:center;flex:0 0 30px;height:30px;border-radius:9px;background:#eaf5fb;color:var(--asset-blue);font-weight:800}
.asset-guide-step strong{display:block;color:var(--asset-ink);font-size:.85rem}.asset-guide-step small{display:block;color:var(--asset-muted);line-height:1.35}
.asset-register-filter summary{cursor:pointer;list-style:none}.asset-register-filter summary::-webkit-details-marker{display:none}
.asset-filter-count{border-radius:999px;padding:.25rem .55rem;background:#eaf5fb;color:var(--asset-blue);font-size:.72rem;font-weight:800}
.asset-identity{min-width:190px}.asset-unit-number{font-size:.92rem;font-weight:800;color:var(--asset-ink)}
.asset-model-name{font-weight:800;color:var(--asset-ink)}.asset-meta{display:block;color:var(--asset-muted);font-size:.75rem;line-height:1.45}
.asset-category-chip{display:inline-flex;align-items:center;margin-top:.3rem;padding:.22rem .48rem;border-radius:999px;background:#eef5fa;color:#315b78;font-size:.7rem;font-weight:700}
.asset-state-stack{display:flex;flex-wrap:wrap;gap:.3rem;max-width:220px}.asset-state-stack .badge{padding:.38rem .5rem}
.asset-row-actions{display:flex;flex-wrap:wrap;gap:.3rem;min-width:205px}.asset-row-actions .btn{white-space:nowrap}
@media(max-width:1199.98px){.asset-register-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:767.98px){.asset-register-kpis,.asset-register-guide{grid-template-columns:1fr 1fr}}
@media(max-width:575.98px){.asset-register-kpis,.asset-register-guide{grid-template-columns:1fr}}
</style>
<div class="container-fluid mt-4 asset-workspace">
    <div class="asset-hero p-3 p-md-4 mb-3 shadow-sm">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <div class="eyebrow">One row, one traceable unit</div>
                <h2 class="mb-1"><i class="fas fa-boxes mr-2"></i>Physical Asset Register</h2>
                <p class="mb-0">Find the exact unit, see where it is, understand its condition and take the next permitted action.</p>
            </div>
            <div class="asset-hero-actions mt-3 mt-md-0">
                <?php if ($canExport): ?>
                    <a class="btn btn-outline-light" href="asset_export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"><i class="fas fa-file-csv mr-1"></i> Export current view</a>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                    <a class="btn btn-warning" href="asset_form.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-plus mr-1"></i> Register physical unit</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php render_asset_workspace_nav('register', $churchId); ?>

    <div class="asset-register-guide mb-3">
        <div class="asset-guide-step"><span class="asset-guide-number">1</span><div><strong>Category</strong><small>Classification such as Vehicles or Sound Equipment.</small></div></div>
        <div class="asset-guide-step"><span class="asset-guide-number">2</span><div><strong>Asset type / model</strong><small>Shared description such as Toyota Hiace or Yamaha Keyboard.</small></div></div>
        <div class="asset-guide-step"><span class="asset-guide-number">3</span><div><strong>Physical unit</strong><small>The uniquely numbered object shown as one row below.</small></div></div>
    </div>

    <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Asset saved successfully.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Asset deleted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['transferred'])): ?><div class="alert alert-success">Asset transferred successfully.</div><?php endif; ?>
    <?php if (isset($_GET['requested'])): ?><div class="alert alert-info">Approval request submitted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

    <div class="asset-register-kpis mb-3">
        <div class="asset-kpi-card"><div class="asset-kpi-label">Physical units</div><div class="asset-kpi-value"><?= number_format($physicalUnits) ?></div><small class="text-muted"><?= number_format($registerEntries) ?> asset type/model record(s)</small></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Available</div><div class="asset-kpi-value text-success"><?= number_format($availableUnits) ?></div><small class="text-muted">Ready to reserve or issue</small></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Reserved / issued</div><div class="asset-kpi-value"><?= number_format($reservedUnits + $issuedUnits) ?></div><small class="text-muted"><?= number_format($reservedUnits) ?> reserved &middot; <?= number_format($issuedUnits) ?> issued</small></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Needs attention</div><div class="asset-kpi-value text-warning"><?= number_format($underMaintenanceAssets) ?></div><small class="text-muted">Under maintenance</small></div>
        <div class="asset-kpi-card"><div class="asset-kpi-label">Recorded value</div><div class="asset-kpi-value"><?= number_format($totalAmount, 2) ?></div><small class="text-muted">Across the filtered units</small></div>
    </div>

    <div class="card asset-panel asset-register-filter mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <div><strong>Find a physical unit</strong><small class="d-block text-muted">Search by unit number, serial, asset type/model or category.</small></div>
            <div><?php if ($activeFilterCount > 0): ?><span class="asset-filter-count mr-2"><?= $activeFilterCount ?> active filter<?= $activeFilterCount === 1 ? '' : 's' ?></span><?php endif; ?><a href="asset_list.php" class="btn btn-sm btn-outline-secondary">Clear</a></div>
        </div>
        <div class="card-body">
            <form method="get">
                <div class="form-row align-items-end">
                    <?php if ($isSuper): ?><div class="form-group col-lg-3"><label>Church</label><select class="form-control" name="church_id"><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <div class="form-group <?= $isSuper ? 'col-lg-2' : 'col-lg-5' ?>"><label>Search register</label><input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Unit number, serial, name/model or category"></div>
                    <?php if ($hasGroups): ?><div class="form-group col-lg-3"><label>Category</label><select class="form-control" name="asset_group_id"><option value="">All categories</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= $assetGroupId === (int) $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $group['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <div class="form-group col-lg-3"><label>Department</label><select class="form-control" name="department_id"><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group col-lg-1"><button class="btn btn-primary btn-block" type="submit" title="Apply filters"><i class="fas fa-search"></i></button></div>
                </div>
                <details <?= ($condition !== '' || $status !== '' || $lifecycle !== '' || $custody !== '' || $acquisitionMode !== '') ? 'open' : '' ?>>
                    <summary class="font-weight-bold text-primary py-2"><i class="fas fa-sliders-h mr-1"></i>Operational filters</summary>
                    <div class="form-row align-items-end pt-2 border-top">
                        <?php if ($hasCustody): ?><div class="form-group col-md-3"><label>Custody state</label><select class="form-control" name="custody_status"><option value="">All custody states</option><?php foreach (['available' => 'Available', 'reserved' => 'Reserved', 'issued' => 'Issued'] as $key => $label): ?><option value="<?= $key ?>" <?= $custody === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div><?php endif; ?>
                        <div class="form-group col-md-3"><label>Condition</label><select class="form-control" name="condition_status"><option value="">All conditions</option><?php foreach ($conditions as $opt): ?><option value="<?= htmlspecialchars($opt) ?>" <?= $condition === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option><?php endforeach; ?></select></div>
                        <?php if ($hasLifecycle): ?><div class="form-group col-md-3"><label>Lifecycle</label><select class="form-control" name="lifecycle_status"><option value="">All lifecycle states</option><?php foreach ($lifecycleOptions as $opt): ?><option value="<?= htmlspecialchars($opt) ?>" <?= $lifecycle === $opt ? 'selected' : '' ?>><?= htmlspecialchars(asset_lifecycle_label($opt)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                        <div class="form-group col-md-3"><label>Register status</label><select class="form-control" name="status"><option value="">All statuses</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="disposed" <?= $status === 'disposed' ? 'selected' : '' ?>>Disposed</option></select></div>
                        <?php if ($hasAcquisitionMode): ?><div class="form-group col-md-3"><label>Acquisition</label><select class="form-control" name="acquisition_mode"><option value="">All acquisition methods</option><?php foreach ($acquisitionModes as $key => $label): ?><option value="<?= htmlspecialchars($key) ?>" <?= $acquisitionMode === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    </div>
                </details>
            </form>
        </div>
    </div>

    <div class="card shadow-sm asset-panel">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center">
            <div><strong>Physical units</strong><small class="d-block text-muted">Every row is an individually numbered object. Open a unit for its full custody, maintenance and audit history.</small></div>
            <span class="badge badge-primary p-2"><?= number_format($pageData['total_rows']) ?> matching unit<?= (int) $pageData['total_rows'] === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-hover mb-0" id="assetTable">
                <thead class="thead-light">
                    <tr>
                        <th>Physical unit</th>
                        <th>Asset type / model</th>
                        <th>Location<?= $isSuper ? ' / Church' : '' ?></th>
                        <th>Operational state</th>
                        <th>Recorded value</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $asset): ?>
                        <?php
                        $assetStatus = (string) ($asset['status'] ?? 'active');
                        $assetCondition = (string) ($asset['condition_status'] ?? '');
                        $effectiveLifecycle = $hasLifecycle
                            ? (string) ($asset['lifecycle_status'] ?? asset_default_lifecycle($assetStatus, $assetCondition))
                            : asset_default_lifecycle($assetStatus, $assetCondition);
                        ?>
                        <?php $categoryLabel = (string) (($asset['asset_group_name'] ?? $asset['item_group'] ?? '') ?: 'Unclassified'); ?>
                        <tr>
                            <td class="asset-identity"><span class="asset-unit-number"><?= htmlspecialchars((string) $asset['item_number']) ?></span><span class="asset-meta">Serial: <?= htmlspecialchars((string) ($asset['serial_number'] ?: 'Not recorded')) ?></span><span class="asset-meta">Unit status: <?= htmlspecialchars(ucfirst($assetStatus)) ?></span></td>
                            <td style="min-width:230px"><span class="asset-model-name"><?= htmlspecialchars((string) $asset['item_name']) ?></span><span class="asset-meta">Type/model reference: <?= htmlspecialchars((string) $asset['asset_code']) ?></span><span class="asset-category-chip"><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($categoryLabel) ?><?= !empty($asset['asset_group_code']) ? ' · ' . htmlspecialchars((string) $asset['asset_group_code']) : '' ?></span><?php if ((int) $asset['sibling_unit_count'] > 1): ?><span class="asset-meta mt-1"><?= number_format((int) $asset['sibling_unit_count']) ?> related physical units use these shared details</span><?php endif; ?><?php if ($hasAcquisitionMode): ?><span class="asset-meta mt-1">Acquired by <?= htmlspecialchars((string) ($acquisitionModes[(string) ($asset['acquisition_mode'] ?? '')] ?? ucfirst(str_replace('_', ' ', (string) ($asset['acquisition_mode'] ?? 'unknown'))))) ?></span><?php endif; ?></td>
                            <td><strong><?= htmlspecialchars((string) ($asset['department_name'] ?? 'Unassigned')) ?></strong><?php if ($isSuper): ?><span class="asset-meta"><?= htmlspecialchars((string) ($asset['church_name'] ?? '-')) ?></span><?php endif; ?></td>
                            <td><div class="asset-state-stack"><span class="badge badge-<?= $asset['custody_status'] === 'available' ? 'success' : ($asset['custody_status'] === 'issued' ? 'primary' : 'warning') ?>"><i class="fas fa-hand-holding mr-1"></i><?= htmlspecialchars(ucfirst((string) $asset['custody_status'])) ?></span><span class="badge badge-<?= asset_condition_badge_class($assetCondition) ?>"><?= htmlspecialchars($assetCondition ?: 'Condition unknown') ?></span><?php if ($hasLifecycle): ?><span class="badge badge-<?= asset_lifecycle_badge_class($effectiveLifecycle) ?>"><?= htmlspecialchars(asset_lifecycle_label($effectiveLifecycle)) ?></span><?php endif; ?></div></td>
                            <td><strong><?= number_format((float) $asset['allocated_unit_value'], 2) ?></strong><span class="asset-meta">Allocated unit value</span></td>
                            <td><div class="asset-row-actions">
                                <?php if ($canViewDetail): ?>
                                    <a href="asset_view.php?id=<?= (int) $asset['asset_id'] ?>&tab=items&asset_item_id=<?= (int) $asset['asset_item_id'] ?>" class="btn btn-sm btn-outline-dark"><i class="fas fa-eye mr-1"></i>Open</a>
                                <?php endif; ?>
                                <?php if ($canTransfer): ?>
                                    <a href="asset_transfer.php?id=<?= (int) $asset['asset_id'] ?>&asset_item_id=<?= (int) $asset['asset_item_id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-exchange-alt mr-1"></i>Move</a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?><a href="asset_form.php?id=<?= (int) $asset['asset_id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit the details shared by related units"><i class="fas fa-edit mr-1"></i>Edit type/model</a><?php endif; ?>
                                <?php if ($canCreate): ?><a href="asset_item_form.php?asset_id=<?= (int) $asset['asset_id'] ?>" class="btn btn-sm btn-outline-success" title="Register another unit with the same type/model"><i class="fas fa-plus mr-1"></i>Add related unit</a><?php endif; ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assets)): ?>
                        <tr><td colspan="6" class="asset-empty-state"><i class="fas fa-box-open"></i><strong>No physical units match these filters.</strong><small class="d-block mt-1">Clear filters or register a new physical unit.</small></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php report_render_server_pagination($pageData['total_rows'], $pageData['page'], $pageData['per_page'], 'Physical asset register pages'); ?>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
