<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_transfer_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$page_title = 'Add Member Transfer';
$error = '';
$success = '';

// Fetch all members for dropdown
$members = $conn->query("SELECT id, CONCAT(last_name, ' ', first_name, ' ', middle_name) AS full_name FROM members ORDER BY last_name, first_name, middle_name");

// Get current user info
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['name'] ?? 'Unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = intval($_POST['member_id'] ?? 0);
    $from_class_id = intval($_POST['from_class_id'] ?? 0);
    $to_class_id = intval($_POST['to_class_id'] ?? 0);
    $transfer_date = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $transferred_by = $user_id;

    if (!$member_id || !$from_class_id || !$to_class_id || !$transferred_by || !$transfer_date) {
        $error = 'Please fill in all required fields.';
    } else if ($from_class_id == $to_class_id) {
        $error = 'From Class and To Class cannot be the same.';
    } else {
        // Get old CRN before update
        $old_crn = '';
        $crn_stmt = $conn->prepare("SELECT crn FROM members WHERE id = ?");
        $crn_stmt->bind_param('i', $member_id);
        $crn_stmt->execute();
        $crn_stmt->bind_result($old_crn);
        $crn_stmt->fetch();
        $crn_stmt->close();
        // Insert transfer with old_crn
        $stmt = $conn->prepare("INSERT INTO member_transfers (member_id, from_class_id, to_class_id, transfer_date, transferred_by, old_crn) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiisis', $member_id, $from_class_id, $to_class_id, $transfer_date, $transferred_by, $old_crn);
        if ($stmt->execute()) {
            // Update member's class_id and church_id to reflect new assignment
            $stmt_update = $conn->prepare('UPDATE members SET class_id = ?, church_id = (SELECT church_id FROM bible_classes WHERE id = ?) WHERE id = ?');
            $stmt_update->bind_param('iii', $to_class_id, $to_class_id, $member_id);
            $stmt_update->execute();
            // Get old CRN before update
            $old_crn = '';
            $crn_stmt = $conn->prepare("SELECT crn FROM members WHERE id = ?");
            $crn_stmt->bind_param('i', $member_id);
            $crn_stmt->execute();
            $crn_stmt->bind_result($old_crn);
            $crn_stmt->fetch();
            $crn_stmt->close();
            // Generate new CRN for member using get_next_crn.php logic
            // Fetch new class and church for member
            $stmt_class = $conn->prepare('SELECT class_id, church_id FROM members WHERE id = ? LIMIT 1');
            $stmt_class->bind_param('i', $member_id);
            $stmt_class->execute();
            $class_result = $stmt_class->get_result();
            $class_row = $class_result->fetch_assoc();
            $new_class_id = $class_row['class_id'];
            $new_church_id = $class_row['church_id'];
            // Get class code
            $stmt = $conn->prepare('SELECT code FROM bible_classes WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $new_class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $class = $result->fetch_assoc();
            $class_code = $class ? $class['code'] : '';
            // Get church code and circuit/location code
            $stmt = $conn->prepare('SELECT church_code, circuit_code FROM churches WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $new_church_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $church = $result->fetch_assoc();
            $church_code = $church ? $church['church_code'] : '';
            $circuit_code = $church ? $church['circuit_code'] : '';
            // Get next sequential number for this class
            $stmt = $conn->prepare('SELECT COUNT(*) as cnt FROM members WHERE class_id = ?');
            $stmt->bind_param('i', $new_class_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['cnt'];
            $seq = str_pad($count, 2, '0', STR_PAD_LEFT); // Use count (not +1) because member already transferred
            // Compose CRN
            $new_crn = $church_code . '-' . $class_code . $seq . '-' . $circuit_code;
            $stmt2 = $conn->prepare("UPDATE members SET crn = ? WHERE id = ?");
            $stmt2->bind_param('si', $new_crn, $member_id);
            if ($stmt2->execute()) {
                // Migrate all related data from old CRN to new CRN
                $migration_msgs = [];
                // Attendance Records: No change needed (uses member_id, not crn)
                // Migrate CRN in other tables only if they exist
                $tables = [
                    ['table'=>'spiritual_activity_logs', 'label'=>'Spiritual activity logs'],
                    ['table'=>'contributions', 'label'=>'Contributions']
                ];
                foreach ($tables as $tbl) {
                    $check = $conn->query("SHOW TABLES LIKE '".$conn->real_escape_string($tbl['table'])."'");
                    if ($check && $check->num_rows) {
                        $conn->query("UPDATE `{$tbl['table']}` SET crn = '".$conn->real_escape_string($new_crn)."' WHERE crn = '".$conn->real_escape_string($old_crn)."'");
                        if ($conn->affected_rows > 0) $migration_msgs[] = $tbl['label'].' migrated.';
                    }
                }
                $success = 'Transfer recorded successfully!<br>New CRN: <b>' . htmlspecialchars($new_crn) . '</b>';
                // Send new CRN SMS after transfer
                $member_stmt = $conn->prepare('SELECT phone, first_name FROM members WHERE id = ? LIMIT 1');
                $member_stmt->bind_param('i', $member_id);
                $member_stmt->execute();
                $member_stmt->bind_result($phone, $first_name);
                $member_stmt->fetch();
                $member_stmt->close();
                if (!empty($phone)) {
                    require_once __DIR__.'/../includes/sms.php';
                    // Fetch new Bible class name
                    $class_stmt = $conn->prepare('SELECT name FROM bible_classes WHERE id = ? LIMIT 1');
                    $class_stmt->bind_param('i', $new_class_id);
                    $class_stmt->execute();
                    $class_stmt->bind_result($bible_class_name);
                    $class_stmt->fetch();
                    $class_stmt->close();
                    if (!empty($bible_class_name)) {
                        $msg = "Hi, $first_name, you have been transferred to $bible_class_name. Your New CRN is: $new_crn";
                        try {
                            log_sms($phone, $msg, null, 'transfer');
                        } catch (Exception $ex) {
                            error_log('Transfer SMS send failed: ' . $ex->getMessage());
                        }
                    }
                }
                if ($migration_msgs) $success .= '<br>' . implode('<br>', $migration_msgs);
            } else {
                $success = 'Transfer recorded, but failed to update CRN.';
            }
            // Optionally: header('Location: transfer_list.php?added=1'); exit;
        } else {
            $error = 'Error recording transfer: ' . $conn->error;
        }
    }
}

// Always initialize transfer summary variables to avoid undefined warnings
$member_name = '';
$from_class_name = '';
$to_class_name = '';
$transfer_date_disp = '';
$transferred_by_disp = htmlspecialchars($user_name);
if ($success) {
    // Safely attempt to populate variables from POST and DB
    $transfer_date_disp = htmlspecialchars($_POST['transfer_date'] ?? date('Y-m-d'));
    // Get member name
    if (!empty($member_id)) {
        $stmt = $conn->prepare('SELECT CONCAT(last_name, " ", first_name, " ", middle_name) AS full_name FROM members WHERE id = ?');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $stmt->bind_result($member_name);
        $stmt->fetch();
        $stmt->close();
    }
    // Get class names
    if (!empty($from_class_id)) {
        $stmt = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
        $stmt->bind_param('i', $from_class_id);
        $stmt->execute();
        $stmt->bind_result($from_class_name);
        $stmt->fetch();
        $stmt->close();
    }
    if (!empty($to_class_id)) {
        $stmt = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
        $stmt->bind_param('i', $to_class_id);
        $stmt->execute();
        $stmt->bind_result($to_class_name);
        $stmt->fetch();
        $stmt->close();
    }
    ob_start(); ?>
    <!-- Bootstrap Modal -->
    <style>
      .modal-backdrop.show { z-index: 1040 !important; }
      .modal.show { z-index: 1050 !important; }
    </style>
    <div class="modal fade" id="transferSuccessModal" tabindex="-1" role="dialog" aria-labelledby="transferSuccessLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header bg-success text-white">
            <h5 class="modal-title" id="transferSuccessLabel">Transfer Successful</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close" onclick="window.location='transfer_list.php'">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          <div class="modal-body">
            <p class="lead"><i class="fa fa-check-circle text-success"></i> Member transfer completed successfully!</p>
            <ul class="list-group mb-3">
              <li class="list-group-item"><b>Member:</b> <?=htmlspecialchars($member_name)?></li>
              <li class="list-group-item"><b>From Class:</b> <?=htmlspecialchars($from_class_name)?></li>
              <li class="list-group-item"><b>To Class:</b> <?=htmlspecialchars($to_class_name)?></li>
              <li class="list-group-item"><b>New CRN:</b> <?=htmlspecialchars($new_crn)?></li>
              <li class="list-group-item"><b>Transfer Date:</b> <?=htmlspecialchars($transfer_date_disp)?></li>
              <li class="list-group-item"><b>Transferred By:</b> <?=htmlspecialchars($transferred_by_disp)?></li>
            </ul>
            <?php if (!empty($migration_msgs)): ?>
              <div class="alert alert-info mb-0">
                <?=implode('<br>', array_map('htmlspecialchars', $migration_msgs))?>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <a href="transfer_list.php" class="btn btn-success">Continue to Transfer List</a>
          </div>
        </div>
      </div>
    </div>
    <input type="hidden" id="showTransferSuccessModal" value="1">
    <script>
      $(function() {
        $('#transferForm').hide();
        $('#transferSuccessModal').modal({backdrop:'static',keyboard:false});
      });
    </script>
    <?php
    $modal_html = ob_get_clean();
}

// Buffer main form content
ob_start(); ?>
<div class="container mt-4">
    <h2 class="mb-4">Add Member Transfer</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
    <?php endif; ?>
    <form method="post" id="transferForm" autocomplete="off">
        <div class="form-group">
            <label for="crn_search">Member CRN <span class="text-danger">*</span></label>
            <div class="input-group">
                <input type="text" id="crn_search" class="form-control" placeholder="Enter CRN..." value="<?=htmlspecialchars($_POST['crn'] ?? '')?>" required>
                <div class="input-group-append">
                    <button class="btn btn-info" type="button" id="find_member_btn">Find Member</button>
                </div>
            </div>
            <input type="hidden" name="member_id" id="member_id" value="<?=isset($_POST['member_id']) ? htmlspecialchars($_POST['member_id']) : ''?>">
            <div id="member_found" class="mt-2 text-success" style="display:none;"></div>
            <div id="member_not_found" class="mt-2 text-danger" style="display:none;"></div>
        </div>
        <div class="form-group">
            <label for="from_class_id">From Class <span class="text-danger">*</span></label>
            <input type="text" id="from_class_name" class="form-control" readonly value="">
            <input type="hidden" name="from_class_id" id="from_class_id" value="<?=isset($_POST['from_class_id']) ? htmlspecialchars($_POST['from_class_id']) : ''?>">
        </div>
        <div class="form-group">
            <label for="to_class_id">To Class <span class="text-danger">*</span></label>
            <select name="to_class_id" id="to_class_id" class="form-control" required>
                <option value="">Select class...</option>
            </select>
        </div>
        <div class="form-group">
            <label for="transfer_date">Transfer Date <span class="text-danger">*</span></label>
            <input type="date" name="transfer_date" id="transfer_date" class="form-control" required value="<?=htmlspecialchars($_POST['transfer_date'] ?? date('Y-m-d'))?>">
        </div>
        <div class="form-group">
            <label for="transferred_by">Transferred By</label>
            <input type="text" class="form-control" value="<?=htmlspecialchars($user_name)?>" readonly>
        </div>
        <button type="submit" class="btn btn-primary">Submit Transfer</button>
        <a href="transfer_list.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<!-- Select2 and jQuery CDN -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Bootstrap 4 CSS & JS (fallback to CDN if not present) -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    // Show modal if transfer was successful
    if ($('#showTransferSuccessModal').length) {
        $('#transferForm').hide();
        $('#transferSuccessModal').modal({backdrop:'static',keyboard:false});
    }

    function setFormEnabled(enabled) {
        $('#from_class_name, #from_class_id, #to_class_id, #transfer_date, button[type=submit]').prop('disabled', !enabled);
    }
    setFormEnabled(false);
    function loadMemberClassAndClasses(memberId, selectedToClassId) {
        if (!memberId) {
            $('#from_class_name').val('');
            $('#from_class_id').val('');
            $('#to_class_id').html('<option value="">Select class...</option>');
            setFormEnabled(false);
            return;
        }
        $.get('get_member_class_and_classes.php', { member_id: memberId }, function(res) {
            if (res && res.class_name && res.class_id) {
                $('#from_class_name').val(res.class_name);
                $('#from_class_id').val(res.class_id);
            } else {
                $('#from_class_name').val('Not found');
                $('#from_class_id').val('');
            }
            // Populate To Class dropdown
            var options = '<option value="">Select class...</option>';
            if (res && res.classes && Array.isArray(res.classes)) {
                res.classes.forEach(function(c) {
                    var selected = selectedToClassId && c.id == selectedToClassId ? ' selected' : '';
                    options += '<option value="'+c.id+'"'+selected+'>'+c.name+'</option>';
                });
            }
            $('#to_class_id').html(options);
            setFormEnabled(true);
        }, 'json');
    }
    $('#find_member_btn').on('click', function() {
        var crn = $('#crn_search').val().trim();
        if (!crn) {
            $('#member_found').hide();
            $('#member_not_found').text('Please enter a CRN.').show();
            setFormEnabled(false);
            return;
        }
        $.get('ajax_find_member_by_crn.php', { crn: crn }, function(res) {
            if (res && res.id) {
                $('#member_id').val(res.id);
                $('#member_found').text('Member: '+res.full_name+' (CRN: '+res.crn+')').show();
                $('#member_not_found').hide();
                loadMemberClassAndClasses(res.id, null);
            } else {
                $('#member_id').val('');
                $('#member_found').hide();
                $('#member_not_found').text('Member not found.').show();
                setFormEnabled(false);
            }
        }, 'json');
    });
    // If form was submitted with member_id, load class/classes on page load
    <?php if (isset($_POST['member_id']) && $_POST['member_id']): ?>
    $('#member_found').text('Member found.').show();
    loadMemberClassAndClasses(<?=json_encode($_POST['member_id'])?>, <?=isset($_POST['to_class_id']) ? json_encode($_POST['to_class_id']) : 'null'?>);
    setFormEnabled(true);
    <?php endif; ?>
});
</script>
<?php
$page_content = ob_get_clean();
// Output modal HTML before main content, like visitor_list.php
include '../includes/layout.php';
