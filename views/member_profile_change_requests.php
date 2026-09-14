<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/MemberLifecycleService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$isSuperAdmin = (int) ($_SESSION['role_id'] ?? 0) === 1 || !empty($_SESSION['is_super_admin']);
if (!$isSuperAdmin && !has_permission('review_member_profile_changes')) {
    http_response_code(403);
    die('You do not have permission to review profile changes.');
}

$service = MemberLifecycleService::fromSession($conn);
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid session token.');
        $service->reviewProfileRequest(
            (int) ($_POST['request_id'] ?? 0),
            (string) ($_POST['decision'] ?? ''),
            (string) ($_POST['review_notes'] ?? '')
        );
        $notice = 'The profile request was ' . ($_POST['decision'] === 'approved' ? 'approved.' : 'rejected.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = (string) ($_GET['status'] ?? 'pending');
if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'all'], true)) $status = 'pending';
$requests = $service->listProfileRequests($status);

function profile_change_display_value($value): string {
    if (is_array($value)) {
        if (!$value) return 'None';
        $parts = [];
        foreach ($value as $item) {
            if (is_array($item)) $parts[] = implode(' / ', array_filter(array_map('strval', $item)));
            else $parts[] = (string) $item;
        }
        return implode('; ', $parts);
    }
    if ($value === null || $value === '') return '—';
    if ($value === 1 || $value === '1') return 'Yes';
    if ($value === 0 || $value === '0') return 'No';
    return (string) $value;
}

function profile_change_label(string $field): string {
    return ucwords(str_replace('_', ' ', $field));
}

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div><h4 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-edit"></i> Profile Change Requests</h4>
    <small class="text-muted">Review member-submitted changes before they replace the official profile.</small></div>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Members</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="card shadow mb-4"><div class="card-body">
    <form method="get" class="form-inline">
        <label class="mr-2" for="status">Status</label>
        <select id="status" name="status" class="form-control mr-2">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'] as $value => $label): ?>
                <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-primary" type="submit">Filter</button>
    </form>
</div></div>

<?php if (!$requests): ?>
    <div class="alert alert-info">No <?= htmlspecialchars($status) ?> profile requests were found in your scope.</div>
<?php endif; ?>
<?php foreach ($requests as $request):
    $before = json_decode((string) $request['original_snapshot'], true) ?: [];
    $after = json_decode((string) $request['change_payload'], true) ?: [];
    $changed = [];
    foreach ($after as $field => $value) {
        if (json_encode($before[$field] ?? null) !== json_encode($value)) $changed[$field] = $value;
    }
?>
<div class="card shadow mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= htmlspecialchars(trim($request['first_name'] . ' ' . $request['middle_name'] . ' ' . $request['last_name'])) ?>
            <span class="text-muted">(<?= htmlspecialchars($request['crn']) ?>)</span></strong>
        <span class="badge badge-<?= $request['status'] === 'pending' ? 'warning' : ($request['status'] === 'approved' ? 'success' : 'danger') ?> text-uppercase">
            <?= htmlspecialchars($request['status']) ?>
        </span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3"><?= htmlspecialchars($request['church_name']) ?> · Submitted <?= htmlspecialchars(date('j M Y, g:i a', strtotime($request['created_at']))) ?></p>
        <?php if (!$changed): ?><div class="alert alert-secondary">The submitted values match the captured profile.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-sm table-bordered">
            <thead class="thead-light"><tr><th>Field</th><th>Current at submission</th><th>Requested value</th></tr></thead>
            <tbody><?php foreach ($changed as $field => $value): ?>
                <tr><th><?= htmlspecialchars(profile_change_label($field)) ?></th>
                    <td><?= nl2br(htmlspecialchars(profile_change_display_value($before[$field] ?? null))) ?></td>
                    <td><?= nl2br(htmlspecialchars(profile_change_display_value($value))) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <?php if ($request['status'] === 'pending'): ?>
        <form method="post" class="mt-3">
            <?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
            <div class="form-group"><label>Review notes <small class="text-muted">(required for rejection)</small></label>
                <textarea name="review_notes" class="form-control" maxlength="500"></textarea></div>
            <button class="btn btn-success" name="decision" value="approved" type="submit" onclick="return confirm('Approve and apply these profile changes?')"><i class="fas fa-check"></i> Approve</button>
            <button class="btn btn-danger" name="decision" value="rejected" type="submit" onclick="return confirm('Reject this profile request?')"><i class="fas fa-times"></i> Reject</button>
        </form>
        <?php elseif (!empty($request['review_notes'])): ?>
            <div class="alert alert-secondary mb-0"><strong>Review notes:</strong> <?= nl2br(htmlspecialchars($request['review_notes'])) ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
