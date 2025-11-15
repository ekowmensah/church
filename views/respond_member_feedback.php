<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Permission check
if (!has_permission('view_member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}


$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: memberfeedback_list.php');
    exit;
}

// Fetch feedback
$stmt = $conn->prepare('SELECT f.*, CONCAT(m.last_name, ", ", m.first_name, " ", m.middle_name) AS member_name FROM member_feedback f LEFT JOIN members m ON f.member_id = m.id WHERE f.id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$feedback = $res->fetch_assoc();
if (!$feedback) {
    header('Location: memberfeedback_list.php');
    exit;
}

ob_start();
?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow p-4 text-center">
                <h3 class="mb-4 text-primary"><i class="fa fa-comments"></i> Feedback Chat Deprecated</h3>
                <div class="alert alert-info">
                    <b>This page is no longer used.</b><br>
                    Please use the <a href="memberfeedback_thread.php?id=<?= intval($feedback['id']) ?>" class="btn btn-primary btn-sm"><i class="fas fa-comments"></i> Chat Interface</a> to reply to member feedback.<br>
                    All responses should be sent as chat messages.
                </div>
                <a href="memberfeedback_list.php" class="btn btn-secondary mt-3">Back to Feedback List</a>
            </div>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); require_once __DIR__.'/../includes/layout.php'; ?>
