<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
// Fetch user info (adjust table/fields as needed)
$stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$page_content = ob_start();
?>
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0">My Profile</h1>
            </div>
        </div>
    </div>
</div>
<div class="card card-primary card-outline">
    <div class="card-body box-profile">
        <div class="text-center">
    <img class="profile-user-img img-fluid img-circle"
         src="<?php echo !empty($user['photo']) ? BASE_URL . '/uploads/users/' . htmlspecialchars($user['photo']) : BASE_URL . '/assets/img/undraw_profile.svg'; ?>"
         alt="User profile picture" style="width:120px;height:120px;object-fit:cover;">
</div>
<h3 class="profile-username text-center"><?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></h3>
<p class="text-muted text-center"><?php echo htmlspecialchars($user['username']); ?></p>
        <ul class="list-group list-group-unbordered mb-3">
            <li class="list-group-item">
                <b>Email</b> <span class="float-right"><?php echo htmlspecialchars($user['email']); ?></span>
            </li>
            <li class="list-group-item">
                <b>Role</b> <span class="float-right"><?php echo htmlspecialchars($user['role'] ?? ''); ?></span>
            </li>
        </ul>
        <a href="<?php echo BASE_URL; ?>/views/profile_edit.php" class="btn btn-primary btn-block"><b>Edit Profile</b></a>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
