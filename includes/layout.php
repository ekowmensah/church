<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="base-url" content="<?php echo BASE_URL; ?>">
    
    <!-- Favicon and App Icons -->
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>/assets/img/favicon.svg">
    <link rel="icon" type="image/x-icon" href="<?php echo BASE_URL; ?>/assets/img/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo BASE_URL; ?>/assets/img/apple-touch-icon.png">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/assets/img/site.webmanifest">
    
    <!-- Theme and App Metadata -->
    <meta name="theme-color" content="#667eea">
    <meta name="msapplication-TileColor" content="#667eea">
    <meta name="application-name" content="Church CMS">
    <meta name="apple-mobile-web-app-title" content="Church CMS">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Church Management System'; ?></title>
    
    <!-- Modern Sidebar Styles -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/modern-sidebar.css">
    <style>
      /* Enhanced Responsive Layout Styles */
      .content-wrapper {
        overflow-x: auto;
        margin-left: 260px !important;
        margin-top: 57px !important;
        z-index: 1000;
        position: relative;
        min-height: calc(100vh - 57px);
        padding-bottom: 20px;
      }
      
      .dashboard-main {
        min-width: 0;
        width: 100%;
        overflow-x: auto;
      }
      
      /* Mobile Sidebar Overlay */
      @media (max-width: 991.98px) {
        .content-wrapper {
          margin-left: 0 !important;
          margin-top: 57px !important;
          width: 100%;
        }
        
        .main-sidebar {
          position: fixed !important;
          top: 0;
          left: -260px;
          height: 100vh;
          z-index: 1050;
          transition: left 0.3s ease-in-out;
          box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .main-sidebar.sidebar-open {
          left: 0;
        }
        
        /* Mobile sidebar backdrop */
        .sidebar-backdrop {
          position: fixed;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          background: rgba(0,0,0,0.5);
          z-index: 1040;
          display: none;
        }
        
        .sidebar-backdrop.show {
          display: block;
        }
        
        /* Mobile close button */
        .sidebar-close-btn {
          position: absolute;
          top: 15px;
          right: 15px;
          background: rgba(255,255,255,0.9);
          border: none;
          border-radius: 50%;
          width: 35px;
          height: 35px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 18px;
          color: #333;
          z-index: 1060;
          cursor: pointer;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .sidebar-close-btn:hover {
          background: #fff;
          transform: scale(1.05);
        }
      }
      
      /* Tablet breakpoint */
      @media (min-width: 768px) and (max-width: 991.98px) {
        .content-wrapper {
          padding: 15px;
        }
      }
      
      /* Small mobile */
      @media (max-width: 575.98px) {
        .content-wrapper {
          padding: 10px;
        }
        
        .main-header .navbar-nav .nav-item .nav-link {
          padding: 0.5rem 0.75rem;
        }
      }
      
      /* Ensure tables are responsive */
      .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
      }
      
      /* Card responsiveness */
      .card {
        margin-bottom: 1rem;
      }
      
      @media (max-width: 575.98px) {
        .card {
          margin-bottom: 0.75rem;
        }
        
        .card-body {
          padding: 1rem 0.75rem;
        }
      }
      
      /* Fix for AdminLTE navbar on mobile */
      @media (max-width: 991.98px) {
        .main-header .navbar {
          margin-left: 0 !important;
        }
      }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <!-- AdminLTE Select2 CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/AdminLTE/plugins/select2/css/select2.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/AdminLTE/plugins/select2-bootstrap4-theme/select2-bootstrap4.min.css">

    <script src="<?php echo BASE_URL; ?>/AdminLTE/plugins/jquery/jquery.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/AdminLTE/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
    <!-- AdminLTE + FontAwesome + Google Fonts -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/AdminLTE/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/AdminLTE/plugins/fontawesome-free/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700" rel="stylesheet">
    <!-- Scripts: jQuery and plugins must come FIRST -->
    

    
    

    


    <script src="<?php echo BASE_URL; ?>/AdminLTE/dist/js/adminlte.min.js"></script>


    <!-- DataTables and other plugins (if needed) -->
    <script src="<?php echo BASE_URL; ?>/AdminLTE/plugins/datatables/jquery.dataTables.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/AdminLTE/plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
    <!-- FullCalendar (for dashboard calendar) -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@latest/main.min.js"></script>
    
    <!-- AdminLTE Select2 JS -->
    <script src="<?php echo BASE_URL; ?>/AdminLTE/plugins/select2/js/select2.full.min.js"></script>
    
</head>
<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <!-- Header must come first for proper AdminLTE layout -->
        <?php
        if (isset($_SESSION['member_id'])) {
            include __DIR__.'/member_header.php';
        } else {
            include __DIR__.'/header.php';
        }
        ?>
        
        <!-- Sidebar comes after header -->
        <?php
        if (isset($_SESSION['member_id'])) {
            include __DIR__.'/member_sidebar.php';
        } else {
            include __DIR__.'/sidebar.php';
        }
        ?>
        
        <div class="content-wrapper">
            <!-- Main Content -->
            <section class="content">
                <?php 
if (isset($page_content)) { 
    echo $page_content; 
} else if (isset($content_view) && $content_view) {
    include $content_view;
}
?>
                </div>
            </section>
        </div>
        <?php include __DIR__.'/footer.php'; ?>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="logoutModalLabel">
                        <i class="fas fa-sign-out-alt mr-2"></i>Confirm Logout
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-question-circle text-warning mb-3" style="font-size: 3rem;"></i>
                    <h5>Are you sure you want to logout?</h5>
                    <p class="text-muted">You will be redirected to the login page.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-danger" id="confirmLogout">
                        <i class="fas fa-sign-out-alt mr-1"></i>Yes, Logout
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Session Timeout Warning Modal -->
    <div class="modal fade" id="sessionTimeoutModal" tabindex="-1" role="dialog" aria-labelledby="sessionTimeoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="sessionTimeoutModalLabel">
                        <i class="fas fa-exclamation-triangle mr-2"></i>Session Timeout Warning
                    </h5>
                    <!-- No close button - force user to choose -->
                </div>
                <div class="modal-body text-center">
                    <i class="fas fa-clock text-warning mb-3" style="font-size: 3rem;"></i>
                    <h5>Your session is about to expire!</h5>
                    <p class="text-muted mb-3">You have been inactive for 10 minutes.</p>
                    
                    <!-- Countdown Display -->
                    <div class="alert alert-warning mb-3">
                        <strong>Auto-logout in: <span id="sessionCountdown" class="text-danger font-weight-bold">30</span> seconds</strong>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="progress mb-3" style="height: 8px;">
                        <div id="sessionProgressBar" class="progress-bar bg-success" role="progressbar" 
                             style="width: 100%;" aria-valuenow="30" aria-valuemin="0" aria-valuemax="30">
                        </div>
                    </div>
                    
                    <p class="small text-muted">
                        Click "Stay Logged In" to extend your session, or "Logout Now" to logout immediately.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="logoutNow">
                        <i class="fas fa-sign-out-alt mr-1"></i>Logout Now
                    </button>
                    <button type="button" class="btn btn-success" id="extendSession">
                        <i class="fas fa-clock mr-1"></i>Stay Logged In
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo BASE_URL; ?>/assets/js/sidebar-collapse-single.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/header-dropdown-fix.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/logout-confirmation.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/session-timeout.js"></script>

<script>
// Enhanced Mobile Sidebar Functionality
document.addEventListener('DOMContentLoaded', function() {
    let backdrop = null;
    let closeBtn = null;
    
    function createBackdrop() {
        if (!backdrop) {
            backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop';
            document.body.appendChild(backdrop);
            
            backdrop.addEventListener('click', function() {
                closeSidebar();
            });
        }
    }
    
    function createCloseButton() {
        const sidebar = document.querySelector('.main-sidebar');
        if (sidebar && !closeBtn && window.innerWidth <= 991.98) {
            closeBtn = document.createElement('button');
            closeBtn.className = 'sidebar-close-btn';
            closeBtn.innerHTML = '<i class="fas fa-times"></i>';
            closeBtn.setAttribute('aria-label', 'Close sidebar');
            
            closeBtn.addEventListener('click', function() {
                closeSidebar();
            });
            
            sidebar.appendChild(closeBtn);
        }
    }
    
    function openSidebar() {
        const sidebar = document.querySelector('.main-sidebar');
        if (sidebar && window.innerWidth <= 991.98) {
            createBackdrop();
            createCloseButton();
            
            sidebar.classList.add('sidebar-open');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
    }
    
    function closeSidebar() {
        const sidebar = document.querySelector('.main-sidebar');
        if (sidebar) {
            sidebar.classList.remove('sidebar-open');
            if (backdrop) {
                backdrop.classList.remove('show');
            }
            document.body.style.overflow = ''; // Restore scrolling
        }
    }
    
    // Enhanced pushmenu functionality
    $(document).on('click', '[data-widget="pushmenu"]', function(e) {
        e.preventDefault();
        if (window.innerWidth <= 991.98) {
            const sidebar = document.querySelector('.main-sidebar');
            if (sidebar && sidebar.classList.contains('sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        }
    });
    
    // Close sidebar with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && window.innerWidth <= 991.98) {
            closeSidebar();
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('.main-sidebar');
        
        if (window.innerWidth > 991.98) {
            // Desktop: clean up mobile elements
            closeSidebar();
            if (closeBtn) {
                closeBtn.remove();
                closeBtn = null;
            }
        } else {
            // Mobile: ensure close button exists when sidebar is open
            if (sidebar && sidebar.classList.contains('sidebar-open')) {
                createCloseButton();
            }
        }
    });
    
    // Initialize on load
    createBackdrop();
    if (window.innerWidth <= 991.98) {
        createCloseButton();
    }
});

// Fix for AdminLTE compatibility and touch improvements
$(document).ready(function() {
    // Ensure AdminLTE doesn't interfere with our layout
    $('body').removeClass('sidebar-collapse sidebar-open');
    
    // Improve touch targets for mobile
    if (window.innerWidth <= 991.98) {
        $('.nav-sidebar .nav-link').css({
            'min-height': '44px',
            'display': 'flex',
            'align-items': 'center'
        });
        
        // Make treeview toggles more touch-friendly
        $('.nav-sidebar .nav-item.has-treeview > .nav-link').css({
            'padding-right': '3rem'
        });
    }
    
    // Smooth scrolling for sidebar navigation
    $('.nav-sidebar').css({
        'scroll-behavior': 'smooth'
    });
    
    // Auto-close mobile sidebar when navigating
    $('.nav-sidebar .nav-link:not(.has-treeview)').on('click', function() {
        if (window.innerWidth <= 991.98) {
            setTimeout(function() {
                const sidebar = document.querySelector('.main-sidebar');
                if (sidebar) {
                    sidebar.classList.remove('sidebar-open');
                    const backdrop = document.querySelector('.sidebar-backdrop');
                    if (backdrop) {
                        backdrop.classList.remove('show');
                    }
                    document.body.style.overflow = '';
                }
            }, 300); // Small delay to allow navigation
        }
    });
});
</script>

<?php if (isset($modal_html)) echo $modal_html; ?>
<?php if (isset($additional_js)) echo $additional_js; ?>
<?php if (isset($additional_css)) echo $additional_css; ?>
</body>
</html>
