<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

$isEdit = isset($_GET['id']) && (int) $_GET['id'] > 0;
asset_require_permission($isEdit ? 'edit_asset' : 'create_asset');

$isSuper = asset_is_super_admin();
$conditions = asset_condition_options();
$lifecycleOptions = asset_lifecycle_options();
$hasLifecycle = asset_can_use_lifecycle($conn);
$hasMaintenanceFields = asset_can_use_maintenance_fields($conn);
$assetId = $isEdit ? (int) $_GET['id'] : 0;
$error = '';

$churchId = $isSuper ? (isset($_GET['church_id']) ? (int) $_GET['church_id'] : 0) : (int) asset_current_church_id($conn);
$departmentId = 0;
$itemGroup = '';
$itemName = '';
$purchaseDate = '';
$quantity = 1;
$receiptOrSerial = '';
$amount = '';
$conditionStatus = 'New';
$allocationNote = '';
$status = 'active';
$assetCode = '';
$lifecycleStatus = 'in_use';
$existingLifecycleStatus = '';
$warrantyExpiryDate = '';
$lastMaintenanceDate = '';
$nextMaintenanceDate = '';
$originalUpdatedAt = '';

if ($isEdit) {
    $sql = 'SELECT * FROM assets WHERE id = ?';
    if (!$isSuper) {
        $sql .= ' AND church_id = ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if ($isSuper) {
        $stmt->bind_param('i', $assetId);
    } else {
        $stmt->bind_param('ii', $assetId, $churchId);
    }
    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$asset) {
        http_response_code(404);
        exit('Asset not found.');
    }

    $churchId = (int) $asset['church_id'];
    $departmentId = (int) ($asset['department_id'] ?? 0);
    $itemGroup = (string) ($asset['item_group'] ?? '');
    $itemName = (string) $asset['item_name'];
    $purchaseDate = (string) ($asset['purchase_date'] ?? '');
    $quantity = (int) $asset['quantity'];
    $receiptOrSerial = (string) ($asset['receipt_or_serial_number'] ?? '');
    $amount = $asset['amount'] !== null ? (string) $asset['amount'] : '';
    $conditionStatus = (string) $asset['condition_status'];
    $allocationNote = (string) ($asset['allocation_note'] ?? '');
    $status = (string) ($asset['status'] ?? 'active');
    $assetCode = (string) ($asset['asset_code'] ?? '');
    $originalUpdatedAt = (string) ($asset['updated_at'] ?? '');
    if ($hasMaintenanceFields) {
        $warrantyExpiryDate = (string) ($asset['warranty_expiry_date'] ?? '');
        $lastMaintenanceDate = (string) ($asset['last_maintenance_date'] ?? '');
        $nextMaintenanceDate = (string) ($asset['next_maintenance_date'] ?? '');
    }

    if ($hasLifecycle) {
        $existingLifecycleStatus = (string) ($asset['lifecycle_status'] ?? '');
        $lifecycleStatus = $existingLifecycleStatus !== '' ? $existingLifecycleStatus : asset_default_lifecycle($status, $conditionStatus);
    } else {
        $lifecycleStatus = asset_default_lifecycle($status, $conditionStatus);
    }
}

$churches = [];
if ($isSuper) {
    $churchRes = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $churchRes->fetch_assoc()) {
        $churches[] = $row;
    }
}

