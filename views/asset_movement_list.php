<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('view_asset_movements');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$q = trim((string) ($_GET['q'] ?? ''));

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$sql = "
SELECT am.*, a.asset_code, a.item_name, c.name AS church_name,
       d1.name AS from_department_name,
       d2.name AS to_department_name,
       u.name AS moved_by_name
FROM asset_movements am
INNER JOIN assets a ON a.id = am.asset_id
LEFT JOIN churches c ON c.id = a.church_id
LEFT JOIN asset_departments d1 ON d1.id = am.from_department_id
LEFT JOIN asset_departments d2 ON d2.id = am.to_department_id
LEFT JOIN users u ON u.id = am.moved_by
WHERE 1
";
$types = '';
$params = [];

if ($churchId !== null) {
    $sql .= ' AND a.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($q !== '') {
    $sql .= ' AND (a.asset_code LIKE ? OR a.item_name LIKE ? OR d1.name LIKE ? OR d2.name LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY am.moved_at DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-history mr-2"></i>Asset Movements</h2>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back to Assets</a>
    </div>

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
                <div class="form-group col-md-4">
                    <label>Search</label>
                    <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Asset code, item, department...">
                </div>
                <div class="form-group col-md-2">
                    <button class="btn btn-outline-primary btn-block" type="submit">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetMovementTable">
                <thead class="thead-light">
                    <tr>
                        <th>Moved At</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Asset Code</th>
                        <th>Item</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Moved By</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $row['moved_at']) ?></td>
                        <?php if ($isSuper): ?><td><?= htmlspecialchars((string) ($row['church_name'] ?? '-')) ?></td><?php endif; ?>
                        <td><?= htmlspecialchars((string) $row['asset_code']) ?></td>
                        <td><?= htmlspecialchars((string) $row['item_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($row['from_department_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['to_department_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['moved_by_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['notes'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= $isSuper ? 8 : 7 ?>" class="text-center">No movement records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetMovementTable').DataTable({pageLength: 25, order:[[0,'desc']]});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
