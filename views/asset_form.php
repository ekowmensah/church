<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

$isEdit = isset($_GET['id']) && (int) $_GET['id'] > 0;
asset_require_permission($isEdit ? 'edit_asset' : 'create_asset');

$isSuper = asset_is_super_admin();
$conditions = asset_condition_options();
$lifecycleOptions = asset_lifecycle_options();
$acquisitionModes = asset_acquisition_mode_options();
$acquisitionDescriptions = asset_acquisition_mode_descriptions();
$hasLifecycle = asset_can_use_lifecycle($conn);
$hasMaintenanceFields = asset_can_use_maintenance_fields($conn);
$hasGroups = asset_can_use_groups($conn);
$hasAcquisitionMode = asset_column_exists($conn, 'assets', 'acquisition_mode');
$hasSeparatedReceiptFields = asset_column_exists($conn, 'assets', 'receipt_number')
    && asset_column_exists($conn, 'assets', 'serial_number');
$hasSerialTracking = asset_table_exists($conn, 'asset_serial_numbers');
$hasItemTracking = asset_item_tracking_available($conn);
$assetId = $isEdit ? (int) $_GET['id'] : 0;
$error = '';

$inferBindTypes = static function (array $values): string {
    $types = '';
    foreach ($values as $value) {
        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }
    return $types;
};

$bindAndExecute = static function (mysqli $conn, string $sql, array $values) use ($inferBindTypes): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Failed to prepare asset query: ' . $conn->error);
    }

    if (!empty($values)) {
        $types = $inferBindTypes($values);
        $refs = [];
        foreach ($values as $idx => $value) {
            $refs[$idx] = &$values[$idx];
        }
        $stmt->bind_param($types, ...$refs);
    }

    $ok = $stmt->execute();
    return [$stmt, $ok];
};

