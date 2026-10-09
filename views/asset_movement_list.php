<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

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
       item.item_number,
       d1.name AS from_department_name,
       d2.name AS to_department_name,
       u.name AS moved_by_name
FROM asset_movements am
INNER JOIN assets a ON a.id = am.asset_id
LEFT JOIN asset_items item ON item.id = am.asset_item_id
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
    $sql .= ' AND (a.asset_code LIKE ? OR item.item_number LIKE ? OR a.item_name LIKE ? OR d1.name LIKE ? OR d2.name LIKE ?)';
    $types .= 'sssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$pageData = report_paginate_query($conn, $sql . ' ORDER BY am.moved_at DESC', $types, $params, 25);
$rows = $pageData['result']->fetch_all(MYSQLI_ASSOC);
$pageData['statement']->close();

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <section class="asset-hero p-4 mb-3"><div class="d-flex justify-content-between align-items-center"><div><div class="eyebrow">Chain of location</div><h2 class="mb-1"><i class="fas fa-exchange-alt mr-2"></i>Asset Movements</h2><p class="mb-0">Every asset transfer, previous department, destination and responsible officer.</p></div></div></section>
    <?php render_asset_workspace_nav('movements', $churchId); ?>

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
                    <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($q) ?>" placeholder="Asset number, category, department...">
                </div>
                <div class="form-group col-md-2">
                    <button class="btn btn-outline-primary btn-block" type="submit">Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm asset-panel">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetMovementTable">
                <thead class="thead-light">
                    <tr>
                        <th>Moved At</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Asset Code</th>
                        <th>Asset Number</th>
                        <th>Asset</th>
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
                        <td><?= htmlspecialchars((string) ($row['item_number'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) $row['item_name']) ?></td>
                        <td><?= htmlspecialchars((string) ($row['from_department_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['to_department_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['moved_by_name'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['notes'] ?? '')) ?> <?php if (has_permission('view_asset_detail') || asset_is_super_admin()): ?><a href="asset_view.php?id=<?= (int) $row['asset_id'] ?>" class="btn btn-sm btn-outline-dark ml-1"><i class="fas fa-eye"></i></a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= $isSuper ? 9 : 8 ?>" class="text-center">No movement records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php report_render_server_pagination($pageData['total_rows'], $pageData['page'], $pageData['per_page'], 'Asset movement pages'); ?>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
