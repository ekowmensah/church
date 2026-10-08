<?php
session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AccessReviewService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('view_access_reviews')) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
}

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$isSuperAdmin = is_super_admin();
$canCertify = $isSuperAdmin || has_permission('certify_access_reviews');
$canEditUsers = $isSuperAdmin || has_permission('edit_user');
$service = new AccessReviewService($conn);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your form expired. Refresh and try again.');
        }
        if (!$canCertify) throw new AccessReviewAuthorizationException('You cannot certify access reviews.');
        $service->certify(
            $actorUserId,
            (int) ($_POST['subject_user_id'] ?? 0),
            (string) ($_POST['decision'] ?? ''),
            (string) ($_POST['notes'] ?? ''),
            (int) ($_POST['valid_days'] ?? 90)
        );
        header('Location: access_reviews.php?saved=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'church_id' => max(0, (int) ($_GET['church_id'] ?? 0)),
    'status' => (string) ($_GET['status'] ?? 'all'),
    'review' => (string) ($_GET['review'] ?? 'all'),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 25);

try {
    $reviewData = $service->listSubjects($actorUserId, $filters, $page, $perPage);
} catch (AccessReviewAuthorizationException $exception) {
    http_response_code(403);
    require __DIR__ . '/errors/403.php';
    exit;
} catch (Throwable $exception) {
    $error = $error ?: 'The access-review queue could not be loaded.';
    error_log('Access review workspace load failed: ' . $exception->getMessage());
    $reviewData = [
        'subjects' => [],
        'summary' => ['total' => 0, 'active' => 0, 'due' => 0, 'current' => 0, 'changes_required' => 0],
        'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'total_pages' => 1],
        'scope' => ['church_id' => 0, 'is_super_admin' => $isSuperAdmin],
    ];
}

$historyData = null;
$historyUserId = max(0, (int) ($_GET['history_user'] ?? 0));
if ($historyUserId > 0) {
    try {
        $historyData = $service->getHistory($actorUserId, $historyUserId);
    } catch (Throwable $exception) {
        $error = $error ?: $exception->getMessage();
    }
}

$churches = [];
if ($isSuperAdmin) {
    $result = $conn->query('SELECT id, name FROM churches ORDER BY name');
    while ($row = $result->fetch_assoc()) $churches[] = $row;
}

$queryForPage = static function (int $targetPage) use ($filters, $reviewData): string {
    $query = array_filter([
        'search' => $filters['search'],
        'church_id' => $filters['church_id'] ?: null,
        'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
        'review' => $filters['review'] !== 'all' ? $filters['review'] : null,
        'per_page' => $reviewData['pagination']['per_page'],
        'page' => $targetPage,
    ], static fn($value) => $value !== null && $value !== '');
    return '?' . http_build_query($query);
};