$departments = asset_fetch_departments($conn, $churchId > 0 ? $churchId : null, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $churchId = $isSuper ? (int) ($_POST['church_id'] ?? 0) : (int) asset_current_church_id($conn);
    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $itemGroup = trim((string) ($_POST['item_group'] ?? ''));
    $itemName = trim((string) ($_POST['item_name'] ?? ''));
    $purchaseDate = trim((string) ($_POST['purchase_date'] ?? ''));
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $receiptOrSerial = trim((string) ($_POST['receipt_or_serial_number'] ?? ''));
    $amount = trim((string) ($_POST['amount'] ?? ''));
    $conditionStatus = trim((string) ($_POST['condition_status'] ?? ''));
    $allocationNote = trim((string) ($_POST['allocation_note'] ?? ''));
    $status = trim((string) ($_POST['status'] ?? 'active'));
    $warrantyExpiryDate = trim((string) ($_POST['warranty_expiry_date'] ?? ''));
    $lastMaintenanceDate = trim((string) ($_POST['last_maintenance_date'] ?? ''));
    $nextMaintenanceDate = trim((string) ($_POST['next_maintenance_date'] ?? ''));
    $postedOriginalUpdatedAt = trim((string) ($_POST['original_updated_at'] ?? ''));
    $lifecycleStatus = $hasLifecycle
        ? trim((string) ($_POST['lifecycle_status'] ?? ''))
        : asset_default_lifecycle($status, $conditionStatus);

    if ($status === 'disposed') {
        $lifecycleStatus = 'disposed';
    }
    if ($conditionStatus === 'Disposed') {
        $status = 'disposed';
        $lifecycleStatus = 'disposed';
    }

    if ($churchId <= 0) {
        $error = 'Church is required.';
    } elseif ($departmentId <= 0) {
        $error = 'Department is required.';
    } elseif ($itemName === '') {
        $error = 'Item name is required.';
    } elseif ($quantity <= 0) {
        $error = 'Quantity must be at least 1.';
    } elseif (!in_array($conditionStatus, $conditions, true)) {
        $error = 'Invalid condition status.';
    } elseif (!in_array($status, ['active', 'disposed'], true)) {
        $error = 'Invalid asset status.';
    } elseif ($hasLifecycle && !in_array($lifecycleStatus, $lifecycleOptions, true)) {
        $error = 'Invalid lifecycle status.';
    } elseif ($hasLifecycle && $isEdit && !asset_validate_lifecycle_transition($existingLifecycleStatus, $lifecycleStatus)) {
        $error = 'Invalid lifecycle transition from ' . asset_lifecycle_label($existingLifecycleStatus) . ' to ' . asset_lifecycle_label($lifecycleStatus) . '.';
    } elseif ($isEdit && $postedOriginalUpdatedAt !== '' && $postedOriginalUpdatedAt !== $originalUpdatedAt) {
        $error = 'This asset was updated by another user. Reload and try again.';
    }

    if (!$error) {
        $purchaseDateDb = $purchaseDate !== '' ? $purchaseDate : null;
        $amountDb = $amount !== '' ? (float) $amount : null;
        $warrantyExpiryDb = $warrantyExpiryDate !== '' ? $warrantyExpiryDate : null;
        $lastMaintenanceDb = $lastMaintenanceDate !== '' ? $lastMaintenanceDate : null;
        $nextMaintenanceDb = $nextMaintenanceDate !== '' ? $nextMaintenanceDate : null;

        if ($isEdit) {
            $before = [
                'church_id' => $asset['church_id'] ?? null,
                'department_id' => $asset['department_id'] ?? null,
                'item_group' => $asset['item_group'] ?? null,
                'item_name' => $asset['item_name'] ?? null,
                'purchase_date' => $asset['purchase_date'] ?? null,
                'quantity' => $asset['quantity'] ?? null,
                'receipt_or_serial_number' => $asset['receipt_or_serial_number'] ?? null,
                'amount' => $asset['amount'] ?? null,
                'condition_status' => $asset['condition_status'] ?? null,
                'allocation_note' => $asset['allocation_note'] ?? null,
                'status' => $asset['status'] ?? null,
                'lifecycle_status' => $hasLifecycle ? ($asset['lifecycle_status'] ?? null) : null,
                'warranty_expiry_date' => $hasMaintenanceFields ? ($asset['warranty_expiry_date'] ?? null) : null,
                'last_maintenance_date' => $hasMaintenanceFields ? ($asset['last_maintenance_date'] ?? null) : null,
                'next_maintenance_date' => $hasMaintenanceFields ? ($asset['next_maintenance_date'] ?? null) : null,
            ];

            if ($hasLifecycle && $hasMaintenanceFields) {
                $stmt = $conn->prepare('UPDATE assets SET church_id=?, department_id=?, item_group=?, item_name=?, purchase_date=?, warranty_expiry_date=?, last_maintenance_date=?, next_maintenance_date=?, quantity=?, receipt_or_serial_number=?, amount=?, condition_status=?, allocation_note=?, status=?, lifecycle_status=? WHERE id=?');
                $stmt->bind_param(
                    'iissssssisdssssi',
                    $churchId,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $warrantyExpiryDb,
                    $lastMaintenanceDb,
                    $nextMaintenanceDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $lifecycleStatus,
                    $assetId
                );
            } elseif ($hasLifecycle) {
                $stmt = $conn->prepare('UPDATE assets SET church_id=?, department_id=?, item_group=?, item_name=?, purchase_date=?, quantity=?, receipt_or_serial_number=?, amount=?, condition_status=?, allocation_note=?, status=?, lifecycle_status=? WHERE id=?');
                $stmt->bind_param(
                    'iisssisdssssi',
                    $churchId,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $lifecycleStatus,
                    $assetId
                );
            } elseif ($hasMaintenanceFields) {
                $stmt = $conn->prepare('UPDATE assets SET church_id=?, department_id=?, item_group=?, item_name=?, purchase_date=?, warranty_expiry_date=?, last_maintenance_date=?, next_maintenance_date=?, quantity=?, receipt_or_serial_number=?, amount=?, condition_status=?, allocation_note=?, status=? WHERE id=?');
                $stmt->bind_param(
                    'iissssssisdsssi',
                    $churchId,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $warrantyExpiryDb,
                    $lastMaintenanceDb,
                    $nextMaintenanceDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $assetId
                );
            } else {
                $stmt = $conn->prepare('UPDATE assets SET church_id=?, department_id=?, item_group=?, item_name=?, purchase_date=?, quantity=?, receipt_or_serial_number=?, amount=?, condition_status=?, allocation_note=?, status=? WHERE id=?');
                $stmt->bind_param(
                    'iisssisdsssi',
                    $churchId,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $assetId
                );
            }
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                $after = [
                    'church_id' => $churchId,
                    'department_id' => $departmentId,
                    'item_group' => $itemGroup,
                    'item_name' => $itemName,
                    'purchase_date' => $purchaseDateDb,
                    'quantity' => $quantity,
                    'receipt_or_serial_number' => $receiptOrSerial,
                    'amount' => $amountDb,
                    'condition_status' => $conditionStatus,
                    'allocation_note' => $allocationNote,
                    'status' => $status,
                    'lifecycle_status' => $hasLifecycle ? $lifecycleStatus : null,
                    'warranty_expiry_date' => $hasMaintenanceFields ? $warrantyExpiryDb : null,
                    'last_maintenance_date' => $hasMaintenanceFields ? $lastMaintenanceDb : null,
                    'next_maintenance_date' => $hasMaintenanceFields ? $nextMaintenanceDb : null,
                ];

                asset_log_action('asset_update', 'asset', $assetId, [
                    'asset_id' => $assetId,
                    'asset_code' => $assetCode,
                    'church_id' => $churchId,
                ], $before, $after);

                header('Location: asset_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to update asset.';
        } else {
            $assetCode = asset_generate_code($conn, $churchId, $departmentId, $itemGroup);
            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

            if ($hasLifecycle && $hasMaintenanceFields) {
                $stmt = $conn->prepare('INSERT INTO assets (church_id, asset_code, department_id, item_group, item_name, purchase_date, warranty_expiry_date, last_maintenance_date, next_maintenance_date, quantity, receipt_or_serial_number, amount, condition_status, allocation_note, status, lifecycle_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param(
                    'isissssssisdssssi',
                    $churchId,
                    $assetCode,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $warrantyExpiryDb,
                    $lastMaintenanceDb,
                    $nextMaintenanceDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $lifecycleStatus,
                    $createdBy
                );
            } elseif ($hasLifecycle) {
                $stmt = $conn->prepare('INSERT INTO assets (church_id, asset_code, department_id, item_group, item_name, purchase_date, quantity, receipt_or_serial_number, amount, condition_status, allocation_note, status, lifecycle_status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param(
                    'isisssisdssssi',
                    $churchId,
                    $assetCode,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $lifecycleStatus,
                    $createdBy
                );
            } elseif ($hasMaintenanceFields) {
                $stmt = $conn->prepare('INSERT INTO assets (church_id, asset_code, department_id, item_group, item_name, purchase_date, warranty_expiry_date, last_maintenance_date, next_maintenance_date, quantity, receipt_or_serial_number, amount, condition_status, allocation_note, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param(
                    'isissssssisdsssi',
                    $churchId,
                    $assetCode,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $warrantyExpiryDb,
                    $lastMaintenanceDb,
                    $nextMaintenanceDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $createdBy
                );
            } else {
                $stmt = $conn->prepare('INSERT INTO assets (church_id, asset_code, department_id, item_group, item_name, purchase_date, quantity, receipt_or_serial_number, amount, condition_status, allocation_note, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param(
                    'isisssisdsssi',
                    $churchId,
                    $assetCode,
                    $departmentId,
                    $itemGroup,
                    $itemName,
                    $purchaseDateDb,
                    $quantity,
                    $receiptOrSerial,
                    $amountDb,
                    $conditionStatus,
                    $allocationNote,
                    $status,
                    $createdBy
                );
            }
            $ok = $stmt->execute();
            $newId = (int) $conn->insert_id;
            $stmt->close();

            if ($ok) {
                asset_log_action('asset_create', 'asset', $newId, [
                    'asset_id' => $newId,
                    'asset_code' => $assetCode,
                    'church_id' => $churchId,
                ], [], [
                    'department_id' => $departmentId,
                    'item_group' => $itemGroup,
                    'item_name' => $itemName,
                    'quantity' => $quantity,
                    'condition_status' => $conditionStatus,
                    'status' => $status,
                    'lifecycle_status' => $hasLifecycle ? $lifecycleStatus : null,
                    'warranty_expiry_date' => $hasMaintenanceFields ? $warrantyExpiryDb : null,
                    'last_maintenance_date' => $hasMaintenanceFields ? $lastMaintenanceDb : null,
                    'next_maintenance_date' => $hasMaintenanceFields ? $nextMaintenanceDb : null,
                ]);
                header('Location: asset_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to create asset. Please retry.';
        }
    }

    $departments = asset_fetch_departments($conn, $churchId > 0 ? $churchId : null, false);
}

ob_start();
?>
<style>
.asset-form-shell {
    background: linear-gradient(145deg, #f7f9fc, #eef3f8);
    border: 1px solid #dde5ee;
    border-radius: 14px;
}
.asset-form-shell .card-header {
    background: #0f3557;
    color: #fff;
    border-radius: 14px 14px 0 0;
}
</style>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-box mr-2"></i><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?></h2>
            <small class="text-muted">Capture asset profile, condition, and lifecycle details.</small>
        </div>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <div class="card asset-form-shell shadow-sm">
        <div class="card-header py-3">
            <strong><?= $isEdit ? 'Asset Update' : 'Asset Onboarding' ?></strong>
        </div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="original_updated_at" value="<?= htmlspecialchars($originalUpdatedAt) ?>">
                <?php endif; ?>
                <?php if ($isSuper): ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Church <span class="text-danger">*</span></label>
                            <select name="church_id" class="form-control" required>
                                <option value="">-- Select Church --</option>
                                <?php foreach ($churches as $church): ?>
                                    <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Asset Code</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($assetCode ?: 'Auto-generated on save') ?>" readonly>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>Asset Code</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($assetCode ?: 'Auto-generated on save') ?>" readonly>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Department <span class="text-danger">*</span></label>
                        <select name="department_id" class="form-control" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= htmlspecialchars($department['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Item Group</label>
                        <input type="text" name="item_group" class="form-control" value="<?= htmlspecialchars($itemGroup) ?>" maxlength="120" placeholder="e.g. Sound Equipment">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($itemName) ?>" required maxlength="180">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label>Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?= htmlspecialchars($purchaseDate) ?>">
                    </div>
                    <?php if ($hasMaintenanceFields): ?>
                    <div class="form-group col-md-3">
                        <label>Warranty Expiry</label>
                        <input type="date" name="warranty_expiry_date" class="form-control" value="<?= htmlspecialchars($warrantyExpiryDate) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Last Maintenance</label>
                        <input type="date" name="last_maintenance_date" class="form-control" value="<?= htmlspecialchars($lastMaintenanceDate) ?>">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Next Maintenance</label>
                        <input type="date" name="next_maintenance_date" class="form-control" value="<?= htmlspecialchars($nextMaintenanceDate) ?>">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-2">
                        <label>Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" class="form-control" value="<?= (int) $quantity ?>" min="1" required>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Receipt/Serial Number</label>
                        <input type="text" name="receipt_or_serial_number" class="form-control" value="<?= htmlspecialchars($receiptOrSerial) ?>" maxlength="120">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Amount</label>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= htmlspecialchars($amount) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Condition Status <span class="text-danger">*</span></label>
                        <select name="condition_status" class="form-control" required>
                            <?php foreach ($conditions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $conditionStatus === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Asset Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="disposed" <?= $status === 'disposed' ? 'selected' : '' ?>>Disposed</option>
                        </select>
                    </div>
                    <?php if ($hasLifecycle): ?>
                    <div class="form-group col-md-4">
                        <label>Lifecycle Stage <span class="text-danger">*</span></label>
                        <select name="lifecycle_status" class="form-control" required>
                            <?php foreach ($lifecycleOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= $lifecycleStatus === $opt ? 'selected' : '' ?>><?= htmlspecialchars(asset_lifecycle_label($opt)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label>Allocation Note</label>
                    <input type="text" name="allocation_note" class="form-control" value="<?= htmlspecialchars($allocationNote) ?>" maxlength="180" placeholder="Department/location note">
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> Save Asset</button>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
