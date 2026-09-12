<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_classgroup_list')) {
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
$can_manage_schedule = $is_super_admin || has_permission('manage_bible_class_attendance_schedule');
$can_add = $is_super_admin || ($can_manage_schedule && has_permission('create_classgroup'));
$can_edit = $is_super_admin || ($can_manage_schedule && has_permission('edit_classgroup'));
$can_delete = $is_super_admin || has_permission('delete_classgroup');
$can_view = true; // Already validated above

// Fetch all class groups
$classgroups = $conn->query(
    "SELECT class_group.*, church.name AS church_name,
            COALESCE(class_totals.class_count, 0) AS class_count,
            EXISTS (
                SELECT 1 FROM class_group_schedule_review review_item
                WHERE review_item.class_group_id = class_group.id
                  AND review_item.issue_type = 'missing_meeting_day'
                  AND review_item.resolved = 0
            ) AS needs_schedule_review
       FROM class_groups class_group
       JOIN churches church ON church.id = class_group.church_id
       LEFT JOIN (
           SELECT class_group_id, COUNT(*) AS class_count
           FROM bible_classes GROUP BY class_group_id
       ) class_totals ON class_totals.class_group_id = class_group.id
      ORDER BY class_group.name"
);
$meetingDayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

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
        <?php elseif (isset($_GET['delete_blocked'])): ?>
            <div class="alert alert-warning">Move all assigned Bible classes before deleting this group.</div>
        <?php elseif (isset($_GET['delete_missing'])): ?>
            <div class="alert alert-warning">The class group was not found.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-bordered" id="classgroupTable" width="100%" cellspacing="0">
                <thead>
                    <tr>
                        <th>Group</th>
                        <th>Church</th>
                        <th>Meeting Day</th>
                        <th>Classes</th>
                        <th>Schedule</th>
                        <?php if ($can_edit || $can_delete): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $classgroups->fetch_assoc()): ?>
                    <tr>
                        <td><?=htmlspecialchars($row['name'])?></td>
                        <td><?= htmlspecialchars($row['church_name']) ?></td>
                        <td><?= $row['meeting_day'] === null ? '<span class="text-danger">Not configured</span>' : htmlspecialchars($meetingDayNames[(int) $row['meeting_day']] ?? 'Invalid') ?></td>
                        <td><?= (int) $row['class_count'] ?></td>
                        <td>
                            <?php if ($row['meeting_day'] === null || !empty($row['needs_schedule_review'])): ?>
                                <span class="badge badge-warning">Review required</span>
                            <?php else: ?>
                                <span class="badge badge-success">Active</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($can_edit || $can_delete): ?>
                        <td>
                            <?php if ($can_edit): ?>
                                <a href="classgroup_edit.php?id=<?=$row['id']?>" class="btn btn-sm btn-info" title="Edit"><i class="fas fa-edit"></i></a>
                            <?php endif; ?>
                            <?php if ($can_delete && (int) $row['class_count'] === 0): ?>
                                <form method="post" action="classgroup_delete.php" class="d-inline" onsubmit="return confirm('Delete this class group?')">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <button class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
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
