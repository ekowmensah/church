<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('manage_asset_departments');

$selectedChurchId = null;
if (asset_is_super_admin()) {
    $selectedChurchId = isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null;
} else {
    $selectedChurchId = asset_current_church_id($conn);
}

$churches = [];
if (asset_is_super_admin()) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

$departments = asset_fetch_departments($conn, $selectedChurchId, true);

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-sitemap mr-2"></i>Asset Departments</h2>
        <a href="asset_department_form.php<?= $selectedChurchId ? '?church_id=' . (int) $selectedChurchId : '' ?>" class="btn btn-primary">
            <i class="fas fa-plus mr-1"></i> Add Department
        </a>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success">Department saved successfully.</div>
    <?php endif; ?>

    <?php if (isset($_GET['toggled'])): ?>
        <div class="alert alert-success">Department status updated.</div>
    <?php endif; ?>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="get" class="form-row align-items-end">
                <?php if (asset_is_super_admin()): ?>
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

    <div class="card shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="assetDepartmentsTable">
                <thead class="thead-light">
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <?php if (asset_is_super_admin()): ?><th>Church</th><?php endif; ?>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><?= htmlspecialchars($dept['name']) ?></td>
                        <td><?= htmlspecialchars((string) ($dept['description'] ?? '')) ?></td>
                        <?php if (asset_is_super_admin()): ?>
                            <td>
                                <?php
                                $churchName = '-';
                                if (isset($dept['church_id'])) {
                                    $stmtC = $conn->prepare('SELECT name FROM churches WHERE id = ? LIMIT 1');
                                    $cid = (int) $dept['church_id'];
                                    $stmtC->bind_param('i', $cid);
                                    $stmtC->execute();
                                    $churchRow = $stmtC->get_result()->fetch_assoc();
                                    $churchName = $churchRow['name'] ?? '-';
                                    $stmtC->close();
                                }
                                echo htmlspecialchars($churchName);
                                ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge badge-<?= (int) $dept['is_active'] === 1 ? 'success' : 'secondary' ?>">
                                <?= (int) $dept['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <a href="asset_department_form.php?id=<?= (int) $dept['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                            <a href="asset_department_toggle.php?id=<?= (int) $dept['id'] ?>" class="btn btn-sm btn-<?= (int) $dept['is_active'] === 1 ? 'secondary' : 'success' ?>" onclick="return confirm('Change department status?');">
                                <i class="fas fa-power-off"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($departments)): ?>
                    <tr><td colspan="<?= asset_is_super_admin() ? 5 : 4 ?>" class="text-center">No departments found.</td></tr>
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
