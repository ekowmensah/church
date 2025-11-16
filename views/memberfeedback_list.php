<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
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


// Handle delete action
$feedback_msg = '';
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $stmt = $conn->prepare('DELETE FROM member_feedback WHERE id = ?');
    $stmt->bind_param('i', $del_id);
    if ($stmt->execute()) {
        header('Location: memberfeedback_list.php?deleted=1');
        exit;
    } else {
        $feedback_msg = 'Error deleting feedback.';
    }
}
if (isset($_GET['deleted'])) {
    $feedback_msg = 'Feedback deleted.';
}
// Fetch all top-level chat threads (feedback_id IS NULL)
$sql = "SELECT id, sender_type, sender_id, recipient_type, recipient_id, message, sent_at
        FROM member_feedback_thread
        WHERE feedback_id IS NULL
        ORDER BY sent_at DESC";
$result = $conn->query($sql);

ob_start();
?>
<div class="row justify-content-center">
    <div class="col-lg-12">
        <div class="card shadow-lg border-0 rounded-lg mt-4">
            <div class="card-header bg-primary text-white d-flex align-items-center">
                <i class="fas fa-comments fa-fw mr-2"></i>
                <h3 class="m-0 font-weight-bold flex-grow-1" style="font-size:1.2rem;">Member Feedback</h3>
                <a href="memberfeedback_form.php" class="btn btn-light btn-sm ml-auto"><i class="fas fa-plus"></i> Add Feedback</a>
            </div>
            <div class="card-body">
                <?php if ($feedback_msg): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($feedback_msg) ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>
                
<div class="table-responsive">
                    <table class="table table-bordered table-hover" width="100%">
                        <thead class="thead-light">
                            <tr>
                                <th>ID</th>
                                <th>Member</th>
                                <th>Message</th>
                                <th>Submitted At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php
// Gather all rows and member IDs first
$all_rows = [];
$member_ids = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $all_rows[] = $row;
        if ($row['sender_type'] === 'member') $member_ids[] = $row['sender_id'];
        if ($row['recipient_type'] === 'member') $member_ids[] = $row['recipient_id'];
    }
    $member_ids = array_unique(array_filter($member_ids));
}
$members = [];
if ($member_ids) {
    $in = implode(',', array_fill(0, count($member_ids), '?'));
    $types = str_repeat('i', count($member_ids));
    $sql2 = "SELECT id, CONCAT_WS(' ', last_name, first_name, middle_name) AS full_name, photo FROM members WHERE id IN ($in)";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param($types, ...$member_ids);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($m = $res2->fetch_assoc()) {
        $members[$m['id']] = $m;
    }
}
function member_display($id, $members) {
    if (isset($members[$id])) {
        $m = $members[$id];
        $name = trim($m['full_name']);
        $photo = !empty($m['photo']) ? BASE_URL . '/uploads/members/' . rawurlencode($m['photo']) : BASE_URL . '/assets/img/undraw_profile.svg';
        return '<span class="d-inline-flex align-items-center"><img src="'.htmlspecialchars($photo).'" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">'.htmlspecialchars($name).'</span>';
    } else {
        return '<span class="d-inline-flex align-items-center"><img src="'.BASE_URL.'/assets/img/undraw_profile.svg" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">Member #'.htmlspecialchars($id).'</span>';
    }
}
?>
<?php if (count($all_rows) > 0): ?>
    <?php foreach($all_rows as $row): ?>
    <tr>
        <td><?= htmlspecialchars($row['id']) ?></td>
        <td>
<?php
$logged_in_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$contact_html = '';
if ($row['sender_type'] === 'user' && $row['sender_id'] == $logged_in_user_id) {
    // Show recipient (contact)
    if ($row['recipient_type'] === 'member') {
        $contact_html = member_display($row['recipient_id'], $members);
    } else {
        $contact_html = '<span class="d-inline-flex align-items-center"><img src="'.BASE_URL.'/assets/img/undraw_profile.svg" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">User #'.htmlspecialchars($row['recipient_id']).'</span>';
    }
} elseif ($row['recipient_type'] === 'user' && $row['recipient_id'] == $logged_in_user_id) {
    // Show sender (contact)
    if ($row['sender_type'] === 'member') {
        $contact_html = member_display($row['sender_id'], $members);
    } else {
        $contact_html = '<span class="d-inline-flex align-items-center"><img src="'.BASE_URL.'/assets/img/undraw_profile.svg" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">User #'.htmlspecialchars($row['sender_id']).'</span>';
    }
} else {
    // Neither party is the logged-in user (system message or orphaned thread)
    // Show both for completeness
    $contact_html = '';
    if ($row['sender_type'] === 'member') {
        $contact_html .= member_display($row['sender_id'], $members);
    } else {
        $contact_html .= '<span class="d-inline-flex align-items-center"><img src="'.BASE_URL.'/assets/img/undraw_profile.svg" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">User #'.htmlspecialchars($row['sender_id']).'</span>';
    }
    $contact_html .= ' <span class="text-muted">to</span> ';
    if ($row['recipient_type'] === 'member') {
        $contact_html .= member_display($row['recipient_id'], $members);
    } else {
        $contact_html .= '<span class="d-inline-flex align-items-center"><img src="'.BASE_URL.'/assets/img/undraw_profile.svg" style="height:28px;width:28px;object-fit:cover;border-radius:50%;margin-right:6px;">User #'.htmlspecialchars($row['recipient_id']).'</span>';
    }
}
echo $contact_html;
?>
</td>
<td><?= nl2br(htmlspecialchars($row['message'])) ?></td>
<td><?= htmlspecialchars($row['sent_at']) ?></td>
        <td>
    <a href="memberfeedback_thread.php?id=<?= $row['id'] ?>" class="btn btn-primary btn-sm"><i class="fas fa-comments"></i> Open Chat</a>
    <a href="memberfeedback_list.php?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger ml-1" onclick="return confirm('Are you sure you want to delete this feedback?');"><i class="fas fa-trash-alt"></i></a>
</td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr><td colspan="7" class="text-center">No feedback found.</td></tr>
<?php endif; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center">No feedback found.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
