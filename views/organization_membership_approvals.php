<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check permissions for organization membership approvals
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$can_view_approvals = $is_super_admin || has_permission('view_organization_membership_approvals');
$can_approve_memberships = $is_super_admin || has_permission('approve_organization_memberships');
$can_reject_memberships = $is_super_admin || has_permission('reject_organization_memberships');

// Check if user has permission to view approval interface
if (!$can_view_approvals) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to view organization membership approvals.</p></div>';
    }
    exit;
}

// Get organizations where this user has approval authority
$user_organizations = [];
$is_org_leader = false;

if (isset($_SESSION['user_id'])) {
    // Check if user has organizational leader role (role_id = 6)
    $role_check = $conn->prepare('SELECT ur.role_id FROM user_roles ur WHERE ur.user_id = ? AND ur.role_id = 6');
    $role_check->bind_param('i', $_SESSION['user_id']);
    $role_check->execute();
    $role_result = $role_check->get_result();
    if ($role_result->num_rows > 0) {
        $is_org_leader = true;
    }
    $role_check->close();
    
    // Get organizations where this user is the leader (if they are an org leader)
    if ($is_org_leader || $is_super_admin) {
        $org_query_sql = 'SELECT id, name FROM organizations';
        $org_params = [];
        
        if (!$is_super_admin) {
            // Non-super admin org leaders can only see their own organizations
            $org_query_sql .= ' WHERE leader_id = ?';
            $org_params[] = $_SESSION['user_id'];
        }
        
        $org_query = $conn->prepare($org_query_sql);
        if (!empty($org_params)) {
            $org_query->bind_param('i', ...$org_params);
        }
        $org_query->execute();
        $org_result = $org_query->get_result();
        while ($org = $org_result->fetch_assoc()) {
            $user_organizations[] = $org;
        }
        $org_query->close();
    }
}

// If user has permission but no organizations to manage, show appropriate message
if (empty($user_organizations) && !$is_super_admin) {
    // Allow viewing but show no data message instead of access denied
}

