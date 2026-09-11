<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/leader_helpers.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/OrganizationGroupService.php';

function build_organization_membership_approvals_url($org_id = 0) {
    $url = $_SERVER['PHP_SELF'];
    if ($org_id > 0) {
        $url .= '?org_id=' . (int) $org_id;
    }

    return $url;
}

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionMemberId = (int) ($_SESSION['member_id'] ?? 0);
$sessionRoleId = (int) ($_SESSION['role_id'] ?? 0);
$isSuperAdmin = $sessionRoleId === 1;
$isOrganizationLeaderRole = $sessionRoleId === 6;
$leaderOrganizations = is_organization_leader($conn, $sessionUserId ?: null, $sessionMemberId ?: null);
$isOrgLeader = !empty($leaderOrganizations);

$canViewApprovals = $isSuperAdmin || $isOrgLeader
    || (!$isOrganizationLeaderRole && has_permission('view_organization_membership_approvals'));
$canApproveMemberships = $isSuperAdmin || $isOrgLeader
    || (!$isOrganizationLeaderRole && has_permission('approve_organization_memberships'));
$canRejectMemberships = $isSuperAdmin || $isOrgLeader
    || (!$isOrganizationLeaderRole && has_permission('reject_organization_memberships'));
$organizationGroupService = new OrganizationGroupService($conn);

if (!$canViewApprovals) {
    http_response_code(403);
    if (file_exists(__DIR__ . '/errors/403.php')) {
        include __DIR__ . '/errors/403.php';
    } elseif (file_exists(dirname(__DIR__) . '/views/errors/403.php')) {
        include dirname(__DIR__) . '/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to view organization membership approvals.</p></div>';
    }
    exit;
}

$userOrganizations = [];
if ($isSuperAdmin || (!$isOrganizationLeaderRole && !$isOrgLeader && $canViewApprovals)) {
    $orgResult = $conn->query('SELECT id, name, church_id FROM organizations ORDER BY name ASC');
    if ($orgResult) {
        while ($org = $orgResult->fetch_assoc()) {
            $userOrganizations[] = $org;
        }
        $orgResult->free();
    }
} elseif ($isOrgLeader) {
    foreach ($leaderOrganizations as $org) {
        $userOrganizations[] = [
            'id' => (int) $org['organization_id'],
            'name' => $org['org_name'],
            'church_id' => (int) ($org['church_id'] ?? 0),
        ];
    }
}

$managedOrgIds = array_map('intval', array_column($userOrganizations, 'id'));
$selectedOrgId = isset($_REQUEST['org_id']) ? (int) $_REQUEST['org_id'] : 0;
if ($selectedOrgId <= 0 || (!empty($managedOrgIds) && !in_array($selectedOrgId, $managedOrgIds, true))) {
    $selectedOrgId = !empty($managedOrgIds) ? $managedOrgIds[0] : 0;
}

