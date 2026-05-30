<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

$isEdit = isset($_GET['id']) && (int) $_GET['id'] > 0;
asset_require_permission($isEdit ? 'edit_asset' : 'create_asset');

$isSuper = asset_is_super_admin();
$conditions = asset_condition_options();
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
    }

    if (!$error) {
        $purchaseDateDb = $purchaseDate !== '' ? $purchaseDate : null;
        $amountDb = $amount !== '' ? (float) $amount : null;

        if ($isEdit) {
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
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                asset_log_action('asset_update', 'asset', $assetId, [
                    'asset_code' => $assetCode,
                    'church_id' => $churchId,
                    'department_id' => $departmentId,
                    'condition_status' => $conditionStatus,
                    'status' => $status,
                ]);
                header('Location: asset_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to update asset.';
        } else {
            $assetCode = asset_generate_code($conn, $churchId, $departmentId, $itemGroup);
            $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

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
            $ok = $stmt->execute();
            $newId = (int) $conn->insert_id;
            $stmt->close();

            if ($ok) {
                asset_log_action('asset_create', 'asset', $newId, [
                    'asset_code' => $assetCode,
                    'church_id' => $churchId,
                    'department_id' => $departmentId,
                    'condition_status' => $conditionStatus,
                    'status' => $status,
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
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-box mr-2"></i><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?></h2>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <form method="post" autocomplete="off">
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
                        <input type="text" name="item_group" class="form-control" value="<?= htmlspecialchars($itemGroup) ?>" maxlength="120" placeholder="e.g. Musical Equipment">
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
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="disposed" <?= $status === 'disposed' ? 'selected' : '' ?>>Disposed</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Allocation Note</label>
                        <input type="text" name="allocation_note" class="form-control" value="<?= htmlspecialchars($allocationNote) ?>" maxlength="180">
                    </div>
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
