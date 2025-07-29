<!-- Member Dashboard Header: Enhanced for Mobile Responsiveness -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light border-bottom">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle sidebar">
        <i class="fas fa-bars"></i>
      </a>
    </li>
    <li class="nav-item d-none d-md-inline-block">
      <a href="<?php echo BASE_URL; ?>/views/member_dashboard.php" class="nav-link font-weight-bold">Member Dashboard</a>
    </li>
    <li class="nav-item d-inline-block d-md-none">
      <a href="<?php echo BASE_URL; ?>/views/member_dashboard.php" class="nav-link font-weight-bold">Dashboard</a>
    </li>
  </ul>
  <ul class="navbar-nav ml-auto">
    <li class="nav-item dropdown">
      <?php
if (session_status() === PHP_SESSION_NONE) session_start();
$profile_img = BASE_URL . '/assets/img/undraw_profile.svg';
if (!empty($_SESSION['member_id'])) {
    $conn = $GLOBALS['conn'] ?? null;
    if ($conn) {
        $stmt = $conn->prepare('SELECT photo FROM members WHERE id = ?');
        $stmt->bind_param('i', $_SESSION['member_id']);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        if ($m && !empty($m['photo']) && file_exists(__DIR__.'/../uploads/members/' . $m['photo'])) {
            $profile_img = BASE_URL . '/uploads/members/' . rawurlencode($m['photo']);
        }
    }
}
?>
<a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="memberDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="min-height: 44px; padding: 0.5rem 0.75rem;">
  <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="rounded-circle mr-2" style="width:32px;height:32px;object-fit:cover; flex-shrink: 0;">
  <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['member_name'] ?? 'Member'); ?></span>
  <span class="d-inline d-sm-none"><?php echo htmlspecialchars(explode(' ', $_SESSION['member_name'] ?? 'Member')[0]); ?></span>
</a>
      <div class="dropdown-menu dropdown-menu-right shadow" aria-labelledby="memberDropdown" style="min-width: 200px; border: none; border-radius: 8px;">
        <h6 class="dropdown-header d-flex align-items-center">
          <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="rounded-circle mr-2" style="width:24px;height:24px;object-fit:cover;">
          <?php echo htmlspecialchars($_SESSION['member_name'] ?? 'Member'); ?>
        </h6>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/member_profile.php" style="padding: 0.75rem 1rem; min-height: 44px;">
          <i class="fas fa-id-card mr-3" style="width: 16px;"></i> My Profile
        </a>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-danger d-flex align-items-center" href="<?php echo BASE_URL; ?>/logout.php" style="padding: 0.75rem 1rem; min-height: 44px;">
          <i class="fas fa-sign-out-alt mr-3" style="width: 16px;"></i> Logout
        </a>
      </div>
    </li>
  </ul>
</nav>
