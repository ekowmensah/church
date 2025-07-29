<!-- Member Dashboard Sidebar: Enhanced for Mobile Responsiveness -->
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
    <div class="text-center py-3" style="border-bottom: 1px solid rgba(0,0,0,0.1); margin-bottom: 1rem;">
      <img src="<?=$logo_path?>" alt="Church Logo" style="max-width:100px; max-height:75px; margin:0 auto 10px auto; box-shadow:0 2px 8px rgba(0,0,0,0.09); border-radius:8px; background:#fff; padding:6px;">
      <h5 class="mb-0 font-weight-bold text-primary">MyFreeman Church</h5>
    </div>
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false" style="padding: 0 0.5rem;">
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/member_dashboard.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-tachometer-alt" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Dashboard</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/member_profile.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-user" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Profile</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/member_health_records.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-heartbeat" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">My Health Records</p>
          </a>
        </li>
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link d-flex align-items-center" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem; position: relative;">
            <i class="nav-icon fas fa-id-card" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0 flex-grow-1">Membership</p>
            <i class="right fas fa-angle-left" style="position: absolute; right: 1rem; transition: transform 0.3s;"></i>
          </a>
          <ul class="nav nav-treeview" style="padding-left: 1rem; margin-top: 0.25rem;">
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_class.php" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-book nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Bible Class</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_organizations.php" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-users-cog nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Organizations</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_profile.php#church-membership-details" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-user-tie nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Service Roles</p>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link d-flex align-items-center" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem; position: relative;">
            <i class="nav-icon fas fa-money-check-alt" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0 flex-grow-1">Payments</p>
            <i class="right fas fa-angle-left" style="position: absolute; right: 1rem; transition: transform 0.3s;"></i>
          </a>
          <ul class="nav nav-treeview" style="padding-left: 1rem; margin-top: 0.25rem;">
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/make_payment.php" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-credit-card nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Make Payments</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/payment_history.php" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-history nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Payment History</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_harvest_records.php" class="nav-link d-flex align-items-center" style="min-height: 40px; padding: 0.5rem 1rem; border-radius: 6px; margin-bottom: 0.125rem;">
                <i class="fas fa-seedling nav-icon" style="width: 16px; margin-right: 0.75rem; font-size: 0.875rem;"></i>
                <p class="mb-0">Harvest Records</p>
              </a>
            </li>
          </ul>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/attendance_history.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-calendar-check" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Attendance History</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/member_events.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-calendar-alt" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Upcoming Events</p>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link d-flex align-items-center" href="<?php echo BASE_URL; ?>/views/memberfeedback_my.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.25rem;">
            <i class="nav-icon fas fa-comments" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Feedback Chats</p>
          </a>
        </li>
        <li class="nav-item" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid rgba(0,0,0,0.1);">
          <a class="nav-link text-danger d-flex align-items-center" href="<?php echo BASE_URL; ?>/logout.php" style="min-height: 44px; padding: 0.75rem 1rem; border-radius: 8px; background: rgba(220, 53, 69, 0.1);">
            <i class="nav-icon fas fa-sign-out-alt" style="width: 20px; margin-right: 0.75rem;"></i>
            <p class="mb-0">Logout</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
