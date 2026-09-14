<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/RoleOfServingAccessService.php';
require_once __DIR__ . '/../services/UserOnboardingService.php';

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
$canSendOnboarding = !$editing
    && ($isSuperAdmin || has_permission('send_user_onboarding_sms'));
$sendOnboarding = $canSendOnboarding
    && ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['send_onboarding_sms']));

$accessService = new RoleOfServingAccessService($conn);
$account = $editing ? $accessService->getUserAccount((int) $userId) : null;
if ($editing && !$account) {
    http_response_code(404);
    exit('The linked member user account was not found.');
}

$memberId = $editing ? (int) $account['member_id'] : (int) ($_POST['member_id'] ?? 0);
$selectedMember = $memberId > 0 ? $accessService->getMemberAccessProfile($memberId) : null;
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
        $onboardingStatus = 'not_requested';
        if ($outcome['created'] && $sendOnboarding) {
            try {
                $delivery = (new UserOnboardingService($conn))->sendAccountCreated(
                    (int) $outcome['user_id'],
                    $password,
                    isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
                );
                $onboardingStatus = (string) ($delivery['status'] ?? 'failed');
            } catch (Throwable $notificationException) {
                error_log('The user account was created, but onboarding notification processing failed.');
                $onboardingStatus = 'failed';
            }
        }
        header(
            'Location: user_list.php?saved=' . ($outcome['created'] ? 'created' : 'updated')
            . '&onboarding=' . rawurlencode($onboardingStatus)
        );
        exit;
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $manualRoleIds = $isSuperAdmin
            ? array_values(array_unique(array_map('intval', (array) ($_POST['manual_role_ids'] ?? []))))
            : $manualRoleIds;
        $selectedMember = $memberId > 0 ? $accessService->getMemberAccessProfile($memberId) : null;
        $mappedRoles = $memberId > 0 ? $accessService->getMappedAccessRolesForMember($memberId) : [];
    }
}

