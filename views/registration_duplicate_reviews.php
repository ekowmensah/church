<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/RegistrationDuplicateService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$isSuperAdmin = (int) ($_SESSION['role_id'] ?? 0) === 1 || !empty($_SESSION['is_super_admin']);
if (!$isSuperAdmin && !has_permission('review_possible_duplicates')) {
    http_response_code(403);
    die('You do not have permission to review possible duplicates.');
}

$service = RegistrationDuplicateService::fromSession($conn);
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid session token.');
        if (($_POST['action'] ?? '') === 'resolve_duplicate') {
            $service->resolveConfirmedDuplicate(
                (int) ($_POST['review_id'] ?? 0),
                (string) ($_POST['survivor_side'] ?? ''),
                (string) ($_POST['resolution_notes'] ?? '')
            );
            $notice = 'Duplicate resolved. The survivor was retained and the duplicate was archived with audit evidence.';
        } else {
            $service->review(
                (int) ($_POST['review_id'] ?? 0),
                (string) ($_POST['decision'] ?? ''),
                (string) ($_POST['review_notes'] ?? '')
            );
            $notice = 'Duplicate review updated. Confirmed matches still require an explicit survivor decision.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'confirmed_duplicate', 'not_duplicate', 'allowed_duplicate', 'all'], true)) {
    $status = 'pending';
}
$reviews = $service->listReviews($status);

function duplicate_source_label(string $type): string {
    return $type === 'sunday_school' ? 'Sunday School' : ucfirst($type);
}

function duplicate_record_url(string $type, int $id): string {
    if ($type === 'member') return 'member_view.php?id=' . $id;
    if ($type === 'sunday_school') return 'sundayschool_view.php?id=' . $id;
    return 'visitor_form.php?id=' . $id;
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div><h4 class="m-0 font-weight-bold text-primary"><i class="fas fa-clone"></i> Possible Duplicates</h4>
    <small class="text-muted">Matching details are review signals. Confirmed duplicates require an explicit survivor; the other record remains available as archived evidence.</small></div>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Members</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="card shadow mb-4"><div class="card-body"><form method="get" class="form-inline">
<label for="status" class="mr-2">Status</label>
<select id="status" name="status" class="form-control mr-2">
<?php foreach (['pending' => 'Pending', 'confirmed_duplicate' => 'Confirmed duplicate', 'not_duplicate' => 'Not duplicate', 'allowed_duplicate' => 'Allowed to continue', 'all' => 'All'] as $value => $label): ?>
<option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
</select><button class="btn btn-primary" type="submit">Filter</button>
</form></div></div>

<?php if (!$reviews): ?><div class="alert alert-info">No duplicate reviews were found in your scope.</div><?php endif; ?>
<?php foreach ($reviews as $review): ?>
<div class="card shadow mb-4"><div class="card-header d-flex justify-content-between align-items-center">
<strong><?= htmlspecialchars($review['church_name'] ?? 'Church not available') ?></strong>
<span class="badge badge-<?= $review['status'] === 'pending' ? 'warning' : ($review['status'] === 'not_duplicate' ? 'success' : 'info') ?> text-uppercase"><?= htmlspecialchars(str_replace('_', ' ', $review['status'])) ?></span>
</div><div class="card-body">
<div class="row">
<?php foreach (['a', 'b'] as $side): $identity = $review['source_' . $side]; $type = $review['source_' . $side . '_type']; $sourceId = (int) $review['source_' . $side . '_id']; ?>
<div class="col-md-6 mb-3"><div class="border rounded p-3 h-100">
<div class="small text-uppercase text-muted mb-1"><?= htmlspecialchars(duplicate_source_label($type)) ?></div>
<h6><?= htmlspecialchars($identity['display_name']) ?></h6>
<div><strong>ID:</strong> <?= htmlspecialchars($identity['identifier'] ?? '') ?></div>
<div><strong>Phone:</strong> <?= htmlspecialchars($identity['phone'] ?? '') ?></div>
<div><strong>Email:</strong> <?= htmlspecialchars($identity['email'] ?? '') ?></div>
<a class="btn btn-sm btn-outline-primary mt-2" href="<?= htmlspecialchars(duplicate_record_url($type, $sourceId)) ?>">Open record</a>
</div></div>
<?php endforeach; ?>
</div>
<p><strong>Matched by:</strong> <?= htmlspecialchars(str_replace(',', ', ', $review['match_rules'])) ?>
· <strong>Detected:</strong> <?= htmlspecialchars($review['created_at']) ?></p>
<?php if (!empty($review['continued_reason'])): ?><div class="alert alert-info"><strong>Continue reason:</strong> <?= nl2br(htmlspecialchars($review['continued_reason'])) ?></div><?php endif; ?>
<?php if ($review['status'] === 'pending' || $review['status'] === 'allowed_duplicate'): ?>
<form method="post"><?= csrf_input() ?><input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
<div class="form-group"><label>Review notes <span class="text-danger">*</span></label><textarea class="form-control" name="review_notes" maxlength="500" required></textarea></div>
<button class="btn btn-danger" name="decision" value="confirmed_duplicate" type="submit">Confirm Duplicate</button>
<button class="btn btn-success" name="decision" value="not_duplicate" type="submit">Not a Duplicate</button>
<button class="btn btn-info" name="decision" value="allowed_duplicate" type="submit">Allow Separate Records</button>
</form>
<?php elseif ($review['status'] === 'confirmed_duplicate' && empty($review['resolution_id'])): ?>
<form method="post" class="border border-danger rounded p-3"><?= csrf_input() ?>
<input type="hidden" name="action" value="resolve_duplicate">
<input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>">
<div class="alert alert-warning">Choose the authoritative surviving identity. The other record is archived, never physically deleted.</div>
<div class="form-group"><label>Surviving record</label><select class="form-control" name="survivor_side" required>
<option value="">Choose survivor</option>
<option value="a"><?= htmlspecialchars(duplicate_source_label($review['source_a_type']) . ': ' . $review['source_a']['display_name']) ?></option>
<option value="b"><?= htmlspecialchars(duplicate_source_label($review['source_b_type']) . ': ' . $review['source_b']['display_name']) ?></option>
</select></div>
<div class="form-group"><label>Resolution evidence <span class="text-danger">*</span></label><textarea class="form-control" name="resolution_notes" maxlength="500" required></textarea></div>
<button class="btn btn-danger" type="submit" onclick="return confirm('Archive the non-surviving duplicate and preserve this decision in the audit trail?');">Resolve and Archive Duplicate</button>
</form>
<?php elseif (!empty($review['resolution_id'])): ?>
<div class="alert alert-success"><strong>Resolved:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $review['resolution_action']))) ?>.
Survivor: <?= htmlspecialchars(duplicate_source_label($review['survivor_type']) . ' #' . $review['survivor_id']) ?>.
<?= nl2br(htmlspecialchars($review['resolution_notes'])) ?></div>
<?php elseif (!empty($review['review_notes'])): ?><div class="alert alert-secondary"><strong>Review notes:</strong> <?= nl2br(htmlspecialchars($review['review_notes'])) ?></div><?php endif; ?>
</div></div>
<?php endforeach; ?>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
