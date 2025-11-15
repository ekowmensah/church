<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('pending_members_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_member');
$can_edit = $is_super_admin || has_permission('edit_member');
$can_delete = $is_super_admin || has_permission('delete_member');
$can_activate = $is_super_admin || has_permission('activate_member');
$can_complete_registration = $is_super_admin || has_permission('activate_member');
$can_view = true; // Already validated above

// Handle bulk actions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $member_ids = $_POST['member_ids'] ?? [];
    
    if (!empty($member_ids) && is_array($member_ids)) {
        $ids = array_map('intval', $member_ids);
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        switch ($action) {
            case 'activate':
                if ($can_activate) {
                    $stmt = $conn->prepare("UPDATE members SET status = 'active', deactivated_at = NULL WHERE id IN ($placeholders)");
                    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
                    if ($stmt->execute()) {
                        $message = count($ids) . ' member(s) activated successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Error activating members.';
                        $message_type = 'danger';
                    }
                }
                break;
                
            case 'delete':
                if ($can_delete) {
                    $stmt = $conn->prepare("DELETE FROM members WHERE id IN ($placeholders)");
                    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
                    if ($stmt->execute()) {
                        $message = count($ids) . ' member(s) deleted successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Error deleting members.';
                        $message_type = 'danger';
                    }
                }
                break;
        }
    }
}

// Fetch pending and de-activated members with additional details
$query = "
    SELECT 
        m.*,
        c.name AS class_name,
        ch.name AS church_name,
        CONCAT(m.last_name, ', ', m.first_name, ' ', COALESCE(m.middle_name, '')) AS full_name,
        DATE_FORMAT(m.created_at, '%Y-%m-%d') AS registration_date,
        DATEDIFF(CURDATE(), m.created_at) AS days_pending
    FROM members m 
    LEFT JOIN bible_classes c ON m.class_id = c.id 
    LEFT JOIN churches ch ON m.church_id = ch.id 
    WHERE m.status = 'de-activated' 
    ORDER BY m.status DESC, m.created_at DESC
";

$members = $conn->query($query);
if (!$members) {
    die("Error fetching members: " . $conn->error);
}

ob_start();
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
    <div>
        <h1 class="h4 mb-1 text-primary font-weight-bold">
            <i class="fas fa-user-clock mr-2"></i>De-activated Members
        </h1>
        <small class="text-muted">Manage de-activated members only</small>
    </div>
    <div class="mt-2 mt-md-0">
        <?php if ($can_add): ?>
            <a href="member_form.php" class="btn btn-success shadow-sm">
                <i class="fas fa-user-plus mr-1"></i> Add New Member
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?> mr-2"></i>
    <?= htmlspecialchars($message) ?>
    <button type="button" class="close" data-dismiss="alert">
        <span>&times;</span>
    </button>
</div>
<?php endif; ?>

