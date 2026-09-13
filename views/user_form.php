<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/RoleOfServingAccessService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$editing = isset($_GET['id']) && ctype_digit((string) $_GET['id']);
$userId = $editing ? (int) $_GET['id'] : null;
$sessionRoleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $sessionRoleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $sessionRoleIds, true);
$requiredPermission = $editing ? 'edit_user' : 'create_user';
if (!$isSuperAdmin && !has_permission($requiredPermission)) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$accessService = new RoleOfServingAccessService($conn);
$account = $editing ? $accessService->getUserAccount((int) $userId) : null;
if ($editing && !$account) {
    http_response_code(404);
    exit('The linked member user account was not found.');
}

$memberId = $editing ? (int) $account['member_id'] : (int) ($_POST['member_id'] ?? 0);
$members = $accessService->getEligibleMembers($editing ? $memberId : null);
$manualRoleIds = $editing ? $accessService->getManualAccessRoleIds((int) $userId) : [];
$mappedRoles = $memberId > 0 ? $accessService->getMappedAccessRolesForMember($memberId) : [];
$allAccessRoles = $conn->query('SELECT id, name FROM roles WHERE is_active = 1 ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$error = '';
$officialEmail = (string) ($account['email'] ?? ($_POST['email'] ?? ''));
$status = (string) ($account['status'] ?? ($_POST['status'] ?? 'active'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your form expired. Refresh and try again.');
        }
        $postedMemberId = (int) ($_POST['member_id'] ?? 0);
        if ($editing && $postedMemberId !== $memberId) {
            throw new RuntimeException('A user account cannot be moved to another member.');
        }
        $memberId = $postedMemberId;
        $officialEmail = trim((string) ($_POST['email'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'inactive');
        $password = (string) ($_POST['password'] ?? '');
        $selectedManualRoles = $isSuperAdmin
            ? (array) ($_POST['manual_role_ids'] ?? [])
            : ($editing ? $manualRoleIds : []);

        $outcome = $accessService->saveUserAccount(
            $userId, $memberId, $officialEmail, $password, $status,
            $selectedManualRoles,
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
        );
        header('Location: user_list.php?saved=' . ($outcome['created'] ? 'created' : 'updated'));
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $manualRoleIds = $isSuperAdmin
            ? array_values(array_unique(array_map('intval', (array) ($_POST['manual_role_ids'] ?? []))))
            : $manualRoleIds;
        $mappedRoles = $memberId > 0 ? $accessService->getMappedAccessRolesForMember($memberId) : [];
    }
}

$selectedMember = null;
foreach ($members as $member) {
    if ((int) $member['id'] === $memberId) $selectedMember = $member;
}

$page_title = $editing ? 'Edit User Access' : 'Add User Access';
ob_start();
?>
<style>
.user-access-page{background:#f5f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.user-access-hero{background:linear-gradient(135deg,#174f5e,#238096);color:#fff;border-radius:15px;padding:1.25rem 1.5rem;box-shadow:0 8px 22px rgba(23,79,94,.22)}.user-access-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.derived-role{display:inline-block;background:#e8f4f7;color:#174f5e;border-radius:12px;padding:.35rem .65rem;margin:.2rem}.policy-note{border-left:4px solid #238096;background:#eef8fa;padding:.75rem .9rem}
</style>
<div class="user-access-page"><div class="container-fluid">
  <div class="user-access-hero mb-3 d-flex justify-content-between align-items-center"><div><h2 class="mb-1"><i class="fas fa-user-shield mr-2"></i><?= $editing ? 'Edit' : 'Add' ?> User Access</h2><div>Back-office access for an existing registered church member</div></div><a class="btn btn-light btn-sm" href="user_list.php">User List</a></div>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="policy-note mb-3"><strong>Account policy:</strong> this form never creates or duplicates a member. The account remains linked to the selected member, uses an official <strong>@myfreeman.org</strong> email, and receives the union of mapped Roles of Serving plus any Super-Admin manual roles.</div>

  <form method="post" autocomplete="off"><?= csrf_input() ?>
    <div class="row">
      <div class="col-lg-7 mb-3"><div class="card user-access-card"><div class="card-header bg-white font-weight-bold">Member and Login</div><div class="card-body">
        <div class="form-group"><label for="member_id">Registered Member</label>
          <select class="form-control" name="member_id" id="member_id" required <?= $editing ? 'disabled' : '' ?>>
            <option value="">Select an active member</option>
            <?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= (int) $member['id'] === $memberId ? 'selected' : '' ?> data-name="<?= htmlspecialchars($member['full_name'], ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars((string) $member['phone'], ENT_QUOTES) ?>" data-church="<?= htmlspecialchars($member['church_name'], ENT_QUOTES) ?>" data-serving="<?= htmlspecialchars((string) $member['serving_roles'], ENT_QUOTES) ?>"><?= htmlspecialchars(($member['crn'] ?: 'No CRN') . ' - ' . $member['full_name'] . ' (' . $member['church_name'] . ')') ?></option><?php endforeach; ?>
          </select>
          <?php if ($editing): ?><input type="hidden" name="member_id" value="<?= $memberId ?>"><?php endif; ?>
          <small class="form-text text-muted"><?= $editing ? 'The member identity cannot be changed after account creation.' : 'Only active members without an existing back-office account are shown.' ?></small>
        </div>
        <div class="form-row"><div class="form-group col-md-7"><label for="email">Official Church Email</label><input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($officialEmail) ?>" placeholder="name@myfreeman.org" required></div><div class="form-group col-md-5"><label for="status">Account Status</label><select class="form-control" id="status" name="status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div></div>
        <div class="form-group"><label for="password"><?= $editing ? 'New Password (optional)' : 'Temporary Password' ?></label><input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" <?= $editing ? '' : 'required' ?>><small class="form-text text-muted"><?= $editing ? 'Leave blank to retain the current password.' : 'Minimum eight characters. Communicate it securely and require the user to change it.' ?></small></div>
      </div></div></div>

      <div class="col-lg-5 mb-3"><div class="card user-access-card mb-3"><div class="card-header bg-white font-weight-bold">Member Identity Preview</div><div class="card-body"><div><strong>Name:</strong> <span id="previewName"><?= htmlspecialchars($selectedMember['full_name'] ?? ($account['name'] ?? '-')) ?></span></div><div><strong>Contact:</strong> <span id="previewPhone"><?= htmlspecialchars($selectedMember['phone'] ?? ($account['member_phone'] ?? '-')) ?></span></div><div><strong>Church:</strong> <span id="previewChurch"><?= htmlspecialchars($selectedMember['church_name'] ?? ($account['church_name'] ?? '-')) ?></span></div><div class="mt-2"><strong>Roles of Serving:</strong><div id="previewServing"><?= htmlspecialchars($selectedMember['serving_roles'] ?? 'None assigned') ?></div></div></div></div>
        <div class="card user-access-card"><div class="card-header bg-white font-weight-bold">Derived System Access</div><div class="card-body"><div class="small text-muted mb-2">Generated from active mappings; edit mappings from the Roles of Serving module.</div><?php if (!$mappedRoles): ?><span class="text-muted">No mapped access roles for this member.</span><?php endif; ?><?php foreach ($mappedRoles as $role): ?><span class="derived-role"><?= htmlspecialchars($role['name']) ?><small> via <?= htmlspecialchars($role['serving_roles']) ?></small></span><?php endforeach; ?></div></div>
      </div>
    </div>

    <?php if ($isSuperAdmin): ?><div class="card user-access-card mb-3"><div class="card-header bg-white font-weight-bold">Additional Manual Access (Super Admin Only)</div><div class="card-body"><div class="form-group mb-0"><select class="form-control" name="manual_role_ids[]" multiple size="7"><?php foreach ($allAccessRoles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $manualRoleIds, true) ? 'selected' : '' ?>><?= htmlspecialchars($role['name']) ?></option><?php endforeach; ?></select><small class="form-text text-muted">Use only for exceptional or system-level access. Role-of-Serving mappings remain the normal source.</small></div></div></div><?php endif; ?>
    <div class="d-flex justify-content-between"><a class="btn btn-secondary" href="user_list.php">Cancel</a><button class="btn btn-success px-4"><i class="fas fa-save mr-1"></i>Save User Access</button></div>
  </form>
</div></div>
<script>
(function(){
  var select=document.getElementById('member_id');
  if(!select || select.disabled) return;
  select.addEventListener('change',function(){
    var option=this.options[this.selectedIndex];
    document.getElementById('previewName').textContent=option.dataset.name||'-';
    document.getElementById('previewPhone').textContent=option.dataset.phone||'-';
    document.getElementById('previewChurch').textContent=option.dataset.church||'-';
    document.getElementById('previewServing').textContent=option.dataset.serving||'None assigned';
  });
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
