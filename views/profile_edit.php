<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $photo = $user['photo'] ?? '';
    
    // Handle image upload
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];
        if (in_array($ext, $allowed)) {
            $newname = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $target = __DIR__ . '/../uploads/users/' . $newname;
            if (!is_dir(__DIR__ . '/../uploads/users')) mkdir(__DIR__ . '/../uploads/users', 0777, true);
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
                $photo = $newname;
            } else {
                $error = 'Failed to upload image.';
            }
        } else {
            $error = 'Invalid image type.';
        }
    }
    if (!$error) {
        if ($password) {
            $stmt = $conn->prepare('UPDATE users SET name=?, email=?, password=?, photo=? WHERE id=?');
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bind_param('ssssi', $name, $email, $hashed, $photo, $user_id);
        } else {
            $stmt = $conn->prepare('UPDATE users SET name=?, email=?, photo=? WHERE id=?');
            $stmt->bind_param('sssi', $name, $email, $photo, $user_id);
        }
        if ($stmt->execute()) {
            $success = 'Profile updated successfully.';
            // Refresh user data
            $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
        } else {
            $error = 'Update failed.';
        }
    }
}

$page_content = ob_start();
?>
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">Edit Profile</h1>
            </div>
        </div>
    </div>
</div>
<div class="card card-primary card-outline">
    <div class="card-body box-profile">
        <?php if ($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <div class="text-center mb-3">
                <img class="profile-user-img img-fluid img-circle"
                     src="<?php echo !empty($user['photo']) ? BASE_URL . '/uploads/users/' . htmlspecialchars($user['photo']) : BASE_URL . '/assets/img/undraw_profile.svg'; ?>"
                     alt="User profile picture" style="width:120px;height:120px;object-fit:cover;">
                <div class="mt-2">
                    <input type="file" name="photo" accept="image/*" class="form-control-file">
                </div>
            </div>
            <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            <div class="form-group">
                <label>New Password <small>(leave blank to keep current)</small></label>
                <input type="password" name="password" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
            <a href="<?php echo BASE_URL; ?>/views/profile.php" class="btn btn-secondary btn-block">Cancel</a>
        </form>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
