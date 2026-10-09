<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

asset_require_permission('manage_asset_groups');

$isSuper = asset_is_super_admin();
$selectedChurchId = $isSuper
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null)
    : asset_current_church_id($conn);

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$groups = asset_fetch_groups($conn, $selectedChurchId, true);
$canCreateAssets = $isSuper || has_permission('create_asset');
$categoryUsage = [];
if ($groups) {
    $usageSql = "SELECT asset.asset_group_id,
                        COUNT(asset.id) AS asset_count,
                        COALESCE(SUM(asset.amount), 0) AS recorded_value,
                        COALESCE(SUM(unit_count.active_units), 0) AS active_unit_count
                   FROM assets asset
                   LEFT JOIN (
                       SELECT asset_id, COUNT(*) AS active_units
                         FROM asset_items
                        WHERE status = 'active'
                        GROUP BY asset_id
                   ) unit_count ON unit_count.asset_id = asset.id
                  WHERE asset.asset_group_id IS NOT NULL";
    $usageTypes = '';
    $usageParams = [];
    if ($selectedChurchId !== null) {
        $usageSql .= ' AND asset.church_id = ?';
        $usageTypes = 'i';
        $usageParams[] = $selectedChurchId;
    }
    $usageSql .= ' GROUP BY asset.asset_group_id';
    $usageStmt = $conn->prepare($usageSql);
    if ($usageTypes !== '') $usageStmt->bind_param($usageTypes, ...$usageParams);
    $usageStmt->execute();
    foreach ($usageStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $usageRow) {
        $categoryUsage[(int) $usageRow['asset_group_id']] = $usageRow;
    }
    $usageStmt->close();
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <?php ob_start(); ?><a href="asset_group_form.php<?= $selectedChurchId ? '?church_id=' . (int) $selectedChurchId : '' ?>" class="btn btn-warning"><i class="fas fa-plus mr-1"></i>Add category</a><?php $heroActions = ob_get_clean(); render_asset_workspace_hero('Register classification', 'Asset Categories', 'Categories classify every asset, supply the identity-code segment and show the records and physical units registered beneath them.', 'fa-layer-group', $heroActions); ?>
    <?php render_asset_workspace_nav('register', $selectedChurchId); ?>

    <div class="alert alert-info border-0 shadow-sm">
        <strong>How the register is structured:</strong>
        a category classifies assets (for example <em>Vehicles</em>), the asset name holds common details (for example <em>Toyota Hiace</em>), and every actual vehicle is registered as its own uniquely numbered physical unit.
        Creating a category never creates an asset or changes stock quantity.
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Asset category saved successfully.</div>
    <?php endif; ?>

    <div class="card asset-panel mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if ($isSuper): ?>
                    <div class="form-group col-md-4">
                        <label for="church_id">Church</label>
                        <select name="church_id" id="church_id" class="form-control">
                            <option value="">All Churches</option>
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $selectedChurchId === (int) $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-block">Filter</button>
                </div>
                <div class="form-group col-md-2">
                    <a href="asset_group_list.php" class="btn btn-outline-secondary btn-block">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card asset-panel">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetGroupsTable">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Asset Category</th>
                        <th>Asset Names / Models</th>
                        <th>Active Physical Units</th>
                        <th>Recorded Value</th>
                        <th>Description</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <?php $usage = $categoryUsage[(int) $group['id']] ?? ['asset_count' => 0, 'active_unit_count' => 0, 'recorded_value' => 0]; ?>
                    <tr>
                        <td><span class="badge badge-light border p-2"><?= htmlspecialchars((string) ($group['group_code'] ?? '')) ?></span></td>
                        <td><strong><?= htmlspecialchars((string) ($group['name'] ?? '')) ?></strong><small class="d-block text-muted">Used in asset identity and register filters</small></td>
                        <td><a href="asset_list.php?<?= http_build_query(array_filter(['church_id' => $selectedChurchId, 'asset_group_id' => (int) $group['id']])) ?>" class="font-weight-bold"><?= number_format((int) $usage['asset_count']) ?></a></td>
                        <td><?= number_format((int) $usage['active_unit_count']) ?></td>
                        <td><?= number_format((float) $usage['recorded_value'], 2) ?></td>
                        <td><?= htmlspecialchars((string) ($group['description'] ?? '')) ?></td>
                        <?php if ($isSuper): ?>
                            <td>
                                <?php
                                $churchName = '-';
                                foreach ($churches as $church) {
                                    if ((int) $church['id'] === (int) ($group['church_id'] ?? 0)) {
                                        $churchName = (string) $church['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($churchName);
                                ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge badge-<?= (int) ($group['is_active'] ?? 0) === 1 ? 'success' : 'secondary' ?>">
                                <?= (int) ($group['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <a href="asset_group_form.php?id=<?= (int) $group['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit category" aria-label="Edit <?= htmlspecialchars((string) $group['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-edit"></i></a>
                            <?php if ($canCreateAssets && (int) ($group['is_active'] ?? 0) === 1): ?><a href="asset_form.php?<?= http_build_query(array_filter(['church_id' => (int) $group['church_id'], 'asset_group_id' => (int) $group['id']])) ?>" class="btn btn-sm btn-primary" title="Register a physical asset in this category"><i class="fas fa-plus mr-1"></i>Physical Asset</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                    <tr><td colspan="<?= $isSuper ? 9 : 8 ?>" class="asset-empty-state"><i class="fas fa-layer-group"></i>No asset categories match this church. Create the first category before registering assets.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetGroupsTable').DataTable({pageLength: 25});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
