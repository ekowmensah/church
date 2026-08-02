<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

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

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-layer-group mr-2"></i>Item Categories</h2>
        <a href="asset_group_form.php<?= $selectedChurchId ? '?church_id=' . (int) $selectedChurchId : '' ?>" class="btn btn-primary">
            <i class="fas fa-plus mr-1"></i> Add Category
        </a>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Item category saved successfully.</div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
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

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetGroupsTable">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Quantity Rule</th>
                        <th>Default Qty</th>
                        <th>Description</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $group): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($group['group_code'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string) ($group['name'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(ucfirst((string) ($group['quantity_rule'] ?? 'fixed'))) ?></td>
                        <td><?= (int) ($group['default_quantity'] ?? 1) ?></td>
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
                            <a href="asset_group_form.php?id=<?= (int) $group['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                    <tr><td colspan="<?= $isSuper ? 8 : 7 ?>" class="text-center">No item categories found.</td></tr>
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
