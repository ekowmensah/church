<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!has_permission('view_feedback_report')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$feedback_msg = '';
if (isset($_GET['delete'])) {
    $del_id = intval($_GET['delete']);
    $stmt = $conn->prepare('DELETE FROM member_feedback WHERE id = ?');
    $stmt->bind_param('i', $del_id);
    if ($stmt->execute()) {
        header('Location: memberfeedback_list.php?deleted=1');
        exit;
    }
    $feedback_msg = 'Error deleting feedback.';
}
if (isset($_GET['deleted'])) {
    $feedback_msg = 'Feedback deleted.';
}

$all_rows = [];
$member_ids = [];
$sql = "SELECT id, sender_type, sender_id, recipient_type, recipient_id, message, sent_at
        FROM member_feedback_thread
        WHERE feedback_id IS NULL
        ORDER BY sent_at DESC";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_rows[] = $row;
        if ($row['sender_type'] === 'member') {
            $member_ids[] = (int) $row['sender_id'];
        }
        if ($row['recipient_type'] === 'member') {
            $member_ids[] = (int) $row['recipient_id'];
        }
    }
}

$members = [];
$member_ids = array_values(array_unique(array_filter($member_ids)));
if (!empty($member_ids)) {
    $in = implode(',', array_fill(0, count($member_ids), '?'));
    $types = str_repeat('i', count($member_ids));
    $sql2 = "SELECT id, CONCAT_WS(' ', last_name, first_name, middle_name) AS full_name, photo FROM members WHERE id IN ($in)";
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param($types, ...$member_ids);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($m = $res2->fetch_assoc()) {
        $members[(int) $m['id']] = $m;
    }
    $stmt2->close();
}

function feedback_member_entity(int $id, array $members): array
{
    if (isset($members[$id])) {
        $m = $members[$id];
        return [
            'id' => $id,
            'type' => 'member',
            'name' => trim((string) $m['full_name']) ?: ('Member #' . $id),
            'avatar' => !empty($m['photo'])
                ? BASE_URL . '/uploads/members/' . rawurlencode($m['photo'])
                : BASE_URL . '/assets/img/undraw_profile.svg',
            'badge' => 'Member',
        ];
    }

    return [
        'id' => $id,
        'type' => 'member',
        'name' => 'Member #' . $id,
        'avatar' => BASE_URL . '/assets/img/undraw_profile.svg',
        'badge' => 'Member',
    ];
}

function feedback_user_entity(int $id): array
{
    return [
        'id' => $id,
        'type' => 'user',
        'name' => 'User #' . $id,
        'avatar' => BASE_URL . '/assets/img/undraw_profile.svg',
        'badge' => 'Staff',
    ];
}

function feedback_entity(string $type, int $id, array $members): array
{
    return $type === 'member' ? feedback_member_entity($id, $members) : feedback_user_entity($id);
}

function feedback_thread_card_data(array $row, array $members, int $logged_in_user_id): array
{
    $sender = feedback_entity($row['sender_type'], (int) $row['sender_id'], $members);
    $recipient = feedback_entity($row['recipient_type'], (int) $row['recipient_id'], $members);

    $direction = 'Mixed';
    $primary = $sender;
    $secondary = $recipient;

    if ($row['sender_type'] === 'user' && (int) $row['sender_id'] === $logged_in_user_id) {
        $direction = 'Outgoing';
        $primary = $recipient;
        $secondary = $sender;
    } elseif ($row['recipient_type'] === 'user' && (int) $row['recipient_id'] === $logged_in_user_id) {
        $direction = 'Incoming';
        $primary = $sender;
        $secondary = $recipient;
    }

    $contact_type = ($primary['type'] === 'member' || $secondary['type'] === 'member') ? 'member' : 'user';
    $pair_label = $sender['name'] . ' -> ' . $recipient['name'];

    return [
        'thread_id' => (int) $row['id'],
        'message' => (string) $row['message'],
        'sent_at' => (string) $row['sent_at'],
        'primary' => $primary,
        'secondary' => $secondary,
        'direction' => $direction,
        'pair_label' => $pair_label,
        'contact_type' => $contact_type,
    ];
}

$logged_in_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
$cards = [];
foreach ($all_rows as $row) {
    $cards[] = feedback_thread_card_data($row, $members, $logged_in_user_id);
}

$total_threads = count($cards);
$member_threads = 0;
$user_threads = 0;
$latest_timestamp = '';

foreach ($cards as $card) {
    if ($card['contact_type'] === 'member') {
        $member_threads++;
    } else {
        $user_threads++;
    }
    if ($latest_timestamp === '' || strtotime($card['sent_at']) > strtotime($latest_timestamp)) {
        $latest_timestamp = $card['sent_at'];
    }
}

