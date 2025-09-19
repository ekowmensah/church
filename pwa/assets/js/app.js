// PWA App Main JS
// Enhanced SPA navigation and API calls

// API Base URL
const API_BASE_URL = '../api';

// DOM Elements
let currentMember = null;

// Offline storage for payment requests
const STORAGE_KEY = 'fmc_offline_payments';

// Store payment request offline
function storePaymentOffline(paymentData) {
    try {
        const offlinePayments = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        paymentData.stored_at = new Date().toISOString();
        paymentData.synced = false;
        offlinePayments.push(paymentData);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(offlinePayments));
        console.log('Payment stored offline:', paymentData.reference);
    } catch (error) {
        console.error('Error storing payment offline:', error);
    }
}

// Get offline payments
function getOfflinePayments() {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
    } catch (error) {
        console.error('Error getting offline payments:', error);
        return [];
    }
}

// Sync offline payments when online
async function syncOfflinePayments() {
    if (!navigator.onLine) return;
    
    const offlinePayments = getOfflinePayments();
    const unsynced = offlinePayments.filter(p => !p.synced);
    
    for (const payment of unsynced) {
        try {
            // Check if payment was completed via USSD
            const response = await fetch(`${API_BASE_URL}/check_payment_status.php?reference=${payment.reference}`);
            const result = await response.json();
            
            if (result.success && result.status === 'completed') {
                // Mark as synced
                payment.synced = true;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(offlinePayments));
                console.log('Payment synced:', payment.reference);
            }
        } catch (error) {
            console.error('Error syncing payment:', payment.reference, error);
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Initialize the app
    initializeNavigation();
    initializePaymentForm();
    loadPaymentTypes();
    loadPaymentPeriods();
    checkForInstallPrompt();
    registerServiceWorker();
    
    // Load payment history if on history page
    if (window.location.hash === '#history') {
        loadPaymentHistory();
    }
    
    // Sync offline payments when online
    if (navigator.onLine) {
        syncOfflinePayments();
    }
    
    // Listen for online/offline events
    window.addEventListener('online', syncOfflinePayments);
    window.addEventListener('offline', () => {
        showAlert('warning', 'You are now offline. Payments will be stored locally.');
    });
});

// Initialize navigation
function initializeNavigation() {
    document.querySelectorAll('[data-page]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const page = link.getAttribute('data-page');
            navigateTo(page);
        });
    });

    // Handle back/forward browser buttons
    window.addEventListener('popstate', () => {
        const page = window.location.hash.substring(1) || 'home';
        navigateTo(page, false);
    });

    // Initial page load
    const page = window.location.hash.substring(1) || 'home';
    navigateTo(page, false);
}

// Navigate to page
function navigateTo(page, updateHistory = true) {
    // Update active nav link
    document.querySelectorAll('.nav-link').forEach(link => {
        link.classList.toggle('active', link.getAttribute('data-page') === page);
    });

    // Update page content
    document.querySelectorAll('.page').forEach(p => {
        p.classList.toggle('active', p.id === `${page}-page`);
    });

    // Update URL
    if (updateHistory) {
        history.pushState({}, '', `#${page}`);
    }

    // Load page-specific data
    if (page === 'history') {
        loadPaymentHistory();
    }
}

// Initialize payment form
function initializePaymentForm() {
    const form = document.getElementById('payment-form');
    if (!form) return;

    form.addEventListener('submit', handlePaymentSubmit);
    
    // Enhanced member validation
    const memberInput = document.getElementById('member-identifier');
    const verifyBtn = document.getElementById('verify-member-btn');
    
    if (memberInput && verifyBtn) {
        memberInput.addEventListener('input', handleMemberInputChange);
        memberInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                validateMember();
            }
        });
        verifyBtn.addEventListener('click', validateMember);
    }
    
    // Form field change handlers
    document.getElementById('payment-type').addEventListener('change', updatePaymentSummary);
    document.getElementById('payment-period').addEventListener('change', updatePaymentSummary);
    document.getElementById('payment-amount').addEventListener('input', updatePaymentSummary);
}

