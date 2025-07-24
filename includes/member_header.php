<!-- Member Dashboard Header: Integrates with AdminLTE/Layout. -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light border-bottom">
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?php echo BASE_URL; ?>/views/member_dashboard.php" class="nav-link font-weight-bold">Member Dashboard</a>
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
<a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="memberDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
  <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="Profile" class="rounded-circle mr-2" style="width:35px;height:35px;object-fit:cover;">
  <span><?php echo htmlspecialchars($_SESSION['member_name'] ?? 'Member'); ?></span>
</a>
      <div class="dropdown-menu dropdown-menu-right" aria-labelledby="memberDropdown">
        <a class="dropdown-item" href="<?php echo BASE_URL; ?>/views/member_profile.php"><i class="fas fa-id-card mr-2"></i> My Profile</a>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/logout.php"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
      </div>
    </li>
  </ul>
</nav>