ob_start();
?>
<div class="chat-portal">
    <section class="portal-hero card border-0 shadow-sm mb-4">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <div class="portal-kicker">Communication Hub</div>
                <h2 class="portal-title mb-1"><i class="fas fa-comments mr-2"></i>Member Feedback Inbox</h2>
                <p class="text-muted mb-0">A dedicated portal for managing message threads with members and staff.</p>
            </div>
            <div class="d-flex mt-3 mt-md-0">
                <a href="memberfeedback_form.php" class="btn btn-primary btn-pill mr-2">
                    <i class="fas fa-pen-alt mr-1"></i> Start Thread
                </a>
                <a href="memberfeedback_list.php" class="btn btn-outline-secondary btn-pill">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </a>
            </div>
        </div>
    </section>

    <?php if ($feedback_msg): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($feedback_msg) ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>

    <section class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="portal-metric metric-blue">
                <div class="metric-label">Total Threads</div>
                <div class="metric-value"><?= number_format($total_threads) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="portal-metric metric-green">
                <div class="metric-label">Member Threads</div>
                <div class="metric-value"><?= number_format($member_threads) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="portal-metric metric-slate">
                <div class="metric-label">Staff Threads</div>
                <div class="metric-value"><?= number_format($user_threads) ?></div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="portal-metric metric-violet">
                <div class="metric-label">Latest Activity</div>
                <div class="metric-value metric-time"><?= $latest_timestamp ? htmlspecialchars(date('M j, g:i A', strtotime($latest_timestamp))) : 'No activity' ?></div>
            </div>
        </div>
    </section>

    <section class="inbox card border-0 shadow-sm">
        <div class="card-body">
            <div class="inbox-toolbar d-flex flex-wrap align-items-center justify-content-between mb-3">
                <h5 class="mb-2 mb-md-0 font-weight-bold">
                    <i class="fas fa-inbox text-primary mr-2"></i>Conversation Threads
                </h5>
                <div class="d-flex flex-wrap align-items-center">
                    <div class="btn-group btn-group-sm mr-2 mb-2 mb-md-0" role="group" aria-label="Thread filter">
                        <button type="button" class="btn btn-outline-secondary thread-filter active" data-filter="all">All</button>
                        <button type="button" class="btn btn-outline-secondary thread-filter" data-filter="member">Members</button>
                        <button type="button" class="btn btn-outline-secondary thread-filter" data-filter="user">Staff</button>
                    </div>
                    <div class="input-group inbox-search">
                        <div class="input-group-prepend">
                            <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
                        </div>
                        <input type="text" id="threadSearch" class="form-control border-left-0" placeholder="Search by name or message...">
                    </div>
                </div>
            </div>

            <div id="threadList" class="thread-list">
                <?php if ($total_threads > 0): ?>
                    <?php foreach ($cards as $card): ?>
                        <?php
                        $dir_class = 'mixed';
                        if ($card['direction'] === 'Incoming') $dir_class = 'incoming';
                        if ($card['direction'] === 'Outgoing') $dir_class = 'outgoing';
                        $search_blob = strtolower(
                            $card['primary']['name'] . ' ' .
                            $card['secondary']['name'] . ' ' .
                            $card['message'] . ' ' .
                            $card['pair_label'] . ' ' .
                            $card['direction']
                        );
                        ?>
                        <article
                            class="thread-card <?= htmlspecialchars($dir_class) ?>"
                            data-thread-type="<?= htmlspecialchars($card['contact_type']) ?>"
                            data-search="<?= htmlspecialchars($search_blob) ?>"
                        >
                            <div class="thread-main">
                                <div class="thread-avatar-wrap">
                                    <img src="<?= htmlspecialchars($card['primary']['avatar']) ?>" alt="" class="thread-avatar">
                                </div>
                                <div class="thread-content">
                                    <div class="thread-head d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="thread-name"><?= htmlspecialchars($card['primary']['name']) ?></div>
                                            <div class="thread-meta">
                                                <span class="badge badge-light border mr-1"><?= htmlspecialchars($card['primary']['badge']) ?></span>
                                                <span class="badge badge-<?= $card['direction'] === 'Incoming' ? 'success' : ($card['direction'] === 'Outgoing' ? 'primary' : 'secondary') ?>">
                                                    <?= htmlspecialchars($card['direction']) ?>
                                                </span>
                                                <span class="ml-2 text-muted small">#<?= (int) $card['thread_id'] ?></span>
                                            </div>
                                        </div>
                                        <div class="thread-time text-muted small"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($card['sent_at']))) ?></div>
                                    </div>
                                    <div class="thread-preview"><?= nl2br(htmlspecialchars($card['message'])) ?></div>
                                    <div class="thread-pair text-muted small mt-1"><?= htmlspecialchars($card['pair_label']) ?></div>
                                </div>
                            </div>
                            <div class="thread-actions">
                                <a href="memberfeedback_thread.php?id=<?= (int) $card['thread_id'] ?>" class="btn btn-sm btn-outline-primary mb-2">
                                    <i class="fas fa-comments mr-1"></i> Open
                                </a>
                                <a href="memberfeedback_list.php?delete=<?= (int) $card['thread_id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this feedback?');">
                                    <i class="fas fa-trash-alt"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-comment-slash fa-2x text-muted mb-2"></i>
                        <div class="text-muted">No feedback threads available yet.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<style>