// Handle member input changes
function handleMemberInputChange() {
    const input = document.getElementById('member-identifier');
    const memberInfo = document.getElementById('member-info');
    const submitBtn = document.querySelector('.submit-payment-btn');
    
    if (input.value.length < 3) {
        memberInfo.classList.add('d-none');
        currentMember = null;
        updateSubmitButton();
    }
}

// Update submit button state
function updateSubmitButton() {
    const submitBtn = document.querySelector('.submit-payment-btn');
    const btnText = submitBtn.querySelector('.btn-text');
    
    if (!currentMember) {
        submitBtn.disabled = true;
        btnText.textContent = 'Complete Member Verification First';
        submitBtn.innerHTML = '<i class="fas fa-user-times me-2"></i><span class="btn-text">Complete Member Verification First</span>';
    } else {
        const form = document.getElementById('payment-form');
        const isValid = form.checkValidity();
        
        if (isValid) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-mobile-alt me-2"></i><span class="btn-text">Send Payment to Phone</span>';
        } else {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i><span class="btn-text">Please Fill All Fields</span>';
        }
    }
}

// Validate member by ID/phone
async function validateMember() {
    const identifier = document.getElementById('member-identifier').value.trim();
    if (!identifier) return;

    const verifyBtn = document.getElementById('verify-member-btn');
    const memberInfo = document.getElementById('member-info');
    
    try {
        // Show loading state
        verifyBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        verifyBtn.disabled = true;
        
        const response = await fetch(`${API_BASE_URL}/validate_member.php?identifier=${encodeURIComponent(identifier)}`);
        const data = await response.json();
        
        if (data.valid) {
            currentMember = data.member;
            
            // Update member info display
            document.getElementById('member-name').textContent = data.member.name || 'Member';
            document.getElementById('member-details').textContent = `CRN: ${data.member.crn || 'N/A'} | Phone: ${data.member.phone || 'N/A'}`;
            
            // Show member info
            memberInfo.classList.remove('d-none');
            
            // Set default amount if available
            if (data.member.default_amount) {
                document.getElementById('payment-amount').value = data.member.default_amount;
            }
            
            // Update summary and button
            updatePaymentSummary();
            updateSubmitButton();
            
            showAlert('success', `Welcome, ${data.member.name}!`);
        } else {
            currentMember = null;
            memberInfo.classList.add('d-none');
            updateSubmitButton();
            showAlert('danger', data.message || 'Member not found');
        }
    } catch (error) {
        console.error('Validation error:', error);
        currentMember = null;
        memberInfo.classList.add('d-none');
        updateSubmitButton();
        showAlert('danger', 'Error validating member. Please try again.');
    } finally {
        // Reset button
        verifyBtn.innerHTML = '<i class="fas fa-search"></i>';
        verifyBtn.disabled = false;
    }
}

// Update payment summary
function updatePaymentSummary() {
    const summaryDiv = document.getElementById('payment-summary');
    const typeSelect = document.getElementById('payment-type');
    const periodSelect = document.getElementById('payment-period');
    const amountInput = document.getElementById('payment-amount');
    
    // Check if we have enough info to show summary
    if (currentMember && typeSelect.value && periodSelect.value && amountInput.value) {
        // Update summary fields
        document.getElementById('summary-member').textContent = currentMember.name;
        document.getElementById('summary-type').textContent = typeSelect.options[typeSelect.selectedIndex].text;
        document.getElementById('summary-period').textContent = periodSelect.options[periodSelect.selectedIndex].text;
        document.getElementById('summary-amount').textContent = `GHS ${parseFloat(amountInput.value || 0).toFixed(2)}`;
        
        // Show summary
        summaryDiv.classList.remove('d-none');
    } else {
        // Hide summary
        summaryDiv.classList.add('d-none');
    }
    
    // Update submit button
    updateSubmitButton();
}

