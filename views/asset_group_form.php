<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('manage_asset_groups');

if (!asset_table_exists($conn, 'asset_groups')) {
    http_response_code(404);
    exit('Item categories table not available. Run the latest assets migration first.');
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
        exit('Item category not found.');
    }

    $churchId = (int) $row['church_id'];
    $name = (string) ($row['name'] ?? '');
    $groupCode = (string) ($row['group_code'] ?? '');
    $defaultQuantity = (int) ($row['default_quantity'] ?? 1);
    $quantityRule = (string) ($row['quantity_rule'] ?? 'fixed');
    $description = (string) ($row['description'] ?? '');
    $isActive = (int) ($row['is_active'] ?? 1);
}

$churches = [];
if ($isSuper) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $groupCode = strtoupper(trim((string) ($_POST['group_code'] ?? '')));
    $defaultQuantity = max(1, (int) ($_POST['default_quantity'] ?? 1));
    $quantityRule = trim((string) ($_POST['quantity_rule'] ?? 'fixed'));
    $description = trim((string) ($_POST['description'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($isSuper) {
        $churchId = (int) ($_POST['church_id'] ?? 0);
    }

    if ($churchId === null || $churchId <= 0) {
        $error = 'Please select a church.';
    } elseif ($name === '') {
        $error = 'Item category name is required.';
    } elseif ($groupCode === '') {
        $error = 'Category code is required.';
    } elseif (!in_array($quantityRule, ['fixed', 'manual'], true)) {
        $error = 'Invalid quantity rule.';
    } else {
        if ($isEdit) {
            $sql = 'UPDATE asset_groups SET church_id = ?, name = ?, group_code = ?, default_quantity = ?, quantity_rule = ?, description = ?, is_active = ? WHERE id = ?';
            if (!$isSuper) {
                $sql .= ' AND church_id = ?';
            }
            $stmt = $conn->prepare($sql);
            if ($isSuper) {
                $stmt->bind_param('ississii', $churchId, $name, $groupCode, $defaultQuantity, $quantityRule, $description, $isActive, $groupId);
            } else {
                $stmt->bind_param('ississiii', $churchId, $name, $groupCode, $defaultQuantity, $quantityRule, $description, $isActive, $groupId, $churchId);
            }
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                asset_log_action('asset_group_update', 'asset_group', $groupId, [
                    'church_id' => $churchId,
                    'name' => $name,
                    'group_code' => $groupCode,
                ]);
                header('Location: asset_group_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to update item category. The code or name may already exist for this church.';
        } else {
            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $stmt = $conn->prepare('INSERT INTO asset_groups (church_id, name, group_code, default_quantity, quantity_rule, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ississii', $churchId, $name, $groupCode, $defaultQuantity, $quantityRule, $description, $isActive, $createdBy);
            $ok = $stmt->execute();
            $newId = (int) $conn->insert_id;
            $stmt->close();
            if ($ok) {
                asset_log_action('asset_group_create', 'asset_group', $newId, [
                    'church_id' => $churchId,
                    'name' => $name,
                    'group_code' => $groupCode,
                ]);
                header('Location: asset_group_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to create item category. The code or name may already exist for this church.';
        }
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-layer-group mr-2"></i><?= $isEdit ? 'Edit' : 'Add' ?> Item Category</h2>
        <a href="asset_group_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <?php if ($isSuper): ?>
                    <div class="form-group">
                        <label for="church_id">Church <span class="text-danger">*</span></label>
                        <select class="form-control" id="church_id" name="church_id" required>
                            <option value="">-- Select Church --</option>
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="name">Category Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="group_code">Category Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase" id="group_code" name="group_code" value="<?= htmlspecialchars($groupCode) ?>" maxlength="10" required>
                        <small class="text-muted">Used in asset codes, for example `COM` or `TRM`.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label for="quantity_rule">Quantity Rule <span class="text-danger">*</span></label>
                        <select class="form-control" id="quantity_rule" name="quantity_rule" required>
                            <option value="fixed" <?= $quantityRule === 'fixed' ? 'selected' : '' ?>>Fixed Quantity</option>
                            <option value="manual" <?= $quantityRule === 'manual' ? 'selected' : '' ?>>Manual Quantity</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label for="default_quantity">Default Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="default_quantity" name="default_quantity" value="<?= (int) $defaultQuantity ?>" min="1" required>
                    </div>
                    <div class="form-group col-md-4 d-flex align-items-center">
                        <div class="form-check mt-4">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" <?= $isActive === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Category</button>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
