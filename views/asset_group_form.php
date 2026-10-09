<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

asset_require_permission('manage_asset_groups');

if (!asset_table_exists($conn, 'asset_groups')) {
    http_response_code(404);
    exit('Asset categories table not available. Run the latest assets migration first.');
}

$isEdit = isset($_GET['id']) && (int) $_GET['id'] > 0;
$groupId = $isEdit ? (int) $_GET['id'] : 0;
$error = '';

$isSuper = asset_is_super_admin();
$churchId = $isSuper
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null)
    : asset_current_church_id($conn);

$name = '';
$groupCode = '';
$defaultQuantity = 1;
$quantityRule = 'fixed';
$description = '';
$isActive = 1;
$usageCount = 0;
$originalChurchId = $churchId;
$originalGroupCode = '';

if ($isEdit) {
    $sql = 'SELECT * FROM asset_groups WHERE id = ?';
    if (!$isSuper) {
        $sql .= ' AND church_id = ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if ($isSuper) {
        $stmt->bind_param('i', $groupId);
    } else {
        $stmt->bind_param('ii', $groupId, $churchId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        exit('Asset category not found.');
    }

    $churchId = (int) $row['church_id'];
    $originalChurchId = $churchId;
    $name = (string) ($row['name'] ?? '');
    $groupCode = (string) ($row['group_code'] ?? '');
    $originalGroupCode = $groupCode;
    $defaultQuantity = (int) ($row['default_quantity'] ?? 1);
    $quantityRule = (string) ($row['quantity_rule'] ?? 'fixed');
    $description = (string) ($row['description'] ?? '');
    $isActive = (int) ($row['is_active'] ?? 1);

    $usageStmt = $conn->prepare('SELECT COUNT(*) AS total FROM assets WHERE asset_group_id = ?');
    $usageStmt->bind_param('i', $groupId);
    $usageStmt->execute();
    $usageCount = (int) ($usageStmt->get_result()->fetch_assoc()['total'] ?? 0);
    $usageStmt->close();
}

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your form expired. Refresh the page and try again.');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $groupCode = strtoupper(trim((string) ($_POST['group_code'] ?? '')));
    // Modern registration tracks every asset individually. Retain the
    // legacy columns only as compatibility values, not as a second workflow.
    $defaultQuantity = 1;
    $quantityRule = 'fixed';
    $description = trim((string) ($_POST['description'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($isSuper) {
        $churchId = (int) ($_POST['church_id'] ?? 0);
    }
    if ($isEdit && $usageCount > 0) {
        $churchId = (int) $originalChurchId;
        $groupCode = (string) $originalGroupCode;
    }

    if ($churchId === null || $churchId <= 0) {
        $error = 'Please select a church.';
    } elseif ($name === '') {
        $error = 'Asset category name is required.';
    } elseif ($groupCode === '') {
        $error = 'Category code is required.';
    } elseif (!preg_match('/^[A-Z0-9]{2,10}$/', $groupCode)) {
        $error = 'Category code must contain 2 to 10 uppercase letters or numbers.';
    } else {
        $conn->begin_transaction();
        try {
            if ($isEdit) {
                $sql = 'UPDATE asset_groups SET church_id = ?, name = ?, group_code = ?, default_quantity = 1, quantity_rule = \'fixed\', description = ?, is_active = ? WHERE id = ?';
                if (!$isSuper) {
                    $sql .= ' AND church_id = ?';
                }
                $stmt = $conn->prepare($sql);
                if ($isSuper) {
                    $stmt->bind_param('isssii', $churchId, $name, $groupCode, $description, $isActive, $groupId);
                } else {
                    $stmt->bind_param('isssiii', $churchId, $name, $groupCode, $description, $isActive, $groupId, $churchId);
                }
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare('UPDATE assets SET item_group = ? WHERE asset_group_id = ? AND church_id = ?');
                $stmt->bind_param('sii', $name, $groupId, $churchId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                asset_log_action('asset_group_update', 'asset_group', $groupId, [
                    'church_id' => $churchId,
                    'name' => $name,
                    'group_code' => $groupCode,
                    'linked_asset_count' => $usageCount,
                ]);
                header('Location: asset_group_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            } else {
                $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $stmt = $conn->prepare("INSERT INTO asset_groups (church_id, name, group_code, default_quantity, quantity_rule, description, is_active, created_by) VALUES (?, ?, ?, 1, 'fixed', ?, ?, ?)");
                $stmt->bind_param('isssii', $churchId, $name, $groupCode, $description, $isActive, $createdBy);
                $stmt->execute();
                $newId = (int) $conn->insert_id;
                $stmt->close();
                $conn->commit();
                asset_log_action('asset_group_create', 'asset_group', $newId, [
                    'church_id' => $churchId,
                    'name' => $name,
                    'group_code' => $groupCode,
                ]);
                header('Location: asset_group_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
        } catch (Throwable $exception) {
            $conn->rollback();
            error_log('Asset category save failed: ' . $exception->getMessage());
            $error = $exception instanceof mysqli_sql_exception && (int) $exception->getCode() === 1062
                ? 'That category name or code already exists for this church.'
                : 'The asset category could not be saved. Please retry.';
        }
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-layer-group mr-2"></i><?= $isEdit ? 'Edit' : 'Add' ?> Asset Category</h2>
        <a href="asset_group_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>

    <?php render_asset_workspace_nav('register', $churchId); ?>
    <div class="card asset-panel asset-form-shell">
        <div class="card-header"><strong>Category definition</strong><small class="d-block text-muted">Every asset must belong to one category. Its code becomes a stable segment in generated asset numbers.</small></div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?= csrf_input() ?>
                <?php if ($isSuper): ?>
                    <div class="form-group">
                        <label for="church_id">Church <span class="text-danger">*</span></label>
                        <select class="form-control" id="church_id" name="church_id" required <?= $isEdit && $usageCount > 0 ? 'disabled' : '' ?>>
                            <option value="">-- Select Church --</option>
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($isEdit && $usageCount > 0): ?><input type="hidden" name="church_id" value="<?= (int) $churchId ?>"><small class="text-muted">The church cannot change after assets use this category.</small><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="name">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="group_code">Category Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase" id="group_code" name="group_code" value="<?= htmlspecialchars($groupCode) ?>" minlength="2" maxlength="10" pattern="[A-Za-z0-9]{2,10}" required <?= $isEdit && $usageCount > 0 ? 'readonly' : '' ?>>
                        <small class="text-muted"><?= $isEdit && $usageCount > 0 ? 'Locked because ' . number_format($usageCount) . ' asset record(s) already use this identity segment.' : 'Used in asset codes, for example COM or TRM.' ?></small>
                    </div>
                </div>

                <div class="alert alert-light border">
                    <strong>One asset, one number:</strong> quantities are not configured on categories. Register every asset individually under its category and responsible department.
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" <?= $isActive === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="form-group col-md-6"><label>Linked assets</label><div class="form-control bg-light"><?= number_format($usageCount) ?> asset record(s)</div></div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <div class="asset-action-bar"><a href="asset_group_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary">Cancel</a><button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save category</button></div>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