// Handle approval/rejection actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $approval_id = intval($_POST['approval_id'] ?? 0);
    $action = $_POST['action'];
    $notes = trim($_POST['notes'] ?? '');
    
    if ($approval_id && in_array($action, ['approve', 'reject'])) {
        // Check specific action permissions
        if ($action === 'approve' && !$can_approve_memberships) {
            $error = "You do not have permission to approve organization memberships.";
        } else if ($action === 'reject' && !$can_reject_memberships) {
            $error = "You do not have permission to reject organization memberships.";
        } else {
            // Verify the approval request belongs to an organization this user can manage
            $verify_sql = '
                SELECT oma.id, oma.member_id, oma.organization_id, o.name as org_name, 
                       m.first_name, m.last_name, m.email, m.phone
                FROM organization_membership_approvals oma
                INNER JOIN organizations o ON oma.organization_id = o.id
                INNER JOIN members m ON oma.member_id = m.id
                WHERE oma.id = ? AND oma.status = "pending"
            ';
            
            $verify_params = [$approval_id];
            
            // Non-super admin users can only manage their own organizations
            if (!$is_super_admin) {
                $verify_sql .= ' AND o.leader_id = ?';
                $verify_params[] = $_SESSION['user_id'];
            }
            
            $verify_stmt = $conn->prepare($verify_sql);
            $verify_stmt->bind_param(str_repeat('i', count($verify_params)), ...$verify_params);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $approval_data = $verify_result->fetch_assoc();
            
            if ($action === 'approve') {
                // Approve: Update status and add to member_organizations
                $conn->begin_transaction();
                try {
                    // Update approval status
                    $update_stmt = $conn->prepare('
                        UPDATE organization_membership_approvals 
                        SET status = "approved", approved_by = ?, approved_at = NOW(), notes = ?
                        WHERE id = ?
                    ');
                    $update_stmt->bind_param('isi', $_SESSION['user_id'], $notes, $approval_id);
                    $update_stmt->execute();
                    
                    // Add to member_organizations
                    $member_org_stmt = $conn->prepare('
                        INSERT INTO member_organizations (member_id, organization_id) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE organization_id = organization_id
                    ');
                    $member_org_stmt->bind_param('ii', $approval_data['member_id'], $approval_data['organization_id']);
                    $member_org_stmt->execute();
                    
                    $conn->commit();
                    $success = "Membership approved for " . htmlspecialchars($approval_data['first_name'] . ' ' . $approval_data['last_name']);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error approving membership: " . $e->getMessage();
                }
            } else {
                // Reject: Update status only
                $update_stmt = $conn->prepare('
                    UPDATE organization_membership_approvals 
                    SET status = "rejected", approved_by = ?, approved_at = NOW(), notes = ?
                    WHERE id = ?
                ');
                $update_stmt->bind_param('isi', $_SESSION['user_id'], $notes, $approval_id);
                $update_stmt->execute();
                $success = "Membership rejected for " . htmlspecialchars($approval_data['first_name'] . ' ' . $approval_data['last_name']);
            }
        } else {
            $error = "Invalid approval request or insufficient permissions.";
        }
        $verify_stmt->close();
        }
    }
}

// Get pending approval requests for organizations this user leads
$org_ids = array_column($user_organizations, 'id');
$org_ids_placeholder = str_repeat('?,', count($org_ids) - 1) . '?';

$pending_query = "
    SELECT oma.id, oma.member_id, oma.organization_id, oma.requested_at,
           o.name as organization_name,
           m.first_name, m.last_name, m.email, m.phone, m.crn
    FROM organization_membership_approvals oma
    INNER JOIN organizations o ON oma.organization_id = o.id
    INNER JOIN members m ON oma.member_id = m.id
    WHERE oma.status = 'pending' AND oma.organization_id IN ($org_ids_placeholder)
    ORDER BY oma.requested_at ASC
";

$pending_stmt = $conn->prepare($pending_query);
$pending_stmt->bind_param(str_repeat('i', count($org_ids)), ...$org_ids);
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();

ob_start();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Membership Organizations Approvals</h1>
    <span class="badge badge-primary badge-pill" style="font-size: 1rem;">
        <i class="fas fa-clock mr-1"></i> <?= $pending_result->num_rows ?> Pending
    </span>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle mr-2"></i><?= $success ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-users-cog mr-2"></i>Pending Membership Requests
        </h6>
    </div>
    <div class="card-body">
        <?php if ($pending_result->num_rows > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Member</th>
                            <th>Contact</th>
                            <th>Organization</th>
                            <th>Requested</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($request = $pending_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?></strong><br>
                                    <small class="text-muted">CRN: <?= htmlspecialchars($request['crn']) ?></small>
                                </td>
                                <td>
                                    <i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($request['email']) ?><br>
                                    <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($request['phone']) ?>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?= htmlspecialchars($request['organization_name']) ?></span>
                                </td>
                                <td>
                                    <small><?= date('M j, Y g:i A', strtotime($request['requested_at'])) ?></small>
                                </td>
                                <td>
                                    <?php if ($can_approve_memberships): ?>
                                        <button class="btn btn-success btn-sm approve-btn" 
                                                data-id="<?= $request['id'] ?>"
                                                data-member="<?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>"
                                                data-org="<?= htmlspecialchars($request['organization_name']) ?>">
                                            <i class="fas fa-check mr-1"></i>Approve
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($can_reject_memberships): ?>
                                        <button class="btn btn-danger btn-sm reject-btn <?= $can_approve_memberships ? 'ml-1' : '' ?>"
                                                data-id="<?= $request['id'] ?>"
                                                data-member="<?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>"
                                                data-org="<?= htmlspecialchars($request['organization_name']) ?>">
                                            <i class="fas fa-times mr-1"></i>Reject
                                        </button>
                                    <?php endif; ?>
                                    
                                    <?php if (!$can_approve_memberships && !$can_reject_memberships): ?>
                                        <span class="text-muted"><i class="fas fa-eye mr-1"></i>View Only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle text-success" style="font-size: 3rem;"></i>
                <h5 class="mt-3">No Pending Requests</h5>
                <p class="text-muted">All membership requests have been processed.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';

// Modal output - moved to end to fix overlay/JS issues
ob_start();
?>

<!-- Approval Modal -->
<div class="modal fade" id="approvalModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="approvalModalTitle">Confirm Action</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <div class="modal-body">
                    <input type="hidden" name="approval_id" id="modal-approval-id">
                    <input type="hidden" name="action" id="modal-action">
                    
                    <p id="modal-message"></p>
                    
                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea class="form-control" name="notes" id="notes" rows="3" 
                                  placeholder="Add any notes about this decision..."></textarea>
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

<script>
$(document).ready(function() {
    // Handle approve button clicks
    $('.approve-btn').on('click', function() {
        const id = $(this).data('id');
        const member = $(this).data('member');
        const org = $(this).data('org');
        
        $('#modal-approval-id').val(id);
        $('#modal-action').val('approve');
        $('#approvalModalTitle').text('Approve Membership');
        $('#modal-message').html(`Are you sure you want to <strong>approve</strong> ${member}'s membership request for <strong>${org}</strong>?`);
        $('#modal-confirm-btn').removeClass('btn-danger').addClass('btn-success').text('Approve');
        $('#approvalModal').modal('show');
    });
    
    // Handle reject button clicks
    $('.reject-btn').on('click', function() {
        const id = $(this).data('id');
        const member = $(this).data('member');
        const org = $(this).data('org');
        
        $('#modal-approval-id').val(id);
        $('#modal-action').val('reject');
        $('#approvalModalTitle').text('Reject Membership');
        $('#modal-message').html(`Are you sure you want to <strong>reject</strong> ${member}'s membership request for <strong>${org}</strong>?`);
        $('#modal-confirm-btn').removeClass('btn-success').addClass('btn-danger').text('Reject');
        $('#approvalModal').modal('show');
    });
});
</script>

<?php
echo ob_get_clean();
?>
