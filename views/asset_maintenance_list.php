<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

if (!asset_is_super_admin() && !has_permission('view_asset_maintenance') && !has_permission('manage_asset_maintenance')) {
    asset_require_permission('view_asset_maintenance');
}
if (!asset_table_exists($conn, 'asset_maintenance_work_orders')) {
    http_response_code(503);
    exit('Asset maintenance is not available. Run Phase 0092 first.');
}

$isSuper = asset_is_super_admin();
$canManage = $isSuper || has_permission('manage_asset_maintenance');
$churchId = $isSuper ? ((int) ($_GET['church_id'] ?? 0) ?: null) : asset_current_church_id($conn);
$status = trim((string) ($_GET['status'] ?? 'active'));
$priority = trim((string) ($_GET['priority'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$churches = [];
if ($isSuper) {
    $res = $conn->query('SELECT id, name FROM churches ORDER BY name');
    while ($row = $res->fetch_assoc()) $churches[] = $row;
}

$where = ['1=1']; $types = ''; $params = [];
if ($churchId) { $where[] = 'work_order.church_id = ?'; $types .= 'i'; $params[] = $churchId; }
if ($status === 'active') $where[] = "work_order.status IN ('open','scheduled','in_progress','on_hold')";
elseif (in_array($status, ['open','scheduled','in_progress','on_hold','completed','cancelled'], true)) { $where[] = 'work_order.status = ?'; $types .= 's'; $params[] = $status; }
if (in_array($priority, ['low','normal','high','critical'], true)) { $where[] = 'work_order.priority = ?'; $types .= 's'; $params[] = $priority; }
if ($q !== '') { $where[] = '(work_order.reference_code LIKE ? OR asset.asset_code LIKE ? OR item.item_number LIKE ? OR asset.item_name LIKE ? OR work_order.summary LIKE ?)'; $types .= 'sssss'; $like = '%' . $q . '%'; array_push($params, $like,$like,$like,$like,$like); }
$sql = "SELECT work_order.*, asset.asset_code, asset.item_name, item.item_number,
               department.name AS department_name, church.name AS church_name,
               reporter.name AS reported_by_name, assignee.name AS assigned_to_name
          FROM asset_maintenance_work_orders work_order
          JOIN assets asset ON asset.id = work_order.asset_id
          JOIN asset_items item ON item.id = work_order.asset_item_id
          LEFT JOIN asset_departments department ON department.id = item.department_id
          LEFT JOIN churches church ON church.id = work_order.church_id
          LEFT JOIN users reporter ON reporter.id = work_order.reported_by_user_id
          LEFT JOIN users assignee ON assignee.id = work_order.assigned_to_user_id
         WHERE " . implode(' AND ', $where) . '
         ORDER BY FIELD(work_order.priority,"critical","high","normal","low"), work_order.created_at DESC';
$pageData = report_paginate_query($conn, $sql, $types, $params, 25);
$rows = $pageData['result']->fetch_all(MYSQLI_ASSOC);
$pageData['statement']->close();

$availableItems = [];
if ($canManage) {
    $itemSql = "SELECT item.id, item.item_number, asset.item_name, asset.asset_code, department.name AS department_name
                  FROM asset_items item JOIN assets asset ON asset.id = item.asset_id
                  LEFT JOIN asset_departments department ON department.id = item.department_id
                 WHERE item.status = 'active' AND item.custody_status = 'available'
                   AND item.lifecycle_status NOT IN ('retired','disposed')
                   AND NOT EXISTS (SELECT 1 FROM asset_maintenance_work_orders active_order WHERE active_order.asset_item_id = item.id AND active_order.status IN ('open','scheduled','in_progress','on_hold'))";
    if ($churchId) { $itemSql .= ' AND item.church_id = ?'; $stmt = $conn->prepare($itemSql . ' ORDER BY asset.item_name, item.item_number'); $stmt->bind_param('i', $churchId); }
    else { $stmt = $conn->prepare($itemSql . ' ORDER BY asset.item_name, item.item_number'); }
    $stmt->execute(); $availableItems = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <section class="asset-hero p-4 mb-3"><div class="d-flex flex-wrap justify-content-between align-items-center"><div><div class="eyebrow">Reliability &amp; service</div><h2 class="mb-1">Asset Maintenance</h2><p class="mb-0">One governed queue for inspections, preventive work, repairs, costs and return-to-service condition.</p></div><?php if ($canManage): ?><a href="#new-work-order" class="btn btn-warning mt-2 mt-md-0"><i class="fas fa-plus mr-1"></i>Open work order</a><?php endif; ?></div></section>
    <?php render_asset_workspace_nav('maintenance', $churchId); ?>
    <?php if (isset($_GET['done'])): ?><div class="alert alert-success">Maintenance work order updated.</div><?php endif; ?>
    <?php if (isset($_GET['err'])): ?><div class="alert alert-danger"><?= htmlspecialchars((string) $_GET['err'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($canManage): ?>
    <details class="card asset-panel mb-3" id="new-work-order"><summary class="card-header font-weight-bold" style="cursor:pointer"><i class="fas fa-plus-circle text-primary mr-2"></i>Open a maintenance work order</summary><div class="card-body">
        <form method="post" action="asset_maintenance_action.php">
            <?= csrf_input() ?><input type="hidden" name="maintenance_action" value="create">
            <div class="form-row">
                <div class="form-group col-lg-5"><label>Physical asset unit</label><select class="form-control" name="asset_item_id" required><option value="">Select an available unit</option><?php foreach ($availableItems as $item): ?><option value="<?= (int) $item['id'] ?>"><?= htmlspecialchars($item['item_number'] . ' — ' . $item['item_name'] . ($item['department_name'] ? ' / ' . $item['department_name'] : ''), ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-lg-3"><label>Type</label><select name="maintenance_type" class="form-control"><?php foreach (['inspection','preventive','repair','service','warranty','other'] as $type): ?><option value="<?= $type ?>"><?= ucfirst($type) ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-lg-2"><label>Priority</label><select name="priority" class="form-control"><?php foreach (['low','normal','high','critical'] as $value): ?><option value="<?= $value ?>" <?= $value === 'normal' ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
                <div class="form-group col-lg-2"><label>Scheduled for</label><input type="date" name="scheduled_for" class="form-control"></div>
            </div>
            <div class="form-row"><div class="form-group col-lg-6"><label>Problem / work summary</label><input name="summary" maxlength="255" class="form-control" required></div><div class="form-group col-lg-3"><label>Vendor</label><input name="vendor_name" maxlength="180" class="form-control"></div><div class="form-group col-lg-3"><label>Estimated cost</label><input name="estimated_cost" type="number" min="0" step="0.01" class="form-control"></div></div>
            <div class="form-group"><label>Initial notes</label><textarea name="work_notes" rows="2" class="form-control"></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-tools mr-1"></i>Create work order</button>
        </form>
    </div></details>
    <?php endif; ?>

    <div class="card asset-panel mb-3"><div class="card-body"><form method="get" class="form-row align-items-end">
        <?php if ($isSuper): ?><div class="form-group col-lg-2"><label>Church</label><select name="church_id" class="form-control"><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $church['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="form-group col-lg-2"><label>Status</label><select name="status" class="form-control"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active queue</option><option value="">All</option><?php foreach (['open','scheduled','in_progress','on_hold','completed','cancelled'] as $value): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$value)) ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-2"><label>Priority</label><select name="priority" class="form-control"><option value="">All</option><?php foreach (['critical','high','normal','low'] as $value): ?><option value="<?= $value ?>" <?= $priority === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div>
        <div class="form-group col-lg-4"><label>Search</label><input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" class="form-control" placeholder="Reference, asset, unit or summary"></div>
        <div class="form-group col-lg-2"><button class="btn btn-outline-primary btn-block">Apply filters</button></div>
    </form></div></div>

    <div class="card asset-panel"><div class="card-header d-flex justify-content-between"><strong>Maintenance work queue</strong><span class="badge badge-primary p-2"><?= number_format($pageData['total_rows']) ?> work order(s)</span></div><div class="card-body table-responsive">
        <table class="table table-hover mb-0"><thead><tr><th>Reference / asset</th><th>Work</th><th>Priority</th><th>Status</th><th>Schedule &amp; cost</th><th>Owner</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($rows as $row): ?><tr>
            <td><strong><?= htmlspecialchars((string) $row['reference_code'], ENT_QUOTES, 'UTF-8') ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) $row['item_number'] . ' — ' . (string) $row['item_name'], ENT_QUOTES, 'UTF-8') ?></small><?php if ($isSuper): ?><small class="d-block text-muted"><?= htmlspecialchars((string) $row['church_name'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
            <td><strong><?= htmlspecialchars(ucfirst((string) $row['maintenance_type']), ENT_QUOTES, 'UTF-8') ?></strong><small class="d-block text-muted"><?= htmlspecialchars((string) $row['summary'], ENT_QUOTES, 'UTF-8') ?></small></td>
            <td><span class="badge badge-<?= $row['priority'] === 'critical' ? 'danger' : ($row['priority'] === 'high' ? 'warning' : 'secondary') ?>"><?= htmlspecialchars(ucfirst((string) $row['priority']), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><span class="badge badge-<?= $row['status'] === 'completed' ? 'success' : ($row['status'] === 'in_progress' ? 'primary' : ($row['status'] === 'cancelled' ? 'secondary' : 'warning')) ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',(string) $row['status'])), ENT_QUOTES, 'UTF-8') ?></span></td>
            <td><?= $row['scheduled_for'] ? htmlspecialchars((string) $row['scheduled_for'], ENT_QUOTES, 'UTF-8') : '<span class="text-muted">Unscheduled</span>' ?><small class="d-block text-muted">Estimate: <?= $row['estimated_cost'] !== null ? number_format((float) $row['estimated_cost'], 2) : '—' ?><?php if ($row['actual_cost'] !== null): ?> / Actual: <?= number_format((float) $row['actual_cost'], 2) ?><?php endif; ?></small></td>
            <td><?= htmlspecialchars((string) ($row['assigned_to_name'] ?: $row['reported_by_name'] ?: 'Unassigned'), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-nowrap"><?php if ($canManage && !in_array($row['status'], ['completed','cancelled'], true)): ?><?php if ($row['status'] !== 'in_progress'): ?><form method="post" action="asset_maintenance_action.php" class="d-inline"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="maintenance_action" value="start"><button class="btn btn-sm btn-outline-primary">Start</button></form><?php endif; ?><details class="d-inline-block ml-1"><summary class="btn btn-sm btn-success">Complete</summary><form method="post" action="asset_maintenance_action.php" class="p-3 border rounded bg-white position-absolute shadow" style="right:1rem;z-index:20;min-width:280px"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="maintenance_action" value="complete"><label class="small">Condition after</label><select name="condition_after" class="form-control form-control-sm mb-2"><?php foreach (asset_condition_options() as $condition): if (in_array($condition,['Under Maintenance','Disposed'],true)) continue; ?><option><?= htmlspecialchars($condition, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><input type="number" name="actual_cost" min="0" step="0.01" class="form-control form-control-sm mb-2" placeholder="Actual cost"><textarea name="work_notes" class="form-control form-control-sm mb-2" placeholder="Completion notes"></textarea><button class="btn btn-success btn-sm btn-block">Confirm completion</button></form></details><?php endif; ?> <a href="asset_view.php?id=<?= (int) $row['asset_id'] ?>&tab=items" class="btn btn-sm btn-outline-secondary"><i class="fas fa-eye"></i></a></td>
        </tr><?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-check-circle fa-2x mb-2 d-block"></i>No maintenance work orders match these filters.</td></tr><?php endif; ?>
        </tbody></table>
        <?php report_render_server_pagination($pageData['total_rows'], $pageData['page'], $pageData['per_page'], 'Maintenance work-order pages'); ?>
    </div></div>
</div>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../includes/layout.php'; ?>

