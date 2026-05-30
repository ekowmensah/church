<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('manage_asset_departments');

$isEdit = isset($_GET['id']) && (int) $_GET['id'] > 0;
$deptId = $isEdit ? (int) $_GET['id'] : 0;
$error = '';

$churchId = asset_is_super_admin()
    ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null)
    : asset_current_church_id($conn);

$name = '';
$description = '';

if ($isEdit) {
    $sql = 'SELECT id, church_id, name, description FROM asset_departments WHERE id = ?';
    if (!asset_is_super_admin()) {
        $sql .= ' AND church_id = ?';
    }
    $sql .= ' LIMIT 1';

    $stmt = $conn->prepare($sql);
    if (asset_is_super_admin()) {
        $stmt->bind_param('i', $deptId);
    } else {
        $stmt->bind_param('ii', $deptId, $churchId);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        exit('Department not found.');
    }

    $churchId = (int) $row['church_id'];
    $name = (string) $row['name'];
    $description = (string) ($row['description'] ?? '');
}

$churches = [];
if (asset_is_super_admin()) {
    $resChurches = $conn->query('SELECT id, name FROM churches ORDER BY name ASC');
    while ($row = $resChurches->fetch_assoc()) {
        $churches[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));

    if (asset_is_super_admin()) {
        $churchId = (int) ($_POST['church_id'] ?? 0);
    }

    if ($churchId === null || $churchId <= 0) {
        $error = 'Please select a church.';
    } elseif ($name === '') {
        $error = 'Department name is required.';
    } else {
        if ($isEdit) {
            $sql = 'UPDATE asset_departments SET church_id = ?, name = ?, description = ? WHERE id = ?';
            if (!asset_is_super_admin()) {
                $sql .= ' AND church_id = ?';
            }
            $stmt = $conn->prepare($sql);
            if (asset_is_super_admin()) {
                $stmt->bind_param('issi', $churchId, $name, $description, $deptId);
            } else {
                $stmt->bind_param('issii', $churchId, $name, $description, $deptId, $churchId);
            }
            $ok = $stmt->execute();
            $stmt->close();
            if ($ok) {
                asset_log_action('asset_department_update', 'asset_department', $deptId, [
                    'church_id' => $churchId,
                    'name' => $name,
                ]);
                header('Location: asset_department_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to update department. It may already exist for this church.';
        } else {
            $stmt = $conn->prepare('INSERT INTO asset_departments (church_id, name, description, is_active) VALUES (?, ?, ?, 1)');
            $stmt->bind_param('iss', $churchId, $name, $description);
            $ok = $stmt->execute();
            $newId = (int) $conn->insert_id;
            $stmt->close();
            if ($ok) {
                asset_log_action('asset_department_create', 'asset_department', $newId, [
                    'church_id' => $churchId,
                    'name' => $name,
                ]);
                header('Location: asset_department_list.php?saved=1' . ($churchId ? '&church_id=' . $churchId : ''));
                exit;
            }
            $error = 'Failed to create department. It may already exist for this church.';
        }
    }
}

ob_start();
?>
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0"><i class="fas fa-sitemap mr-2"></i><?= $isEdit ? 'Edit' : 'Add' ?> Asset Department</h2>
        <a href="asset_department_list.php<?= $churchId ? '?church_id=' . (int) $churchId : '' ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left mr-1"></i> Back
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <?php if (asset_is_super_admin()): ?>
                    <div class="form-group">
                        <label for="church_id">Church <span class="text-danger">*</span></label>
                        <select class="form-control" id="church_id" name="church_id" required>
                            <option value="">-- Select Church --</option>
                            <?php foreach ($churches as $church): ?>
                                <option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($church['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="name">Department Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save mr-1"></i> Save
                </button>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
