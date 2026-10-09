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
$acquisitionMode = trim((string) ($_GET['acquisition_mode'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$conditions = asset_condition_options();
$lifecycleOptions = asset_lifecycle_options();
$acquisitionModes = asset_acquisition_mode_options();
$canCreate = $isSuper || has_permission('create_asset');
$canEdit = $isSuper || has_permission('edit_asset');
$canTransfer = $isSuper || has_permission('transfer_asset');
$canExport = $isSuper || has_permission('export_asset_register');
$canViewAudit = $isSuper || has_permission('view_asset_audit');
$canViewDetail = $isSuper || has_permission('view_asset_detail') || has_permission('view_asset_register');
$canViewReports = $isSuper || has_permission('view_asset_reports');
$canViewApprovals = $isSuper || has_permission('approve_asset_request') || has_permission('request_asset_approval');
$canManageGroups = $isSuper || has_permission('manage_asset_groups');
$canViewUseRequests = asset_user_can_view_use_requests() || isset($_SESSION['member_id']);

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
$activeUnits = (int) ($registerSummary['active_units'] ?? 0);
$underMaintenanceAssets = (int) ($registerSummary['maintenance_units'] ?? 0);
$totalAmount = (float) ($registerSummary['total_amount'] ?? 0);

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<style>
.asset-kpi {
    border-radius: 14px;
    border: 1px solid #dbe3ec;
    background: linear-gradient(145deg, #ffffff, #f4f7fb);
}
.asset-kpi .label {
    font-size: 11px;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #5f6d7a;
    font-weight: 700;
}
.asset-kpi .value {
    font-size: 1.5rem;
    font-weight: 700;
    color: #18324a;
}
.asset-hero {
    border-radius: 16px;
    background: linear-gradient(120deg, #0f3557, #18527f);
    color: #fff;
}
.asset-hero small {
    color: rgba(255, 255, 255, .84);
}
</style>
<div class="container-fluid mt-4 asset-workspace">
    <div class="asset-hero p-3 p-md-4 mb-3 shadow-sm">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <div class="eyebrow">Physical asset control</div>
                <h2 class="mb-1"><i class="fas fa-boxes mr-2"></i>Asset Register</h2>
                <small>Every row is one uniquely numbered physical asset. Categories classify it; shared details are maintained without creating another visible “category.”</small>
            </div>
            <div class="mt-2 mt-md-0">
                <?php if ($canViewAudit): ?>
                    <a class="btn btn-light mr-2" href="asset_audit_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-user-shield mr-1"></i> Audit Log</a>
                <?php endif; ?>
                <?php if ($canViewApprovals): ?>
                    <a class="btn btn-outline-light mr-2" href="asset_approval_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-user-check mr-1"></i> Approval Queue</a>
                <?php endif; ?>
                <?php if ($canViewReports): ?>
                    <a class="btn btn-outline-light mr-2" href="asset_reports.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-chart-line mr-1"></i> Reports</a>
                <?php endif; ?>
                <?php if ($canViewUseRequests): ?>
                    <a class="btn btn-outline-light mr-2" href="asset_request_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-hand-holding mr-1"></i> Requests</a>
                <?php endif; ?>
                <?php if ($canManageGroups): ?>
                    <a class="btn btn-outline-light mr-2" href="asset_group_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-layer-group mr-1"></i> Categories</a>
                <?php endif; ?>
                <?php if ($canExport): ?>
                    <a class="btn btn-outline-light mr-2" href="asset_export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"><i class="fas fa-file-csv mr-1"></i> Export</a>
                <?php endif; ?>
                <?php if ($canCreate): ?>
                    <a class="btn btn-warning" href="asset_form.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-plus mr-1"></i> Register Physical Asset</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php render_asset_workspace_nav('register', $churchId); ?>

    <div class="asset-lifecycle-steps mb-3">
        <div class="asset-lifecycle-step"><strong>1. Classify</strong><small>Select asset category</small></div><div class="asset-lifecycle-step"><strong>2. Register Unit</strong><small>Unique physical number</small></div><div class="asset-lifecycle-step"><strong>3. Locate</strong><small>Accountable department</small></div><div class="asset-lifecycle-step"><strong>4. Issue / Return</strong><small>Named custodian</small></div><div class="asset-lifecycle-step"><strong>5. Maintain</strong><small>Service work orders</small></div><div class="asset-lifecycle-step"><strong>6. Retire</strong><small>Approved disposal</small></div>
    </div>

    <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Asset saved successfully.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Asset deleted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['transferred'])): ?><div class="alert alert-success">Asset transferred successfully.</div><?php endif; ?>
    <?php if (isset($_GET['requested'])): ?><div class="alert alert-info">Approval request submitted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err']) ?></div><?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-3 mb-2">
            <div class="asset-kpi p-3 h-100">
                <div class="label">Physical assets</div>
                <div class="value"><?= number_format($physicalUnits) ?></div>
                <small class="text-muted"><?= number_format($registerEntries) ?> shared record(s)</small>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="asset-kpi p-3 h-100">
                <div class="label">Active units</div>
                <div class="value"><?= number_format($activeUnits) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="asset-kpi p-3 h-100">
                <div class="label">Under Maintenance</div>
                <div class="value"><?= number_format($underMaintenanceAssets) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-2">
            <div class="asset-kpi p-3 h-100">
                <div class="label">Total Recorded Value</div>
                <div class="value"><?= number_format($totalAmount, 2) ?></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper): ?>
                <div class="form-group col-md-2">
                    <label>Church</label>
                    <select class="form-control" name="church_id">
                        <option value="">All Churches</option>
                        <?php foreach ($churches as $church): ?>
                            <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group col-md-2">
                    <label>Department</label>
                    <select class="form-control" name="department_id">
                        <option value="">All</option>
                        <?php foreach ($departments as $department): ?>
                            <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($hasGroups): ?>
                <div class="form-group col-md-2">
                    <label>Asset Category</label>
                    <select class="form-control" name="asset_group_id">
                        <option value="">All</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= $assetGroupId === (int) $group['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group col-md-2">
                    <label>Condition</label>
                    <select class="form-control" name="condition_status">
                        <option value="">All</option>
                        <?php foreach ($conditions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $condition === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label>Status</label>
                    <select class="form-control" name="status">
                        <option value="">All</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="disposed" <?= $status === 'disposed' ? 'selected' : '' ?>>Disposed</option>
                    </select>
                </div>
                <?php if ($hasAcquisitionMode): ?>
                <div class="form-group col-md-2">
                    <label>Acquisition</label>
                    <select class="form-control" name="acquisition_mode">
                        <option value="">All</option>
                        <?php foreach ($acquisitionModes as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $acquisitionMode === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($hasLifecycle): ?>
                <div class="form-group col-md-2">
                    <label>Lifecycle</label>
                    <select class="form-control" name="lifecycle_status">
                        <option value="">All</option>
                        <?php foreach ($lifecycleOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $lifecycle === $opt ? 'selected' : '' ?>><?= htmlspecialchars(asset_lifecycle_label($opt)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="form-group <?= $hasLifecycle ? 'col-md-2' : 'col-md-3' ?>">
                    <label>Search</label>
                    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Code, item, receipt, serial...">
                </div>
                <div class="form-group col-md-1">
                    <button class="btn btn-outline-primary btn-block" type="submit">Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm asset-panel">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetTable">
                <thead class="thead-light">
                    <tr>
                        <th>Physical Asset Number</th>
                        <th>Asset Name</th>
                        <th>Asset Category</th>
                        <th>Department</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Serial</th>
                        <th>Custody</th>
                        <th>Unit Value</th>
                        <?php if ($hasAcquisitionMode): ?><th>Acquisition</th><?php endif; ?>
                        <th>Condition</th>
                        <?php if ($hasLifecycle): ?><th>Lifecycle</th><?php endif; ?>
                        <th>Status</th>
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
                        <tr>
                            <td><strong><?= htmlspecialchars((string) $asset['item_number']) ?></strong><small class="d-block text-muted">Shared record: <?= htmlspecialchars((string) $asset['asset_code']) ?></small></td>
                            <td><strong><?= htmlspecialchars((string) $asset['item_name']) ?></strong><?php if ((int) $asset['sibling_unit_count'] > 1): ?><small class="d-block text-muted"><?= number_format((int) $asset['sibling_unit_count']) ?> identical/related units share these details</small><?php endif; ?></td>
                            <td><?= htmlspecialchars((string) (($asset['asset_group_name'] ?? $asset['item_group'] ?? '') !== '' ? (($asset['asset_group_name'] ?? $asset['item_group'] ?? '') . (!empty($asset['asset_group_code']) ? ' (' . $asset['asset_group_code'] . ')' : '')) : '-')) ?></td>
                            <td><?= htmlspecialchars((string) ($asset['department_name'] ?? '-')) ?></td>
                            <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($asset['church_name'] ?? '-')) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string) ($asset['serial_number'] ?: '-')) ?></td>
                            <td><span class="badge badge-<?= $asset['custody_status'] === 'available' ? 'success' : ($asset['custody_status'] === 'issued' ? 'primary' : 'warning') ?>"><?= htmlspecialchars(ucfirst((string) $asset['custody_status'])) ?></span></td>
                            <td><?= number_format((float) $asset['allocated_unit_value'], 2) ?></td>
                            <?php if ($hasAcquisitionMode): ?>
                                <td><?= htmlspecialchars((string) (asset_acquisition_mode_options()[(string) ($asset['acquisition_mode'] ?? '')] ?? ucfirst(str_replace('_', ' ', (string) ($asset['acquisition_mode'] ?? ''))))) ?></td>
                            <?php endif; ?>
                            <td><span class="badge badge-<?= asset_condition_badge_class($assetCondition) ?>"><?= htmlspecialchars($assetCondition) ?></span></td>
                            <?php if ($hasLifecycle): ?>
                                <td><span class="badge badge-<?= asset_lifecycle_badge_class($effectiveLifecycle) ?>"><?= htmlspecialchars(asset_lifecycle_label($effectiveLifecycle)) ?></span></td>
                            <?php endif; ?>
                            <td><span class="badge badge-<?= $assetStatus === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($assetStatus) ?></span></td>
                            <td class="text-nowrap">
                                <?php if ($canViewDetail): ?>
                                    <a href="asset_view.php?id=<?= (int) $asset['asset_id'] ?>&tab=items" class="btn btn-sm btn-outline-dark" title="View physical unit and shared record"><i class="fas fa-eye"></i></a>
                                <?php endif; ?>
                                <?php if ($canEdit): ?>
                                    <a href="asset_form.php?id=<?= (int) $asset['asset_id'] ?>" class="btn btn-sm btn-warning" title="Edit shared details for related units"><i class="fas fa-edit"></i></a>
                                <?php endif; ?>
                                <?php if ($canCreate): ?><a href="asset_item_form.php?asset_id=<?= (int) $asset['asset_id'] ?>" class="btn btn-sm btn-outline-success" title="Register another related physical unit"><i class="fas fa-plus"></i></a><?php endif; ?>
                                <?php if ($canTransfer): ?>
                                    <a href="asset_transfer.php?id=<?= (int) $asset['asset_id'] ?>&asset_item_id=<?= (int) $asset['asset_item_id'] ?>" class="btn btn-sm btn-primary" title="Transfer this physical unit"><i class="fas fa-exchange-alt"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assets)): ?>
                        <tr><td colspan="<?= 10 + ($isSuper ? 1 : 0) + ($hasAcquisitionMode ? 1 : 0) + ($hasLifecycle ? 1 : 0) ?>" class="asset-empty-state"><i class="fas fa-box-open"></i>No physical assets match these filters.</td></tr>
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
