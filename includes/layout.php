<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="base-url" content="<?php echo BASE_URL; ?>">
    <title>Church Management System</title>
    <style>
      .content-wrapper {
        overflow-x: visible;
        margin-left: 260px !important;
        z-index: 1000;
        position: relative;
      }
      .dashboard-main {
        min-width: 0;
        width: 100%;
      }
      @media (max-width: 991.98px) {
        .content-wrapper {
          margin-left: 0 !important;
          width: 100%;
        }
      }
      /* .main-header: Let AdminLTE handle header/stacking, do not add margin or width */
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">

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
    
</head>
<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <?php
        if (isset($_SESSION['member_id'])) {
            include __DIR__.'/member_sidebar.php';
        } else {
            include __DIR__.'/sidebar.php';
        }
        ?>
        <div class="content-wrapper">
    <?php
    if (isset($_SESSION['member_id'])) {
        include __DIR__.'/member_header.php';
    } else {
        include __DIR__.'/header.php';
    }
    ?>
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
// Mobile sidebar toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    // Create mobile menu toggle button if it doesn't exist
    if (window.innerWidth <= 991.98) {
        const header = document.querySelector('.main-header');
        if (header && !header.querySelector('.sidebar-toggle')) {
            const toggleBtn = document.createElement('button');
            toggleBtn.className = 'btn btn-link sidebar-toggle d-md-none';
            toggleBtn.innerHTML = '<i class="fas fa-bars"></i>';
            toggleBtn.style.cssText = 'position: fixed; top: 10px; left: 10px; z-index: 1070; color: #333; background: rgba(255,255,255,0.9); border-radius: 4px; padding: 8px 12px;';
            
            toggleBtn.addEventListener('click', function() {
                const sidebar = document.querySelector('.main-sidebar');
                if (sidebar) {
                    sidebar.classList.toggle('sidebar-open');
                }
            });
            
            document.body.appendChild(toggleBtn);
        }
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 991.98) {
            const sidebar = document.querySelector('.main-sidebar');
            const toggleBtn = document.querySelector('.sidebar-toggle');
            
            if (sidebar && sidebar.classList.contains('sidebar-open')) {
                if (!sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                    sidebar.classList.remove('sidebar-open');
                }
            }
        }
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        const sidebar = document.querySelector('.main-sidebar');
        const toggleBtn = document.querySelector('.sidebar-toggle');
        
        if (window.innerWidth > 991.98) {
            // Desktop: ensure sidebar is visible and remove mobile classes
            if (sidebar) {
                sidebar.classList.remove('sidebar-open');
            }
            if (toggleBtn) {
                toggleBtn.style.display = 'none';
            }
        } else {
            // Mobile: show toggle button
            if (toggleBtn) {
                toggleBtn.style.display = 'block';
            }
        }
    });
});

// Fix for AdminLTE compatibility
$(document).ready(function() {
    // Ensure AdminLTE doesn't interfere with our layout
    $('body').removeClass('sidebar-collapse sidebar-open');
    
    // Fix any z-index issues with AdminLTE components
    $('.main-sidebar').css('z-index', '1040');
    $('.content-wrapper').css('z-index', '1000');
});
</script>

<?php if (isset($modal_html)) echo $modal_html; ?>
<?php if (isset($additional_js)) echo $additional_js; ?>
<?php if (isset($additional_css)) echo $additional_css; ?>
</body>
</html>
