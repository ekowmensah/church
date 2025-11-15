<?php
// birthday_sms_manager.php - Birthday SMS Management Interface
ob_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check permissions - allow super admin or users with send_sms permission
if (!$is_super_admin && !has_permission('send_sms')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access Birthday SMS Manager.</p></div>';
    }
    exit;
}

$page_title = "Birthday SMS Manager";
?>

<!-- Content Header (Page header) -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1>
                    <i class="fas fa-birthday-cake mr-2 text-warning"></i>
                    Birthday SMS Manager
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item">
                        <a href="<?= BASE_URL ?>/views/user_dashboard.php">
                            <i class="fas fa-home mr-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="breadcrumb-item active">Birthday SMS</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Main content -->
<section class="content">
    <div class="container-fluid">
            
        <!-- Birthday Members Today -->
        <div class="card card-primary">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-birthday-cake mr-2"></i>
                    Birthday Members Today (<?= date('F j, Y') ?>)
                </h3>
            </div>
            <div class="card-body">
                <div class="row align-items-center mb-3">
                    <div class="col-md-8">
                        <button type="button" class="btn btn-success mr-2" id="loadBirthdayMembers">
                            <i class="fas fa-sync-alt mr-1"></i> Load Today's Birthdays
                        </button>
                        <button type="button" class="btn btn-primary" id="sendAllBirthdaySMS" disabled>
                            <i class="fas fa-paper-plane mr-1"></i> Send SMS to All
                        </button>
                    </div>
                    <div class="col-md-4 text-right">
                        <span class="badge badge-info badge-lg" id="birthdayCount">
                            <i class="fas fa-users mr-1"></i> 0 members
                        </span>
                    </div>
                </div>
                
                <div id="birthdayMembersContainer">
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-birthday-cake fa-4x mb-3"></i>
                        <h5>Ready to Send Birthday Wishes!</h5>
                        <p class="mb-0">Click "Load Today's Birthdays" to see members celebrating today</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test SMS Section -->
        <div class="card card-warning">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-flask mr-2"></i>
                    Test Birthday SMS
                </h3>
            </div>
            <div class="card-body">
                <form id="testSMSForm">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="testPhone">
                                    <i class="fas fa-phone mr-1 text-success"></i> Phone Number
                                </label>
                                <input type="text" class="form-control" id="testPhone" 
                                       placeholder="e.g., 0241234567" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="testName">
                                    <i class="fas fa-user mr-1 text-primary"></i> Name
                                </label>
                                <input type="text" class="form-control" id="testName" 
                                       placeholder="e.g., John Doe" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="btn btn-warning btn-block">
                                    <i class="fas fa-paper-plane mr-2"></i> Send Test SMS
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- SMS Preview -->
        <div class="card card-info">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-eye mr-2"></i>
                    SMS Message Preview
                </h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>Birthday SMS Template Preview</strong>
                </div>
                <div class="bg-light p-3 rounded">
                    <div class="text-muted small mb-2">
                        <i class="fas fa-mobile-alt mr-1"></i> SMS Preview:
                    </div>
                    <div id="smsPreview" style="font-family: monospace; white-space: pre-line; border-left: 3px solid #007bff; padding-left: 10px;">Happy Birthday, [Name]!

As you celebrate another year of God's faithfulness, we wish you all the good things you desire in life. May the Grace and Favour of God be multiplied unto you. Enjoy your special day, and stay blessed.

