<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../includes/asset_workspace_nav.php';

asset_require_permission('transfer_asset');

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: asset_list.php');
    exit;
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);
$canApprove = asset_user_can_approve_requests();
$error = '';

$sql = 'SELECT a.*, d.name AS department_name FROM assets a LEFT JOIN asset_departments d ON d.id = a.department_id WHERE a.id = ?';
if (!$isSuper) {
    $sql .= ' AND a.church_id = ?';
}
$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $id);
} else {
    $stmt->bind_param('ii', $id, $churchId);
}
$stmt->execute();
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    http_response_code(404);
    exit('Asset not found.');
}

$churchId = (int) $asset['church_id'];
$physicalItems = asset_fetch_physical_items($conn, $id, true);
$hasCustodyState = asset_column_exists($conn, 'asset_items', 'custody_status');
$hasItemLifecycle = asset_column_exists($conn, 'asset_items', 'lifecycle_status');
$physicalItems = array_values(array_filter($physicalItems, static function (array $item) use ($hasCustodyState, $hasItemLifecycle): bool {
    if ($hasCustodyState && (string) ($item['custody_status'] ?? 'available') !== 'available') {
        return false;
    }
    if ($hasItemLifecycle && in_array((string) ($item['lifecycle_status'] ?? ''), ['under_maintenance', 'retired', 'disposed'], true)) {
        return false;
    }
    return true;
}));
$selectedItemId = (int) ($_POST['asset_item_id'] ?? $_GET['asset_item_id'] ?? (count($physicalItems) === 1 ? $physicalItems[0]['id'] : 0));
$selectedItem = null;
foreach ($physicalItems as $physicalItem) {
    if ((int) $physicalItem['id'] === $selectedItemId) $selectedItem = $physicalItem;
}
$currentDepartmentId = (int) ($selectedItem['department_id'] ?? 0);
$departments = asset_fetch_departments($conn, $churchId, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your form expired. Refresh the page and try again.');
    }
    $toDepartmentId = (int) ($_POST['to_department_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!$selectedItem) {
        $error = 'Please select the asset to transfer.';
    } elseif ($toDepartmentId <= 0) {
        $error = 'Please select destination department.';
    } elseif ($toDepartmentId === $currentDepartmentId) {
        $error = 'Destination department must be different from current department.';
    } elseif ($notes === '') {
        $error = 'A transfer reason is required.';
    } else {
        if (!$canApprove && asset_table_exists($conn, 'asset_approval_requests')) {
            $requestId = asset_create_approval_request($conn, $churchId, $id, 'transfer', [
                'from_department_id' => $currentDepartmentId,
                'to_department_id' => $toDepartmentId,
                'asset_item_id' => $selectedItemId,
                'item_number' => (string) $selectedItem['item_number'],
                'note' => $notes,
            ]);
            asset_log_action('asset_approval_requested', 'asset_approval_request', $requestId, [
                'asset_id' => $id,
                'asset_code' => (string) $asset['asset_code'],
                'church_id' => $churchId,
                'request_type' => 'transfer',
            ], [], [
                'from_department_id' => $currentDepartmentId,
                'to_department_id' => $toDepartmentId,
                'note' => $notes,
            ]);
            header('Location: asset_list.php?requested=1' . ($churchId ? '&church_id=' . $churchId : ''));
            exit;
        } else {
            $conn->begin_transaction();
            try {
                $movedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
                $movement = asset_transfer_registered_item(
                    $conn,
                    $id,
                    $selectedItemId,
                    $toDepartmentId,
                    $movedBy,
                    $notes
                );

                $conn->commit();

                $toDepartmentName = '';
                foreach ($departments as $dept) {
                    if ((int) $dept['id'] === $toDepartmentId) {
                        $toDepartmentName = (string) $dept['name'];
                        break;
                    }
                }

                $after = [
                    'department_id' => $toDepartmentId,
                    'department_name' => $toDepartmentName,
                    'asset_code' => $movement['new_asset_code'],
                    'item_number' => $movement['new_item_number'],
                ];

                asset_log_action('asset_transfer', 'asset_movement', $movement['movement_id'], [
                    'asset_id' => $id,
                    'asset_code' => $movement['new_asset_code'],
                    'old_asset_code' => $movement['old_asset_code'],
                    'new_asset_code' => $movement['new_asset_code'],
                    'asset_item_id' => $selectedItemId,
                    'old_item_number' => $movement['old_item_number'],
                    'new_item_number' => $movement['new_item_number'],
                    'church_id' => $churchId,
                    'from_department_id' => $movement['from_department_id'],
                    'to_department_id' => $toDepartmentId,
                ], [
                    'department_id' => $movement['from_department_id'],
                    'item_number' => $movement['old_item_number'],
                ], $after);

                header('Location: asset_list.php?transferred=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Asset transfer failed: ' . $e->getMessage());
                $error = $e instanceof mysqli_sql_exception
                    ? 'The transfer could not be recorded. Please retry.'
                    : $e->getMessage();
            }
        }
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/assets/css/asset-workspace.css">
<div class="container-fluid mt-4 asset-workspace">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-exchange-alt mr-2"></i>Transfer Asset</h2>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>
    <?php render_asset_workspace_nav('movements', $churchId); ?>

    <div class="card asset-panel asset-form-shell">
        <div class="card-header"><strong>Asset transfer</strong><small class="d-block text-muted">The movement keeps a complete location trail and rewrites only the department segment of the asset number.</small></div>
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <?php if (!$physicalItems): ?>
                <div class="asset-empty-state">
                    <i class="fas fa-lock"></i>
                    <strong>No transferable assets</strong>
                    <div>Reserved, issued, maintenance, retired and disposed assets must complete their current workflow before a department transfer.</div>
                </div>
            <?php else: ?>

            <div class="mb-3">
                <strong>Asset:</strong> <?= htmlspecialchars($asset['item_name']) ?><br>
                <?php if ($selectedItem): ?><strong>Asset Number:</strong> <?= htmlspecialchars((string) $selectedItem['item_number']) ?><br><strong>Current Department:</strong> <?= htmlspecialchars((string) ($selectedItem['department_name'] ?? '-')) ?><?php endif; ?>
            </div>

            <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= (int) $id ?>">

                <div class="form-group">
                    <label for="asset_item_id">Asset <span class="text-danger">*</span></label>
                    <select class="form-control" id="asset_item_id" name="asset_item_id" required onchange="if(this.value){window.location='asset_transfer.php?id=<?= (int) $id ?>&asset_item_id='+encodeURIComponent(this.value);}">
                        <option value="">-- Select Asset --</option>
                        <?php foreach ($physicalItems as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $selectedItemId === (int) $item['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $item['item_number']) ?><?= !empty($item['department_name']) ? ' - ' . htmlspecialchars((string) $item['department_name']) : '' ?></option><?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="to_department_id">Transfer To Department <span class="text-danger">*</span></label>
                    <select class="form-control" id="to_department_id" name="to_department_id" required>
                        <option value="">-- Select Department --</option>
                        <?php foreach ($departments as $dept): ?>
                            <?php if ((int) $dept['id'] === $currentDepartmentId) continue; ?>
                            <option value="<?= (int) $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes">Transfer Reason <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="255" required placeholder="Explain why this asset is moving"></textarea>
                </div>

                <?php if (!$canApprove && asset_table_exists($conn, 'asset_approval_requests')): ?>
                    <div class="alert alert-info py-2">This transfer will be submitted for approval before execution.</div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane mr-1"></i> Submit Transfer Request</button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-check mr-1"></i> Confirm Transfer</button>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