// Load payment types
async function loadPaymentTypes() {
    try {
        const response = await fetch(`${API_BASE_URL}/payment_types.php`);
        const types = await response.json();
        const select = document.getElementById('payment-type');
        
        if (select) {
            select.innerHTML = '<option value="" selected disabled>Select payment type</option>';
            types.forEach(type => {
                const option = document.createElement('option');
                option.value = type.id;
                option.textContent = type.name;
                if (type.default) option.selected = true;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading payment types:', error);
        showAlert('warning', 'Could not load payment types');
    }
}

// Load payment periods
async function loadPaymentPeriods() {
    try {
        const response = await fetch(`${API_BASE_URL}/payment_periods.php`);
        const periods = await response.json();
        const select = document.getElementById('payment-period');
        
        if (select) {
            select.innerHTML = '<option value="" selected disabled>Select period</option>';
            periods.forEach(period => {
                const option = document.createElement('option');
                option.value = period.id;
                option.textContent = period.name;
                if (period.default) option.selected = true;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading payment periods:', error);
        showAlert('warning', 'Could not load payment periods');
    }
}

// Handle payment form submission
async function handlePaymentSubmit(e) {
    e.preventDefault();
    
    if (!currentMember) {
        showAlert('warning', 'Please validate your member ID first');
        return;
    }

    const formData = {
        member_id: currentMember.id,
        payment_type: document.getElementById('payment-type').value,
        period: document.getElementById('payment-period').value,
        amount: document.getElementById('payment-amount').value,
    };

    // Basic validation
    if (!formData.payment_type || !formData.period || !formData.amount) {
        showAlert('warning', 'Please fill in all required fields');
        return;
    }

    try {
        showLoading('Processing payment...');
        
        const response = await fetch(`${API_BASE_URL}/process_payment.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(formData)
        });

        const result = await response.json();
        
        if (result.success) {
            showAlert('success', result.message);
            
            // Show mobile money prompt modal
            if (result.data) {
                showMobileMoneyPrompt(result.data);
            }
            
            // Reset form
            document.getElementById('payment-form').reset();
            currentMember = null;
        } else {
            showAlert('danger', result.message || 'Payment failed. Please try again.');
        }
    } catch (error) {
        console.error('Payment error:', error);
        showAlert('danger', 'An error occurred while processing your payment');
    } finally {
        hideLoading();
    }
}

// Load payment history
async function loadPaymentHistory() {
    const historyContainer = document.getElementById('history-content');
    const loadingElement = document.getElementById('history-loading');
    
    if (!historyContainer || !loadingElement) return;

    try {
        historyContainer.classList.add('d-none');
        loadingElement.classList.remove('d-none');
        
        const response = await fetch(`${API_BASE_URL}/payment_history.php`);
        const history = await response.json();
        
        if (history.length === 0) {
            historyContainer.innerHTML = '<div class="text-center py-4 text-muted">No payment history found</div>';
        } else {
            historyContainer.innerHTML = history.map(payment => `
                <div class="history-item">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="mb-0">${payment.type}</h5>
                        <span class="badge bg-${payment.status === 'completed' ? 'success' : 'warning'}">
                            ${payment.status}
                        </span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small mb-2">
                        <span>${new Date(payment.date).toLocaleDateString()}</span>
                        <span>Ref: ${payment.reference}</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">GHS ${parseFloat(payment.amount).toFixed(2)}</span>
                        <button class="btn btn-sm btn-outline-primary" onclick="viewReceipt('${payment.reference}')">
                            <i class="fas fa-receipt me-1"></i> Receipt
                        </button>
                    </div>
                </div>
            `).join('');
        }
    } catch (error) {
        console.error('Error loading history:', error);
        historyContainer.innerHTML = `
            <div class="alert alert-danger">
                Failed to load payment history. Please try again later.
            </div>
        `;
    } finally {
        loadingElement.classList.add('d-none');
        historyContainer.classList.remove('d-none');
    }
}

// View receipt
function viewReceipt(reference) {
    // In a real app, this would open a receipt modal or page
    alert(`Receipt for payment ${reference} would be displayed here.`);
    // window.open(`/receipt.php?ref=${reference}`, '_blank');
}

// Show mobile money prompt modal (replaces USSD)
function showMobileMoneyPrompt(paymentData) {
    const modal = document.createElement('div');
    modal.className = 'modal fade show';
    modal.style.display = 'block';
    modal.style.backgroundColor = 'rgba(0,0,0,0.5)';
    modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-mobile-alt me-2"></i>Mobile Money Payment
                    </h5>
                </div>
                <div class="modal-body text-center">
                    <div class="mb-4">
                        <div class="payment-icon mb-3">
                            <i class="fas fa-phone fa-4x text-primary"></i>
                        </div>
                        <h4>Check Your Phone</h4>
                        <p class="text-muted">Mobile money prompt sent to:</p>
                        <h5 class="text-primary">${paymentData.phone}</h5>
                    </div>
                    
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-6"><strong>Member:</strong></div>
                            <div class="col-6">${paymentData.member_name}</div>
                            <div class="col-6"><strong>Amount:</strong></div>
                            <div class="col-6 text-success fw-bold">GHS ${parseFloat(paymentData.amount).toFixed(2)}</div>
                            <div class="col-6"><strong>Reference:</strong></div>
                            <div class="col-6"><small>${paymentData.reference}</small></div>
                        </div>
                    </div>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Instructions:</strong><br>
                        1. Check your phone for mobile money prompt<br>
                        2. Enter your mobile money PIN<br>
                        3. Confirm the payment<br>
                        4. Wait for confirmation SMS
                    </div>
                    
                    <div id="payment-status-${paymentData.payment_id}" class="payment-status">
                        <div class="d-flex align-items-center justify-content-center">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            <span>Waiting for payment confirmation...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeMobileMoneyModal()">
                        Close
                    </button>
                    <button type="button" class="btn btn-primary" onclick="checkPaymentStatus('${paymentData.reference}')">
                        <i class="fas fa-sync-alt me-1"></i>Check Status
                    </button>
                </div>
            </div>
        </div>
    `;
    
    modal.id = 'mobile-money-modal';
    document.body.appendChild(modal);
    
    // Start checking payment status automatically
    startPaymentStatusCheck(paymentData.reference, paymentData.payment_id);
}

// Close mobile money modal
function closeMobileMoneyModal() {
    const modal = document.getElementById('mobile-money-modal');
    if (modal) {
        modal.remove();
    }
    // Clear any running status checks
    if (window.paymentStatusInterval) {
        clearInterval(window.paymentStatusInterval);
    }
}

// Copy USSD code to clipboard
function copyUSSDCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showAlert('success', 'USSD code copied to clipboard!');
    }).catch(() => {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = code;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        showAlert('success', 'USSD code copied!');
    });
}

// Open phone dialer with USSD code
function openDialer(code) {
    // Try to open the phone dialer with the USSD code
    const telUrl = `tel:${encodeURIComponent(code)}`;
    window.location.href = telUrl;
}

// Check payment status
async function checkPaymentStatus(reference) {
    try {
        const response = await fetch(`${API_BASE_URL}/check_payment_status.php?reference=${encodeURIComponent(reference)}`);
        const result = await response.json();
        
        const statusElement = document.querySelector(`#payment-status-modal .payment-status`);
        if (!statusElement) return;
        
        if (result.success) {
            if (result.status === 'completed') {
                statusElement.innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Payment Successful!</strong><br>
                        Your payment has been confirmed.
                    </div>
                `;
                
                // Auto-close modal after 3 seconds
                setTimeout(() => {
                    closePaymentStatus();
                    loadPaymentHistory(); // Refresh history
                }, 3000);
                
            } else if (result.status === 'failed') {
                statusElement.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-times-circle me-2"></i>
                        <strong>Payment Failed</strong><br>
                        ${result.message || 'Please try again or contact support.'}
                    </div>
                `;
            } else {
                statusElement.innerHTML = `
                    <div class="d-flex align-items-center justify-content-center">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        <span>Status: ${result.status || 'Pending'}...</span>
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Status check error:', error);
    }
}

// Start automatic payment status checking
function startPaymentStatusCheck(reference, paymentId) {
    // Check immediately
    checkPaymentStatus(reference);
    
    // Then check every 5 seconds for up to 5 minutes
    let checkCount = 0;
    const maxChecks = 60; // 5 minutes
    
    window.paymentStatusInterval = setInterval(() => {
        checkCount++;
        
        if (checkCount >= maxChecks) {
            clearInterval(window.paymentStatusInterval);
            const statusElement = document.querySelector(`#payment-status-modal .payment-status`);
            if (statusElement) {
                statusElement.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-clock me-2"></i>
                        <strong>Status Check Timeout</strong><br>
                        Please check your payment history or contact support if needed.
                    </div>
                `;
            }
            return;
        }
        
        checkPaymentStatus(reference);
    }, 5000);
}

// Show alert message
function showAlert(type, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.role = 'alert';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    const container = document.querySelector('.container.mt-5.pt-4') || document.body;
    container.insertBefore(alertDiv, container.firstChild);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        const bsAlert = new bootstrap.Alert(alertDiv);
        bsAlert.close();
    }, 5000);
}

// Show loading indicator
function showLoading(message = 'Loading...') {
    let loadingDiv = document.getElementById('loading-overlay');
    
    if (!loadingDiv) {
        loadingDiv = document.createElement('div');
        loadingDiv.id = 'loading-overlay';
        loadingDiv.className = 'position-fixed top-0 start-0 w-100 h-100 d-flex justify-content-center align-items-center bg-dark bg-opacity-50 z-50';
        loadingDiv.innerHTML = `
            <div class="text-center text-white">
                <div class="spinner-border mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mb-0">${message}</p>
            </div>
        `;
        document.body.appendChild(loadingDiv);
    }
}

// Hide loading indicator
function hideLoading() {
    const loadingDiv = document.getElementById('loading-overlay');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

// Register service worker
function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('service-worker.js')
                .then(registration => {
                    console.log('ServiceWorker registration successful');
                })
                .catch(error => {
                    console.log('ServiceWorker registration failed: ', error);
                });
        });
    }
}

// Handle PWA install prompt
let deferredPrompt;

function checkForInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (e) => {
        // Prevent Chrome 67 and earlier from automatically showing the prompt
        e.preventDefault();
        // Stash the event so it can be triggered later
        deferredPrompt = e;
        
        // Show install button
        const installButton = document.createElement('button');
        installButton.className = 'btn btn-outline-light position-fixed bottom-0 end-0 m-3';
        installButton.id = 'install-button';
        installButton.innerHTML = '<i class="fas fa-download me-2"></i>Install App';
        installButton.onclick = showInstallPrompt;
        document.body.appendChild(installButton);
    });
}

// Show install prompt
function showInstallPrompt() {
    if (!deferredPrompt) return;
    
    // Show the install prompt
    deferredPrompt.prompt();
    
    // Wait for the user to respond to the prompt
    deferredPrompt.userChoice.then((choiceResult) => {
        if (choiceResult.outcome === 'accepted') {
            console.log('User accepted the install prompt');
        } else {
            console.log('User dismissed the install prompt');
        }
        deferredPrompt = null;
        
        // Remove install button
        const installButton = document.getElementById('install-button');
        if (installButton) {
            installButton.remove();
        }
    });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', () => {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(tooltipTriggerEl => {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});