<?php
//if (session_status() === PHP_SESSION_NONE) session_start();

// Get member profile image
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

<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
  <!-- Left navbar links -->
  <ul class="navbar-nav">
    <li class="nav-item">
      <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?php echo BASE_URL; ?>/views/member_dashboard.php" class="nav-link">Home</a>
    </li>
    <li class="nav-item d-none d-sm-inline-block">
      <a href="<?php echo BASE_URL; ?>/views/member_events.php" class="nav-link">Events</a>
    </li>
  </ul>

  <!-- Right navbar links -->
  <ul class="navbar-nav ml-auto">
    <!-- Notifications Dropdown Menu -->
    <li class="nav-item dropdown">
      <a class="nav-link" data-toggle="dropdown" href="#">
        <i class="far fa-bell"></i>
        <span class="badge badge-warning navbar-badge">15</span>
      </a>
      <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
        <span class="dropdown-item dropdown-header">15 Notifications</span>
        <div class="dropdown-divider"></div>
        <a href="#" class="dropdown-item">
          <i class="fas fa-envelope mr-2"></i> 4 new messages
          <span class="float-right text-muted text-sm">3 mins</span>
        </a>
        <div class="dropdown-divider"></div>
        <a href="#" class="dropdown-item">
          <i class="fas fa-users mr-2"></i> 8 friend requests
          <span class="float-right text-muted text-sm">12 hours</span>
        </a>
        <div class="dropdown-divider"></div>
        <a href="#" class="dropdown-item">
          <i class="fas fa-file mr-2"></i> 3 new reports
          <span class="float-right text-muted text-sm">2 days</span>
        </a>
        <div class="dropdown-divider"></div>
        <a href="#" class="dropdown-item dropdown-footer">See All Notifications</a>
      </div>
    </li>
    
    <!-- User Account Dropdown Menu -->
    <li class="nav-item dropdown">
      <a class="nav-link" data-toggle="dropdown" href="#">
        <img src="<?php echo htmlspecialchars($profile_img); ?>" alt="User Avatar" class="img-size-32 mr-2 img-circle">
        <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['member_name'] ?? 'Member'); ?></span>
      </a>
      <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
        <div class="dropdown-divider"></div>
        <a href="<?php echo BASE_URL; ?>/views/member_profile.php" class="dropdown-item">
          <i class="fas fa-user mr-2"></i> Profile
        </a>
        <div class="dropdown-divider"></div>
        <a href="<?php echo BASE_URL; ?>/views/member_health_records.php" class="dropdown-item">
          <i class="fas fa-heartbeat mr-2"></i> Health Records
        </a>
        <div class="dropdown-divider"></div>
        <a href="<?php echo BASE_URL; ?>/views/member_events.php" class="dropdown-item">
          <i class="fas fa-calendar mr-2"></i> Events
        </a>
        <div class="dropdown-divider"></div>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="dropdown-item">
          <i class="fas fa-sign-out-alt mr-2"></i> Logout
        </a>
      </div>
    </li>
  </ul>
</nav>
