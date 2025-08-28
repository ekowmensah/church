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

if (!$is_super_admin && !has_permission('view_church_list')) {
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
$can_add = $is_super_admin || has_permission('create_church');
$can_edit = $is_super_admin || has_permission('edit_church');
$can_delete = $is_super_admin || has_permission('delete_church');
$can_view = true; // Already validated above

// Fetch all churches
$churches = $conn->query("SELECT * FROM churches ORDER BY created_at DESC");

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Churches</h1>
    <?php if ($can_add): ?>
    <a href="church_form.php" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm">
        <i class="fas fa-plus fa-sm text-white-50"></i> Add Church
    </a>
    <?php endif; ?>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Church List</h6>
    </div>
    <div class="card-body">
    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success">Church added successfully!</div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Church updated successfully!</div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Church deleted successfully!</div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table table-bordered" id="churchTable" width="100%" cellspacing="0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Church Code</th>
                    <th>Location Code</th>
                    <th>Logo</th>
                    <th>Created At</th>
                    <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php while($row = $churches->fetch_assoc()): ?>
                <tr>
                    <td><?=htmlspecialchars($row['name'])?></td>
                    <td><?=htmlspecialchars($row['church_code'])?></td>
                    <td><?=htmlspecialchars($row['circuit_code'])?></td>
                    <td><?php if ($row['logo']): ?><img src="<?= BASE_URL ?>/uploads/<?=htmlspecialchars($row['logo'])?>" alt="logo" height="40"><?php endif; ?></td>
                    <td><?=htmlspecialchars($row['created_at'])?></td>
                    <?php if ($can_edit || $can_delete): ?>
                    <td>
                        <?php if ($can_edit): ?>
                        <a href="church_edit.php?id=<?=$row['id']?>" class="btn btn-sm btn-info" title="Edit"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                        <a href="church_delete.php?id=<?=$row['id']?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this church?')" title="Delete"><i class="fas fa-trash"></i></a>
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
<!-- DataTables JS -->
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/datatables/dataTables.bootstrap4.min.css">
<script src="<?= BASE_URL ?>/assets/vendor/datatables/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>/assets/vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function() {
    $('#churchTable').DataTable({
        "pageLength": 10,
        "lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
        "order": [[4, "desc"]],
        dom: 'Bfrtip',
        buttons: [
            'copy', 'csv', 'excel', 'pdf', 'print'
        ]
    });
});
</script>
<!-- DataTables Buttons -->
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap4.min.css">
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
