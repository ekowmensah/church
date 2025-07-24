<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

$error = '';
$success = '';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!has_permission('edit_church')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $error = 'Invalid church ID.';
} else {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare('SELECT * FROM churches WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $church = $result->fetch_assoc();
    if (!$church) {
        $error = 'Church not found.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $name = trim($_POST['name'] ?? '');
    $church_code = trim($_POST['church_code'] ?? '');
    $circuit_code = trim($_POST['circuit_code'] ?? '');
    $logo = $church['logo'];
    if (!$name || !$church_code) {
        $error = 'Name and Church Code are required.';
    } else {
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $logo = uniqid('logo_').'.'.$ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__.'/../uploads/'.$logo);
        }
        $stmt = $conn->prepare('UPDATE churches SET name=?, church_code=?, circuit_code=?, logo=? WHERE id=?');
        $stmt->bind_param('ssssi', $name, $church_code, $circuit_code, $logo, $id);
        $stmt->execute();
        if ($stmt->affected_rows >= 0) {
            header('Location: church_list.php?updated=1');
            exit;
        } else {
            $error = 'Database error. Please try again.';
        }
    }
}
ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Edit Church</h1>
    <a href="church_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Church Details</h6>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
                <?php endif; ?>
                <form enctype="multipart/form-data" method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Church Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" value="<?=htmlspecialchars($church['name'] ?? '')?>" required>
                    </div>
                    <div class="form-group">
                        <label for="church_code">Church Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="church_code" id="church_code" value="<?=htmlspecialchars($church['church_code'] ?? '')?>" required>
                    </div>
                    <div class="form-group">
                        <label for="circuit_code">Circuit Code</label>
                        <input type="text" class="form-control" name="circuit_code" id="circuit_code" value="<?=htmlspecialchars($church['circuit_code'] ?? '')?>">
                    </div>
                    <div class="form-group">
                        <label for="logo">Logo</label><br>
                        <?php if (!empty($church['logo'])): ?>
                            <img src="<?= BASE_URL ?>/uploads/<?=htmlspecialchars($church['logo'])?>" alt="logo" height="40" class="mb-2"><br>
                        <?php endif; ?>
                        <input type="file" class="form-control-file" name="logo" id="logo" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
