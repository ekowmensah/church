/**
 * Logout Confirmation Modal Handler
 * Intercepts logout links and shows confirmation modal
 */
$(document).ready(function() {
    // Intercept all logout links
    $(document).on('click', 'a[href*="logout.php"], .logout-link', function(e) {
        e.preventDefault(); // Prevent immediate navigation
        $('#logoutModal').modal('show');
    });
    
    // Handle confirm logout
    $('#confirmLogout').on('click', function() {
        // Show loading state
        $(this).html('<i class="fas fa-spinner fa-spin mr-1"></i>Logging out...');
        $(this).prop('disabled', true);
        
        // Redirect to logout after short delay for UX
        setTimeout(function() {
            // Get the base URL dynamically
            const baseUrl = $('meta[name="base-url"]').attr('content') || '';
            window.location.href = baseUrl + '/logout.php';
        }, 500);
    });
    
    // Reset modal when closed
    $('#logoutModal').on('hidden.bs.modal', function() {
        $('#confirmLogout').html('<i class="fas fa-sign-out-alt mr-1"></i>Yes, Logout');
        $('#confirmLogout').prop('disabled', false);
    });
    
    // Handle keyboard shortcuts (optional: Ctrl+Alt+L for logout)
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.altKey && e.keyCode === 76) { // Ctrl+Alt+L
            e.preventDefault();
            $('#logoutModal').modal('show');
        }
    });
});