$page_title = $editing ? 'Edit User Access' : 'Add User Access';
ob_start();
?>
<style>
.user-access-page{background:#f5f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.user-access-hero{background:linear-gradient(135deg,#174f5e,#238096);color:#fff;border-radius:15px;padding:1.25rem 1.5rem;box-shadow:0 8px 22px rgba(23,79,94,.22)}.user-access-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.derived-role{display:inline-block;background:#e8f4f7;color:#174f5e;border-radius:12px;padding:.35rem .65rem;margin:.2rem}.policy-note{border-left:4px solid #238096;background:#eef8fa;padding:.75rem .9rem}.crn-lookup-feedback{min-height:1.35rem}
</style>
<div class="user-access-page"><div class="container-fluid">
  <div class="user-access-hero mb-3 d-flex justify-content-between align-items-center"><div><h2 class="mb-1"><i class="fas fa-user-shield mr-2"></i><?= $editing ? 'Edit' : 'Add' ?> User Access</h2><div>Back-office access for an existing registered church member</div></div><a class="btn btn-light btn-sm" href="user_list.php">User List</a></div>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="policy-note mb-3"><strong>Account policy:</strong> this form never creates or duplicates a member. The account remains linked to the selected member, uses an official <strong>@myfreeman.org</strong> email, and receives the union of mapped Roles of Serving plus any Super-Admin manual roles.</div>

  <form method="post" autocomplete="off"><?= csrf_input() ?>
    <div class="row">
      <div class="col-lg-7 mb-3"><div class="card user-access-card"><div class="card-header bg-white font-weight-bold">Member and Login</div><div class="card-body">
        <div class="form-group"><label for="member_crn">Registered Member CRN</label>
          <?php if ($editing): ?>
            <input type="text" class="form-control" id="member_crn" value="<?= htmlspecialchars((string) ($selectedMember['crn'] ?? $account['crn'] ?? '')) ?>" readonly>
            <input type="hidden" name="member_id" id="member_id" value="<?= $memberId ?>">
            <small class="form-text text-muted">The member identity cannot be changed after account creation.</small>
          <?php else: ?>
            <div class="input-group">
              <input type="text" class="form-control text-uppercase" id="member_crn" maxlength="50" value="<?= htmlspecialchars((string) ($selectedMember['crn'] ?? '')) ?>" placeholder="Enter member CRN" autocomplete="off" aria-describedby="memberCrnHelp">
              <div class="input-group-append"><button class="btn btn-primary" type="button" id="findMemberBtn"><span id="crnSpinner" class="spinner-border spinner-border-sm mr-1 d-none" role="status" aria-hidden="true"></span><i class="fas fa-search mr-1" id="crnSearchIcon"></i>Search CRN</button></div>
            </div>
            <input type="hidden" name="member_id" id="member_id" value="<?= $memberId > 0 && $selectedMember ? $memberId : '' ?>">
            <div id="memberCrnHelp" class="form-text text-muted">Search an exact CRN. Only an active member without an existing back-office account can be selected.</div>
            <div id="crnFeedback" class="crn-lookup-feedback small mt-1 <?= $selectedMember ? 'text-success' : 'text-muted' ?>" aria-live="polite"><?= $selectedMember ? 'Member confirmed. Review the identity details before saving.' : '' ?></div>
          <?php endif; ?>
        </div>
        <div class="form-row"><div class="form-group col-md-7"><label for="email">Official Church Email</label><input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($officialEmail) ?>" placeholder="name@myfreeman.org" required></div><div class="form-group col-md-5"><label for="status">Account Status</label><select class="form-control" id="status" name="status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div></div>
        <div class="form-group"><label for="password"><?= $editing ? 'New Password (optional)' : 'Temporary Password' ?></label><input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" <?= $editing ? '' : 'required' ?>><small class="form-text text-muted"><?= $editing ? 'If changed, the user must replace this temporary password at their next login.' : 'Minimum eight characters. The user must replace it at first login.' ?></small></div>
        <?php if ($canSendOnboarding): ?><div class="custom-control custom-checkbox mb-2"><input type="checkbox" class="custom-control-input" id="send_onboarding_sms" name="send_onboarding_sms" value="1" <?= $sendOnboarding ? 'checked' : '' ?>><label class="custom-control-label" for="send_onboarding_sms">Send onboarding SMS after creating the account</label><small class="form-text text-muted">Includes the user’s assigned role, official email, temporary password, and login link. The audit stores only delivery status and the destination’s last four digits.</small></div><?php endif; ?>
      </div></div></div>

      <div class="col-lg-5 mb-3"><div class="card user-access-card mb-3"><div class="card-header bg-white font-weight-bold">Member Identity Preview</div><div class="card-body"><div><strong>Name:</strong> <span id="previewName"><?= htmlspecialchars($selectedMember['full_name'] ?? ($account['name'] ?? '-')) ?></span></div><div><strong>Contact:</strong> <span id="previewPhone"><?= htmlspecialchars($selectedMember['phone'] ?? ($account['member_phone'] ?? '-')) ?></span></div><div><strong>Church:</strong> <span id="previewChurch"><?= htmlspecialchars($selectedMember['church_name'] ?? ($account['church_name'] ?? '-')) ?></span></div><div class="mt-2"><strong>Roles of Serving:</strong><div id="previewServing"><?= htmlspecialchars($selectedMember['serving_roles'] ?? 'None assigned') ?></div></div></div></div>
        <div class="card user-access-card"><div class="card-header bg-white font-weight-bold">Derived System Access</div><div class="card-body"><div class="small text-muted mb-2">Generated from active mappings; edit mappings from the Roles of Serving module.</div><div id="derivedRoles"><?php if (!$mappedRoles): ?><span class="text-muted">No mapped access roles for this member.</span><?php endif; ?><?php foreach ($mappedRoles as $role): ?><span class="derived-role"><?= htmlspecialchars($role['name']) ?><small> via <?= htmlspecialchars($role['serving_roles']) ?></small></span><?php endforeach; ?></div></div></div>
      </div>
    </div>

    <?php if ($isSuperAdmin): ?><div class="card user-access-card mb-3"><div class="card-header bg-white font-weight-bold">Additional Manual Access (Super Admin Only)</div><div class="card-body"><div class="form-group mb-0"><select class="form-control" name="manual_role_ids[]" multiple size="7"><?php foreach ($allAccessRoles as $role): ?><option value="<?= (int) $role['id'] ?>" <?= in_array((int) $role['id'], $manualRoleIds, true) ? 'selected' : '' ?>><?= htmlspecialchars($role['name']) ?></option><?php endforeach; ?></select><small class="form-text text-muted">Use only for exceptional or system-level access. Role-of-Serving mappings remain the normal source.</small></div></div></div><?php endif; ?>
    <div class="d-flex justify-content-between"><a class="btn btn-secondary" href="user_list.php">Cancel</a><button class="btn btn-success px-4"><i class="fas fa-save mr-1"></i>Save User Access</button></div>
  </form>
</div></div>
<script>
(function(){
  var crnInput=document.getElementById('member_crn');
  var searchButton=document.getElementById('findMemberBtn');
  var memberIdInput=document.getElementById('member_id');
  if(!crnInput || !searchButton || !memberIdInput) return;

  var feedback=document.getElementById('crnFeedback');
  var spinner=document.getElementById('crnSpinner');
  var searchIcon=document.getElementById('crnSearchIcon');
  var confirmedCrn=memberIdInput.value ? crnInput.value.trim().toUpperCase() : '';

  function setFeedback(message,isError){
    feedback.textContent=message;
    feedback.className='crn-lookup-feedback small mt-1 '+(isError?'text-danger':'text-success');
  }
  function clearMember(){
    memberIdInput.value='';
    confirmedCrn='';
    document.getElementById('previewName').textContent='-';
    document.getElementById('previewPhone').textContent='-';
    document.getElementById('previewChurch').textContent='-';
    document.getElementById('previewServing').textContent='None assigned';
    var roles=document.getElementById('derivedRoles');
    roles.textContent='';
    var empty=document.createElement('span');
    empty.className='text-muted';
    empty.textContent='No mapped access roles for this member.';
    roles.appendChild(empty);
  }
  function renderRoles(mappedRoles){
    var container=document.getElementById('derivedRoles');
    container.textContent='';
    if(!mappedRoles || !mappedRoles.length){
      var empty=document.createElement('span');
      empty.className='text-muted';
      empty.textContent='No mapped access roles for this member.';
      container.appendChild(empty);
      return;
    }
    mappedRoles.forEach(function(role){
      var badge=document.createElement('span');
      badge.className='derived-role';
      badge.appendChild(document.createTextNode(role.name+' '));
      var source=document.createElement('small');
      source.textContent='via '+role.serving_roles;
      badge.appendChild(source);
      container.appendChild(badge);
    });
  }
  function searchMember(){
    var crn=crnInput.value.trim();
    clearMember();
    if(!crn){ setFeedback('Enter a CRN.',true); crnInput.focus(); return; }
    searchButton.disabled=true;
    spinner.classList.remove('d-none');
    searchIcon.classList.add('d-none');
    feedback.className='crn-lookup-feedback small mt-1 text-muted';
    feedback.textContent='Searching...';
    fetch('ajax_user_access_member_by_crn.php?crn='+encodeURIComponent(crn),{
      credentials:'same-origin',headers:{'Accept':'application/json'}
    }).then(function(response){
      return response.json().catch(function(){ throw new Error('The server returned an invalid response.'); });
    }).then(function(payload){
      if(!payload.success) throw new Error(payload.message||'Member not found.');
      var member=payload.member;
      memberIdInput.value=String(member.id);
      confirmedCrn=String(member.crn||crn).trim().toUpperCase();
      crnInput.value=member.crn||crn;
      document.getElementById('previewName').textContent=member.full_name||'-';
      document.getElementById('previewPhone').textContent=member.phone||'-';
      document.getElementById('previewChurch').textContent=member.church_name||'-';
      document.getElementById('previewServing').textContent=member.serving_roles||'None assigned';
      renderRoles(member.mapped_roles||[]);
      setFeedback('Member confirmed. Review the identity details before saving.',false);
    }).catch(function(error){
      setFeedback(error.message||'The member lookup failed.',true);
    }).finally(function(){
      searchButton.disabled=false;
      spinner.classList.add('d-none');
      searchIcon.classList.remove('d-none');
    });
  }
  crnInput.addEventListener('input',function(){
    if(confirmedCrn && this.value.trim().toUpperCase()!==confirmedCrn){
      clearMember();
      feedback.textContent='Search this CRN to confirm the member.';
      feedback.className='crn-lookup-feedback small mt-1 text-muted';
    }
  });
  crnInput.addEventListener('keydown',function(event){
    if(event.key==='Enter'){event.preventDefault();searchMember();}
  });
  searchButton.addEventListener('click',searchMember);
})();
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
