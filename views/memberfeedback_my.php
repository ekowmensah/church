<?php
//die('DEBUG: memberfeedback_my.php is running');
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);

// Fetch all top-level chat threads for this member (sent or received)
$sql = "SELECT t.id, t.sender_type, t.sender_id, t.recipient_type, t.recipient_id, t.message, t.sent_at,
    CASE WHEN t.sender_type = 'member' AND t.sender_id != ? THEN t.sender_id
         WHEN t.recipient_type = 'member' AND t.recipient_id != ? THEN t.recipient_id
         ELSE NULL END AS contact_member_id
FROM member_feedback_thread t
WHERE t.feedback_id IS NULL AND (
    (t.sender_type = 'member' AND t.sender_id = ?) OR
    (t.recipient_type = 'member' AND t.recipient_id = ?)
)
ORDER BY t.sent_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iiii', $member_id, $member_id, $member_id, $member_id);
$stmt->execute();
$result = $stmt->get_result();

// Collect all contact_member_ids
$contact_ids = [];
$threads = [];
while ($row = $result->fetch_assoc()) {
    if ($row['contact_member_id']) $contact_ids[] = $row['contact_member_id'];
    $threads[] = $row;
}
$contact_ids = array_unique(array_filter($contact_ids));
$contacts = [];
if ($contact_ids) {
    $in = implode(',', array_fill(0, count($contact_ids), '?'));
    $types = str_repeat('i', count($contact_ids));
    $sql2 = "SELECT id, CONCAT_WS(' ', last_name, first_name, middle_name) AS full_name, photo FROM members WHERE id IN ($in)";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param($types, ...$contact_ids);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($m = $res2->fetch_assoc()) {
        $contacts[$m['id']] = $m;
    }
}


// Helper function for 'time ago'
function time_ago($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    if ($diff < 60) return $diff.' seconds ago';
    $diff = round($diff/60);
    if ($diff < 60) return $diff.' minutes ago';
    $diff = round($diff/60);
    if ($diff < 24) return $diff.' hours ago';
    $diff = round($diff/24);
    if ($diff < 7) return $diff.' days ago';
    $diff = round($diff/7);
    if ($diff < 4) return $diff.' weeks ago';
    return date('M j, Y', $timestamp);
}




ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card shadow-lg border-0 rounded-lg">
                <div class="card-header bg-primary text-white d-flex align-items-center">
                    <i class="fas fa-comments fa-fw mr-2"></i>
                    <h3 class="m-0 font-weight-bold flex-grow-1" style="font-size:1.2rem;">My Feedback Chats</h3>
                    <span class="ml-3 font-weight-bold d-none d-md-inline" style="font-size:1.05em;">
    <?php
        // Show member name and role name (right-aligned)
        $display_name = $_SESSION['member_name'] ?? '';
        if (!$display_name && isset($member_id)) {
            $stmt = $conn->prepare('SELECT CONCAT_WS(" ", last_name, first_name, middle_name) AS full_name FROM members WHERE id = ?');
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $display_name = $r && $r['full_name'] ? $r['full_name'] : 'Member';
        }
        $role_name = 'Member';
        if (isset($_SESSION['role_id'])) {
            $stmt = $conn->prepare('SELECT name FROM roles WHERE id = ?');
            $stmt->bind_param('i', $_SESSION['role_id']);
            $stmt->execute();
            $role_row = $stmt->get_result()->fetch_assoc();
            if ($role_row && $role_row['name']) $role_name = $role_row['name'];
        }
    ?>
    <span class="d-block text-right">
        <span class="text-light">Name:</span> <span class="font-weight-bold text-white"><?= htmlspecialchars($display_name) ?></span><br>
        <span class="text-light">Role:</span> <span class="font-weight-bold text-white"><?= htmlspecialchars($role_name) ?></span>
    </span>
</span>
                    <a href="memberfeedback_form.php" class="btn btn-light btn-sm ml-auto"><i class="fas fa-plus"></i> New Chat</a>
                </div>
                <div class="card-body">
                    <div class="chat-list-container py-2 px-1">
                        <?php if (!empty($threads)): ?>
                            <?php foreach($threads as $row): ?>
                                <?php
                                    $contact_name = '';
                                    $contact_photo = '';
                                    $contact_is_member = false;
                                    if ($row['contact_member_id']) {
                                        if (isset($contacts[$row['contact_member_id']])) {
                                            $m = $contacts[$row['contact_member_id']];
                                            $contact_name = trim($m['full_name']);
                                            $contact_photo = !empty($m['photo']) ? BASE_URL . '/uploads/members/' . rawurlencode($m['photo']) : BASE_URL . '/assets/img/undraw_profile.svg';
                                        } else {
                                            $contact_name = 'Member #' . $row['contact_member_id'];
                                            $contact_photo = BASE_URL . '/assets/img/undraw_profile.svg';
                                        }
                                        $contact_is_member = true;
                                    } elseif ($row['sender_type'] === 'user' || $row['recipient_type'] === 'user') {
                                        $user_id = ($row['sender_type'] === 'user') ? $row['sender_id'] : $row['recipient_id'];
                                        $contact_name = 'User #' . $user_id;
                                        $contact_photo = BASE_URL . '/assets/img/undraw_profile.svg';
                                    }
                                    $last_msg = $row['message'];
                                    $last_sent_at = $row['sent_at'];
                                ?>
                                <a href="memberfeedback_thread.php?id=<?= $row['id'] ?>" class="chat-preview-link text-decoration-none">
                                    <div class="chat-preview d-flex align-items-center mb-3 p-3 rounded shadow-sm" style="background:#f8fafc; transition:box-shadow .2s;">
                                        <div class="avatar mr-3">
                                            <img src="<?= htmlspecialchars($contact_photo) ?>" alt="Photo" style="height:48px;width:48px;object-fit:cover;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,0.10);">
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-1">
                                                <span class="font-weight-bold mr-2" style="font-size:1.1em;"><?= htmlspecialchars($contact_name) ?></span>
                                                <span class="text-muted ml-2" style="font-size:0.95em;">
                                                    <?= htmlspecialchars(time_ago($last_sent_at)) ?>
                                                </span>
                                            </div>
                                            <div class="text-dark" style="font-size:1.04em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:90vw;">
                                                <?= nl2br(htmlspecialchars(mb_strimwidth($last_msg, 0, 120, '...'))) ?>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <i class="fas fa-chevron-right text-secondary"></i>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info text-center mb-0">No chats started yet.</div>
                        <?php endif; ?>
                    </div>
                    <style>
                        .chat-preview-link:hover .chat-preview {
                            box-shadow: 0 0 0 3px #007bff33;
                            background: #e6f0ff;
                        }
                    </style>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); require_once __DIR__.'/../includes/layout.php'; ?>