.chat-portal { padding-bottom: 1rem; }
.portal-hero { background: linear-gradient(130deg, #f4f8ff 0%, #eef7ff 35%, #f8f7ff 100%); }
.portal-kicker {
    display: inline-block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #4f46e5;
    font-weight: 700;
    margin-bottom: 0.25rem;
}
.portal-title {
    font-size: 1.55rem;
    font-weight: 700;
    color: #182337;
}
.btn-pill {
    border-radius: 999px;
    padding-left: 1rem;
    padding-right: 1rem;
}
.portal-metric {
    background: #fff;
    border: 1px solid #e8ebf0;
    border-radius: 12px;
    padding: 0.95rem 1rem;
    height: 100%;
}
.metric-label {
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #64748b;
    font-weight: 700;
}
.metric-value {
    margin-top: 0.35rem;
    font-size: 1.3rem;
    font-weight: 700;
    color: #0f172a;
}
.metric-time { font-size: 0.98rem; line-height: 1.3; }
.metric-blue { border-left: 4px solid #2563eb; }
.metric-green { border-left: 4px solid #059669; }
.metric-slate { border-left: 4px solid #475569; }
.metric-violet { border-left: 4px solid #7c3aed; }
.inbox-search { width: 280px; }
.thread-list { display: flex; flex-direction: column; gap: 0.7rem; }
.thread-card {
    display: flex;
    justify-content: space-between;
    align-items: stretch;
    gap: 0.9rem;
    border: 1px solid #e5e9f1;
    background: #fff;
    border-radius: 12px;
    padding: 0.9rem;
    transition: all 0.2s ease;
}
.thread-card:hover {
    border-color: #c9d4e8;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
    transform: translateY(-1px);
}
.thread-card.incoming { border-left: 4px solid #059669; }
.thread-card.outgoing { border-left: 4px solid #2563eb; }
.thread-card.mixed { border-left: 4px solid #64748b; }
.thread-main {
    min-width: 0;
    flex: 1 1 auto;
    display: flex;
    align-items: flex-start;
    gap: 0.8rem;
}
.thread-avatar-wrap { flex: 0 0 auto; }
.thread-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    border: 1px solid #d6dbe5;
}
.thread-content { min-width: 0; flex: 1 1 auto; }
.thread-name {
    font-size: 1rem;
    font-weight: 700;
    color: #172033;
    line-height: 1.2;
}
.thread-meta { margin-top: 0.22rem; }
.thread-preview {
    margin-top: 0.45rem;
    color: #334155;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.35;
    max-width: 760px;
}
.thread-pair { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.thread-actions {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: flex-end;
    flex: 0 0 auto;
}
.thread-time { white-space: nowrap; margin-left: 0.7rem; }
@media (max-width: 991px) {
    .inbox-search { width: 100%; margin-top: 0.5rem; }
}
@media (max-width: 768px) {
    .thread-card { flex-direction: column; }
    .thread-actions {
        flex-direction: row;
        justify-content: flex-start;
        gap: 0.5rem;
    }
    .thread-actions .btn { margin-bottom: 0 !important; }
    .thread-head { flex-direction: column; gap: 0.35rem; }
    .thread-time { margin-left: 0; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('threadSearch');
    const cards = Array.from(document.querySelectorAll('.thread-card'));
    const filterBtns = Array.from(document.querySelectorAll('.thread-filter'));
    let activeFilter = 'all';

    function applyFilters() {
        const q = (searchInput ? searchInput.value : '').trim().toLowerCase();
        cards.forEach(function (card) {
            const type = card.getAttribute('data-thread-type') || 'all';
            const hay = card.getAttribute('data-search') || '';
            const matchesFilter = activeFilter === 'all' || type === activeFilter;
            const matchesSearch = q === '' || hay.indexOf(q) !== -1;
            card.style.display = (matchesFilter && matchesSearch) ? '' : 'none';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    filterBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            filterBtns.forEach(function (b) { b.classList.remove('active'); });
            this.classList.add('active');
            activeFilter = this.getAttribute('data-filter') || 'all';
            applyFilters();
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
