<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (!has_permission('edit_organization')) {
        $error = 'No permission to edit organization';
    }
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    header('Location: organization_list.php');
    exit;
}

// Fetch current data
$stmt = $conn->prepare("SELECT * FROM organizations WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
if (!$org) {
    header('Location: organization_list.php');
    exit;
}

$name = $org['name'];
$description = $org['description'];
$church_id = $org['church_id'] ?? '';
$error = '';
// Fetch churches for dropdown
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $church_id = intval($_POST['church_id'] ?? 0);
    if (!$name) {
        $error = 'Organization name is required.';
    } elseif (!$church_id) {
        $error = 'Please select a church.';
    } else {
        $stmt = $conn->prepare("UPDATE organizations SET name=?, description=?, church_id=? WHERE id=?");
        $stmt->bind_param('ssii', $name, $description, $church_id, $id);
        if ($stmt->execute()) {
            header('Location: organization_list.php?updated=1');
            exit;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Edit Organization</h1>
    <a href="organization_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold">Edit Organization</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger mb-4"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="church_id">Church <span class="text-danger">*</span></label>
                        <select class="form-control" id="church_id" name="church_id" required>
                            <option value="">-- Select Church --</option>
                            <?php if ($churches && $churches->num_rows > 0): while($c = $churches->fetch_assoc()): ?>
                                <option value="<?= $c['id'] ?>" <?= ($church_id==$c['id']?'selected':'') ?>><?= htmlspecialchars($c['name']) ?></option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($description) ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-success">Update Organization</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