ob_start();
?>
<style>
.review-workspace{max-width:1550px;margin:0 auto}.review-hero{border:0;border-radius:1rem;background:linear-gradient(135deg,#163f5c,#395b86);color:#fff}.review-hero p{color:rgba(255,255,255,.78);max-width:800px}.review-metric{height:100%;padding:1rem;border:1px solid #e4ebf1;border-radius:.9rem;background:#fff}.review-metric strong{display:block;font-size:1.55rem;color:#173f5f}.review-card{border:0;border-radius:1rem;box-shadow:0 .35rem 1.4rem rgba(23,63,95,.08)}.review-toolbar{display:grid;grid-template-columns:minmax(240px,1fr) 190px 165px 165px 120px;gap:.7rem}.review-table th{border-top:0;color:#526071;font-size:.7rem;text-transform:uppercase;letter-spacing:.055em;white-space:nowrap}.identity-name{font-weight:700;color:#173f5f}.identity-meta{font-size:.8rem;color:#718096}.role-copy{max-width:380px;font-size:.84rem;color:#425466}.review-pill{display:inline-flex;border-radius:999px;padding:.24rem .55rem;font-size:.72rem;font-weight:700}.review-current{background:#e4f5eb;color:#176b3a}.review-due{background:#fff0cb;color:#765800}.review-changes_required{background:#f9dddd;color:#922d2d}.risk-count{color:#922d2d;font-weight:700}.review-editor{border:1px solid #dbe6ee;border-radius:1rem;background:#f8fbfd}.history-card{border-left:4px solid #395b86}.empty-state{text-align:center;color:#718096;padding:2.5rem 1rem}@media(max-width:1100px){.review-toolbar{grid-template-columns:1fr 1fr}}@media(max-width:767px){.review-toolbar{grid-template-columns:1fr}.review-table thead{display:none}.review-table,.review-table tbody,.review-table tr,.review-table td{display:block;width:100%}.review-table tr{border:1px solid #e4ebf1;border-radius:.8rem;padding:.65rem;margin-bottom:.75rem}.review-table td{border:0;padding:.3rem}.review-table td[data-label]:before{content:attr(data-label);display:block;color:#8793a1;font-size:.67rem;font-weight:700;text-transform:uppercase}}
</style>

<div class="review-workspace">
    <section class="card review-hero shadow-sm mb-4"><div class="card-body p-4"><div class="small text-uppercase font-weight-bold mb-2" style="letter-spacing:.12em">Periodic access governance</div><h1 class="h3 font-weight-bold mb-2">Access Reviews</h1><p class="mb-0">Verify that every back-office account still has appropriate roles, overrides and high-risk capabilities. Reviews capture immutable evidence and never change access silently.</p></div></section>

    <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Access review recorded. The certification snapshot is now immutable.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <div class="row mb-4">
        <div class="col-6 col-xl mb-3 mb-xl-0"><div class="review-metric"><span class="small text-muted">Accounts</span><strong><?= number_format($reviewData['summary']['total']) ?></strong></div></div>
        <div class="col-6 col-xl mb-3 mb-xl-0"><div class="review-metric"><span class="small text-muted">Active</span><strong><?= number_format($reviewData['summary']['active']) ?></strong></div></div>
        <div class="col-6 col-xl mb-3 mb-xl-0"><div class="review-metric"><span class="small text-muted">Review due</span><strong class="text-warning"><?= number_format($reviewData['summary']['due']) ?></strong></div></div>
        <div class="col-6 col-xl mb-3 mb-xl-0"><div class="review-metric"><span class="small text-muted">Current</span><strong class="text-success"><?= number_format($reviewData['summary']['current']) ?></strong></div></div>
        <div class="col-12 col-xl"><div class="review-metric"><span class="small text-muted">Changes required</span><strong class="text-danger"><?= number_format($reviewData['summary']['changes_required']) ?></strong></div></div>
    </div>

    <?php if ($canCertify): ?>
    <section id="reviewEditor" class="review-editor p-3 p-lg-4 mb-4" hidden>
        <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="small text-uppercase text-muted font-weight-bold">Independent certification</div><h2 id="reviewSubjectName" class="h5 mb-1">Review user access</h2><p class="small text-muted mb-0">You cannot certify your own access. Choose “changes required” when the role owner must correct access separately.</p></div><button id="closeReviewEditor" class="btn btn-sm btn-outline-secondary" type="button"><i class="fas fa-times mr-1"></i>Close</button></div>
        <form method="post" id="reviewForm"><?= csrf_input() ?><input id="reviewSubjectId" type="hidden" name="subject_user_id">
            <div class="row"><div class="col-md-4 form-group"><label for="reviewDecision">Decision</label><select id="reviewDecision" name="decision" class="form-control" required><option value="certified">Access is appropriate</option><option value="changes_required">Changes required</option></select></div><div class="col-md-4 form-group"><label for="reviewValidity">Certification period</label><select id="reviewValidity" name="valid_days" class="form-control"><option value="30">30 days</option><option value="60">60 days</option><option value="90" selected>90 days</option><option value="180">180 days</option><option value="365">365 days</option></select></div><div class="col-md-4 d-flex align-items-end form-group"><div class="alert alert-light border mb-0 py-2 small"><i class="fas fa-lock mr-1"></i>A new snapshot is stored for every decision.</div></div><div class="col-12 form-group"><label for="reviewNotes">Review notes</label><textarea id="reviewNotes" name="notes" class="form-control" rows="3" maxlength="1000" placeholder="State what was checked or describe the changes required."></textarea><small id="reviewNotesHelp" class="form-text text-muted">Notes are optional for certification and mandatory when changes are required.</small></div></div>
            <div class="d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i class="fas fa-clipboard-check mr-2"></i>Record review</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="card review-card mb-4"><div class="card-body">
        <form method="get" class="review-toolbar mb-3">
            <input class="form-control" type="search" name="search" value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Search name, email or CRN…" aria-label="Search accounts">
            <?php if ($isSuperAdmin): ?><select class="form-control" name="church_id" aria-label="Church"><option value="">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $filters['church_id'] === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><?php endif; ?>
            <select class="form-control" name="status" aria-label="Account status"><option value="all">All statuses</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
            <select class="form-control" name="review" aria-label="Review state"><option value="all">All review states</option><option value="due" <?= $filters['review'] === 'due' ? 'selected' : '' ?>>Review due</option><option value="current" <?= $filters['review'] === 'current' ? 'selected' : '' ?>>Current</option><option value="changes" <?= $filters['review'] === 'changes' ? 'selected' : '' ?>>Changes required</option></select>
            <div class="input-group"><select class="form-control" name="per_page" aria-label="Rows per page"><?php foreach ([10,25,50,100] as $size): ?><option value="<?= $size ?>" <?= $reviewData['pagination']['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select><div class="input-group-append"><button class="btn btn-primary" type="submit" title="Apply filters"><i class="fas fa-filter"></i></button></div></div>
        </form>

        <div class="table-responsive"><table class="table review-table mb-0"><thead><tr><th>Account</th><th>Roles and source</th><th>Effective access</th><th>Overrides / expiry</th><th>Latest review</th><th class="text-right">Actions</th></tr></thead><tbody>
        <?php if (!$reviewData['subjects']): ?><tr><td colspan="6" class="empty-state">No user accounts match this review filter.</td></tr><?php endif; ?>
        <?php foreach ($reviewData['subjects'] as $subject): $nextRoleExpiry = $subject['next_role_expiry'] ?? null; ?>
            <tr>
                <td data-label="Account"><div class="identity-name"><?= htmlspecialchars($subject['name'] ?: 'Unnamed account', ENT_QUOTES, 'UTF-8') ?></div><div class="identity-meta"><?= htmlspecialchars($subject['crn'] ?: 'No CRN', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($subject['email'] ?: 'No email', ENT_QUOTES, 'UTF-8') ?></div><div class="identity-meta"><?= htmlspecialchars($subject['church_name'] ?: 'No church', ENT_QUOTES, 'UTF-8') ?> · <span class="badge badge-<?= $subject['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($subject['status']), ENT_QUOTES, 'UTF-8') ?></span></div></td>
                <td data-label="Roles and source"><div class="role-copy"><?= htmlspecialchars($subject['role_names'] ?: 'No effective role', ENT_QUOTES, 'UTF-8') ?></div><div class="identity-meta"><?= (int) $subject['role_count'] ?> role(s) · <?= htmlspecialchars(str_replace('_', ' ', $subject['role_sources'] ?: 'no source'), ENT_QUOTES, 'UTF-8') ?></div></td>
                <td data-label="Effective access"><strong><?= number_format($subject['effective_permission_count']) ?></strong> capabilities<?php if ($subject['high_risk_permission_count'] > 0): ?><div class="risk-count"><i class="fas fa-exclamation-triangle mr-1"></i><?= number_format($subject['high_risk_permission_count']) ?> high risk</div><?php else: ?><div class="identity-meta">No high-risk capability</div><?php endif; ?></td>
                <td data-label="Overrides / expiry"><div><?= number_format((int) ($subject['allow_override_count'] ?? 0)) ?> allow · <?= number_format((int) ($subject['deny_override_count'] ?? 0)) ?> deny</div><div class="identity-meta"><?= $nextRoleExpiry ? 'Next expiry ' . htmlspecialchars(date('M j, Y', strtotime($nextRoleExpiry)), ENT_QUOTES, 'UTF-8') : 'No role expiry scheduled' ?></div></td>
                <td data-label="Latest review"><span class="review-pill review-<?= htmlspecialchars($subject['review_state'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $subject['review_state'])), ENT_QUOTES, 'UTF-8') ?></span><?php if ($subject['reviewed_at']): ?><div class="identity-meta mt-1"><?= htmlspecialchars(date('M j, Y', strtotime($subject['reviewed_at'])), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($subject['reviewer_name'] ?: 'Unknown reviewer', ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?></td>
                <td data-label="Actions" class="text-right text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary mb-1" href="?<?= http_build_query(array_merge(array_filter($_GET, static fn($key) => $key !== 'history_user', ARRAY_FILTER_USE_KEY), ['history_user' => (int) $subject['id']])) ?>"><i class="fas fa-history mr-1"></i>History</a>
                    <?php if ($canEditUsers): ?><a class="btn btn-sm btn-outline-primary mb-1" href="user_form.php?id=<?= (int) $subject['id'] ?>"><i class="fas fa-user-cog mr-1"></i>Manage</a><?php endif; ?>
                    <?php if ($canCertify && (int) $subject['id'] !== $actorUserId): ?><button type="button" class="btn btn-sm btn-primary mb-1 review-action" data-user-id="<?= (int) $subject['id'] ?>" data-user-name="<?= htmlspecialchars($subject['name'] ?: 'Unnamed account', ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-clipboard-check mr-1"></i>Review</button><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

        <?php if ($reviewData['pagination']['total_pages'] > 1): ?><div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mt-3"><div class="small text-muted mb-2 mb-md-0">Showing page <?= $reviewData['pagination']['page'] ?> of <?= $reviewData['pagination']['total_pages'] ?> · <?= number_format($reviewData['pagination']['total']) ?> account(s)</div><nav aria-label="Access review pages"><ul class="pagination mb-0"><?php $current = $reviewData['pagination']['page']; $last = $reviewData['pagination']['total_pages']; ?><li class="page-item <?= $current <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $queryForPage(max(1, $current - 1)) ?>">Previous</a></li><?php for ($number = max(1, $current - 2); $number <= min($last, $current + 2); $number++): ?><li class="page-item <?= $number === $current ? 'active' : '' ?>"><a class="page-link" href="<?= $queryForPage($number) ?>"><?= $number ?></a></li><?php endfor; ?><li class="page-item <?= $current >= $last ? 'disabled' : '' ?>"><a class="page-link" href="<?= $queryForPage(min($last, $current + 1)) ?>">Next</a></li></ul></nav></div><?php endif; ?>
    </div></section>

    <?php if ($historyData): ?><section class="card review-card history-card mb-4"><div class="card-header bg-white d-flex justify-content-between align-items-center"><div><strong>Certification history</strong><div class="small text-muted"><?= htmlspecialchars($historyData['account']['name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($historyData['account']['crn'] ?: 'No CRN', ENT_QUOTES, 'UTF-8') ?></div></div><a class="btn btn-sm btn-outline-secondary" href="access_reviews.php"><i class="fas fa-times mr-1"></i>Close</a></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Reviewed</th><th>Decision</th><th>Reviewer</th><th>Valid until</th><th>Notes</th></tr></thead><tbody><?php if (!$historyData['history']): ?><tr><td colspan="5" class="empty-state">No certification has been recorded for this account.</td></tr><?php endif; ?><?php foreach ($historyData['history'] as $review): ?><tr><td><?= htmlspecialchars($review['reviewed_at'], ENT_QUOTES, 'UTF-8') ?></td><td><span class="review-pill review-<?= $review['decision'] === 'certified' ? 'current' : 'changes_required' ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $review['decision'])), ENT_QUOTES, 'UTF-8') ?></span></td><td><?= htmlspecialchars($review['reviewer_name'] ?: 'Unknown reviewer', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($review['valid_until'] ?: 'Not certified', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($review['notes'] ?: 'No notes', ENT_QUOTES, 'UTF-8') ?></td></tr><?php endforeach; ?></tbody></table></div></section><?php endif; ?>
</div>

<?php if ($canCertify): ?>
<script>
(() => {
    'use strict';
    const editor = document.getElementById('reviewEditor');
    const decision = document.getElementById('reviewDecision');
    const notes = document.getElementById('reviewNotes');
    const validity = document.getElementById('reviewValidity');
    function syncDecision() { const changes = decision.value === 'changes_required'; validity.disabled = changes; notes.required = changes; document.getElementById('reviewNotesHelp').textContent = changes ? 'Describe the required changes in at least 10 characters.' : 'Record the evidence checked or leave a concise certification note.'; }
    document.querySelectorAll('.review-action').forEach(button => button.addEventListener('click', () => { document.getElementById('reviewSubjectId').value = button.dataset.userId; document.getElementById('reviewSubjectName').textContent = `Review ${button.dataset.userName}`; decision.value = 'certified'; notes.value = ''; syncDecision(); editor.hidden = false; editor.scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
    document.getElementById('closeReviewEditor').addEventListener('click', () => { editor.hidden = true; });
    decision.addEventListener('change', syncDecision);
    syncDecision();
})();
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
