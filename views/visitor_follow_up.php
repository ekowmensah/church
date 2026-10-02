<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/VisitorFollowUpService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true) || is_super_admin();
if (!$isSuperAdmin && !has_permission('manage_visitor_follow_up')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$service = VisitorFollowUpService::fromSession($conn);
$visitorId = (int) ($_REQUEST['id'] ?? 0);
$visitor = $service->getVisitor($visitorId);
if (!$visitor) {
    http_response_code(404);
    exit('Visitor not found in your authorized church scope.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your form expired. Refresh and try again.');
    }
    try {
        $assignedTo = isset($_POST['assigned_to_user_id']) && $_POST['assigned_to_user_id'] !== ''
            ? (int) $_POST['assigned_to_user_id'] : null;
        $service->recordFollowUp(
            $visitorId,
            (string) ($_POST['follow_up_status'] ?? ''),
            (string) ($_POST['contact_method'] ?? 'none'),
            (string) ($_POST['notes'] ?? ''),
            $_POST['next_follow_up_date'] ?? null,
            $assignedTo,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
        );
        header('Location: visitor_follow_up.php?id=' . $visitorId . '&saved=1');
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$visitor = $service->getVisitor($visitorId);
$history = $service->getHistory($visitorId);
$users = $service->getAssignableUsers((int) $visitor['church_id']);
$statuses = VisitorFollowUpService::statusLabels();
$methods = VisitorFollowUpService::contactMethodLabels();
$page_title = 'Visitor Follow-up';
ob_start();
?>
<style>
.follow-up-page{background:#f5f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.follow-up-hero{background:linear-gradient(135deg,#225f50,#3d9b7d);color:#fff;border-radius:15px;padding:1.25rem 1.5rem}.follow-up-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.timeline-row{border-left:4px solid #3d9b7d}.table thead th{white-space:nowrap}
</style>
<div class="follow-up-page"><div class="container-fluid">
  <div class="follow-up-hero mb-3 d-flex flex-wrap justify-content-between align-items-center">
    <div><h2 class="mb-1"><i class="fas fa-people-arrows mr-2"></i>Visitor Follow-up</h2><div><?= htmlspecialchars($visitor['name']) ?> · <?= htmlspecialchars($visitor['church_name'] ?? '') ?></div></div>
    <a class="btn btn-light btn-sm mt-2 mt-md-0" href="visitor_list.php"><i class="fas fa-arrow-left mr-1"></i>Visitors</a>
  </div>
  <?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Follow-up activity recorded.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="row">
    <div class="col-lg-4 mb-3">
      <div class="card follow-up-card mb-3"><div class="card-body">
        <h5 class="mb-3">Visitor</h5>
        <dl class="row mb-0">
          <dt class="col-5">Status</dt><dd class="col-7"><?= htmlspecialchars($statuses[$visitor['follow_up_status']] ?? $visitor['follow_up_status']) ?></dd>
          <dt class="col-5">Conversion</dt><dd class="col-7"><?= htmlspecialchars(ucfirst($visitor['conversion_status'] ?? 'visitor')) ?><?php if (!empty($visitor['converted_crn'])): ?><br><small><?= htmlspecialchars($visitor['converted_crn']) ?></small><?php endif; ?></dd>
          <dt class="col-5">Phone</dt><dd class="col-7"><?= htmlspecialchars($visitor['phone'] ?: '-') ?></dd>
          <dt class="col-5">Email</dt><dd class="col-7"><?= htmlspecialchars($visitor['email'] ?: '-') ?></dd>
          <dt class="col-5">Visit Date</dt><dd class="col-7"><?= htmlspecialchars($visitor['visit_date'] ?: '-') ?></dd>
          <dt class="col-5">Next Follow-up</dt><dd class="col-7"><?= htmlspecialchars($visitor['next_follow_up_date'] ?: '-') ?></dd>
          <dt class="col-5">Assigned To</dt><dd class="col-7"><?= htmlspecialchars($visitor['assigned_to_name'] ?: '-') ?></dd>
        </dl>
      </div></div>
      <div class="card follow-up-card"><div class="card-header bg-white font-weight-bold">Record Activity</div><div class="card-body">
        <form method="post">
          <?= csrf_input() ?><input type="hidden" name="id" value="<?= $visitorId ?>">
          <div class="form-group"><label>Status</label><select class="form-control" name="follow_up_status" required><?php foreach ($statuses as $value => $label): ?><option value="<?= htmlspecialchars($value) ?>" <?= $visitor['follow_up_status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Contact Method</label><select class="form-control" name="contact_method" required><?php foreach ($methods as $value => $label): ?><option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Assigned User</label><select class="form-control" name="assigned_to_user_id"><option value="">Unassigned</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>" <?= (int) ($visitor['follow_up_assigned_to_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name'] ?: $user['email']) ?></option><?php endforeach; ?></select></div>
          <div class="form-group"><label>Next Follow-up Date</label><input type="date" class="form-control" name="next_follow_up_date" value="<?= htmlspecialchars($visitor['next_follow_up_date'] ?? '') ?>"></div>
          <div class="form-group"><label>Notes</label><textarea class="form-control" name="notes" maxlength="1000" rows="4" required></textarea></div>
          <button class="btn btn-success btn-block"><i class="fas fa-save mr-1"></i>Save Follow-up</button>
        </form>
      </div></div>
    </div>
    <div class="col-lg-8"><div class="card follow-up-card"><div class="card-header bg-white font-weight-bold">Follow-up History</div><div class="card-body">
      <?php if (!$history): ?><div class="text-muted text-center py-4">No follow-up activity has been recorded.</div><?php endif; ?>
      <?php foreach ($history as $entry): ?><div class="timeline-row pl-3 py-2 mb-3">
        <div class="d-flex flex-wrap justify-content-between"><strong><?= htmlspecialchars($statuses[$entry['follow_up_status']] ?? $entry['follow_up_status']) ?></strong><small class="text-muted"><?= htmlspecialchars($entry['created_at']) ?></small></div>
        <div class="small text-muted mb-1"><?= htmlspecialchars($methods[$entry['contact_method']] ?? $entry['contact_method']) ?> · recorded by <?= htmlspecialchars($entry['recorded_by_name'] ?: 'system') ?></div>
        <div><?= nl2br(htmlspecialchars($entry['notes'])) ?></div>
        <?php if ($entry['next_follow_up_date'] || $entry['assigned_to_name']): ?><div class="small mt-2"><strong>Next:</strong> <?= htmlspecialchars($entry['next_follow_up_date'] ?: 'not scheduled') ?><?php if ($entry['assigned_to_name']): ?> · <?= htmlspecialchars($entry['assigned_to_name']) ?><?php endif; ?></div><?php endif; ?>
      </div><?php endforeach; ?>
    </div></div></div>
  </div>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
