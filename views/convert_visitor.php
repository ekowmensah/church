<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

$error = '';
$success = '';
$editing = false;
$member = [
    'first_name'=>'','middle_name'=>'','last_name'=>'','crn'=>'','phone'=>'','email'=>'','class_id'=>'','church_id'=>''
];

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1)) {
    if (function_exists('has_permission') && !has_permission('manage_members')) {
        die('No permission to manage members.');
    }
}

// For convert visitor: if visitor_id is passed, fetch visitor and pre-fill
if (isset($_GET['visitor_id']) && is_numeric($_GET['visitor_id'])) {
    $visitor_id = intval($_GET['visitor_id']);
    $stmt = $conn->prepare('SELECT * FROM visitors WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $visitor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $visitor = $result->fetch_assoc();
    if ($visitor) {
        // Pre-fill member fields from visitor
        // Robustly handle old visitor records with only 'name', no first/middle/last/class_id/church_id
        $first = $middle = $last = '';
        if (!empty($visitor['first_name']) || !empty($visitor['last_name'])) {
            $first = $visitor['first_name'] ?? '';
            $middle = $visitor['middle_name'] ?? '';
            $last = $visitor['last_name'] ?? '';
        } elseif (!empty($visitor['name'])) {
            // Split name into parts
            $parts = preg_split('/\s+/', trim($visitor['name']));
            $first = $parts[0] ?? '';
            $last = count($parts) > 1 ? array_pop($parts) : '';
            $middle = count($parts) > 1 ? implode(' ', $parts) : '';
        }
        $member = [
            'first_name' => $first,
            'middle_name' => $middle,
            'last_name' => $last,
            'crn' => '', // Will be generated
            'phone' => $visitor['phone'] ?? '',
            'email' => $visitor['email'] ?? '',
            'class_id' => $visitor['class_id'] ?? '',
            'church_id' => $visitor['church_id'] ?? ''
        ];
    } else {
        $error = 'Visitor not found.';
    }
} else if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editing = true;
    $id = intval($_GET['id']);
    $stmt = $conn->prepare('SELECT * FROM members WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $member = $result->fetch_assoc();
    if (!$member) {
        $error = 'Member not found.';
        $editing = false;
        $member = [
            'first_name'=>'','middle_name'=>'','last_name'=>'','crn'=>'','phone'=>'','email'=>'','class_id'=>'','church_id'=>''
        ];
    }
}

// Fetch dropdowns
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
// Classes loaded dynamically by church selection
$users = $conn->query("SELECT id, name FROM users ORDER BY name ASC");

// Check if this is an AJAX request
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Prepare response array
        $response = [
            'success' => false,
            'message' => '',
            'redirect' => '',
            'redirect_text' => ''
        ];
        
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $crn = trim($_POST['crn'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $class_id = intval($_POST['class_id'] ?? 0);
        $church_id = intval($_POST['church_id'] ?? 0);
        $visitor_id = isset($_POST['visitor_id']) ? intval($_POST['visitor_id']) : 0;
        
        // Validate required fields
        if (!$first_name || !$last_name || !$crn || !$phone || !$class_id || !$church_id) {
            $error = 'Please fill in all required fields.';
        if ($isAjax) {
            $response['success'] = false;
            $response['message'] = $error;
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
    } else if ($visitor_id) {
        // Convert visitor to member
        // Check for existing member with same phone or email
        $stmt = $conn->prepare('SELECT id FROM members WHERE phone = ? OR (email != "" AND email = ?) LIMIT 1');
        $stmt->bind_param('ss', $phone, $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $error = 'A member with this phone or email already exists.';
        } else {
            // Generate registration token
            $registration_token = bin2hex(random_bytes(16));
            // Insert new member
            $stmt = $conn->prepare('INSERT INTO members (first_name, middle_name, last_name, crn, phone, email, class_id, church_id, registration_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssiss', $first_name, $middle_name, $last_name, $crn, $phone, $email, $class_id, $church_id, $registration_token);
            if ($stmt->execute()) {
                // Delete visitor
                $del = $conn->prepare('DELETE FROM visitors WHERE id = ?');
                $del->bind_param('i', $visitor_id);
                $del->execute();
                $registration_link = BASE_URL . '/views/complete_registration.php?token=' . urlencode($registration_token);
                $successMessage = 'Visitor converted to member! Registration link has been sent.';
                $response['success'] = true;
                $response['message'] = $successMessage . '<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                $response['redirect'] = 'member_list.php';
                $response['redirect_text'] = 'View Members';
                
                // Send registration SMS if phone is provided
                if (!empty($phone)) {
                    require_once __DIR__.'/../includes/sms.php';
                    require_once __DIR__.'/../includes/sms_templates.php';
                    
                    try {
                        // Hardcoded SMS message (no template lookup)
                        $msg = "Hi, $first_name, you have been converted to be a member. Follow the link to complete your registration: $registration_link";
                        error_log("Attempting to send SMS to $phone (convert visitor, hardcoded)");
                        $smsResult = send_sms($phone, $msg);
                        error_log('SMS API Response (convert visitor, hardcoded): ' . print_r($smsResult, true));
                        $logResult = log_sms($phone, $msg, null, 'registration', null, [
                            'member_name' => $first_name,
                            'link' => $registration_link,
                            'phone' => $phone,
                            'template' => 'registration_link (hardcoded)'
                        ]);
                        error_log('SMS Log Result (convert visitor, hardcoded): ' . print_r($logResult, true));
                        $smsSent = isset($smsResult['status']) && $smsResult['status'] === 'success';
                        $smsError = $smsResult['message'] ?? 'Unknown error';
                        error_log('SMS Send Status (convert visitor, hardcoded): ' . ($smsSent ? 'Success' : 'Failed - ' . $smsError));
                        if ($isAjax) {
                            $response['sms_sent'] = $smsSent;
                            $response['sms_error'] = $smsSent ? null : $smsError;
                            // Update success message based on SMS status
                            if ($smsSent) {
                                $successMessage = 'Visitor converted to member! Registration link has been sent via SMS.';
                                $response['message'] = $successMessage . '<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                            } else {
                                $successMessage = 'Visitor converted to member, but failed to send SMS: ' . $smsError;
                                $response['message'] = $successMessage . '<br>Please send this registration link manually: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                            }
                        }
                    } catch (Exception $e) {
                        $errorMsg = 'SMS sending exception: ' . $e->getMessage();
                        error_log($errorMsg);
                        
                        if ($isAjax) {
                            $response['sms_sent'] = false;
                            $response['sms_error'] = $e->getMessage();
                            $response['message'] = 'Visitor converted to member, but an error occurred while sending SMS: ' . $e->getMessage();
                        }
                    }
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    } else if ($editing) {
        $stmt = $conn->prepare('UPDATE members SET first_name=?, middle_name=?, last_name=?, crn=?, phone=?, email=?, class_id=?, church_id=? WHERE id=?');
        $stmt->bind_param('sssssssii', $first_name, $middle_name, $last_name, $crn, $phone, $email, $class_id, $church_id, $id);
        $stmt->execute();
        if ($stmt->affected_rows >= 0) {
            $success = 'Member updated. (Notification would be sent here)';
        } else {
            $error = 'Database error. Please try again.';
        }
    } else {
        // Fallback: normal add member (should not occur from convert_visitor)
        $stmt = $conn->prepare('INSERT INTO members (first_name, middle_name, last_name, crn, phone, email, class_id, church_id, registration_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $registration_token = bin2hex(random_bytes(16));
        $stmt->bind_param('ssssssiss', $first_name, $middle_name, $last_name, $crn, $phone, $email, $class_id, $church_id, $registration_token);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $registration_link = BASE_URL . '/views/complete_registration.php?token=' . urlencode($registration_token);
            $success = 'Member added successfully!<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
            // Send registration SMS if phone is provided
            if (!empty($phone)) {
                require_once __DIR__.'/../includes/sms.php';
                require_once __DIR__.'/../includes/sms_templates.php';
                try {
                    $tpl = get_sms_template('registration_link', $conn);
                    if ($tpl) {
                        $msg = fill_sms_template($tpl['body'], [
                            'name' => $first_name,
                            'link' => $registration_link
                        ]);
                        error_log("Attempting to send SMS to $phone (member add)");
                        $smsResult = send_sms($phone, $msg);
                        error_log('SMS API Response (member add): ' . print_r($smsResult, true));
                        $logResult = log_sms($phone, $msg, null, 'registration', null, [
                            'member_name' => $first_name,
                            'link' => $registration_link,
                            'phone' => $phone,
                            'template' => 'registration_link'
                        ]);
                        error_log('SMS Log Result (member add): ' . print_r($logResult, true));
                        $smsSent = isset($smsResult['status']) && $smsResult['status'] === 'success';
                        $smsError = $smsResult['message'] ?? 'Unknown error';
                        error_log('SMS Send Status (member add): ' . ($smsSent ? 'Success' : 'Failed - ' . $smsError));
                        if ($isAjax) {
                            $response['sms_sent'] = $smsSent;
                            $response['sms_error'] = $smsSent ? null : $smsError;
                            if ($smsSent) {
                                $success = 'Member added successfully! Registration link has been sent via SMS.<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                                $response['message'] = $success;
                            } else {
                                $success = 'Member added, but failed to send SMS: ' . $smsError . '<br>Please send this registration link manually: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                                $response['message'] = $success;
                            }
                        }
                    } else {
                        $errorMsg = 'Failed to load SMS template: registration_link';
                        error_log($errorMsg);
                        if ($isAjax) {
                            $response['sms_sent'] = false;
                            $response['sms_error'] = $errorMsg;
                            $response['message'] = 'Member added, but failed to load SMS template. Please send the registration link manually.';
                        }
                    }
                } catch (Exception $e) {
                    $errorMsg = 'SMS sending exception (member add): ' . $e->getMessage();
                    error_log($errorMsg);
                    if ($isAjax) {
                        $response['sms_sent'] = false;
                        $response['sms_error'] = $e->getMessage();
                        $response['message'] = 'Member added, but an error occurred while sending SMS: ' . $e->getMessage();
                    }
                }
            }
        } else {
            $error = 'Database error. Please try again.';
        }
    }
        $member = compact('first_name','middle_name','last_name','crn','phone','email','class_id','church_id');
        
        // If this is an AJAX request, return JSON response
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }
        
        // For non-AJAX requests, set the success/error messages
        if (isset($successMessage)) {
            $success = $successMessage;
        }
    } catch (Exception $e) {
        error_log('Error in convert_visitor: ' . $e->getMessage());
        if ($isAjax) {
            $response = [
                'success' => false,
                'message' => 'An error occurred: ' . $e->getMessage()
            ];
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        } else {
            $error = 'An error occurred. Please try again.';
        }
    }
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $editing ? 'Edit Member' : 'Add Member' ?></h1>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
<?php if (isset($visitor_id) && isset($visitor) && $visitor): ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-info mb-3">
                <div class="card-header bg-info text-white"><strong>Visitor Information</strong></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-2"><strong>Name:</strong> <?=htmlspecialchars($visitor['name'] ?? ($visitor['first_name'] . ' ' . $visitor['last_name']))?></div>
                        <div class="col-md-6 mb-2"><strong>Phone:</strong> <?=htmlspecialchars($visitor['phone'])?></div>
                        <div class="col-md-6 mb-2"><strong>Email:</strong> <?=htmlspecialchars($visitor['email'])?></div>
                        <div class="col-md-6 mb-2"><strong>Address:</strong> <?=htmlspecialchars($visitor['address'])?></div>
                        <div class="col-md-6 mb-2"><strong>Visit Date:</strong> <?=htmlspecialchars($visitor['visit_date'])?></div>
                        <div class="col-md-6 mb-2"><strong>Invited By:</strong> <?php
                            if (!empty($visitor['invited_by'])) {
                                $mid = intval($visitor['invited_by']);
                                $mres = $conn->query("SELECT CONCAT(last_name, ' ', first_name, ' ', middle_name) as name FROM members WHERE id=$mid LIMIT 1");
                                if ($mres && $row = $mres->fetch_assoc()) {
                                    echo htmlspecialchars($row['name']);
                                } else {
                                    echo 'Unknown (ID: '.htmlspecialchars($visitor['invited_by']).')';
                                }
                            } else {
                                echo '-';
                            }
                        ?></div>
                        <div class="col-md-12 mb-2"><strong>Purpose:</strong> <?=htmlspecialchars($visitor['purpose'])?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Member Details</h6>
            </div>
            <div class="card-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"> <?= $success ?> </div>
                    <script>
                    $(function(){
                        $('form :input').prop('disabled', true);
                        $('button[type=submit]').prop('disabled', true);
                    });
                    </script>
                <?php endif; ?>
                <?php if (!$success): ?>
                <form id="memberForm" method="post" autocomplete="off">
                    <?php if (isset($visitor_id) && isset($visitor) && $visitor): ?>
                        <input type="hidden" name="visitor_id" value="<?=htmlspecialchars($visitor_id)?>">
                    <?php endif; ?>
                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="first_name">First Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="first_name" id="first_name" value="<?=htmlspecialchars($member['first_name'])?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" id="middle_name" value="<?=htmlspecialchars($member['middle_name'])?>">
                        </div>
                        <div class="form-group col-md-4">
                            <label for="last_name">Last Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="last_name" id="last_name" value="<?=htmlspecialchars($member['last_name'])?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="phone">Phone <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="phone" id="phone" 
                                    value="<?=htmlspecialchars($member['phone'])?>" 
                                    pattern="[0-9]{10,15}" 
                                    title="Please enter a valid phone number (10-digits)" 
                                    required>
                                <div class="input-group-append">
                                    <span class="input-group-text d-none" id="phone-valid-icon">
                                        <i class="fas fa-check text-success"></i>
                                    </span>
                                    <span class="input-group-text d-none" id="phone-invalid-icon">
                                        <i class="fas fa-times text-danger"></i>
                                    </span>
                                </div>
                            </div>
                            <small id="phone-help" class="form-text text-muted">Format: 0244123456 (10-digits)</small>
                            <div class="invalid-feedback" id="phone-feedback">Please enter a valid phone number</div>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">Email</label>
                            <div class="input-group">
                                <input type="email" class="form-control" name="email" id="email" 
                                    value="<?=htmlspecialchars($member['email'])?>"
                                    pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$">
                                <div class="input-group-append">
                                    <span class="input-group-text d-none" id="email-valid-icon">
                                        <i class="fas fa-check text-success"></i>
                                    </span>
                                    <span class="input-group-text d-none" id="email-invalid-icon">
                                        <i class="fas fa-times text-danger"></i>
                                    </span>
                                </div>
                            </div>
                            <div class="invalid-feedback" id="email-feedback">Please enter a valid email address</div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="church_id">Church <span class="text-danger">*</span></label>
                            <select class="form-control" name="church_id" id="church_id" required <?= $editing ? 'disabled' : '' ?>>
    <option value="">-- Select Church --</option>
    <?php if ($churches && $churches->num_rows > 0): while($ch = $churches->fetch_assoc()): ?>
        <option value="<?=$ch['id']?>" <?=($member['church_id']==$ch['id']?'selected':'')?>><?=htmlspecialchars($ch['name'])?></option>
    <?php endwhile; endif; ?>
</select>
<?php if ($editing): ?>
    <input type="hidden" name="church_id" value="<?=htmlspecialchars($member['church_id'])?>">
<?php endif; ?>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="class_id">Bible Class <span class="text-danger">*</span></label>
                            <select class="form-control" id="class_id" name="class_id" required style="width:100%" <?= $editing ? 'disabled' : '' ?>>
    <option value="">-- Select Class --</option>
</select>
<?php if ($editing): ?>
    <input type="hidden" name="class_id" value="<?=htmlspecialchars($member['class_id'])?>">
<?php endif; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="crn">CRN <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="crn" placeholder="CRN will appear here" value="<?=htmlspecialchars($member['crn'])?>" readonly required tabindex="-1" autocomplete="off" style="background:#f9f9f9;">
                        <input type="hidden" name="crn" id="crn_hidden" value="<?=htmlspecialchars($member['crn'])?>">
                    </div>
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <?php echo $editing ? 'Update' : 'Save & Send Registration Link'; ?>
                        <span id="submitSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<!-- Ensure jQuery is loaded -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 for searchable dropdowns -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // Handle form submission with AJAX
    $('#memberForm').on('submit', function(e) {
        e.preventDefault();
        
        // Show loading state
        const $submitBtn = $('#submitBtn');
        const $spinner = $('#submitSpinner');
        $submitBtn.prop('disabled', true);
        $spinner.removeClass('d-none');
        
        // Get form data
        const formData = new FormData(this);
        
        // Send AJAX request
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Show success message
                    const successHtml = `
                        <div class="alert alert-success">
                            ${response.message}
                            <a href="${response.redirect || 'javascript:void(0)'}" class="btn btn-sm btn-success ml-2">
                                ${response.redirect_text || 'Continue'}
                            </a>
                        </div>
                    `;
                    $('.card-body').prepend(successHtml);
                    
                    // Disable form and show success state
                    $('form :input').prop('disabled', true);
                    $submitBtn.prop('disabled', true);
                    
                    // Redirect if needed
                    if (response.redirect) {
                        setTimeout(() => {
                            window.location.href = response.redirect;
                        }, 2000);
                    }
                } else {
                    // Show error message
                    const errorHtml = `
                        <div class="alert alert-danger">
                            ${response.message || 'An error occurred. Please try again.'}
                        </div>
                    `;
                    $('.alert').remove();
                    $('.card-body').prepend(errorHtml);
                    
                    // Re-enable form
                    $submitBtn.prop('disabled', false);
                }
            },
            error: function(xhr, status, error) {
                // Show error message
                const errorHtml = `
                    <div class="alert alert-danger">
                        Network error. Please check your connection and try again.
                        <div class="small text-muted">${error}</div>
                    </div>
                `;
                $('.alert').remove();
                $('.card-body').prepend(errorHtml);
                
                // Re-enable form
                $submitBtn.prop('disabled', false);
            },
            complete: function() {
                // Hide loading spinner
                $spinner.addClass('d-none');
            }
        });
    });
    
    // Rest of the code
    function loadClasses(churchId, selectedClassId) {
        if (!churchId) {
            $('#class_id').html('<option value="">-- Select Class --</option>');
            $('#class_id').val('').trigger('change');
            return;
        }
        $.get('ajax_get_classes_by_church.php', {church_id: churchId}, function(options) {
            $('#class_id').html(options);
            if (selectedClassId) {
                $('#class_id').val(selectedClassId).trigger('change');
            } else {
                $('#class_id').val('').trigger('change');
            }
        });
    }
    // On church change
    $('#church_id').on('change', function() {
        loadClasses($(this).val(), ''); // Only populate, don't auto-select
        $('#class_id').val(''); // Clear selection
        updateCRN();
    });
    // On page load, pre-select class if editing
    var initialChurch = $('#church_id').val();
    var initialClass = "<?=isset($member['class_id']) ? htmlspecialchars($member['class_id']) : ''?>";
    if (initialChurch && initialClass) {
        loadClasses(initialChurch, initialClass); // Only pre-select if editing
    } else if (initialChurch) {
        loadClasses(initialChurch, ''); // Populate but do not select
    }

    // Initialize Select2 for class dropdown
    $('#class_id').select2({
        placeholder: '-- Select Class --',
        allowClear: true,
        width: '100%'
    });

    function updateCRN() {
        var classId = $('#class_id').val();
        var churchId = $('#church_id').val();
        if(classId && churchId) {
            $.get('get_next_crn.php', {class_id: classId, church_id: churchId}, function(data) {
                $('#crn').val(data);
                $('#crn_hidden').val(data);
            });
        } else {
            $('#crn').val('');
            $('#crn_hidden').val('');
        }
    }
    $('#class_id, #church_id').change(updateCRN);
    updateCRN();

    // Real-time validation for form fields
    function validatePhone(phone) {
        // Validate phone: 10-15 digits, optionally starting with country code
        return /^(?:\+?233|0)?[235][0-9]{8}$/.test(phone);
    }

    function validateEmail(email) {
        // Skip validation if empty (since email is optional)
        if (!email) return true;
        // Standard email validation
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

    // Validate field and update UI
    function validateField(field) {
        const value = field.val().trim();
        const id = field.attr('id');
        let isValid = false;
        let feedback = '';

        if (id === 'phone') {
            isValid = validatePhone(value);
            feedback = 'Please enter a valid phone number (0244XXXXXX or 024XXXXXXXX)';
            
            // Update UI
            if (value) {
                $('#phone-valid-icon').toggleClass('d-none', !isValid);
                $('#phone-invalid-icon').toggleClass('d-none', isValid);
            } else {
                $('#phone-valid-icon, #phone-invalid-icon').addClass('d-none');
            }
        } else if (id === 'email') {
            // Skip validation if empty (email is optional)
            if (!value) {
                $('#email-valid-icon, #email-invalid-icon').addClass('d-none');
                return true;
            }
            
            isValid = validateEmail(value);
            feedback = 'Please enter a valid email address';
            
            // Update UI
            $('#email-valid-icon').toggleClass('d-none', !isValid);
            $('#email-invalid-icon').toggleClass('d-none', isValid);
        }

        // Update field validity
        field[0].setCustomValidity(isValid ? '' : feedback);
        field.toggleClass('is-invalid', !isValid && value.length > 0);
        field.toggleClass('is-valid', isValid && value.length > 0);
        
        // Update feedback message
        if (id === 'phone' || id === 'email') {
            $(`#${id}-feedback`).text(feedback);
        }

        return isValid;
    }

    // Initialize real-time validation
    function initRealTimeValidation() {
        // Phone validation
        $('#phone').on('input', function() {
            validateField($(this));
        });

        // Email validation
        $('#email').on('input', function() {
            validateField($(this));
        });

        // Validate on blur
        $('#phone, #email').on('blur', function() {
            validateField($(this));
        });
    }

    // Initialize real-time validation when document is ready
    initRealTimeValidation();

    // Form submission validation
    $('#memberForm').on('submit', function(e) {
        // Validate all fields before submission
        const phoneValid = validateField($('#phone'));
        const emailValid = validateField($('#email'));

        if (!phoneValid) {
            e.preventDefault();
            $('#phone').focus();
            return false;
        }

        if (!emailValid) {
            e.preventDefault();
            $('#email').focus();
            return false;
        }

        return true;
    });

    // Original function for other validations
    function validateField(type, value, id, input) {
        if (!value) {
            $(input).removeClass('is-valid is-invalid');
            $(input).siblings('.invalid-feedback, .valid-feedback').remove();
            return;
        }
        $.get('ajax_validate_member.php', {type: type, value: value, id: id}, function(resp) {
            $(input).siblings('.invalid-feedback, .valid-feedback').remove();
            if (resp.valid) {
                $(input).removeClass('is-invalid').addClass('is-valid');
                $(input).after('<div class="valid-feedback">Looks good!</div>');
            } else {
                $(input).removeClass('is-valid').addClass('is-invalid');
                $(input).after('<div class="invalid-feedback">'+resp.msg+'</div>');
            }
        }, 'json');
    }
    var memberId = <?=($editing ? $id : 0)?>;
    function checkSubmitAllowed() {
        var phoneInvalid = $('#phone').hasClass('is-invalid');
        var emailInvalid = $('#email').hasClass('is-invalid');
        var phoneVal = $('#phone').val();
        var emailVal = $('#email').val();
        if ((phoneInvalid && phoneVal) || (emailInvalid && emailVal)) {
            $('button[type=submit]').prop('disabled', true);
        } else {
            $('button[type=submit]').prop('disabled', false);
        }
    }
    $('#phone, #email').on('blur change', function() {
        var type = $(this).attr('id');
        var value = $(this).val();
        validateField(type, value, memberId, this);
        setTimeout(checkSubmitAllowed, 200); // wait for AJAX
    });
    // Also check before submit
    $('form').on('submit', function(e) {
        checkSubmitAllowed();
        if ($('button[type=submit]').prop('disabled')) {
            e.preventDefault();
            if ($('.is-invalid').length) {
                $('.is-invalid').first().focus();
            }
        }
    });
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';