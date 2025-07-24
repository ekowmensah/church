<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

// Only allow logged-in users with manage_members or super admin
if (!is_logged_in() || (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) && !has_permission('manage_members'))) {
    http_response_code(403);
    die('You do not have permission to view deleted members.');
}

// Fetch deleted members from deleted_members table for audit, join with bible_classes and churches for display
$members = $conn->query("SELECT d.*, c.name AS class_name, ch.name AS church_name FROM deleted_members d LEFT JOIN bible_classes c ON d.class_id = c.id LEFT JOIN churches ch ON d.church_id = ch.id ORDER BY d.last_name ASC, d.first_name ASC, d.middle_name ASC");

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h4 class="m-0 font-weight-bold text-danger"><i class="fas fa-trash-alt"></i> Deleted Members</h4>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Member List</a>
</div>
<?php if (isset($_GET['restored'])): ?>
    <div class="alert alert-success">Member restored as pending!</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Member permanently deleted!</div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>
<div class="card shadow mb-4 animated fadeIn">
    <div class="card-header py-3 bg-danger">
        <h6 class=\"m-0 font-weight-bold text-white\">Deleted Members List</h6>
    </div>
    <div class=\"card-body\">
        <div class=\"table-responsive\">
            <table class="table table-bordered table-hover" id="deletedMemberTable" width="100%" cellspacing="0">
                <thead class="thead-dark">
    <tr>
        <th>Photo</th>
        <th>CRN</th>
        <th>Full Name</th>
        <th>Phone</th>
        <th>Bible Class</th>
        <th>Day Born</th>
        <th>Gender</th>
        <th>Status</th>
        <th>Deleted At</th>
        <th>Action</th>
    </tr>
</thead>
<tbody>
<?php while($row = $members->fetch_assoc()): ?>
    <tr>
        <td>
<?php
$photo_path = !empty($row['photo']) ? __DIR__.'/../uploads/members/' . $row['photo'] : '';
if (!empty($row['photo']) && file_exists($photo_path)) {
    $photo_url = BASE_URL . '/uploads/members/' . rawurlencode($row['photo']);
} else {
    $photo_url = BASE_URL . '/assets/img/undraw_profile.svg';
}
?>
<img src="<?= $photo_url ?>" alt="photo" height="40" style="border-radius:50%">
</td>
        <td><?=htmlspecialchars($row['crn'])?></td>
        <td><?=htmlspecialchars(trim($row['last_name'].' '.$row['first_name'].' '.$row['middle_name']))?></td>
        <td><?=htmlspecialchars($row['phone'])?></td>
        <td><?=htmlspecialchars($row['class_name'])?></td>
        <td><?=htmlspecialchars($row['day_born'])?></td>
        <td><span class="badge badge-<?=strtolower($row['gender'])=='male'?'primary':'warning'?> text-capitalize"><?=htmlspecialchars($row['gender'])?></span></td>
        <td><span class="badge badge-danger">Deleted</span></td>
        <td><span class="badge badge-dark"><?=htmlspecialchars($row['deleted_at'])?></span></td>
        <td>
            <a href="restore_deleted_member.php?id=<?=urlencode($row['id'])?>" class="btn btn-success btn-xs restore-btn mb-1" onclick="return confirm('Restore this member as pending?')">
                <i class="fas fa-undo"></i> Restore
            </a>
            <a href="permanently_delete_member.php?id=<?=urlencode($row['id'])?>" class="btn btn-danger btn-xs delete-btn" onclick="return confirm('This will permanently delete this member and all related records. Continue?')">
                <i class="fas fa-trash-alt"></i> Delete Permanently
            </a>
        </td>
    </tr>
<?php endwhile; ?>
</tbody>
            </table>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function() {
    $('#deletedMemberTable').DataTable();
});
</script>
<style>
.btn-xs {
    padding: 0.14rem 0.34rem !important;
    font-size: 0.89rem !important;
    line-height: 1.15 !important;
    border-radius: 0.22rem !important;
}
#deletedMemberTable th, #deletedMemberTable td {
    font-size: 1.01rem !important;
}
#deletedMemberTable thead th {
    font-size: 0.98rem !important;
    font-weight: 600;
}
#deletedMemberTable {
    font-size: 1.01rem !important;
}
</style>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
