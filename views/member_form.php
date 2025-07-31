<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$editing = isset($_GET['id']) && is_numeric($_GET['id']);
$required_permission = $editing ? 'edit_member' : 'create_member';

if (!$is_super_admin && !has_permission($required_permission)) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_member');
$can_edit = $is_super_admin || has_permission('edit_member');
$can_view = true; // Already validated above

$error = '';
$success = '';
$member = [
    'first_name'=>'','middle_name'=>'','last_name'=>'','crn'=>'','phone'=>'','email'=>'','class_id'=>'','church_id'=>''
];

if ($editing) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $crn = trim($_POST['crn'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $class_id = intval($_POST['class_id'] ?? 0);
    $church_id = intval($_POST['church_id'] ?? 0);
    // Validate required fields
    if (!$first_name || !$last_name || !$crn || !$phone || !$class_id || !$church_id) {
        $error = 'Please fill in all required fields.';
    } else {
        $registration_token = bin2hex(random_bytes(16));
        if ($editing) {
            $stmt = $conn->prepare('UPDATE members SET first_name=?, middle_name=?, last_name=?, crn=?, phone=?, email=?, class_id=?, church_id=? WHERE id=?');
            $stmt->bind_param('sssssssii', $first_name, $middle_name, $last_name, $crn, $phone, $email, $class_id, $church_id, $id);
            $stmt->execute();
            if ($stmt->affected_rows >= 0) {
                $success = 'Member updated. (Notification would be sent here)';
            } else {
                $error = 'Database error. Please try again.';
            }
        } else {
            $stmt = $conn->prepare('INSERT INTO members (first_name, middle_name, last_name, crn, phone, email, class_id, church_id, registration_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('ssssssiss', $first_name, $middle_name, $last_name, $crn, $phone, $email, $class_id, $church_id, $registration_token);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $registration_link = rtrim(BASE_URL, '/') . '/views/complete_registration.php?token=' . urlencode($registration_token);
                $success = 'Member added successfully!<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                // Send registration SMS if phone is provided
                if (!empty($phone)) {
                    require_once __DIR__.'/../includes/sms.php';
                    require_once __DIR__.'/../includes/sms_templates.php';
                    try {
                        // Hardcoded SMS message (no template lookup)
                        $msg = "Hi $first_name, click on the link to complete your registration: $registration_link"; // BASE_URL is now always used above
                        error_log("Attempting to send SMS to $phone (member add, hardcoded)");
                        $smsResult = send_sms($phone, $msg);
                        error_log('SMS API Response (member add, hardcoded): ' . print_r($smsResult, true));
                        $logResult = log_sms($phone, $msg, null, 'registration', null, [
                            'member_name' => $first_name,
                            'link' => $registration_link,
                            'phone' => $phone,
                            'template' => 'registration_link (hardcoded)'
                        ]);
                        error_log('SMS Log Result (member add, hardcoded): ' . print_r($logResult, true));
                        $smsSent = isset($smsResult['status']) && $smsResult['status'] === 'success';
                        $smsError = $smsResult['message'] ?? 'Unknown error';
                        error_log('SMS Send Status (member add, hardcoded): ' . ($smsSent ? 'Success' : 'Failed - ' . $smsError));
                        if ($smsSent) {
                            $success = 'Member added successfully! Registration link has been sent via SMS.<br>Registration link: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                        } else {
                            $success = 'Member added, but failed to send SMS: ' . $smsError . '<br>Please send this registration link manually: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                        }
                    } catch (Exception $e) {
                        $errorMsg = 'SMS sending exception (member add, hardcoded): ' . $e->getMessage();
                        error_log($errorMsg);
                        $success = 'Member added, but an error occurred while sending SMS: ' . $e->getMessage() . '<br>Please send the registration link manually: <a href="' . $registration_link . '" target="_blank">' . htmlspecialchars($registration_link) . '</a>';
                    }
                }
            } else {
                $error = 'Database error. Please try again.';
            }
        }
    }
    $member = compact('first_name','middle_name','last_name','crn','phone','email','class_id','church_id');
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800"><?= $editing ? 'Edit Member' : 'Add Member' ?></h1>
    <a href="member_upload.php" class="btn btn-success btn-sm mr-2"><i class="fas fa-upload"></i> Bulk Member Upload</a>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>
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
                <form method="post" autocomplete="off">
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
                            <input type="text" class="form-control" name="phone" id="phone" value="<?=htmlspecialchars($member['phone'])?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="email">Email</label>
                            <input type="email" class="form-control" name="email" id="email" value="<?=htmlspecialchars($member['email'])?>">
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
                    <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Update' : 'Save & Send Registration Link'; ?></button>
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

    // Real-time validation for phone and email
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
