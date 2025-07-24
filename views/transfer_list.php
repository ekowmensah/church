<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_transfer_list')) {
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
$can_add = $is_super_admin || has_permission('create_transfer');
$can_edit = $is_super_admin || has_permission('edit_transfer');
$can_delete = $is_super_admin || has_permission('delete_transfer');
$can_view = true; // Already validated above

$page_title = 'Member Transfers';

// Fetch all transfers with member/class/user names
$sql = "SELECT t.*, 
    CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS member_name,
    bc_from.name AS from_class,
    bc_to.name AS to_class,
    u.name AS transferred_by_name,
    t.old_crn,
    m.crn AS new_crn
FROM member_transfers t
LEFT JOIN members m ON t.member_id = m.id
LEFT JOIN bible_classes bc_from ON t.from_class_id = bc_from.id
LEFT JOIN bible_classes bc_to ON t.to_class_id = bc_to.id
LEFT JOIN users u ON t.transferred_by = u.id
ORDER BY t.transfer_date DESC, t.id DESC";
$transfers = $conn->query($sql);

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Member Transfers</h1>
    <?php if ($can_add): ?>
    <a href="transfer_form.php" class="btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-plus fa-sm text-white-50"></i> Add Transfer
    </a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Transfer List</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered" id="transferTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>From Class</th>
                        <th>To Class</th>
                        <th>Transfer Date</th>
                        <th>Transferred By</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $transfers->fetch_assoc()): ?>
                    <tr>
                        <td><?=htmlspecialchars(trim($row['member_name']))?></td>
                        <td><?=htmlspecialchars($row['from_class'])?><br>
                            <small class='text-muted'><?=htmlspecialchars($row['old_crn'] ?? '')?></small></td>
                        <td><?=htmlspecialchars($row['to_class'])?><br>
                            <small class='text-success'><?=htmlspecialchars($row['new_crn'] ?? '')?></small></td>
                        <td><?=htmlspecialchars($row['transfer_date'])?></td>
                        <td><?=htmlspecialchars($row['transferred_by_name'])?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function() {
    $('#transferTable').DataTable();
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
