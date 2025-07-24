/**
 * Session Timeout Warning System
 * Shows warning modal after 10 minutes of inactivity
 * Auto-logout after 30 seconds if no action taken
 */
(function() {
    'use strict';
    
    // Configuration
    const IDLE_TIME = 10 * 60 * 1000; // 10 minutes in milliseconds
    const WARNING_TIME = 30 * 1000;   // 30 seconds warning countdown
    
    let idleTimer;
    let warningTimer;
    let countdownTimer;
    let countdownSeconds = 30;
    let isWarningShown = false;
    
    // Initialize session timeout system
    function initSessionTimeout() {
        resetIdleTimer();
        bindActivityEvents();
    }
    
    // Reset the idle timer
    function resetIdleTimer() {
        clearTimeout(idleTimer);
        clearTimeout(warningTimer);
        clearTimeout(countdownTimer);
        
        // Hide warning modal if shown
        if (isWarningShown) {
            hideTimeoutWarning();
        }
        
        // Start idle timer
        idleTimer = setTimeout(showTimeoutWarning, IDLE_TIME);
    }
    
    // Show timeout warning modal
    function showTimeoutWarning() {
        if (isWarningShown) return;
        
        isWarningShown = true;
        countdownSeconds = 30;
        
        // Show the modal
        $('#sessionTimeoutModal').modal({
            backdrop: 'static',
            keyboard: false
        });
        
        // Start countdown
        startCountdown();
        
        // Set auto-logout timer
        warningTimer = setTimeout(function() {
            performAutoLogout();
        }, WARNING_TIME);
    }
    
    // Hide timeout warning modal
    function hideTimeoutWarning() {
        isWarningShown = false;
        $('#sessionTimeoutModal').modal('hide');
        clearTimeout(countdownTimer);
        clearTimeout(warningTimer);
        countdownSeconds = 30;
    }
    
    // Start countdown display
    function startCountdown() {
        updateCountdownDisplay();
        
        countdownTimer = setInterval(function() {
            countdownSeconds--;
            updateCountdownDisplay();
            
            if (countdownSeconds <= 0) {
                clearInterval(countdownTimer);
            }
        }, 1000);
    }
    
    // Update countdown display
    function updateCountdownDisplay() {
        const countdownElement = $('#sessionCountdown');
        const progressBar = $('#sessionProgressBar');
        
        if (countdownElement.length) {
            countdownElement.text(countdownSeconds);
        }
        
        // Update progress bar
        if (progressBar.length) {
            const percentage = (countdownSeconds / 30) * 100;
            progressBar.css('width', percentage + '%');
            
            // Change color as time runs out
            if (countdownSeconds <= 10) {
                progressBar.removeClass('bg-warning bg-danger').addClass('bg-danger');
            } else if (countdownSeconds <= 20) {
                progressBar.removeClass('bg-success bg-danger').addClass('bg-warning');
            } else {
                progressBar.removeClass('bg-warning bg-danger').addClass('bg-success');
            }
        }
    }
    
    // Perform automatic logout
    function performAutoLogout() {
        // Show logging out message
        $('#sessionTimeoutModal .modal-body').html(`
            <div class="text-center">
                <i class="fas fa-spinner fa-spin text-danger mb-3" style="font-size: 3rem;"></i>
                <h5 class="text-danger">Session Expired</h5>
                <p>You are being logged out due to inactivity...</p>
            </div>
        `);
        
        // Redirect after short delay
        setTimeout(function() {
            const baseUrl = $('meta[name="base-url"]').attr('content') || '';
            window.location.href = baseUrl + '/logout.php';
        }, 2000);
    }
    
    // Extend session (user clicked "Stay Logged In")
    function extendSession() {
        hideTimeoutWarning();
        resetIdleTimer();
        
        // Show brief success message
        showSessionExtendedToast();
    }
    
    // Show session extended toast
    function showSessionExtendedToast() {
        const toast = $(`
            <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" 
                 style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <div class="toast-header bg-success text-white">
                    <i class="fas fa-check-circle mr-2"></i>
                    <strong class="mr-auto">Session Extended</strong>
                    <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="toast-body">
                    Your session has been extended for another 10 minutes.
                </div>
            </div>
        `);
        
        $('body').append(toast);
        toast.toast({ delay: 3000 }).toast('show');
        
        // Remove toast after it's hidden
        toast.on('hidden.bs.toast', function() {
            $(this).remove();
        });
    }
    
    // Bind activity events to reset timer
    function bindActivityEvents() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
        
        events.forEach(function(event) {
            document.addEventListener(event, function() {
                if (!isWarningShown) {
                    resetIdleTimer();
                }
            }, true);
        });
    }
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Bind extend session button
        $(document).on('click', '#extendSession', function() {
            extendSession();
        });
        
        // Bind logout now button
        $(document).on('click', '#logoutNow', function() {
            performAutoLogout();
        });
        
        // Initialize the system
        initSessionTimeout();
    });
    
    // Expose functions for debugging (optional)
    window.SessionTimeout = {
        reset: resetIdleTimer,
        showWarning: showTimeoutWarning,
        extend: extendSession
    };
    
})();