$selectedOrganization = null;
foreach ($userOrganizations as $org) {
    if ((int) $org['id'] === $selectedOrgId) {
        $selectedOrganization = $org;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $approvalId = (int) ($_POST['approval_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $organizationId = (int) ($_POST['organization_id'] ?? $selectedOrgId);
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    } elseif ($organizationId <= 0 || (!empty($managedOrgIds) && !in_array($organizationId, $managedOrgIds, true))) {
        $error = 'You cannot manage approvals for the selected organization.';
    } elseif (!in_array($action, ['approve', 'reject'], true) || $approvalId <= 0) {
        $error = 'Invalid approval request.';
    } elseif ($action === 'approve' && !$canApproveMemberships) {
        $error = 'You do not have permission to approve organization memberships.';
    } elseif ($action === 'reject' && !$canRejectMemberships) {
        $error = 'You do not have permission to reject organization memberships.';
    } else {
        $verifyStmt = $conn->prepare("
            SELECT oma.id, oma.member_id, oma.organization_id,
                   m.first_name, m.last_name
            FROM organization_membership_approvals oma
            INNER JOIN members m ON oma.member_id = m.id
            WHERE oma.id = ? AND oma.organization_id = ? AND oma.status = 'pending'
            LIMIT 1
        ");
        $verifyStmt->bind_param('ii', $approvalId, $organizationId);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();

        if ($verifyResult->num_rows === 0) {
            $error = 'Invalid approval request or insufficient permissions.';
        } else {
            $approvalData = $verifyResult->fetch_assoc();

            if ($action === 'approve') {
                $conn->begin_transaction();
                try {
                    $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
                    $updateStmt = $conn->prepare("
                        UPDATE organization_membership_approvals
                        SET status = 'approved', approved_by = ?, approved_at = NOW(), notes = ?
                        WHERE id = ? AND status = 'pending'
                    ");
                    $updateStmt->bind_param('isi', $actorUserId, $notes, $approvalId);
                    if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                        throw new Exception($updateStmt->error ?: 'Failed to update approval status.');
                    }
                    $updateStmt->close();

                    add_member_to_organization($conn, (int) $approvalData['member_id'], (int) $approvalData['organization_id']);
                    $assignedUnits = $organizationGroupService->assignForApprovedMembership(
                        (int) $approvalData['member_id'],
                        (int) $approvalData['organization_id'],
                        $approvalId,
                        $actorUserId,
                        [
                            'vocal_part_unit_id' => (int) ($_POST['vocal_part_unit_id'] ?? 0),
                            'brigade_section_unit_id' => (int) ($_POST['brigade_section_unit_id'] ?? 0),
                            'brigade_rank' => trim((string) ($_POST['brigade_rank'] ?? '')),
                        ]
                    );

                    $conn->commit();
                    $assignmentNames = array_map(static function ($unit) {
                        return $unit['name'];
                    }, $assignedUnits);
                    $assignmentSuffix = $assignmentNames
                        ? ' Assigned to: ' . implode(', ', $assignmentNames) . '.'
                        : '';
                    $_SESSION['approval_success'] = 'Membership approved for ' . $approvalData['first_name'] . ' ' . $approvalData['last_name'] . '.' . $assignmentSuffix;
                    header('Location: ' . build_organization_membership_approvals_url($organizationId));
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Error approving membership: ' . $e->getMessage();
                }
            } else {
                $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
                $updateStmt = $conn->prepare("
                    UPDATE organization_membership_approvals
                    SET status = 'rejected', approved_by = ?, approved_at = NOW(), notes = ?
                    WHERE id = ? AND status = 'pending'
                ");
                $updateStmt->bind_param('isi', $actorUserId, $notes, $approvalId);
                if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
                    $error = 'Failed to update rejection status: ' . $updateStmt->error;
                } else {
                    $_SESSION['approval_success'] = 'Membership rejected for ' . $approvalData['first_name'] . ' ' . $approvalData['last_name'] . '.';
                    header('Location: ' . build_organization_membership_approvals_url($organizationId));
                    exit;
                }
                $updateStmt->close();
            }
        }

        $verifyStmt->close();
    }
}

$pendingRequests = [];
if ($selectedOrgId > 0) {
    $pendingRequests = get_organization_pending_membership_requests($conn, $selectedOrgId);
}

$organizationGroupConfig = null;
$vocalPartUnits = [];
$brigadeSectionUnits = [];
if ($selectedOrgId > 0) {
    try {
        $organizationGroupConfig = $organizationGroupService->getOrganizationConfig($selectedOrgId);
        $vocalPartUnits = $organizationGroupService->getUnits($selectedOrgId, 'vocal_part');
        $brigadeSectionUnits = $organizationGroupService->getUnits($selectedOrgId, 'brigade_section');
    } catch (Throwable $e) {
        $error = $error ?? $e->getMessage();
    }
}

if (isset($_SESSION['approval_success'])) {
    $success = $_SESSION['approval_success'];
    unset($_SESSION['approval_success']);
}

$page_title = 'Organization Membership Approvals';
ob_start();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800">Organization Membership Approvals</h1>
        <p class="mb-0 text-muted">Review pending join requests for the organizations you manage.</p>
    </div>
    <span class="badge badge-primary badge-pill" style="font-size: 1rem;">
        <i class="fas fa-clock mr-1"></i> <?= count($pendingRequests) ?> Pending
    </span>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-body">
        <?php if (!empty($userOrganizations)): ?>
            <form method="get" class="form-row align-items-end">
                <div class="col-md-8">
                    <label for="org_id" class="font-weight-bold">Organization</label>
                    <select name="org_id" id="org_id" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($userOrganizations as $org): ?>
                            <option value="<?= (int) $org['id'] ?>" <?= (int) $org['id'] === $selectedOrgId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 text-md-right">
                    <?php if ($selectedOrgId > 0): ?>
                        <a href="my_organization_leader.php?org_id=<?= $selectedOrgId ?>" class="btn btn-outline-primary mt-4 mt-md-0">
                            <i class="fas fa-arrow-left mr-1"></i> Back to Leader Page
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-users-slash text-muted" style="font-size: 3rem;"></i>
                <h5 class="mt-3">No Organizations Available</h5>
                <p class="text-muted mb-0">There are no organizations assigned to you for membership approvals.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-user-check mr-2"></i>Pending Membership Requests
        </h6>
    </div>
    <div class="card-body">
        <?php if (!empty($pendingRequests)): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Member</th>
                            <th>Contact</th>
                            <th>Requested</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $request): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></strong><br>
                                    <small class="text-muted">CRN: <?= htmlspecialchars($request['crn'] ?: 'N/A') ?></small>
                                </td>
                                <td>
                                    <div><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($request['email'] ?: 'No email') ?></div>
                                    <small><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($request['phone'] ?: 'No phone') ?></small>
                                </td>
                                <td>
                                    <small><?= date('M j, Y g:i A', strtotime($request['requested_at'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($canApproveMemberships): ?>
                                        <button
                                            class="btn btn-success btn-sm approve-btn"
                                            data-id="<?= (int) $request['id'] ?>"
                                            data-org-id="<?= $selectedOrgId ?>"
                                            data-member="<?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>"
                                            data-org="<?= htmlspecialchars($selectedOrganization['name'] ?? 'Organization') ?>"
                                        >
                                            <i class="fas fa-check mr-1"></i>Approve
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($canRejectMemberships): ?>
                                        <button
                                            class="btn btn-danger btn-sm reject-btn <?= $canApproveMemberships ? 'ml-1' : '' ?>"
                                            data-id="<?= (int) $request['id'] ?>"
                                            data-org-id="<?= $selectedOrgId ?>"
                                            data-member="<?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>"
                                            data-org="<?= htmlspecialchars($selectedOrganization['name'] ?? 'Organization') ?>"
                                        >
                                            <i class="fas fa-times mr-1"></i>Reject
                                        </button>
                                    <?php endif; ?>

                                    <?php if (!$canApproveMemberships && !$canRejectMemberships): ?>
                                        <span class="text-muted"><i class="fas fa-eye mr-1"></i>View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-3">No Pending Requests</h5>
                <p class="text-muted mb-0">All membership requests for this organization have been processed.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$page_content = ob_get_clean();

ob_start();
?>
<div class="modal fade" id="approvalModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approvalModalTitle">Confirm Action</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <?= csrf_input() ?>
                    <input type="hidden" name="approval_id" id="modal-approval-id">
                    <input type="hidden" name="organization_id" id="modal-organization-id">
                    <input type="hidden" name="action" id="modal-action">

                    <p id="modal-message"></p>

                    <?php if (($organizationGroupConfig['assignment_strategy'] ?? '') === 'balanced_auto'): ?>
                        <div class="alert alert-info approval-assignment-fields">
                            The member will be assigned automatically to the smallest active group.
                        </div>
                    <?php elseif (($organizationGroupConfig['assignment_strategy'] ?? '') === 'manual_vocal_part'): ?>
                        <div class="form-group approval-assignment-fields">
                            <label for="vocal_part_unit_id">Vocal Part <span class="text-danger">*</span></label>
                            <select class="form-control approval-required-field" name="vocal_part_unit_id" id="vocal_part_unit_id" required>
                                <option value="">Select vocal part</option>
                                <?php foreach ($vocalPartUnits as $unit): ?>
                                    <option value="<?= (int) $unit['id'] ?>"><?= htmlspecialchars($unit['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Select the part that matches the member's assessed vocal range.</small>
                        </div>
                    <?php elseif (($organizationGroupConfig['assignment_strategy'] ?? '') === 'balanced_plus_section'): ?>
                        <div class="alert alert-info approval-assignment-fields">
                            The Brigade group will be balanced automatically. Select the rank-based section below.
                        </div>
                        <div class="form-group approval-assignment-fields">
                            <label for="brigade_rank">Brigade Rank or Level <span class="text-danger">*</span></label>
                            <input type="text" class="form-control approval-required-field" name="brigade_rank" id="brigade_rank" maxlength="100" required>
                        </div>
                        <div class="form-group approval-assignment-fields">
                            <label for="brigade_section_unit_id">Brigade Section <span class="text-danger">*</span></label>
                            <select class="form-control approval-required-field" name="brigade_section_unit_id" id="brigade_section_unit_id" required>
                                <option value="">Select section</option>
                                <?php foreach ($brigadeSectionUnits as $unit): ?>
                                    <option value="<?= (int) $unit['id'] ?>"><?= htmlspecialchars(ucfirst($unit['branch']) . ' - ' . $unit['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea
                            class="form-control"
                            name="notes"
                            id="notes"
                            rows="3"
                            placeholder="Add any notes about this decision..."
                        ></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="modal-confirm-btn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php
$modal_html = ob_get_clean();

ob_start();
?>
<script>
$(document).ready(function() {
    $('.approve-btn').on('click', function() {
        var id = $(this).data('id');
        var orgId = $(this).data('org-id');
        var member = $(this).data('member');
        var org = $(this).data('org');

        $('#modal-approval-id').val(id);
        $('#modal-organization-id').val(orgId);
        $('#modal-action').val('approve');
        $('.approval-assignment-fields').show();
        $('.approval-required-field').prop('disabled', false);
        $('#approvalModalTitle').text('Approve Membership');
        $('#modal-message').html('Are you sure you want to <strong>approve</strong> ' + member + '\'s membership request for <strong>' + org + '</strong>?');
        $('#modal-confirm-btn').removeClass('btn-danger').addClass('btn-success').text('Approve');
        $('#approvalModal').modal('show');
    });

    $('.reject-btn').on('click', function() {
        var id = $(this).data('id');
        var orgId = $(this).data('org-id');
        var member = $(this).data('member');
        var org = $(this).data('org');

        $('#modal-approval-id').val(id);
        $('#modal-organization-id').val(orgId);
        $('#modal-action').val('reject');
        $('.approval-assignment-fields').hide();
        $('.approval-required-field').prop('disabled', true);
        $('#approvalModalTitle').text('Reject Membership');
        $('#modal-message').html('Are you sure you want to <strong>reject</strong> ' + member + '\'s membership request for <strong>' + org + '</strong>?');
        $('#modal-confirm-btn').removeClass('btn-success').addClass('btn-danger').text('Reject');
        $('#approvalModal').modal('show');
    });
});
</script>
<?php
$additional_js = ob_get_clean();

include '../includes/layout.php';
?>
