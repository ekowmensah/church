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
$canRestore = $isSuperAdmin || has_permission('restore_deleted_member');
$canAudit = $isSuperAdmin || has_permission('view_member_lifecycle_audit');
if (!$canRestore && !$canAudit) {
    http_response_code(403);
    die('You do not have permission to view archived members.');
}

$members = MemberLifecycleService::fromSession($conn)->listArchivedMembers();
$where = [];
$params = [];
$types = '';
if (!$isSuperAdmin) {
    $churchId = (int) ($_SESSION['church_id'] ?? 0);
    if ($churchId <= 0 && isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $churchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
        $stmt->close();
    }
    $where[] = 'audit.church_id = ?';
    $types = 'i';
    $params[] = $churchId;
}
$auditSql = "SELECT audit.*, user_account.name AS actor_name
               FROM member_lifecycle_audit audit
               LEFT JOIN users user_account ON user_account.id = audit.performed_by_user_id";
if ($where) $auditSql .= ' WHERE ' . implode(' AND ', $where);
$auditSql .= ' ORDER BY audit.created_at DESC, audit.id DESC LIMIT 100';
$stmt = $conn->prepare($auditSql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$auditRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div><h4 class="m-0 font-weight-bold text-danger"><i class="fas fa-archive"></i> Archived Members</h4>
    <small class="text-muted">Recoverable member records; financial and attendance history is preserved.</small></div>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Member List</a>
</div>
<?php if (!empty($_GET['info'])): ?><div class="alert alert-success"><?= htmlspecialchars($_GET['info']) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div><?php endif; ?>

<div class="card shadow mb-4">
<div class="card-header bg-danger text-white"><h6 class="m-0 font-weight-bold">Archive</h6></div>
<div class="card-body"><div class="table-responsive">
<table class="table table-bordered table-hover" id="archivedMemberTable">
<thead class="thead-dark"><tr><th>CRN</th><th>Member</th><th>Church</th><th>Bible Class</th><th>Archived</th><th>Reason</th><th>Action</th></tr></thead>
<tbody><?php foreach ($members as $member): ?>
<tr>
    <td><?= htmlspecialchars($member['crn']) ?></td>
    <td><?= htmlspecialchars(trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name'])) ?></td>
    <td><?= htmlspecialchars($member['church_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($member['class_name'] ?? '') ?></td>
    <td><?= htmlspecialchars($member['archived_at'] ?? '') ?></td>
    <td><?= nl2br(htmlspecialchars($member['archive_reason'] ?? '')) ?></td>
    <td><?php if ($canRestore): ?>
        <button type="button" class="btn btn-success btn-sm" data-toggle="modal" data-target="#restoreModal"
                data-member-id="<?= (int) $member['id'] ?>"
                data-member-name="<?= htmlspecialchars(trim($member['first_name'] . ' ' . $member['last_name']), ENT_QUOTES) ?>">
            <i class="fas fa-undo"></i> Restore
        </button>
    <?php else: ?><span class="text-muted">Read only</span><?php endif; ?></td>
</tr>
<?php endforeach; ?></tbody>
</table>
</div></div></div>

<?php if ($canAudit): ?>
<div class="card shadow mb-4"><div class="card-header"><h6 class="m-0 font-weight-bold text-primary">Recent Lifecycle Audit</h6></div>
<div class="card-body"><div class="table-responsive"><table class="table table-sm table-bordered">
<thead class="thead-light"><tr><th>Date</th><th>Member</th><th>CRN</th><th>Action</th><th>Reason</th><th>Actor</th></tr></thead>
<tbody><?php foreach ($auditRows as $audit): ?>
<tr><td><?= htmlspecialchars($audit['created_at']) ?></td><td><?= htmlspecialchars($audit['member_name']) ?></td>
<td><?= htmlspecialchars($audit['member_crn'] ?? '') ?></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $audit['action']))) ?></td>
<td><?= nl2br(htmlspecialchars($audit['reason'])) ?></td><td><?= htmlspecialchars($audit['actor_name'] ?? 'Legacy/system') ?></td></tr>
<?php endforeach; ?></tbody></table></div></div></div>
<?php endif; ?>

<?php if ($canRestore): ?>
<div class="modal fade" id="restoreModal" tabindex="-1" role="dialog" aria-hidden="true"><div class="modal-dialog" role="document"><div class="modal-content">
<form method="post" action="restore_deleted_member.php">
<div class="modal-header"><h5 class="modal-title">Restore Archived Member</h5><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button></div>
<div class="modal-body"><?= csrf_input() ?><input type="hidden" name="id" id="restore-member-id">
<p>Restore <strong id="restore-member-name"></strong> as pending?</p>
<div class="form-group"><label for="restore-reason">Reason <span class="text-danger">*</span></label><textarea class="form-control" id="restore-reason" name="reason" maxlength="500" required></textarea></div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Restore</button></div>
</form></div></div></div>
<script>
$('#restoreModal').on('show.bs.modal', function (event) {
    var button = $(event.relatedTarget);
    $('#restore-member-id').val(button.data('member-id'));
    $('#restore-member-name').text(button.data('member-name'));
});
</script>
<?php endif; ?>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