<div class="card shadow mb-4">
    <div class="card-header py-3 bg-gradient-primary text-white d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold">
            <i class="fas fa-list-ul mr-2"></i>Pending Member List
        </h6>
        <span class="badge badge-light text-primary font-weight-bold">
            <?= $members->num_rows ?> members
        </span>
    </div>
    
    <div class="card-body">
        <?php if ($members->num_rows === 0): ?>
            <div class="text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No Pending Members</h5>
                <p class="text-muted">All members have been processed or there are no pending registrations.</p>
            </div>
        <?php else: ?>
            <!-- Bulk Actions -->
            <?php if ($can_activate || $can_delete): ?>
            <form method="POST" id="bulkActionForm">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="input-group">
                            <select name="bulk_action" class="form-control" required>
                                <option value="">Select Action</option>
                                <?php if ($can_activate): ?>
                                    <option value="activate">Activate Selected</option>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <option value="delete">Delete Selected</option>
                                <?php endif; ?>
                            </select>
                            <div class="input-group-append">
                                <button type="submit" class="btn btn-primary" onclick="return confirmBulkAction()">
                                    <i class="fas fa-play mr-1"></i> Execute
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 text-right">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleSelectAll()">
                            <i class="fas fa-check-square mr-1"></i> Toggle All
                        </button>
                    </div>
                </div>
            
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="pendingMemberTable">
                        <thead class="thead-light">
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                                <th width="60">Photo</th>
                                <th>CRN</th>
                                <th>Full Name</th>
                                <th>Phone</th>
                                <th>Bible Class</th>
                                <th>Church</th>
                                <th>Status</th>
                                <th>Days Pending</th>
                                <th width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $members->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" name="member_ids[]" value="<?= $row['id'] ?>" class="member-checkbox">
                                </td>
                                <td class="text-center">
                                    <?php
                                    $photo_path = !empty($row['photo']) ? __DIR__.'/../uploads/members/' . $row['photo'] : '';
                                    if (!empty($row['photo']) && file_exists($photo_path)) {
                                        $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($row['photo']);
                                    } else {
                                        $photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
                                    }
                                    ?>
                                    <img src="<?= $photo_url ?>" alt="Member Photo" class="rounded-circle" width="40" height="40" style="object-fit: cover;">
                                </td>
                                <td>
                                    <span class="font-weight-bold text-primary"><?= htmlspecialchars($row['crn']) ?></span>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                                        <?php if (!empty($row['email'])): ?>
                                            <br><small class="text-muted">
                                                <i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($row['email']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($row['phone'])): ?>
                                        <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($row['phone']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Not provided</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['class_name'] ?? 'Not assigned') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($row['church_name'] ?? 'Not assigned') ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'de-activated'): ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-user-times mr-1"></i>De-activated
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-user-clock mr-1"></i>Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $row['days_pending'] > 7 ? 'danger' : ($row['days_pending'] > 3 ? 'warning' : 'info') ?>">
                                        <?= $row['days_pending'] ?> days
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <?php if ($row['status'] === 'pending' && $can_complete_registration): ?>
                                            <a href="complete_registration_admin.php?id=<?= $row['id'] ?>" 
                                               class="btn btn-success btn-sm" 
                                               title="Complete Registration">
                                                <i class="fas fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($row['status'] === 'de-activated' && $can_activate): ?>
                                            <a href="member_activate.php?id=<?= $row['id'] ?>" 
                                               class="btn btn-success btn-sm" 
                                               title="Activate Member"
                                               onclick="return confirm('Activate this member?')">
                                                <i class="fas fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit): ?>
                                            <a href="<?= $row['status'] === 'pending' ? 'member_form.php' : 'admin_member_edit.php' ?>?id=<?= $row['id'] ?>" 
                                               class="btn btn-info btn-sm" 
                                               title="Edit Member">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_delete): ?>
                                            <a href="member_delete.php?id=<?= $row['id'] ?>" 
                                               class="btn btn-danger btn-sm" 
                                               title="Delete Member"
                                               onclick="return confirm('Are you sure you want to delete this member? This action cannot be undone.')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize DataTable with enhanced features
    $('#pendingMemberTable').DataTable({
        "pageLength": 25,
        "order": [[8, "desc"]], // Sort by days pending (descending)
        "columnDefs": [
            { "orderable": false, "targets": [0, 1, 9] }, // Disable sorting for checkbox, photo, and actions
            { "searchable": false, "targets": [0, 1, 9] }  // Disable search for checkbox, photo, and actions
        ],
        "language": {
            "search": "Search members:",
            "lengthMenu": "Show _MENU_ members per page",
            "info": "Showing _START_ to _END_ of _TOTAL_ pending members",
            "emptyTable": "No pending members found"
        },
        "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
               '<"row"<"col-sm-12"tr>>' +
               '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>'
    });
});

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.member-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
}

function confirmBulkAction() {
    const selectedCheckboxes = document.querySelectorAll('.member-checkbox:checked');
    const action = document.querySelector('select[name="bulk_action"]').value;
    
    if (selectedCheckboxes.length === 0) {
        alert('Please select at least one member.');
        return false;
    }
    
    if (!action) {
        alert('Please select an action.');
        return false;
    }
    
    const actionText = action === 'activate' ? 'activate' : 'delete';
    const count = selectedCheckboxes.length;
    
    return confirm(`Are you sure you want to ${actionText} ${count} selected member(s)?`);
}

// Auto-dismiss alerts after 5 seconds
setTimeout(function() {
    $('.alert').fadeOut('slow');
}, 5000);
</script>

<style>
.btn-group .btn {
    margin-right: 2px;
}

.btn-group .btn:last-child {
    margin-right: 0;
}

.table td {
    vertical-align: middle;
}

.badge {
    font-size: 0.8em;
}

#pendingMemberTable_wrapper .row {
    margin-bottom: 1rem;
}

.dataTables_filter input {
    border-radius: 20px;
    padding: 0.375rem 0.75rem;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,123,255,.075);
}
</style>

<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
