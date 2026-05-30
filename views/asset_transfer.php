<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('transfer_asset');

$id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: asset_list.php');
    exit;
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);
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
$currentDepartmentId = (int) ($asset['department_id'] ?? 0);
$departments = asset_fetch_departments($conn, $churchId, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toDepartmentId = (int) ($_POST['to_department_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($toDepartmentId <= 0) {
        $error = 'Please select destination department.';
    } elseif ($toDepartmentId === $currentDepartmentId) {
        $error = 'Destination department must be different from current department.';
    } else {
        $conn->begin_transaction();
        try {
            $before = [
                'department_id' => $currentDepartmentId,
                'department_name' => $asset['department_name'] ?? null,
            ];

            $stmt = $conn->prepare('UPDATE assets SET department_id = ? WHERE id = ?');
            $stmt->bind_param('ii', $toDepartmentId, $id);
            $stmt->execute();
            $stmt->close();

            $movedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $stmt = $conn->prepare('INSERT INTO asset_movements (asset_id, from_department_id, to_department_id, moved_by, notes) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('iiiis', $id, $currentDepartmentId, $toDepartmentId, $movedBy, $notes);
            $stmt->execute();
            $movementId = (int) $conn->insert_id;
            $stmt->close();

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
            ];

            asset_log_action('asset_transfer', 'asset_movement', $movementId, [
                'asset_id' => $id,
                'asset_code' => $asset['asset_code'],
                'church_id' => $churchId,
                'from_department_id' => $currentDepartmentId,
                'to_department_id' => $toDepartmentId,
            ], $before, $after);

            header('Location: asset_list.php?transferred=1' . ($churchId ? '&church_id=' . $churchId : ''));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Transfer failed. Please retry.';
        }
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-exchange-alt mr-2"></i>Transfer Asset</h2>
        <a href="asset_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

            <div class="mb-3">
                <strong>Asset Code:</strong> <?= htmlspecialchars($asset['asset_code']) ?><br>
                <strong>Item:</strong> <?= htmlspecialchars($asset['item_name']) ?><br>
                <strong>Current Department:</strong> <?= htmlspecialchars((string) ($asset['department_name'] ?? '-')) ?>
            </div>

            <form method="post">
                <input type="hidden" name="id" value="<?= (int) $id ?>">

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
                    <label for="notes">Transfer Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="255" placeholder="Reason or notes for this movement"></textarea>
                </div>

                <button type="submit" class="btn btn-primary"><i class="fas fa-check mr-1"></i> Confirm Transfer</button>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
