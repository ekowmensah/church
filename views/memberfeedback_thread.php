<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Get thread id (top-level chat id)
$thread_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$thread_id) {
    header('Location: memberfeedback_list.php');
    exit;
}

// Fetch thread info (for header)
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? AND feedback_id IS NULL');
$stmt->bind_param('i', $thread_id);
$stmt->execute();
$res = $stmt->get_result();
$thread = $res->fetch_assoc();
if (!$thread) {
    header('Location: memberfeedback_list.php');
    exit;
}

// Handle new message post
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim($_POST['message'] ?? '');
    if ($msg === '') {
        $error = 'Message cannot be empty.';
    } else {
        // Determine sender type/id
        if (isset($_SESSION['member_id'])) {
            $sender_type = 'member';
            $sender_id = $_SESSION['member_id'];
        } else {
            $sender_type = 'user';
            $sender_id = $_SESSION['user_id'] ?? 0;
        }
        $stmt = $conn->prepare('INSERT INTO member_feedback_thread (feedback_id, recipient_type, recipient_id, sender_type, sender_id, message, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $stmt->bind_param('isisss', $thread_id, $thread['recipient_type'], $thread['recipient_id'], $sender_type, $sender_id, $msg);
        if ($stmt->execute()) {
            header('Location: memberfeedback_thread.php?id=' . $thread_id);
            exit;
        } else {
            $error = 'Failed to send message.';
        }
    }
}

// Fetch all messages in thread (top-level + replies)
$stmt = $conn->prepare('SELECT * FROM member_feedback_thread WHERE id = ? OR feedback_id = ? ORDER BY sent_at ASC, id ASC');
$stmt->bind_param('ii', $thread_id, $thread_id);
$stmt->execute();
$messages = $stmt->get_result();

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white">
                    <i class="fas fa-comments"></i>
                    Chat: <?= ucfirst($thread['sender_type']) ?> #<?= htmlspecialchars($thread['sender_id']) ?> to <?= ucfirst($thread['recipient_type']) ?> #<?= htmlspecialchars($thread['recipient_id']) ?>
                </div>
                <div class="card-body" style="background:#f7f7f9; min-height:300px; max-height:500px; overflow-y:auto;">
                    <?php if ($messages->num_rows > 0): ?>
                        <?php while($msg = $messages->fetch_assoc()): ?>
                            <div class="mb-3 d-flex <?= $msg['sender_type']==='user' ? 'justify-content-end' : '' ?>">
                                <div class="p-2 rounded shadow-sm <?= $msg['sender_type']==='user' ? 'bg-success text-white' : 'bg-light border' ?>" style="max-width:70%;">
                                    <div style="font-size:0.95em;">
                                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                    </div>
                                    <div class="text-muted mt-1" style="font-size:0.8em;">
                                        <?= ucfirst($msg['sender_type']) ?> #<?= htmlspecialchars($msg['sender_id']) ?>
                                        <span class="ml-2"><?= htmlspecialchars($msg['sent_at']) ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="alert alert-info">No messages yet. Start the conversation below.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <?php if($error): ?><div class="alert alert-danger mb-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <form method="post" class="d-flex align-items-end">
                        <textarea name="message" class="form-control mr-2" rows="2" placeholder="Type your message..." required></textarea>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
                    </form>
                </div>
            </div>
            <?php
    // Determine back link target
    $back_link = (isset($_SESSION['member_id']) && !isset($_SESSION['role_id']))
        ? 'memberfeedback_my.php'
        : 'memberfeedback_list.php';
?>
<a href="<?= $back_link ?>" class="btn btn-link mt-3">&larr; Back to Feedback List</a>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); require_once __DIR__.'/../includes/layout.php'; ?>
