<?php
// Get member info and church logo
//if (session_status() === PHP_SESSION_NONE) session_start();

$logo_path = BASE_URL.'/logo.png';
$member_name = $_SESSION['member_name'] ?? 'Member';
$member_role = 'Church Member'; // Could be fetched from database

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

<!-- Modern Professional Sidebar -->
<aside class="main-sidebar sidebar-dark-primary elevation-4" style="background: linear-gradient(180deg, #2c3e50 0%, #34495e 100%);">
  <div class="sidebar">
    <!-- Brand Logo and Welcome Section -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex flex-column" style="border-bottom: 1px solid rgba(255,255,255,0.1);">
      <!-- Church Logo -->
      <div class="text-center mb-3">
        <div class="logo-container" style="position: relative; display: inline-block;">
          <img src="<?=$logo_path?>" alt="Church Logo" class="brand-image img-circle elevation-3" 
               style="width: 60px; height: 60px; object-fit: cover; border: 3px solid rgba(255,255,255,0.2); background: white; padding: 4px;">
          <div class="logo-overlay" style="position: absolute; top: -2px; right: -2px; width: 20px; height: 20px; background: linear-gradient(45deg, #28a745, #20c997); border-radius: 50%; border: 2px solid #2c3e50;"></div>
        </div>
        <h6 class="brand-text font-weight-bold text-white mt-2 mb-1">MyFreeman Church</h6>
        <small class="text-light opacity-75">Member Portal</small>
      </div>
      
      <!-- Member Welcome -->
      <div class="info text-center">
        <div class="member-welcome p-2" style="background: rgba(255,255,255,0.1); border-radius: 8px; backdrop-filter: blur(10px);">
          <div class="d-flex align-items-center justify-content-center mb-1">
            <i class="fas fa-user-circle text-light mr-2" style="font-size: 1.2rem;"></i>
            <span class="text-white font-weight-bold" style="font-size: 0.9rem;"><?= htmlspecialchars(explode(' ', $member_name)[0]) ?></span>
          </div>
          <small class="text-light opacity-75"><?= htmlspecialchars($member_role) ?></small>
        </div>
      </div>
    </div>
    <!-- Navigation Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false" style="padding: 0 1rem;">
        
        <!-- Quick Actions Section -->
        <li class="nav-header" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 1rem 0 0.5rem 0;">
          <i class="fas fa-bolt mr-2"></i>Quick Actions
        </li>
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/member_dashboard.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-home"></i>
            </div>
            <span class="nav-text">Dashboard</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/member_events.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-calendar-alt"></i>
            </div>
            <span class="nav-text">Events</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/member_health_records.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-heartbeat"></i>
            </div>
            <span class="nav-text">Health Records</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        
        <!-- Personal Section -->
        <li class="nav-header" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 1.5rem 0 0.5rem 0;">
          <i class="fas fa-user mr-2"></i>Personal
        </li>
        
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/member_profile.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-user-edit"></i>
            </div>
            <span class="nav-text">My Profile</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/attendance_history.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-calendar-check"></i>
            </div>
            <span class="nav-text">My Attendance</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        <!-- Membership Section -->
        <li class="nav-header" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 1.5rem 0 0.5rem 0;">
          <i class="fas fa-church mr-2"></i>Membership
        </li>
        
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link modern-nav-link modern-treeview-toggle">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-id-card"></i>
            </div>
            <span class="nav-text">Church Life</span>
            <i class="right fas fa-angle-left treeview-arrow"></i>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_class.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-book nav-icon"></i>
                </div>
                <span class="nav-text">Bible Class</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_organizations.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-users nav-icon"></i>
                </div>
                <span class="nav-text">Organizations</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_service_roles.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-user-tie nav-icon"></i>
                </div>
                <span class="nav-text">Service Roles</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_join_organization.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-users-cog nav-icon"></i>
                </div>
                <span class="nav-text">Join Organization(s)</span>
              </a>
            </li>
          </ul>
        </li>
        <!-- Financial Section -->
        <li class="nav-header" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 1.5rem 0 0.5rem 0;">
          <i class="fas fa-dollar-sign mr-2"></i>Financial
        </li>
        
        <li class="nav-item has-treeview">
          <a href="#" class="nav-link modern-nav-link modern-treeview-toggle">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-wallet"></i>
            </div>
            <span class="nav-text">Payments</span>
            <i class="right fas fa-angle-left treeview-arrow"></i>
          </a>
          <ul class="nav nav-treeview">
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/make_payment.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-credit-card nav-icon"></i>
                </div>
                <span class="nav-text">Make Payment</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/payment_history.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-history nav-icon"></i>
                </div>
                <span class="nav-text">Payment History</span>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?php echo BASE_URL; ?>/views/member_harvest_records.php" class="nav-link modern-sub-nav-link">
                <div class="sub-nav-icon-wrapper">
                  <i class="fas fa-seedling nav-icon"></i>
                </div>
                <span class="nav-text">Harvest Records</span>
              </a>
            </li>
          </ul>
        </li>
        <!-- Communication Section -->
        <li class="nav-header" style="color: rgba(255,255,255,0.6); font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin: 1.5rem 0 0.5rem 0;">
          <i class="fas fa-comments mr-2"></i>Communication
        </li>
        
        <li class="nav-item">
          <a class="nav-link modern-nav-link" href="<?php echo BASE_URL; ?>/views/memberfeedback_my.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-comment-dots"></i>
            </div>
            <span class="nav-text">Feedback & Chat</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
        <!-- Logout Section -->
        <li class="nav-item" style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.1);">
          <a class="nav-link modern-nav-link logout-link" href="<?php echo BASE_URL; ?>/logout.php">
            <div class="nav-icon-wrapper">
              <i class="nav-icon fas fa-sign-out-alt"></i>
            </div>
            <span class="nav-text">Logout</span>
            <div class="nav-indicator"></div>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
