<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users with correct permission
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$can_upload = $is_super_admin || (function_exists('has_permission') && has_permission('manage_members'));
if (!$can_upload) {
    die('No permission to upload members.');
}

$success = '';
$error = '';
$upload_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['members_file'])) {
    $file = $_FILES['members_file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'Only CSV files are allowed.';
        } else {
            $csv = fopen($file['tmp_name'], 'r');
            $header = fgetcsv($csv);
            $expected = ['first_name','middle_name','last_name','gender','dob','phone','church_code','class_code'];
            if ($header === false || array_map('strtolower', $header) !== $expected) {
                $error = 'CSV header must be: '.implode(',', $expected);
            } else {
                $count = 0;
                $row_num = 1;
                while ($row = fgetcsv($csv)) {
                    $row_num++;
                    list($first_name, $middle_name, $last_name, $gender, $dob, $phone, $church_code, $class_code) = $row;
                    if (!$church_code || !$class_code) {
                        $upload_errors[] = "Row $row_num: Missing church_code or class_code.";
                        continue;
                    }
                    // Lookup church_id and circuit_code from church_code
                    $stmt2 = $conn->prepare('SELECT id, circuit_code FROM churches WHERE church_code = ? LIMIT 1');
                    $stmt2->bind_param('s', $church_code);
                    $stmt2->execute();
                    $result2 = $stmt2->get_result();
                    $church = $result2->fetch_assoc();
                    $stmt2->close();
                    if (!$church) {
                        $upload_errors[] = "Row $row_num: church_code '$church_code' not found.";
                        continue;
                    }
                    $church_id = $church['id'];
                    $circuit_code = $church['circuit_code'];
                    // Lookup class_id from class_code and church_id
                    $stmt1 = $conn->prepare('SELECT id FROM bible_classes WHERE code = ? AND church_id = ? LIMIT 1');
                    $stmt1->bind_param('si', $class_code, $church_id);
                    $stmt1->execute();
                    $result1 = $stmt1->get_result();
                    $class = $result1->fetch_assoc();
                    $stmt1->close();
                    if (!$class) {
                        $upload_errors[] = "Row $row_num: class_code '$class_code' not found for church_code '$church_code'.";
                        continue;
                    }
                    $class_id = $class['id'];
                    // Get next sequential number for this church/class
                    $stmt3 = $conn->prepare('SELECT COUNT(*) as cnt FROM members WHERE class_id = ? AND church_id = ?');
                    $stmt3->bind_param('ii', $class_id, $church_id);
                    $stmt3->execute();
                    $result3 = $stmt3->get_result();
                    $count_existing = $result3->fetch_assoc()['cnt'];
                    $stmt3->close();
                    $seq = str_pad($count_existing + 1, 2, '0', STR_PAD_LEFT);
                    // Compose CRN
                    $crn = $church_code . '-' . $class_code . $seq . '-' . $circuit_code;
                    // Insert member
                    $stmt = $conn->prepare('INSERT INTO members (crn, first_name, middle_name, last_name, gender, dob, phone, class_id, church_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $status = 'pending';
                    $stmt->bind_param('ssssssssss', $crn, $first_name, $middle_name, $last_name, $gender, $dob, $phone, $class_id, $church_id, $status);
                    $stmt->execute();
                    $stmt->close();
                    $count++;
                }
                $success = "Successfully uploaded $count members.";
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
            <h5 class="mb-0">Bulk Member Upload</h5>
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
                    <label for="members_file">Select CSV File</label>
                    <input type="file" class="form-control" name="members_file" id="members_file" accept=".csv" required>
                    <small class="form-text text-muted">CSV header must be: crn,first_name,middle_name,last_name,gender,dob,phone,class_id,church_id</small>
                </div>
                <button type="submit" class="btn btn-success">Upload</button>
                <a href="member_list.php" class="btn btn-secondary ml-2">Back to List</a>
                <a href="sample_member_upload.csv" class="btn btn-link ml-2">Download Sample CSV</a>
            </form>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
