<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$editing = isset($_GET['id']) && is_numeric($_GET['id']);
$required_permission = $editing ? 'edit_church' : 'add_church';

if (!$is_super_admin && !has_permission($required_permission)) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('add_church');
$can_edit = $is_super_admin || has_permission('edit_church');
$can_view = true; // Already validated above

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $name = trim($_POST['name'] ?? '');
    $church_code = trim($_POST['church_code'] ?? '');
    $circuit_code = trim($_POST['circuit_code'] ?? '');
    $logo = '';
    if (!$name || !$church_code) {
        $error = 'Name and Church Code are required.';
    } else {
        // Handle logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $logo = uniqid('logo_').'.'.$ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], __DIR__.'/../uploads/'.$logo);
        }
        $stmt = $conn->prepare("INSERT INTO churches (name, church_code, circuit_code, logo) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('ssss', $name, $church_code, $circuit_code, $logo);
        try {
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                header('Location: church_list.php?added=1');
                exit;
            } else {
                $error = 'Database error. Please try again.';
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1062) {
                $error = 'Church Code must be unique. Another church already uses this code.';
            } else {
                $error = 'Database error: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Add Church</h1>
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
                <?php elseif (isset($_GET['added'])): ?>
                    <div class="alert alert-success">Church added successfully!</div>
                <?php endif; ?>
                <form enctype="multipart/form-data" method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="name">Church Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="name" required>
                    </div>
                    <div class="form-group">
                        <label for="church_code">Church Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="church_code" id="church_code" required>
                    </div>
                    <div class="form-group">
                        <label for="circuit_code">Circuit Code</label>
                        <input type="text" class="form-control" name="circuit_code" id="circuit_code">
                    </div>
                    <div class="form-group">
                        <label for="logo">Logo</label>
                        <input type="file" class="form-control-file" name="logo" id="logo" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
