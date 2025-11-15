<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_feedback_report')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}


// Fetch members for dropdown
$members = $conn->query("SELECT id, CONCAT(last_name, ', ', first_name, ' ', middle_name) AS name FROM members ORDER BY last_name, first_name");
// Fetch users for user dropdown (show all users except the current user)
$users = $conn->query("SELECT id, name FROM users WHERE id != " . intval($_SESSION['user_id'] ?? 0) . " ORDER BY name");

$id = intval($_GET['id'] ?? 0);
$editing = $id > 0;
$feedback = [
    'recipient_type' => 'member', // 'member' or 'user'
    'recipient_id' => '',
    'message' => '',
];
$form_msg = '';

// Not supporting edit for new chat system
$editing = false; // force only add mode

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_type = $_POST['recipient_type'] === 'user' ? 'user' : 'member';
    $recipient_id = intval($_POST['recipient_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($recipient_id && $message) {
        // Insert into member_feedback_thread as new chat
        $sender_is_member = isset($_SESSION['member_id']) ? 1 : 0;
        $sender_id = $sender_is_member ? intval($_SESSION['member_id']) : intval($_SESSION['user_id']);
        $sender_type = $sender_is_member ? 'member' : 'user';
        $stmt = $conn->prepare('INSERT INTO member_feedback_thread (recipient_type, recipient_id, sender_type, sender_id, message, sent_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('sisis', $recipient_type, $recipient_id, $sender_type, $sender_id, $message);
        if ($stmt->execute()) {
            header('Location: memberfeedback_my.php?saved=1');
            exit;
        } else {
            $form_msg = 'Error saving feedback.';
        }
    } else {
        $form_msg = 'Recipient and message are required.';
    }
    // Refill form values
    $feedback = [
        'recipient_type' => $recipient_type,
        'recipient_id' => $recipient_id,
        'message' => $message,
    ];
}

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-md-10">
        <div class="card shadow-lg border-0 rounded-lg mt-5">
            <div class="card-header bg-primary text-white d-flex align-items-center">
                <i class="fas fa-comments fa-fw mr-2"></i>
                <h3 class="m-0 font-weight-bold flex-grow-1" style="font-size:1.3rem;"> <?= $editing ? 'Edit' : 'Add' ?> Member Feedback</h3>
            </div>
            <div class="card-body p-4">
                <?php if ($form_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($form_msg) ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                <form method="post" autocomplete="off">
    <div class="form-group">
        <label for="recipient_type" class="font-weight-bold">Recipient Type <span class="text-danger">*</span></label>
        <select class="form-control" name="recipient_type" id="recipient_type" required>
            <option value="member" <?= ($feedback['recipient_type'] == 'member' ? 'selected' : '') ?>>Member</option>
            <option value="user" <?= ($feedback['recipient_type'] == 'user' ? 'selected' : '') ?>>User (Admin/Manager)</option>
        </select>
        <small class="form-text text-muted">Choose whether to send to a member or a user (admin/manager).</small>
    </div>
    <div class="form-group" id="member_select_group" style="display:<?= ($feedback['recipient_type']=='user'?'none':'block') ?>;">
        <label for="member_id" class="font-weight-bold">Member <span class="text-danger">*</span></label>
        <select class="form-control" name="recipient_id" id="member_id" <?= ($feedback['recipient_type']=='user'?'disabled':'required') ?>>
            <option value="">Select member...</option>
            <?php 
            // Always re-fetch members for select to ensure options are present after JS show/hide
            $members_for_select = $conn->query("SELECT id, CONCAT(last_name, ', ', first_name, ' ', middle_name) AS name FROM members ORDER BY last_name, first_name");
            if ($members_for_select && $members_for_select->num_rows > 0): 
                while($m = $members_for_select->fetch_assoc()): ?>
                <option value="<?= $m['id'] ?>" <?= ($feedback['recipient_type']=='member' && $feedback['recipient_id']==$m['id'] ? 'selected' : '') ?>><?= htmlspecialchars($m['name']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="form-group" id="user_select_group" style="display:<?= ($feedback['recipient_type']=='user'?'block':'none') ?>;">
        <label for="user_id" class="font-weight-bold">User (Admin/Manager) <span class="text-danger">*</span></label>
        <select class="form-control" name="recipient_id" id="user_id" <?= ($feedback['recipient_type']=='member'?'disabled':'required') ?>>
            <option value="">Select user...</option>
            <?php 
            // Always re-fetch users for select to ensure options are present after JS show/hide
            $users_for_select = $conn->query("SELECT id, name FROM users WHERE id != " . intval($_SESSION['user_id'] ?? 0) . " ORDER BY name");
            if ($users_for_select && $users_for_select->num_rows > 0): 
                while($u = $users_for_select->fetch_assoc()): ?>
                <option value="<?= $u['id'] ?>" <?= ($feedback['recipient_type']=='user' && $feedback['recipient_id']==$u['id'] ? 'selected' : '') ?>><?= htmlspecialchars($u['name']) ?></option>
            <?php endwhile; endif; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="message" class="font-weight-bold">Message <span class="text-danger">*</span></label>
        <textarea class="form-control autosize" name="message" id="message" rows="3" required><?= htmlspecialchars($feedback['message']) ?></textarea>
        <small class="form-text text-muted">Enter the feedback message.</small>
    </div>
    <div class="form-group d-flex justify-content-between mt-4">
        <button type="submit" class="btn btn-success px-4"><i class="fas fa-save mr-1"></i> Send</button>
        <a href="memberfeedback_list.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left mr-1"></i> Back to List</a>
    </div>
</form>
<script>
$(function(){
    $('#recipient_type').on('change', function(){
        if($(this).val() === 'user'){
            $('#member_select_group').hide();
            $('#user_select_group').show();
            $('#member_id').prop('disabled', true).prop('required', false);
            $('#user_id').prop('disabled', false).prop('required', true);
        }else{
            $('#member_select_group').show();
            $('#user_select_group').hide();
            $('#user_id').prop('disabled', true).prop('required', false);
            $('#member_id').prop('disabled', false).prop('required', true);
        }
    });
});
</script>
            </div>
        </div>
    </div>
</div>
<script>
// Autosize textarea (Bootstrap 4 compatible)
document.querySelectorAll('.autosize').forEach(function(textarea) {
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });
    // Trigger resize on load
    textarea.dispatchEvent(new Event('input'));
});
</script>
<!-- jQuery (required for Select2) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<!-- Select2 CSS/JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.6.4/dist/select2-bootstrap4.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(function() {
    $('#member_id').select2({ width: '100%', theme: 'bootstrap4', placeholder: 'Select member...' });
    $('#responded_by').select2({ width: '100%', theme: 'bootstrap4', placeholder: 'Select responder...' });
});
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
