<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('create_asset');

if (!asset_item_tracking_available($conn)) {
    http_response_code(409);
    exit('Physical-item tracking is not available. Run Phase 0024 first.');
}

$assetId = (int) ($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);
if ($assetId <= 0) {
    header('Location: asset_list.php');
    exit;
}

$isSuper = asset_is_super_admin();
$scopeChurchId = $isSuper ? null : asset_current_church_id($conn);
$sql = 'SELECT asset.*, church.name AS church_name
        FROM assets asset
        JOIN churches church ON church.id = asset.church_id
        WHERE asset.id = ?';
if (!$isSuper) {
    $sql .= ' AND asset.church_id = ?';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $assetId);
} else {
    $stmt->bind_param('ii', $assetId, $scopeChurchId);
}
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    http_response_code(404);
    exit('Asset category not found.');
}

$churchId = (int) $asset['church_id'];
$departments = asset_fetch_departments($conn, $churchId, false);
$departmentId = (int) ($_POST['department_id'] ?? $asset['department_id'] ?? 0);
$serialNumber = trim((string) ($_POST['serial_number'] ?? ''));
$conditionStatus = trim((string) ($_POST['condition_status'] ?? $asset['condition_status'] ?? 'Good'));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $validDepartment = false;
    foreach ($departments as $department) {
        if ((int) $department['id'] === $departmentId) {
            $validDepartment = true;
            break;
        }
    }

    $validConditions = ['New', 'Good', 'Fair', 'Poor', 'Under Maintenance', 'Damaged', 'Obsolete', 'Condemned'];
    if (!$validDepartment) {
        $error = 'Select a valid department.';
    } elseif (!in_array($conditionStatus, $validConditions, true)) {
        $error = 'Select a valid condition.';
    } elseif ($serialNumber !== '' && strlen($serialNumber) > 120) {
        $error = 'Serial number must not exceed 120 characters.';
    }

    if ($error === '') {
        $conn->begin_transaction();
        try {
            $itemNumber = asset_generate_item_number($conn, $asset, $departmentId);
            $registeredBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $serialDb = $serialNumber !== '' ? $serialNumber : null;
            $stmt = $conn->prepare(
                "INSERT INTO asset_items
                    (church_id, asset_id, item_number, department_id, serial_number,
                     condition_status, status, lifecycle_status, registered_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', 'in_use', ?)"
            );
            $stmt->bind_param(
                'iisissi',
                $churchId,
                $assetId,
                $itemNumber,
                $departmentId,
                $serialDb,
                $conditionStatus,
                $registeredBy
            );
            $stmt->execute();
            $itemId = (int) $conn->insert_id;
            $stmt->close();

            asset_sync_parent_from_items($conn, $assetId);
            $conn->commit();

            asset_log_action('asset_item_registered', 'asset_item', $itemId, [
                'asset_id' => $assetId,
                'asset_code' => (string) $asset['asset_code'],
                'item_number' => $itemNumber,
                'church_id' => $churchId,
                'department_id' => $departmentId,
                'serial_number' => $serialDb,
            ]);

            header('Location: asset_view.php?id=' . $assetId . '&tab=items&item_saved=1');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e instanceof mysqli_sql_exception && (int) $e->getCode() === 1062
                ? 'That physical item number or serial number already exists.'
                : $e->getMessage();
        }
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-plus-circle mr-2"></i>Register Physical Item</h2>
            <div class="text-muted"><?= htmlspecialchars((string) $asset['item_name']) ?> · <?= htmlspecialchars((string) $asset['asset_code']) ?> · <?= htmlspecialchars((string) $asset['church_name']) ?></div>
        </div>
        <a href="asset_view.php?id=<?= $assetId ?>&tab=items" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i>Back</a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted">Register one physical item at a time. The item number is generated automatically and the category quantity increases only after this item is saved.</p>
            <form method="post">
                <input type="hidden" name="asset_id" value="<?= $assetId ?>">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label for="department_id">Department <span class="text-danger">*</span></label>
                        <select class="form-control" id="department_id" name="department_id" required>
                            <option value="">-- Select department --</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $department['name']) ?><?= !empty($department['department_code']) ? ' (' . htmlspecialchars((string) $department['department_code']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label for="serial_number">Serial Number</label>
                        <input type="text" class="form-control" id="serial_number" name="serial_number" maxlength="120" value="<?= htmlspecialchars($serialNumber) ?>">
                    </div>
                    <div class="form-group col-md-6">
                        <label for="condition_status">Condition <span class="text-danger">*</span></label>
                        <select class="form-control" id="condition_status" name="condition_status" required>
                            <?php foreach (['New', 'Good', 'Fair', 'Poor', 'Under Maintenance', 'Damaged', 'Obsolete', 'Condemned'] as $condition): ?>
                                <option value="<?= htmlspecialchars($condition) ?>" <?= $conditionStatus === $condition ? 'selected' : '' ?>><?= htmlspecialchars($condition) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i>Register Item</button>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
