<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/MembershipStatusService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$isSuperAdmin = (int) ($_SESSION['role_id'] ?? 0) === 1 || !empty($_SESSION['is_super_admin']);
if (!$isSuperAdmin && !has_permission('manage_membership_status')) {
    http_response_code(403);
    exit('You do not have permission to manage membership statuses.');
}

$service = MembershipStatusService::fromSession($conn);
$error = '';
$notice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid session token.');
        $service->updateStatus(
            (int) ($_POST['member_id'] ?? 0),
            (string) ($_POST['membership_status'] ?? ''),
            (string) ($_POST['reason'] ?? '')
        );
        $notice = 'Membership status updated and audited.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$status = (string) ($_GET['status'] ?? '');
$search = (string) ($_GET['search'] ?? '');
$issuesOnly = isset($_GET['issues_only']);
try {
    $members = $service->listMembers($status, $search, $issuesOnly);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $members = [];
}

function membership_issue_label(string $issue): string {
    return ucwords(str_replace('_', ' ', $issue));
}

ob_start();
?>
<div class="d-sm-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-tag"></i> Membership Status</h4>
        <small class="text-muted">Assign the six approved statuses. Full Member and Catechumen are validated against sacramental records.</small>
    </div>
    <a href="reports/details/membership_status_report.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-chart-bar"></i> Status Report</a>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

<div class="card shadow mb-4"><div class="card-body">
<form method="get" class="form-row align-items-end">
    <div class="form-group col-md-4"><label>Search</label><input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, CRN or phone"></div>
    <div class="form-group col-md-3"><label>Status</label><select class="form-control" name="status">
        <option value="">All statuses</option><option value="unclassified" <?= $status === 'unclassified' ? 'selected' : '' ?>>Unclassified</option>
        <?php foreach (MembershipStatusService::STATUSES as $option): ?><option value="<?= htmlspecialchars($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?>
    </select></div>
    <div class="form-group col-md-3"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="issues_only" value="1" id="issues_only" <?= $issuesOnly ? 'checked' : '' ?>><label class="form-check-label" for="issues_only">Open reviews only</label></div></div>
    <div class="form-group col-md-2"><button class="btn btn-primary btn-block">Filter</button></div>
</form></div></div>

<div class="card shadow"><div class="card-body"><div class="table-responsive">
<table class="table table-bordered table-hover">
<thead><tr><th>Member</th><th>Church / Class</th><th>Sacramental evidence</th><th>Current status / Issues</th><th style="min-width:280px">Decision</th></tr></thead>
<tbody>
<?php if (!$members): ?><tr><td colspan="5" class="text-center text-muted">No members match this filter.</td></tr><?php endif; ?>
<?php foreach ($members as $member): ?>
<tr>
    <td><strong><?= htmlspecialchars(trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name'])) ?></strong><br><small><?= htmlspecialchars($member['crn'] ?: 'No CRN') ?> · <?= htmlspecialchars($member['phone'] ?: 'No phone') ?></small></td>
    <td><?= htmlspecialchars($member['church_name'] ?: '-') ?><br><small><?= htmlspecialchars($member['class_name'] ?: 'No Bible Class') ?></small></td>
    <td>Baptized: <strong><?= htmlspecialchars($member['baptized'] ?: 'Not recorded') ?></strong><br>Confirmed: <strong><?= htmlspecialchars($member['confirmed'] ?: 'Not recorded') ?></strong></td>
    <td><span class="badge badge-<?= $member['membership_status'] ? 'primary' : 'secondary' ?>"><?= htmlspecialchars($member['membership_status'] ?: 'Unclassified') ?></span>
        <?php foreach (array_filter(explode(',', (string) $member['review_issues'])) as $issue): ?><br><span class="badge badge-warning mt-1"><?= htmlspecialchars(membership_issue_label($issue)) ?></span><?php endforeach; ?>
    </td>
    <td><form method="post"><?= csrf_input() ?><input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>">
        <div class="form-row"><div class="col-5"><select class="form-control form-control-sm" name="membership_status" required><option value="">Choose…</option><?php foreach (MembershipStatusService::STATUSES as $option): ?><option value="<?= htmlspecialchars($option) ?>" <?= $member['membership_status'] === $option ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option><?php endforeach; ?></select></div>
        <div class="col-7"><input class="form-control form-control-sm" name="reason" maxlength="500" required placeholder="Reason for decision"></div></div>
        <button class="btn btn-success btn-sm mt-2" type="submit"><i class="fas fa-save"></i> Save status</button>
    </form></td>
</tr>
<?php endforeach; ?>
</tbody></table></div><small class="text-muted">Up to 500 scoped records are shown. Use search and filters to narrow the list.</small></div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
