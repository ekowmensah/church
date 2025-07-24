<!-- Member Dashboard Sidebar -->
<!-- Member Dashboard Sidebar: Integrates with AdminLTE/Layout. No fixed height or position. -->
<aside class="main-sidebar sidebar-light-primary elevation-2">
  <div class="sidebar">
    <!-- Church Logo -->
    <?php
      $logo_path = BASE_URL.'/logo.png';
      if (isset($_SESSION['member_id'])) {
        $conn = $GLOBALS['conn'] ?? null;
        if ($conn) {
          $stmt = $conn->prepare('SELECT c.logo FROM members m JOIN churches c ON m.church_id = c.id WHERE m.id = ? LIMIT 1');
          $stmt->bind_param('i', $_SESSION['member_id']);
          $stmt->execute();
          $stmt->bind_result($church_logo);
          if ($stmt->fetch() && $church_logo && file_exists(__DIR__.'/../uploads/'.$church_logo)) {
            $logo_path = BASE_URL.'/uploads/'.rawurlencode($church_logo);
          }
          $stmt->close();
        }
      }
    ?>
    <div class="text-center py-3">
      <img src="<?=$logo_path?>" alt="Church Logo" style="max-width:120px; max-height:90px; margin:0 auto 10px auto; box-shadow:0 2px 8px rgba(0,0,0,0.09); border-radius:9px; background:#fff; padding:8px;">
    </div>
    <nav class="mt-3">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/member_dashboard.php">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>Dashboard</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/member_profile.php">
            <i class="nav-icon fas fa-user"></i>
            <p>Profile</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/member_health_records.php">
            <i class="nav-icon fas fa-heartbeat"></i>
            <p>My Health Records</p>
          </a>
        </li>
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-id-card"></i>
            <p>Membership <i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview ml-3">
            
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_class.php" class="nav-link">
                <i class="fas fa-book nav-icon"></i>
                <p>Bible Class</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_organizations.php" class="nav-link">
                <i class="fas fa-users-cog nav-icon"></i>
                <p>Organizations</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_profile.php#church-membership-details" class="nav-link">
                <i class="fas fa-user-tie nav-icon"></i>
                <p>Service Roles</p>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-money-check-alt"></i>
            <p>Payments<i class="right fas fa-angle-left"></i></p>
          </a>
          <ul class="nav nav-treeview ml-3">
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/make_payment.php" class="nav-link">
                <i class="fas fa-credit-card nav-icon"></i>
                <p>Make Payments</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/payment_history.php" class="nav-link">
                <i class="fas fa-history nav-icon"></i>
                <p>Payment History</p>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/attendance_history.php">
            <i class="nav-icon fas fa-calendar-check"></i>
            <p>View Attendance History</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/member_events.php">
            <i class="nav-icon fas fa-calendar-alt"></i>
            <p>Upcoming Events</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?php echo BASE_URL; ?>/views/memberfeedback_my.php">
            <i class="nav-icon fas fa-comments"></i>
            <p>Feedback Chats</p>
          </a>
        </li>
        <li class="nav-item mt-3">
          <a class="nav-link text-danger" href="<?php echo BASE_URL; ?>/logout.php">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <p>Logout</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
