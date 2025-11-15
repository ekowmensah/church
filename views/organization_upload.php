<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$can_upload = $is_super_admin || (has_permission('manage_organizations'));
if (!$can_upload) {
    die('No permission to upload Organizations.');
}

$success = '';
$error = '';
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['org_file'])) {
    $file = $_FILES['org_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } else {
            $csv = fopen($file['tmp_name'], 'r');
            $header = fgetcsv($csv);
            $expected = ['name','code','type'];
            if ($header === false || array_map('strtolower', $header) !== $expected) {
                $error = 'CSV header must be: '.implode(',', $expected);
            } else {
                $count = 0;
                $row_num = 1;
                while ($row = fgetcsv($csv)) {
                    $row_num++;
                    list($name, $code, $type) = $row;
                    if (!$name || !$code || !$type) {
                        $upload_errors[] = "Row $row_num: Missing name, code, or type.";
                        continue;
                    }
                    $stmt = $conn->prepare('INSERT INTO organizations (name, code, type) VALUES (?, ?, ?)');
                    if (!$stmt) {
                        $upload_errors[] = "Row $row_num: Prepare failed (".$conn->error.").";
                        continue;
                    }
                    $stmt->bind_param('sss', $name, $code, $type);
                    if (!$stmt->execute()) {
                        $upload_errors[] = "Row $row_num: Insert failed (".$stmt->error.").";
                    } else {
                        $count++;
                    }
                    $stmt->close();
                }
                $success = "Successfully uploaded $count Organizations.";
            }
            fclose($csv);
        }
    } else {
        $error = 'File upload error.';
    }
}

ob_start();
?>
<div class="container mt-4">
    <div class="card shadow" style="max-width:600px;margin:auto;">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">Bulk Organization Upload</h5>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php elseif ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (!empty($upload_errors)): ?>
                <div class="alert alert-warning alert-dismissible fade show mt-2" role="alert">
                    <strong>Some rows were skipped:</strong>
                    <ul class="mb-0">
                        <?php foreach ($upload_errors as $e): ?>
                            <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="org_file">Select CSV File</label>
                    <input type="file" class="form-control" name="org_file" id="org_file" accept=".csv" required>
                    <small class="form-text text-muted">CSV header must be: name,code,type</small>
                </div>
                <button type="submit" class="btn btn-success">Upload</button>
                <a href="organization_list.php" class="btn btn-secondary ml-2">Back to List</a>
                <a href="sample_organization_upload.csv" class="btn btn-link ml-2">Download Sample CSV</a>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