$churchId = $isSuper ? (isset($_GET['church_id']) ? (int) $_GET['church_id'] : 0) : (int) asset_current_church_id($conn);
$departmentId = 0;
$assetGroupId = 0;
$itemGroup = '';
$itemName = '';
$acquisitionMode = $hasAcquisitionMode ? 'purchase' : 'purchase';
$acquisitionModeOther = '';
$purchaseDate = '';
$quantity = 1;
$receiptNumber = '';
$primarySerialNumber = '';
$serialNumbersText = '';
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
    $assetGroupId = (int) ($asset['asset_group_id'] ?? 0);
    $itemGroup = (string) ($asset['item_group'] ?? '');
    $itemName = (string) $asset['item_name'];
    $acquisitionMode = (string) ($asset['acquisition_mode'] ?? 'purchase');
    $acquisitionModeOther = (string) ($asset['acquisition_mode_other'] ?? '');
    $purchaseDate = (string) ($asset['purchase_date'] ?? '');
    $quantity = (int) $asset['quantity'];
    $receiptNumber = (string) ($asset['receipt_number'] ?? ($asset['receipt_or_serial_number'] ?? ''));
    $primarySerialNumber = (string) ($asset['serial_number'] ?? '');
    if ($hasItemTracking) {
        $physicalItems = asset_fetch_physical_items($conn, $assetId);
        $quantity = count(array_filter($physicalItems, static function (array $item): bool {
            return (string) ($item['status'] ?? '') === 'active';
        }));
        $primarySerialNumber = (string) ($physicalItems[0]['serial_number'] ?? $primarySerialNumber);
        $serialNumbersText = implode("\n", array_values(array_filter(array_column($physicalItems, 'serial_number'))));
    } elseif ($hasSerialTracking) {
        $serialNumbersText = implode("\n", asset_fetch_serial_numbers($conn, $assetId));
    } elseif ($primarySerialNumber !== '') {
        $serialNumbersText = $primarySerialNumber;
    }
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
$groups = $hasGroups ? asset_fetch_groups($conn, $churchId > 0 ? $churchId : null, false) : [];
$groupMap = asset_group_map_by_id($groups);
$departmentMap = [];
foreach ($departments as $departmentRow) {
    $departmentMap[(int) $departmentRow['id']] = $departmentRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $churchId = $isSuper ? (int) ($_POST['church_id'] ?? 0) : (int) asset_current_church_id($conn);
    $departments = asset_fetch_departments($conn, $churchId > 0 ? $churchId : null, false);
    $groups = $hasGroups ? asset_fetch_groups($conn, $churchId > 0 ? $churchId : null, false) : [];
    $groupMap = asset_group_map_by_id($groups);
    $departmentMap = [];
    foreach ($departments as $departmentRow) {
        $departmentMap[(int) $departmentRow['id']] = $departmentRow;
    }

    $departmentId = (int) ($_POST['department_id'] ?? 0);
    $assetGroupId = (int) ($_POST['asset_group_id'] ?? 0);
    $itemGroup = trim((string) ($_POST['item_group'] ?? ''));
    $itemName = trim((string) ($_POST['item_name'] ?? ''));
    $acquisitionMode = trim((string) ($_POST['acquisition_mode'] ?? 'purchase'));
    $acquisitionModeOther = trim((string) ($_POST['acquisition_mode_other'] ?? ''));
    $purchaseDate = trim((string) ($_POST['purchase_date'] ?? ''));
    $quantity = $hasItemTracking
        ? ($isEdit ? count(asset_fetch_physical_items($conn, $assetId, true)) : 1)
        : (int) ($_POST['quantity'] ?? 1);
    $receiptNumber = trim((string) ($_POST['receipt_number'] ?? ''));
    $primarySerialNumber = trim((string) ($_POST['primary_serial_number'] ?? ''));
    $serialNumbersText = trim((string) ($_POST['serial_numbers_text'] ?? ''));
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

    $selectedGroup = $hasGroups && $assetGroupId > 0 ? ($groupMap[$assetGroupId] ?? null) : null;
    if ($selectedGroup) {
        $itemGroup = (string) ($selectedGroup['name'] ?? $itemGroup);
        if (!$hasItemTracking && (string) ($selectedGroup['quantity_rule'] ?? 'fixed') === 'fixed') {
            $quantity = (int) ($selectedGroup['default_quantity'] ?? 1);
        } elseif (!$hasItemTracking && $quantity <= 0) {
            $quantity = (int) ($selectedGroup['default_quantity'] ?? 1);
        }
    }

    if ($status === 'disposed') {
        $lifecycleStatus = 'disposed';
    }
    if ($conditionStatus === 'Disposed') {
        $status = 'disposed';
        $lifecycleStatus = 'disposed';
    }

    if (!array_key_exists($acquisitionMode, $acquisitionModes)) {
        $acquisitionMode = 'purchase';
    }
    if ($acquisitionMode !== 'other') {
        $acquisitionModeOther = '';
    }

    $serials = asset_parse_serial_numbers($serialNumbersText);
    if ($primarySerialNumber === '' && !empty($serials)) {
        $primarySerialNumber = $serials[0];
    }
    if ($primarySerialNumber !== '' && !in_array($primarySerialNumber, $serials, true)) {
        array_unshift($serials, $primarySerialNumber);
    }

    if ($churchId <= 0) {
        $error = 'Church is required.';
    } elseif ($departmentId <= 0) {
        $error = 'Department is required.';
    } elseif (!isset($departmentMap[$departmentId])) {
        $error = 'Please select a department that belongs to the chosen church.';
    } elseif ($hasGroups && $assetGroupId <= 0) {
        $error = 'Item category is required.';
    } elseif ($hasGroups && !$selectedGroup) {
        $error = 'Please select an item category that belongs to the chosen church.';
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
    } elseif ($acquisitionMode === 'other' && $acquisitionModeOther === '') {
        $error = 'Please specify the acquisition mode when "Other" is selected.';
    } elseif ($hasItemTracking && count($serials) > 1) {
        $error = 'Register one physical item at a time. Enter only that item\'s serial number.';
    } elseif (!$hasItemTracking && count($serials) > $quantity) {
        $error = 'Serial numbers cannot exceed the asset quantity.';
    } elseif ($isEdit && $postedOriginalUpdatedAt !== '' && $postedOriginalUpdatedAt !== $originalUpdatedAt) {
        $error = 'This asset was updated by another user. Reload and try again.';
    }

    if ($error === '') {
        $purchaseDateDb = $purchaseDate !== '' ? $purchaseDate : null;
        $amountDb = $amount !== '' ? (float) $amount : null;
        $warrantyExpiryDb = $warrantyExpiryDate !== '' ? $warrantyExpiryDate : null;
        $lastMaintenanceDb = $lastMaintenanceDate !== '' ? $lastMaintenanceDate : null;
        $nextMaintenanceDb = $nextMaintenanceDate !== '' ? $nextMaintenanceDate : null;
        $assetGroupDb = $hasGroups && $assetGroupId > 0 ? $assetGroupId : null;
        $acquisitionModeDb = $hasAcquisitionMode ? $acquisitionMode : null;
        $acquisitionModeOtherDb = $hasAcquisitionMode ? ($acquisitionModeOther !== '' ? $acquisitionModeOther : null) : null;
        $receiptNumberDb = $hasSeparatedReceiptFields ? ($receiptNumber !== '' ? $receiptNumber : null) : null;
        $primarySerialDb = $hasSeparatedReceiptFields ? ($primarySerialNumber !== '' ? $primarySerialNumber : null) : null;
        $legacyReceiptOrSerial = $receiptNumber !== '' ? $receiptNumber : $primarySerialNumber;
        $legacyReceiptOrSerialDb = $legacyReceiptOrSerial !== '' ? $legacyReceiptOrSerial : null;

        $conn->begin_transaction();
        try {
            if ($isEdit) {
                $before = [
                    'church_id' => $asset['church_id'] ?? null,
                    'department_id' => $asset['department_id'] ?? null,
                    'asset_group_id' => $asset['asset_group_id'] ?? null,
                    'item_group' => $asset['item_group'] ?? null,
                    'item_name' => $asset['item_name'] ?? null,
                    'acquisition_mode' => $asset['acquisition_mode'] ?? null,
                    'acquisition_mode_other' => $asset['acquisition_mode_other'] ?? null,
                    'purchase_date' => $asset['purchase_date'] ?? null,
                    'quantity' => $asset['quantity'] ?? null,
                    'receipt_number' => $asset['receipt_number'] ?? ($asset['receipt_or_serial_number'] ?? null),
                    'serial_number' => $asset['serial_number'] ?? null,
                    'amount' => $asset['amount'] ?? null,
                    'condition_status' => $asset['condition_status'] ?? null,
                    'allocation_note' => $asset['allocation_note'] ?? null,
                    'status' => $asset['status'] ?? null,
                    'lifecycle_status' => $hasLifecycle ? ($asset['lifecycle_status'] ?? null) : null,
                    'warranty_expiry_date' => $hasMaintenanceFields ? ($asset['warranty_expiry_date'] ?? null) : null,
                    'last_maintenance_date' => $hasMaintenanceFields ? ($asset['last_maintenance_date'] ?? null) : null,
                    'next_maintenance_date' => $hasMaintenanceFields ? ($asset['next_maintenance_date'] ?? null) : null,
                ];

                $sql = 'UPDATE assets SET church_id = ?, department_id = ?';
                $values = [$churchId, $departmentId];

                if ($hasGroups) {
                    $sql .= ', asset_group_id = ?';
                    $values[] = $assetGroupDb;
                }

                $sql .= ', item_group = ?, item_name = ?';
                $values[] = $itemGroup;
                $values[] = $itemName;

                if ($hasAcquisitionMode) {
                    $sql .= ', acquisition_mode = ?, acquisition_mode_other = ?';
                    $values[] = $acquisitionModeDb;
                    $values[] = $acquisitionModeOtherDb;
                }

                $sql .= ', purchase_date = ?';
                $values[] = $purchaseDateDb;

                if ($hasMaintenanceFields) {
                    $sql .= ', warranty_expiry_date = ?, last_maintenance_date = ?, next_maintenance_date = ?';
                    $values[] = $warrantyExpiryDb;
                    $values[] = $lastMaintenanceDb;
                    $values[] = $nextMaintenanceDb;
                }

                if (!$hasItemTracking) {
                    $sql .= ', quantity = ?';
                    $values[] = $quantity;
                }
                $sql .= ', receipt_or_serial_number = ?';
                $values[] = $legacyReceiptOrSerialDb;

                if ($hasSeparatedReceiptFields) {
                    $sql .= ', receipt_number = ?, serial_number = ?';
                    $values[] = $receiptNumberDb;
                    $values[] = $primarySerialDb;
                }

                $sql .= ', amount = ?, condition_status = ?, allocation_note = ?, status = ?';
                $values[] = $amountDb;
                $values[] = $conditionStatus;
                $values[] = $allocationNote;
                $values[] = $status;

                if ($hasLifecycle) {
                    $sql .= ', lifecycle_status = ?';
                    $values[] = $lifecycleStatus;
                }

                $sql .= ' WHERE id = ?';
                $values[] = $assetId;

                [$stmt, $ok] = $bindAndExecute($conn, $sql, $values);
                $stmt->close();
                if (!$ok) {
                    throw new RuntimeException('Failed to update asset.');
                }

                if ($hasSerialTracking) {
                    $sync = asset_sync_serial_numbers($conn, $churchId, $assetId, $serials);
                    if (!$sync['ok']) {
                        throw new RuntimeException($sync['message']);
                    }
                }

                if ($hasItemTracking) {
                    $items = asset_fetch_physical_items($conn, $assetId);
                    if (count($items) === 1) {
                        $itemId = (int) $items[0]['id'];
                        $stmt = $conn->prepare(
                            'UPDATE asset_items SET serial_number = ?, condition_status = ?, status = ?, lifecycle_status = ? WHERE id = ?'
                        );
                        $serialDb = $primarySerialNumber !== '' ? $primarySerialNumber : null;
                        $stmt->bind_param('ssssi', $serialDb, $conditionStatus, $status, $lifecycleStatus, $itemId);
                        $stmt->execute();
                        $stmt->close();
                    }
                    asset_sync_parent_from_items($conn, $assetId);
                    $quantity = count(asset_fetch_physical_items($conn, $assetId, true));
                }

                $conn->commit();

                $after = [
                    'church_id' => $churchId,
                    'department_id' => $departmentId,
                    'asset_group_id' => $assetGroupDb,
                    'item_group' => $itemGroup,
                    'item_name' => $itemName,
                    'acquisition_mode' => $acquisitionModeDb,
                    'acquisition_mode_other' => $acquisitionModeOtherDb,
                    'purchase_date' => $purchaseDateDb,
                    'quantity' => $quantity,
                    'receipt_number' => $receiptNumberDb,
                    'serial_number' => $primarySerialDb,
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

            $assetCode = asset_generate_code($conn, $churchId, $departmentId, $itemGroup, $assetGroupDb, $purchaseDateDb);
            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

            $columns = ['church_id', 'asset_code', 'department_id'];
            $values = [$churchId, $assetCode, $departmentId];

            if ($hasGroups) {
                $columns[] = 'asset_group_id';
                $values[] = $assetGroupDb;
            }

            $columns[] = 'item_group';
            $columns[] = 'item_name';
            $values[] = $itemGroup;
            $values[] = $itemName;

            if ($hasAcquisitionMode) {
                $columns[] = 'acquisition_mode';
                $columns[] = 'acquisition_mode_other';
                $values[] = $acquisitionModeDb;
                $values[] = $acquisitionModeOtherDb;
            }

            $columns[] = 'purchase_date';
            $values[] = $purchaseDateDb;

            if ($hasMaintenanceFields) {
                $columns[] = 'warranty_expiry_date';
                $columns[] = 'last_maintenance_date';
                $columns[] = 'next_maintenance_date';
                $values[] = $warrantyExpiryDb;
                $values[] = $lastMaintenanceDb;
                $values[] = $nextMaintenanceDb;
            }

            $columns[] = 'quantity';
            $columns[] = 'receipt_or_serial_number';
            $values[] = $quantity;
            $values[] = $legacyReceiptOrSerialDb;

            if ($hasSeparatedReceiptFields) {
                $columns[] = 'receipt_number';
                $columns[] = 'serial_number';
                $values[] = $receiptNumberDb;
                $values[] = $primarySerialDb;
            }

            $columns[] = 'amount';
            $columns[] = 'condition_status';
            $columns[] = 'allocation_note';
            $columns[] = 'status';
            $columns[] = 'created_by';
            $values[] = $amountDb;
            $values[] = $conditionStatus;
            $values[] = $allocationNote;
            $values[] = $status;
            $values[] = $createdBy;

            if ($hasLifecycle) {
                $columns[] = 'lifecycle_status';
                $values[] = $lifecycleStatus;
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $sql = 'INSERT INTO assets (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
            [$stmt, $ok] = $bindAndExecute($conn, $sql, $values);
            $newId = (int) $conn->insert_id;
            $stmt->close();

            if (!$ok) {
                throw new RuntimeException('Failed to create asset. Please retry.');
            }

            if ($hasSerialTracking) {
                $sync = asset_sync_serial_numbers($conn, $churchId, $newId, $serials);
                if (!$sync['ok']) {
                    throw new RuntimeException($sync['message']);
                }
            }


            if ($hasItemTracking) {
                $stmt = $conn->prepare(
                    'INSERT INTO asset_items
                        (church_id, asset_id, item_number, department_id, serial_number,
                         condition_status, status, lifecycle_status, registered_by_user_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $serialDb = $primarySerialNumber !== '' ? $primarySerialNumber : null;
                $stmt->bind_param(
                    'iisissssi',
                    $churchId,
                    $newId,
                    $assetCode,
                    $departmentId,
                    $serialDb,
                    $conditionStatus,
                    $status,
                    $lifecycleStatus,
                    $createdBy
                );
                $stmt->execute();
                $stmt->close();
                $quantity = 1;
            }

            $conn->commit();

            asset_log_action('asset_create', 'asset', $newId, [
                'asset_id' => $newId,
                'asset_code' => $assetCode,
                'church_id' => $churchId,
            ], [], [
                'department_id' => $departmentId,
                'asset_group_id' => $assetGroupDb,
                'item_group' => $itemGroup,
                'item_name' => $itemName,
                'acquisition_mode' => $acquisitionModeDb,
                'acquisition_mode_other' => $acquisitionModeOtherDb,
                'quantity' => $quantity,
                'receipt_number' => $receiptNumberDb,
                'serial_number' => $primarySerialDb,
                'condition_status' => $conditionStatus,
                'status' => $status,
                'lifecycle_status' => $hasLifecycle ? $lifecycleStatus : null,
                'warranty_expiry_date' => $hasMaintenanceFields ? $warrantyExpiryDb : null,
                'last_maintenance_date' => $hasMaintenanceFields ? $lastMaintenanceDb : null,
                'next_maintenance_date' => $hasMaintenanceFields ? $nextMaintenanceDb : null,
            ]);

            header('Location: asset_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
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
            <small class="text-muted">Capture structured asset data, acquisition details, and serial tracking.</small>
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
                                    <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $church['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Asset Code</label>
                            <input type="text" class="form-control" value="<?= htmlspecialchars($assetCode ?: 'Generated on save from church/department/category/year/society') ?>" readonly>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label>Asset Code</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($assetCode ?: 'Generated on save from church/department/category/year/society') ?>" readonly>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Department <span class="text-danger">*</span></label>
                        <select name="department_id" class="form-control" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= (int) $department['id'] ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $department['name']) ?><?= !empty($department['department_code']) ? ' (' . htmlspecialchars((string) $department['department_code']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($hasGroups): ?>
                    <div class="form-group col-md-4">
                        <label>Item Category <span class="text-danger">*</span></label>
                        <select name="asset_group_id" id="asset_group_id" class="form-control" required>
                            <option value="">-- Select Item Category --</option>
                            <?php foreach ($groups as $group): ?>
                                <option
                                    value="<?= (int) $group['id'] ?>"
                                    data-default-quantity="<?= (int) ($group['default_quantity'] ?? 1) ?>"
                                    data-quantity-rule="<?= htmlspecialchars((string) ($group['quantity_rule'] ?? 'fixed')) ?>"
                                    data-group-name="<?= htmlspecialchars((string) ($group['name'] ?? '')) ?>"
                                    <?= $assetGroupId === (int) $group['id'] ? 'selected' : '' ?>
                                >
                                    <?= htmlspecialchars((string) $group['name']) ?><?= !empty($group['group_code']) ? ' (' . htmlspecialchars((string) $group['group_code']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Category Name</label>
                        <input type="text" id="item_group_display" class="form-control" value="<?= htmlspecialchars($itemGroup) ?>" readonly>
                        <input type="hidden" name="item_group" id="item_group" value="<?= htmlspecialchars($itemGroup) ?>">
                        <small class="text-muted">Filled automatically from the selected item category.</small>
                    </div>
                    <?php else: ?>
                    <div class="form-group col-md-8">
                        <label>Item Category</label>
                        <input type="text" name="item_group" class="form-control" value="<?= htmlspecialchars($itemGroup) ?>" maxlength="120" placeholder="e.g. Sound Equipment">
                    </div>
                    <?php endif; ?>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>Item Name <span class="text-danger">*</span></label>
                        <input type="text" name="item_name" class="form-control" value="<?= htmlspecialchars($itemName) ?>" required maxlength="180">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Acquisition Mode <span class="text-danger">*</span></label>
                        <select name="acquisition_mode" id="acquisition_mode" class="form-control" required>
                            <?php foreach ($acquisitionModes as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $acquisitionMode === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($acquisitionDescriptions[$acquisitionMode])): ?>
                            <small class="text-muted" id="acquisition_mode_help"><?= htmlspecialchars((string) $acquisitionDescriptions[$acquisitionMode]) ?></small>
                        <?php else: ?>
                            <small class="text-muted" id="acquisition_mode_help"></small>
                        <?php endif; ?>
                    </div>
                    <div class="form-group col-md-4" id="acquisition_mode_other_wrap" style="<?= $acquisitionMode === 'other' ? '' : 'display:none;' ?>">
                        <label>Specify Other Mode <span class="text-danger">*</span></label>
                        <input type="text" name="acquisition_mode_other" class="form-control" value="<?= htmlspecialchars($acquisitionModeOther) ?>" maxlength="180">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-3">
                        <label>Purchase/Acquisition Date</label>
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
                        <label><?= $hasItemTracking ? 'Derived Quantity' : 'Quantity' ?> <span class="text-danger">*</span></label>
                        <input type="number" name="quantity" id="quantity" class="form-control" value="<?= (int) $quantity ?>" min="<?= $hasItemTracking && $isEdit ? 0 : 1 ?>" required <?= $hasItemTracking ? 'readonly data-derived="1"' : '' ?>>
                        <?php if ($hasItemTracking): ?><small class="text-muted"><?= $isEdit ? 'Counted from active physical items.' : 'Each registration creates one uniquely numbered physical item.' ?></small><?php endif; ?>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Receipt Number</label>
                        <input type="text" name="receipt_number" class="form-control" value="<?= htmlspecialchars($receiptNumber) ?>" maxlength="120" placeholder="One receipt can cover multiple items">
                    </div>
                    <div class="form-group col-md-3">
                        <label><?= $hasItemTracking ? 'Item Serial Number' : 'Primary Serial Number' ?></label>
                        <input type="text" name="primary_serial_number" class="form-control" value="<?= htmlspecialchars($primarySerialNumber) ?>" maxlength="120" placeholder="Unique per item">
                    </div>
                    <div class="form-group col-md-3">
                        <label>Amount</label>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control" value="<?= htmlspecialchars($amount) ?>">
                    </div>
                </div>

                <div class="form-group" <?= $hasItemTracking ? 'style="display:none"' : '' ?>>
                    <label>All Serial Numbers</label>
                    <textarea name="serial_numbers_text" class="form-control" rows="3" placeholder="Enter one serial per line, or separate with commas"><?= htmlspecialchars($serialNumbersText) ?></textarea>
                    <small class="text-muted">Useful when one asset record covers multiple individually tracked items.</small>
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
<script>
(function () {
    var acquisitionDescriptions = <?= json_encode($acquisitionDescriptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var acquisitionSelect = document.getElementById('acquisition_mode');
    var acquisitionOtherWrap = document.getElementById('acquisition_mode_other_wrap');
    var acquisitionHelp = document.getElementById('acquisition_mode_help');
    var groupSelect = document.getElementById('asset_group_id');
    var quantityInput = document.getElementById('quantity');
    var itemGroupInput = document.getElementById('item_group');
    var itemGroupDisplayInput = document.getElementById('item_group_display');

    function syncAcquisitionMode() {
        if (!acquisitionSelect) {
            return;
        }
        var value = acquisitionSelect.value || '';
        if (acquisitionHelp) {
            acquisitionHelp.textContent = acquisitionDescriptions[value] || '';
        }
        if (acquisitionOtherWrap) {
            acquisitionOtherWrap.style.display = value === 'other' ? '' : 'none';
        }
    }

    function syncGroupQuantity() {
        if (!groupSelect || !quantityInput) {
            return;
        }
        var option = groupSelect.options[groupSelect.selectedIndex];
        if (!option) {
            return;
        }
        var defaultQuantity = parseInt(option.getAttribute('data-default-quantity') || '1', 10);
        var quantityRule = option.getAttribute('data-quantity-rule') || 'fixed';
        var groupName = option.getAttribute('data-group-name') || '';
        if (itemGroupInput) {
            itemGroupInput.value = groupName;
        }
        if (itemGroupDisplayInput) {
            itemGroupDisplayInput.value = groupName;
        }
        if (quantityInput.getAttribute('data-derived') === '1') {
            quantityInput.value = <?= $isEdit ? (int) $quantity : 1 ?>;
        } else if (quantityRule === 'fixed') {
            quantityInput.value = defaultQuantity > 0 ? defaultQuantity : 1;
            quantityInput.setAttribute('readonly', 'readonly');
        } else {
            if (!quantityInput.value || parseInt(quantityInput.value, 10) <= 0) {
                quantityInput.value = defaultQuantity > 0 ? defaultQuantity : 1;
            }
            quantityInput.removeAttribute('readonly');
        }
    }

    if (acquisitionSelect) {
        acquisitionSelect.addEventListener('change', syncAcquisitionMode);
        syncAcquisitionMode();
    }
    if (groupSelect) {
        groupSelect.addEventListener('change', syncGroupQuantity);
        syncGroupQuantity();
    }
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
