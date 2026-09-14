<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/MemberLifecycleService.php';

if (!is_logged_in() || ((int) ($_SESSION['role_id'] ?? 0) !== 1 && !has_permission('delete_member'))) {
    http_response_code(403);
    die('You do not have permission to archive members.');
}
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$lifecycleService = MemberLifecycleService::fromSession($conn);
try {
    $member = $lifecycleService->getScopedMember($id);
} catch (Throwable $e) {
    $member = null;
}
if (!$member) {
    header('Location: member_list.php?error=' . urlencode('Member not found or already archived.'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid session token.');
        $lifecycleService->archiveMember($id, (string) ($_POST['reason'] ?? ''));
        header('Location: deleted_members_list.php?info=' . urlencode('Member archived; related history was preserved.'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$fullName = trim(implode(' ', array_filter([$member['first_name'], $member['middle_name'], $member['last_name']])));
ob_start();
?>
<div class="row justify-content-center"><div class="col-lg-7"><div class="card shadow mb-4">
<div class="card-header bg-danger text-white"><h6 class="m-0 font-weight-bold">Archive Member</h6></div>
<div class="card-body">
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<p>Archive <strong><?= htmlspecialchars($fullName) ?></strong> (<?= htmlspecialchars($member['crn']) ?>)? Payments, attendance, and other history will remain intact.</p>
<?php if ($member['status'] === 'active'): ?><div class="alert alert-warning">Deactivate this active member before archiving.</div><?php endif; ?>
<form method="post"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $id ?>">
<div class="form-group"><label for="reason">Archive reason <span class="text-danger">*</span></label>
<textarea id="reason" name="reason" class="form-control" maxlength="500" required><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea></div>
<button class="btn btn-danger" type="submit" <?= $member['status'] === 'active' ? 'disabled' : '' ?>><i class="fas fa-archive"></i> Archive</button>
<a class="btn btn-secondary" href="member_list.php">Cancel</a></form>
</div></div></div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
