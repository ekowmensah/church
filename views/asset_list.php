<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('view_asset_register');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$departmentId = isset($_GET['department_id']) && (int) $_GET['department_id'] > 0 ? (int) $_GET['department_id'] : null;
$condition = trim((string) ($_GET['condition_status'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$conditions = asset_condition_options();
$canCreate = $isSuper || has_permission('create_asset');
$canEdit = $isSuper || has_permission('edit_asset');
$canDelete = $isSuper || has_permission('delete_asset');
$canTransfer = $isSuper || has_permission('transfer_asset');
$canExport = $isSuper || has_permission('export_asset_register');

$departments = asset_fetch_departments($conn, $churchId, true);
$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$sql = "
    SELECT a.*, d.name AS department_name, c.name AS church_name
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    LEFT JOIN churches c ON c.id = a.church_id
    WHERE 1
";
$types = '';
$params = [];

if ($churchId !== null) {
    $sql .= ' AND a.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($departmentId !== null) {
    $sql .= ' AND a.department_id = ?';
    $types .= 'i';
    $params[] = $departmentId;
}
if ($condition !== '' && in_array($condition, $conditions, true)) {
    $sql .= ' AND a.condition_status = ?';
    $types .= 's';
    $params[] = $condition;
}
if ($status !== '' && in_array($status, ['active', 'disposed'], true)) {
    $sql .= ' AND a.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (a.asset_code LIKE ? OR a.item_name LIKE ? OR a.item_group LIKE ? OR a.receipt_or_serial_number LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY a.created_at DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$assets = [];
while ($row = $res->fetch_assoc()) {
    $assets[] = $row;
}
$stmt->close();

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-0"><i class="fas fa-boxes mr-2"></i>Asset Register</h2>
            <small class="text-muted">Track church assets by department, condition, and movement history.</small>
        </div>
        <div class="mt-2 mt-md-0">
            <?php if ($canExport): ?>
                <a class="btn btn-outline-success mr-2" href="asset_export.php?<?= htmlspecialchars(http_build_query($_GET)) ?>"><i class="fas fa-file-csv mr-1"></i> Export</a>
            <?php endif; ?>
            <?php if ($canCreate): ?>
                <a class="btn btn-primary" href="asset_form.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>"><i class="fas fa-plus mr-1"></i> Add Asset</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Asset saved successfully.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Asset deleted successfully.</div><?php endif; ?>
    <?php if (isset($_GET['transferred'])): ?><div class="alert alert-success">Asset transferred successfully.</div><?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper): ?>
                <div class="form-group col-md-3">
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
                <div class="form-group col-md-2">
                    <label>Search</label>
                    <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Code, item, serial...">
                </div>
                <div class="form-group col-md-1">
                    <button class="btn btn-outline-primary btn-block" type="submit">Go</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetTable">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Item Group</th>
                        <th>Item Name</th>
                        <th>Department</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Purchase Date</th>
                        <th>Qty</th>
                        <th>Receipt/Serial</th>
                        <th>Amount</th>
                        <th>Condition</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assets as $asset): ?>
                        <tr>
                            <td><?= htmlspecialchars($asset['asset_code']) ?></td>
                            <td><?= htmlspecialchars((string) ($asset['item_group'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($asset['item_name']) ?></td>
                            <td><?= htmlspecialchars((string) ($asset['department_name'] ?? '-')) ?></td>
                            <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($asset['church_name'] ?? '-')) ?></td><?php endif; ?>
                            <td><?= htmlspecialchars((string) ($asset['purchase_date'] ?? '')) ?></td>
                            <td><?= (int) $asset['quantity'] ?></td>
                            <td><?= htmlspecialchars((string) ($asset['receipt_or_serial_number'] ?? '')) ?></td>
                            <td><?= $asset['amount'] !== null ? number_format((float) $asset['amount'], 2) : '' ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($asset['condition_status']) ?></span></td>
                            <td><span class="badge badge-<?= $asset['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($asset['status']) ?></span></td>
                            <td class="text-nowrap">
                                <?php if ($canEdit): ?>
                                    <a href="asset_form.php?id=<?= (int) $asset['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                                <?php endif; ?>
                                <?php if ($canTransfer): ?>
                                    <a href="asset_transfer.php?id=<?= (int) $asset['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-exchange-alt"></i></a>
                                <?php endif; ?>
                                <?php if ($canDelete): ?>
                                    <a href="asset_delete.php?id=<?= (int) $asset['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this asset?');"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assets)): ?>
                        <tr><td colspan="<?= $isSuper ? 12 : 11 ?>" class="text-center">No assets found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetTable').DataTable({pageLength: 25, order:[[0,'desc']]});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
