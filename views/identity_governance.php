<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/IdentityOrganizationGovernanceService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$isSuperAdmin = (int) ($_SESSION['role_id'] ?? 0) === 1 || !empty($_SESSION['is_super_admin']);
if (!$isSuperAdmin && !has_permission('manage_identity_governance')) {
    http_response_code(403);
    die('You do not have permission to manage identity governance.');
}

$service = IdentityOrganizationGovernanceService::fromSession($conn);
$organizationId = max(0, (int) ($_GET['organization_id'] ?? $_POST['organization_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) throw new RuntimeException('Invalid session token.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'repair_crn') {
            $newCrn = $service->assignNextCrn((int) ($_POST['review_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            $_SESSION['identity_governance_success'] = 'CRN repaired successfully: ' . $newCrn;
        } elseif ($action === 'update_official_email') {
            $service->updateOfficialEmail((int) ($_POST['user_id'] ?? 0), (string) ($_POST['email'] ?? ''));
            $_SESSION['identity_governance_success'] = 'Official email updated and the related policy issue was resolved.';
        } elseif ($action === 'deactivate_user') {
            $service->deactivateIneligibleUser((int) ($_POST['user_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            $_SESSION['identity_governance_success'] = 'The ineligible back-office account was deactivated.';
        } elseif ($action === 'set_email_policy') {
            $service->setEmailEnforcement((int) ($_POST['enabled'] ?? 0) === 1);
            $_SESSION['identity_governance_success'] = 'Official-email login enforcement was updated.';
        } elseif ($action === 'certify_brigade_officer') {
            $service->certifyBrigadeOfficer(
                $organizationId,
                (int) ($_POST['member_id'] ?? 0),
                (string) ($_POST['officer_branch'] ?? ''),
                (string) ($_POST['rank_or_level'] ?? ''),
                (string) ($_POST['confirmation_notes'] ?? '')
            );
            $_SESSION['identity_governance_success'] = 'Brigade officer eligibility certified.';
        } elseif ($action === 'revoke_brigade_officer') {
            $service->revokeBrigadeOfficer(
                $organizationId,
                (int) ($_POST['member_id'] ?? 0),
                (string) ($_POST['reason'] ?? '')
            );
            $_SESSION['identity_governance_success'] = 'Brigade officer eligibility revoked.';
        } else {
            throw new RuntimeException('Invalid governance action.');
        }
    } catch (Throwable $e) {
        $_SESSION['identity_governance_error'] = $e->getMessage();
    }
    $query = $organizationId > 0 ? '?organization_id=' . $organizationId : '';
    header('Location: ' . BASE_URL . '/views/identity_governance.php' . $query);
    exit;
}

$notice = (string) ($_SESSION['identity_governance_success'] ?? '');
$error = (string) ($_SESSION['identity_governance_error'] ?? '');
unset($_SESSION['identity_governance_success'], $_SESSION['identity_governance_error']);
$crnIssues = $service->listCrnIssues();
$emailIssues = $service->listEmailIssues();
$emailPolicy = $service->getEmailPolicy();
$brigadeOrganizations = $service->listBrigadeOrganizations();
if ($organizationId === 0 && $brigadeOrganizations) $organizationId = (int) $brigadeOrganizations[0]['id'];
$brigadeMembers = $organizationId > 0 ? $service->listBrigadeMembers($organizationId) : [];

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
  <div><h4 class="m-0 font-weight-bold text-primary"><i class="fas fa-user-shield"></i> Identity Governance</h4>
  <small class="text-muted">Audited CRN repair, account-policy closure, and Brigade officer certification.</small></div>
  <a class="btn btn-outline-primary btn-sm" href="registration_duplicate_reviews.php?status=confirmed_duplicate"><i class="fas fa-clone"></i> Resolve Confirmed Duplicates</a>
</div>
<?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card shadow mb-4"><div class="card-header"><strong>CRN Integrity Queue</strong></div><div class="card-body">
<p class="text-muted">The allocator locks the church/class sequence, checks both CRNs and SRNs, and records the old and new values.</p>
<?php if (!$crnIssues): ?><div class="alert alert-success mb-0">No unresolved CRN issues in your scope.</div><?php else: ?>
<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Member</th><th>Church / Class</th><th>Issue</th><th>Current CRN</th><th>Governed repair</th></tr></thead><tbody>
<?php foreach ($crnIssues as $issue): ?>
<tr><td><?= htmlspecialchars(trim($issue['first_name'].' '.$issue['middle_name'].' '.$issue['last_name'])) ?></td>
<td><?= htmlspecialchars(($issue['church_name'] ?: '-') . ' / ' . ($issue['class_name'] ?: '-')) ?></td>
<td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $issue['issue_type']))) ?><br><small><?= htmlspecialchars($issue['details']) ?></small></td>
<td><?= htmlspecialchars($issue['crn'] ?: 'Missing') ?></td><td>
<form method="post" class="form-inline"><?= csrf_input() ?><input type="hidden" name="action" value="repair_crn"><input type="hidden" name="review_id" value="<?= (int) $issue['id'] ?>">
<input class="form-control form-control-sm mr-2" name="reason" maxlength="500" placeholder="Reason/evidence" required>
<button class="btn btn-sm btn-warning" onclick="return confirm('Allocate the next governed CRN and retain the old value in audit history?');">Allocate Next CRN</button></form>
</td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div>

<div class="card shadow mb-4"><div class="card-header d-flex justify-content-between"><strong>Official Email and Active-Member Policy</strong>
<span class="badge badge-<?= (int) $emailPolicy['enforcement_enabled'] === 1 ? 'success' : 'warning' ?>"><?= (int) $emailPolicy['enforcement_enabled'] === 1 ? 'Enforced' : 'Staged' ?></span></div><div class="card-body">
<p class="text-muted">Enforcement can only be enabled after every active account with a role has an approved @myfreeman.org email and an active member profile. This prevents deployment lockout.</p>
<?php if ($isSuperAdmin): ?><form method="post" class="mb-3"><?= csrf_input() ?><input type="hidden" name="action" value="set_email_policy"><input type="hidden" name="enabled" value="<?= (int) $emailPolicy['enforcement_enabled'] === 1 ? 0 : 1 ?>">
<button class="btn btn-sm btn-<?= (int) $emailPolicy['enforcement_enabled'] === 1 ? 'outline-danger' : 'success' ?>"><?= (int) $emailPolicy['enforcement_enabled'] === 1 ? 'Disable Enforcement' : 'Enable After Queue Is Clear' ?></button></form><?php endif; ?>
<?php if (!$emailIssues): ?><div class="alert alert-success mb-0">No unresolved account-policy issues in your scope.</div><?php else: ?>
<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>User</th><th>Issue</th><th>Current email</th><th>Resolution</th></tr></thead><tbody>
<?php foreach ($emailIssues as $issue): ?><tr><td><?= htmlspecialchars($issue['name']) ?><br><small><?= htmlspecialchars($issue['crn'] ?: 'No CRN') ?></small></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $issue['issue_type']))) ?><br><small><?= htmlspecialchars($issue['details']) ?></small></td><td><?= htmlspecialchars($issue['email'] ?: 'Missing') ?></td><td>
<?php if ($issue['issue_type'] === 'inactive_member'): ?>
<form method="post" class="form-inline"><?= csrf_input() ?><input type="hidden" name="action" value="deactivate_user"><input type="hidden" name="user_id" value="<?= (int) $issue['user_id'] ?>"><input class="form-control form-control-sm mr-2" name="reason" maxlength="500" placeholder="Deactivation reason" required><button class="btn btn-sm btn-danger">Deactivate Account</button></form>
<?php else: ?>
<form method="post" class="form-inline"><?= csrf_input() ?><input type="hidden" name="action" value="update_official_email"><input type="hidden" name="user_id" value="<?= (int) $issue['user_id'] ?>"><input type="email" class="form-control form-control-sm mr-2" name="email" placeholder="name@myfreeman.org" required><button class="btn btn-sm btn-primary">Update Email</button></form>
<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div>

