<?php
session_start();
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

// Show member name and role
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
    if ($role_row && $role_row['name']) {
        $role_name = $role_row['name'];
    }
}

$total_threads = count($threads);
$page_title = 'My Feedback Chats';

ob_start();
?>
<div class="container-fluid py-4 member-feedback-page">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
            <div class="feedback-shell shadow-sm">
                <div class="feedback-hero">
                    <div class="d-flex flex-wrap align-items-start justify-content-between">
                        <div class="hero-copy mb-3 mb-md-0">
                            <p class="hero-label mb-1">Member Communication</p>
                            <h2 class="hero-title mb-2">My Feedback Chats</h2>
                            <p class="hero-subtitle mb-0">Continue existing chats or start a new one with church leaders.</p>
                        </div>
                        <div class="hero-actions">
                            <div class="identity-card mb-3">
                                <div class="identity-row">
                                    <span class="identity-key">Name</span>
                                    <span class="identity-value"><?= htmlspecialchars($display_name) ?></span>
                                </div>
                                <div class="identity-row">
                                    <span class="identity-key">Role</span>
                                    <span class="identity-value"><?= htmlspecialchars($role_name) ?></span>
                                </div>
                                <div class="identity-row">
                                    <span class="identity-key">Total Chats</span>
                                    <span class="identity-value"><?= number_format($total_threads) ?></span>
                                </div>
                            </div>
                            <a href="memberfeedback_form.php" class="btn btn-warning btn-sm font-weight-bold px-3 py-2">
                                <i class="fas fa-plus mr-1"></i> Start New Chat
                            </a>
                        </div>
                    </div>
                </div>

                <div class="feedback-body">
                    <div class="chat-toolbar d-flex flex-wrap align-items-center justify-content-between mb-3">
                        <div class="chat-search-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" id="chatSearchInput" class="form-control form-control-sm" placeholder="Search chats by name or message">
                        </div>
                        <div class="text-muted small mt-2 mt-md-0">
                            Showing <span id="visibleChatCount"><?= number_format($total_threads) ?></span> of <?= number_format($total_threads) ?> chat<?= $total_threads === 1 ? '' : 's' ?>
                        </div>
                    </div>

                    <div class="chat-list-container" id="chatListContainer">
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

                                    if ($contact_name === '') {
                                        $contact_name = 'Conversation #' . intval($row['id']);
                                        $contact_photo = BASE_URL . '/assets/img/undraw_profile.svg';
                                    }

                                    $contact_badge = $contact_is_member ? 'Member' : 'User';
                                    $last_msg = $row['message'];
                                    $last_sent_at = $row['sent_at'];
                                    $search_blob = strtolower(trim($contact_name . ' ' . $last_msg));
                                ?>
                                <a href="memberfeedback_thread.php?id=<?= $row['id'] ?>" class="chat-preview-link text-decoration-none" data-chat-search="<?= htmlspecialchars($search_blob) ?>">
                                    <div class="chat-preview d-flex align-items-center">
                                        <div class="avatar mr-3 mr-md-4">
                                            <img src="<?= htmlspecialchars($contact_photo) ?>" alt="Photo">
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between mb-1">
                                                <div class="chat-name-line">
                                                    <span class="chat-name"><?= htmlspecialchars($contact_name) ?></span>
                                                    <span class="badge badge-pill badge-light chat-badge"><?= htmlspecialchars($contact_badge) ?></span>
                                                </div>
                                                <div class="chat-time">
                                                    <?= htmlspecialchars(time_ago($last_sent_at)) ?>
                                                </div>
                                            </div>
                                            <div class="chat-message">
                                                <?= htmlspecialchars(mb_strimwidth($last_msg, 0, 140, '...')) ?>
                                            </div>
                                            <div class="chat-date text-muted">
                                                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($last_sent_at))) ?>
                                            </div>
                                        </div>
                                        <div class="ml-3 chevron-wrap">
                                            <i class="fas fa-chevron-right"></i>
                                        </div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state text-center">
                                <div class="empty-icon mb-3"><i class="far fa-comment-dots"></i></div>
                                <h5 class="mb-2">No chats started yet</h5>
                                <p class="text-muted mb-3">Start a new feedback conversation and it will appear here.</p>
                                <a href="memberfeedback_form.php" class="btn btn-primary btn-sm px-3">
                                    <i class="fas fa-plus mr-1"></i> Create First Chat
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div id="noSearchResult" class="empty-state text-center mt-3 d-none">
                        <div class="empty-icon mb-3"><i class="fas fa-search"></i></div>
                        <h5 class="mb-2">No matching chats</h5>
                        <p class="text-muted mb-0">Try another name or message keyword.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .member-feedback-page {
        --page-blue: #114b8f;
        --page-blue-dark: #0d3564;
        --page-bg: #f5f8fd;
        --page-card: #ffffff;
        --page-muted: #5f6b7a;
    }

    .feedback-shell {
        border-radius: 18px;
        overflow: hidden;
        background: var(--page-card);
        border: 1px solid #e3ebf6;
    }

    .feedback-hero {
        background: linear-gradient(120deg, var(--page-blue) 0%, var(--page-blue-dark) 100%);
        color: #fff;
        padding: 1.5rem;
    }

    .hero-label {
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-size: 0.76rem;
        color: #d7e7ff;
        font-weight: 700;
    }

    .hero-title {
        font-weight: 700;
        margin: 0;
        font-size: 1.55rem;
    }

    .hero-subtitle {
        color: #dbe8fc;
        max-width: 530px;
    }

    .identity-card {
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        min-width: 250px;
        padding: 0.75rem;
        backdrop-filter: blur(2px);
    }

    .identity-row {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        font-size: 0.92rem;
        padding: 0.2rem 0;
    }

    .identity-key {
        color: #d6e5ff;
    }

    .identity-value {
        font-weight: 700;
        color: #fff;
    }

    .feedback-body {
        background: var(--page-bg);
        padding: 1rem;
    }

    .chat-toolbar {
        padding: 0.5rem;
    }

    .chat-search-wrap {
        position: relative;
        width: 100%;
        max-width: 360px;
    }

    .chat-search-wrap i {
        position: absolute;
        top: 50%;
        left: 12px;
        transform: translateY(-50%);
        color: #7f8a99;
        font-size: 0.88rem;
    }

    .chat-search-wrap .form-control {
        border-radius: 999px;
        padding-left: 33px;
        height: 36px;
        border: 1px solid #d7e0ed;
        box-shadow: none;
    }

    .chat-search-wrap .form-control:focus {
        border-color: #5f96d8;
        box-shadow: 0 0 0 0.2rem rgba(54, 127, 220, 0.15);
    }

    .chat-preview-link {
        display: block;
        margin-bottom: 0.75rem;
    }

    .chat-preview {
        background: var(--page-card);
        border: 1px solid #e2e9f2;
        border-radius: 14px;
        padding: 0.9rem;
        transition: 0.22s ease;
    }

    .chat-preview-link:hover .chat-preview {
        border-color: #93b7e3;
        box-shadow: 0 10px 24px rgba(25, 73, 139, 0.12);
        transform: translateY(-2px);
    }

    .avatar img {
        width: 52px;
        height: 52px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #ebf2fb;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.08);
    }

    .chat-name-line {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .chat-name {
        color: #1a2c44;
        font-weight: 700;
        font-size: 1rem;
    }

    .chat-badge {
        color: #335b89;
        border: 1px solid #ccdef3;
        background: #f2f7fe;
        font-size: 0.73rem;
        padding: 0.26rem 0.5rem;
    }

    .chat-time {
        color: #607387;
        font-size: 0.84rem;
        font-weight: 600;
    }

    .chat-message {
        color: #22354f;
        font-size: 0.94rem;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        margin-bottom: 0.16rem;
    }

    .chat-date {
        font-size: 0.76rem;
    }

    .chevron-wrap i {
        color: #8796aa;
        font-size: 0.92rem;
    }

    .empty-state {
        background: #fff;
        border: 1px dashed #c8d7ea;
        border-radius: 14px;
        padding: 2rem 1rem;
    }

    .empty-icon i {
        font-size: 1.7rem;
        color: #6f91b7;
    }

    @media (max-width: 767.98px) {
        .feedback-hero {
            padding: 1.2rem;
        }

        .hero-title {
            font-size: 1.35rem;
        }

        .identity-card {
            min-width: 100%;
        }

        .chat-preview {
            padding: 0.85rem;
        }

        .avatar img {
            width: 46px;
            height: 46px;
        }

        .chat-time {
            width: 100%;
            margin-top: 0.2rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('chatSearchInput');
    var links = Array.prototype.slice.call(document.querySelectorAll('.chat-preview-link[data-chat-search]'));
    var visibleCount = document.getElementById('visibleChatCount');
    var noResult = document.getElementById('noSearchResult');

    if (!input || !links.length) {
        return;
    }

    function applyFilter() {
        var term = input.value.toLowerCase().trim();
        var shown = 0;

        links.forEach(function (link) {
            var haystack = (link.getAttribute('data-chat-search') || '').toLowerCase();
            var matches = term === '' || haystack.indexOf(term) !== -1;
            link.classList.toggle('d-none', !matches);
            if (matches) {
                shown++;
            }
        });

        if (visibleCount) {
            visibleCount.textContent = shown;
        }

        if (noResult) {
            noResult.classList.toggle('d-none', shown > 0);
        }
    }

    input.addEventListener('input', applyFilter);
});
</script>
<?php $page_content = ob_get_clean(); require_once __DIR__.'/../includes/layout.php'; ?>
