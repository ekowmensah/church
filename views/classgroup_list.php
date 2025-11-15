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

if (!$is_super_admin && !has_permission('view_class_group_list')) {
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
$can_add = $is_super_admin || has_permission('create_class_group');
$can_edit = $is_super_admin || has_permission('edit_class_group');
$can_delete = $is_super_admin || has_permission('delete_class_group');
$can_view = true; // Already validated above

// Fetch all class groups
$classgroups = $conn->query("SELECT * FROM class_groups ORDER BY id DESC");

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Class Groups</h1>
    <?php if ($can_add): ?>
    <a href="classgroup_form.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-plus fa-sm text-white-50"></i> Add Class Group
    </a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Class Group List</h6>
    </div>
    <div class="card-body">
        <?php if (isset($_GET['added'])): ?>
            <div class="alert alert-success">Class group added successfully!</div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success">Class group updated successfully!</div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success">Class group deleted successfully!</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered" id="classgroupTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $classgroups->fetch_assoc()): ?>
                    <tr>
                        <td><?=htmlspecialchars($row['name'])?></td>
                        <?php if ($can_edit || $can_delete): ?>
                        <td>
                            <?php if ($can_edit): ?>
                                <a href="classgroup_edit.php?id=<?=$row['id']?>" class="btn btn-sm btn-info" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete): ?>
                                <a href="classgroup_delete.php?id=<?=$row['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this class group?')" title="Delete"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<!-- DataTables scripts -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script>
$(document).ready(function() {
    $('#classgroupTable').DataTable({
        dom: 'Bfrtip',
        buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
    });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