Freeman Methodist Church, Kwesimintsim.</div>
                    <div class="text-muted small mt-3 d-flex justify-content-between align-items-center">
                        <span>
                            <i class="fas fa-info-circle mr-1"></i> 
                            Character count: <span id="charCount" class="font-weight-bold text-primary">~200</span> characters
                        </span>
                        <span class="badge badge-success">
                            <i class="fas fa-check mr-1"></i> SMS Ready
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Birthday SMS Logs -->
        <div class="card card-secondary">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-history mr-2"></i>
                    Recent Birthday SMS Activity
                </h3>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped" id="birthdaySMSLogs">
                        <thead>
                            <tr>
                                <th><i class="fas fa-clock mr-1"></i> Date/Time</th>
                                <th><i class="fas fa-user mr-1"></i> Member</th>
                                <th><i class="fas fa-phone mr-1"></i> Phone</th>
                                <th><i class="fas fa-check-circle mr-1"></i> Status</th>
                                <th><i class="fas fa-server mr-1"></i> Provider</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="fas fa-history fa-2x mb-3 d-block"></i>
                                    <p class="mb-0">Recent birthday SMS activity will appear here</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-body text-center">
                <i class="fas fa-spinner fa-spin fa-2x text-primary mb-3"></i>
                <p class="mb-0" id="loadingText">Processing...</p>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Load birthday members
    $('#loadBirthdayMembers').click(function() {
        loadBirthdayMembers();
    });

    // Send SMS to all birthday members
    $('#sendAllBirthdaySMS').click(function() {
        if (confirm('Are you sure you want to send birthday SMS to all members with birthdays today?')) {
            sendAllBirthdaySMS();
        }
    });

    // Test SMS form
    $('#testSMSForm').submit(function(e) {
        e.preventDefault();
        sendTestSMS();
    });

    // Load recent SMS logs
    loadRecentSMSLogs();

    // Auto-load birthday members on page load
    loadBirthdayMembers();
});

function loadBirthdayMembers() {
    $('#loadBirthdayMembers').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Loading...');
    
    $.ajax({
        url: '<?= BASE_URL ?>/ajax_birthday_sms.php',
        method: 'POST',
        data: { action: 'get_birthday_members' },
        dataType: 'json',
        success: function(response) {
            if (response.status === 'success') {
                displayBirthdayMembers(response);
                $('#sendAllBirthdaySMS').prop('disabled', response.count === 0);
            } else {
                showAlert('Error loading birthday members: ' + response.message, 'danger');
            }
        },
        error: function() {
            showAlert('Failed to load birthday members', 'danger');
        },
        complete: function() {
            $('#loadBirthdayMembers').prop('disabled', false).html('<i class="fas fa-sync-alt mr-1"></i> Load Today\'s Birthdays');
        }
    });
}

function displayBirthdayMembers(data) {
    $('#birthdayCount').html(`<i class="fas fa-users mr-1"></i> ${data.count} member${data.count !== 1 ? 's' : ''}`);
    
    if (data.count === 0) {
        $('#birthdayMembersContainer').html(`
            <div class="empty-state">
                <i class="fas fa-calendar-check fa-4x"></i>
                <h5>No Birthdays Today</h5>
                <p class="mb-0">No members have birthdays today (${data.formatted_date})</p>
                <small class="text-muted">Check back tomorrow for new birthday celebrations!</small>
            </div>
        `);
        return;
    }

    let html = '<div class="row">';
    data.members.forEach(function(member) {
        html += `
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card card-warning">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="mr-3">
                                <i class="fas fa-birthday-cake fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h6 class="card-title mb-1 font-weight-bold">
                                    ${member.full_name}
                                </h6>
                                <small class="text-muted">
                                    <i class="fas fa-gift mr-1"></i> Birthday Today!
                                </small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-phone text-success mr-2"></i>
                                <span class="small">${member.phone}</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-calendar-alt text-info mr-2"></i>
                                <span class="small">${member.birthday_formatted}</span>
                            </div>
                        </div>
                        
                        <button class="btn btn-primary btn-sm btn-block" 
                                onclick="sendIndividualSMS(${member.id}, '${member.full_name.replace(/'/g, "\\'")}')"">
                            <i class="fas fa-paper-plane mr-2"></i> Send Birthday SMS
                        </button>
                    </div>
                </div>
            </div>
        `;
    });
    html += '</div>';
    
    // Add celebration header
    const celebrationHeader = `
        <div class="celebration-header text-center mb-4">
            <h4 style="color: #2d3436; margin-bottom: 10px;">
                <i class="fas fa-party-horn mr-2" style="color: #ff6b6b;"></i>
                🎉 Today's Birthday Celebrations! 🎉
            </h4>
            <p class="text-muted mb-0">
                ${data.count} member${data.count !== 1 ? 's are' : ' is'} celebrating their special day
            </p>
        </div>
    `;
    
    $('#birthdayMembersContainer').html(celebrationHeader + html);
}

