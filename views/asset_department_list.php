<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

asset_require_permission('manage_asset_departments');

$isSuper = asset_is_super_admin();
$selectedChurchId = null;
if ($isSuper) {
    $selectedChurchId = isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null;
} else {
    $selectedChurchId = asset_current_church_id($conn);
}

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}
$churchNames = [];
foreach ($churches as $church) $churchNames[(int) $church['id']] = (string) $church['name'];

$departments = asset_fetch_departments($conn, $selectedChurchId, true);

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <?php ob_start(); ?><a href="asset_department_form.php<?= $selectedChurchId ? '?church_id=' . (int) $selectedChurchId : '' ?>" class="btn btn-warning"><i class="fas fa-plus mr-1"></i>Add department</a><?php $heroActions = ob_get_clean(); render_asset_workspace_hero('Location governance', 'Asset Departments', 'Maintain the accountable departments used in asset numbers, transfers and reporting.', 'fa-sitemap', $heroActions); ?>
    <?php render_asset_workspace_nav('register', $selectedChurchId); ?>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Department saved successfully.</div>
    <?php endif; ?>

    <?php if (isset($_GET['toggled'])): ?>
        <div class="alert alert-success">Department status updated.</div>
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
                                    <?= htmlspecialchars($church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-block">Filter</button>
                </div>
                <div class="form-group col-md-2">
                    <a href="asset_department_list.php" class="btn btn-outline-secondary btn-block">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card asset-panel">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetDepartmentsTable">
                <thead class="thead-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Description</th>
                        <?php if ($isSuper): ?><th>Church</th><?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($dept['department_code'] ?? '')) ?></td>
                        <td><?= htmlspecialchars($dept['name']) ?></td>
                        <td><?= htmlspecialchars((string) ($dept['description'] ?? '')) ?></td>
                        <?php if ($isSuper): ?>
                            <td><?= htmlspecialchars((string) ($churchNames[(int) ($dept['church_id'] ?? 0)] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endif; ?>
                        <td>
                            <span class="badge badge-<?= (int) $dept['is_active'] === 1 ? 'success' : 'secondary' ?>">
                                <?= (int) $dept['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <a href="asset_department_form.php?id=<?= (int) $dept['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit department"><i class="fas fa-edit"></i></a>
                            <form method="post" action="asset_department_toggle.php" class="d-inline" onsubmit="return confirm('Change department status?');"><?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $dept['id'] ?>"><button type="submit" class="btn btn-sm btn-outline-<?= (int) $dept['is_active'] === 1 ? 'secondary' : 'success' ?>" title="<?= (int) $dept['is_active'] === 1 ? 'Deactivate' : 'Activate' ?> department"><i class="fas fa-power-off"></i></button></form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="<?= $isSuper ? 6 : 5 ?>" class="asset-empty-state"><i class="fas fa-sitemap"></i>No asset departments match this church.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(function(){
  if ($.fn.DataTable) {
    $('#assetDepartmentsTable').DataTable({pageLength: 25});
  }
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