<div class="card shadow mb-4"><div class="card-header"><strong>Brigade Officer Eligibility</strong></div><div class="card-body">
<div class="alert alert-info">Document rule enforced: a certified female officer may lead a Boys section; a male officer cannot lead a Girls section. A section leader need not belong to that section, but must have active officer evidence.</div>
<?php if (!$brigadeOrganizations): ?><div class="alert alert-secondary mb-0">No Brigade organization is configured in your scope.</div><?php else: ?>
<form method="get" class="form-inline mb-3"><label class="mr-2">Brigade</label><select class="form-control mr-2" name="organization_id" onchange="this.form.submit()">
<?php foreach ($brigadeOrganizations as $organization): ?><option value="<?= (int) $organization['id'] ?>" <?= $organizationId === (int) $organization['id'] ? 'selected' : '' ?>><?= htmlspecialchars($organization['church_name'].' - '.$organization['name']) ?></option><?php endforeach; ?></select></form>
<div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Member</th><th>Gender</th><th>Current eligibility</th><th>Certify / revoke</th></tr></thead><tbody>
<?php foreach ($brigadeMembers as $member): ?><tr><td><?= htmlspecialchars(($member['crn'] ? $member['crn'].' - ' : '').trim($member['first_name'].' '.$member['middle_name'].' '.$member['last_name'])) ?></td><td><?= htmlspecialchars($member['gender'] ?: '-') ?></td><td><?= $member['status'] === 'active' ? htmlspecialchars(ucfirst($member['officer_branch']).' / '.$member['rank_or_level']) : 'Not certified' ?></td><td>
<?php if ($member['status'] === 'active'): ?><form method="post" class="form-inline"><?= csrf_input() ?><input type="hidden" name="action" value="revoke_brigade_officer"><input type="hidden" name="organization_id" value="<?= $organizationId ?>"><input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>"><input class="form-control form-control-sm mr-2" name="reason" placeholder="Revocation reason" required><button class="btn btn-sm btn-outline-danger">Revoke</button></form>
<?php else: ?><form method="post" class="form-row"><?= csrf_input() ?><input type="hidden" name="action" value="certify_brigade_officer"><input type="hidden" name="organization_id" value="<?= $organizationId ?>"><input type="hidden" name="member_id" value="<?= (int) $member['id'] ?>"><div class="col-md-2"><select class="form-control form-control-sm" name="officer_branch" required><option value="">Branch</option><option value="boys">Boys</option><option value="girls">Girls</option><option value="both">Both</option></select></div><div class="col-md-3"><input class="form-control form-control-sm" name="rank_or_level" maxlength="100" placeholder="Officer rank/level" required></div><div class="col-md-5"><input class="form-control form-control-sm" name="confirmation_notes" maxlength="500" placeholder="Approval/evidence reference" required></div><div class="col-md-2"><button class="btn btn-sm btn-primary btn-block">Certify</button></div></form><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