function sendIndividualSMS(memberId, memberName) {
    if (!confirm(`Send birthday SMS to ${memberName}?`)) return;
    
    showLoadingModal('Sending SMS to ' + memberName + '...');
    
    $.ajax({
        url: '<?= BASE_URL ?>/ajax_birthday_sms.php',
        method: 'POST',
        data: { 
            action: 'send_birthday_sms',
            member_id: memberId 
        },
        dataType: 'json',
        success: function(response) {
            hideLoadingModal();
            if (response.status === 'success') {
                showAlert(`Birthday SMS sent successfully to ${response.member}`, 'success');
                loadRecentSMSLogs();
            } else {
                showAlert(`Failed to send SMS to ${response.member}: ${response.message}`, 'danger');
            }
        },
        error: function() {
            hideLoadingModal();
            showAlert('Failed to send SMS', 'danger');
        }
    });
}

function sendAllBirthdaySMS() {
    showLoadingModal('Sending birthday SMS to all members...');
    
    $.ajax({
        url: '<?= BASE_URL ?>/ajax_birthday_sms.php',
        method: 'POST',
        data: { action: 'send_birthday_sms' },
        dataType: 'json',
        success: function(response) {
            hideLoadingModal();
            if (response.status === 'completed') {
                showAlert(`Birthday SMS process completed. Success: ${response.success_count}, Errors: ${response.error_count}`, 
                    response.error_count === 0 ? 'success' : 'warning');
                loadRecentSMSLogs();
            } else {
                showAlert('Failed to send birthday SMS: ' + response.message, 'danger');
            }
        },
        error: function() {
            hideLoadingModal();
            showAlert('Failed to send birthday SMS', 'danger');
        }
    });
}

function sendTestSMS() {
    const phone = $('#testPhone').val();
    const name = $('#testName').val();
    
    if (!phone || !name) {
        showAlert('Please fill in both phone number and name', 'warning');
        return;
    }
    
    showLoadingModal('Sending test SMS...');
    
    $.ajax({
        url: '<?= BASE_URL ?>/ajax_birthday_sms.php',
        method: 'POST',
        data: { 
            action: 'test_birthday_sms',
            phone: phone,
            name: name
        },
        dataType: 'json',
        success: function(response) {
            hideLoadingModal();
            if (response.status === 'success') {
                showAlert(`Test SMS sent successfully to ${response.phone}`, 'success');
                $('#testSMSForm')[0].reset();
            } else {
                showAlert(`Failed to send test SMS: ${response.message}`, 'danger');
            }
        },
        error: function() {
            hideLoadingModal();
            showAlert('Failed to send test SMS', 'danger');
        }
    });
}

function loadRecentSMSLogs() {
    // This would load recent birthday SMS logs from the database
    // For now, we'll show a placeholder
    $('#birthdaySMSLogs tbody').html(`
        <tr>
            <td colspan="5" class="text-center text-muted">
                Recent birthday SMS logs will appear here
            </td>
        </tr>
    `);
}

function showLoadingModal(text) {
    $('#loadingText').text(text);
    $('#loadingModal').modal('show');
}

function hideLoadingModal() {
    $('#loadingModal').modal('hide');
}

function showAlert(message, type) {
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    
    // Insert at the top of the content
    $('.content-header').after(alertHtml);
    
    // Auto-dismiss after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut();
    }, 5000);
}
</script>

<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
