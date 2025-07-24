<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Defaults
$user_name = 'User Name';
$user_photo = BASE_URL . '/assets/img/undraw_profile.svg';
$user_roles_arr = [];
$is_super_admin = false;

if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if ($user) {
        $user_name = htmlspecialchars($user['name'] ?? $user['username']);
        $is_super_admin = ($uid == 1) || (isset($user['role_id']) && $user['role_id'] == 1);
        if ($is_super_admin) {
            $user_roles_arr[] = 'Super Admin';
        } else {
            $stmt_roles = $conn->prepare('SELECT r.name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ?');
            $stmt_roles->bind_param('i', $uid);
            $stmt_roles->execute();
            $res_roles = $stmt_roles->get_result();
            while ($role_row = $res_roles->fetch_assoc()) {
                $user_roles_arr[] = $role_row['name'];
            }
        }
        if (!empty($user['photo']) && file_exists(__DIR__ . '/../uploads/users/' . $user['photo'])) {
            $user_photo = BASE_URL . '/uploads/users/' . rawurlencode($user['photo']);
        } elseif (!empty($user['member_id'])) {
            $stmt2 = $conn->prepare('SELECT photo FROM members WHERE id = ?');
            $stmt2->bind_param('i', $user['member_id']);
            $stmt2->execute();
            $m = $stmt2->get_result()->fetch_assoc();
            if ($m && !empty($m['photo']) && file_exists(__DIR__ . '/../uploads/members/' . $m['photo'])) {
                $user_photo = BASE_URL . '/uploads/members/' . rawurlencode($m['photo']);
            }
        }
    }
}
?>
<!-- AdminLTE Main Header -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light border-bottom shadow-sm" style="margin-left:0;padding-left:0;">
  <!-- Left navbar links -->
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?php echo BASE_URL; ?>/index.php" class="navbar-brand font-weight-bold text-primary" style="font-size:1.25rem;">Freeman Methodist Church Kwesimintsim - Takoradi</a>
    </li>
  </ul>

  <!-- Right navbar links -->
  <ul class="navbar-nav ml-auto align-items-center">
    <!-- User Dropdown Menu -->
    <li class="nav-item dropdown user-menu">
      <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
        <img src="<?php echo $user_photo; ?>" class="user-image img-circle elevation-2" alt="User Image" style="width:36px;height:36px;object-fit:cover;">
        <span class="d-none d-md-inline font-weight-bold"><?php echo htmlspecialchars($user_name); ?></span>
      </a>
      <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
        <!-- User image -->
        <li class="user-header bg-primary">
          <img src="<?php echo $user_photo; ?>" class="img-circle elevation-2 mb-2" alt="User Image" style="width:70px;height:70px;object-fit:cover;">
          <p class="mb-0">
            <?php echo htmlspecialchars($user_name); ?>
            <br>
            <?php if (!empty($user_roles_arr)): ?>
              <?php foreach ($user_roles_arr as $role): ?>
                <?php $badge = ($role === 'Super Admin') ? 'badge-danger' : 'badge-light'; ?>
                <span class="badge <?php echo $badge; ?> mx-1" style="font-size:0.93em;"><?php echo htmlspecialchars($role); ?></span>
              <?php endforeach; ?>
            <?php endif; ?>
          </p>
        </li>
        <!-- Menu Footer-->
        <li class="user-footer d-flex justify-content-between">
          <?php if (isset($_SESSION['member_id'])): ?>
            <a href="<?php echo BASE_URL; ?>/views/member_profile.php" class="btn btn-default btn-flat">Profile</a>
          <?php elseif (isset($_SESSION['user_id'])): ?>
            <a href="<?php echo BASE_URL; ?>/views/profile.php" class="btn btn-default btn-flat">Profile</a>
          <?php endif; ?>
          <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-default btn-flat text-danger">Sign out</a>
        </li>
      </ul>
    </li>
  </ul>
</nav>