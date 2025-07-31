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

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('create_member')) {
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
$can_view = true; // Already validated above

$error = '';
$success = '';
$editing = false;
$member = [
    'first_name'=>'','middle_name'=>'','last_name'=>'','crn'=>'','phone'=>'','email'=>'','class_id'=>'','church_id'=>''
];

// Fetch dropdowns
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
$users = $conn->query("SELECT id, name FROM users ORDER BY name ASC");

// Fetch pending members
$pending_members = $conn->query("SELECT id, first_name, last_name, phone, crn, registration_token FROM members WHERE status = 'pending' ORDER BY id DESC");

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Register Member</h1>
    <a href="member_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
</div>

<?php if ($pending_members && $pending_members->num_rows > 0): ?>
<div class="card mb-4 shadow">
    <div class="card-header bg-warning text-dark font-weight-bold">Pending Members (Require Completion)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
    <tr>
        <th>CRN</th>
        <th>First Name</th>
        <th>Other Name</th>
        <th>Last Name</th>
        <th>Phone</th>
        <th>Action</th>
    </tr>
</thead>
<tbody>
<?php
// Refetch with middle_name
$pending_members = $conn->query("SELECT id, first_name, middle_name, last_name, phone, crn, registration_token FROM members WHERE status = 'pending' ORDER BY id DESC");
while($pm = $pending_members->fetch_assoc()): ?>
    <tr>
        <td><?=htmlspecialchars($pm['crn'])?></td>
        <td><?=htmlspecialchars($pm['first_name'])?></td>
        <td><?=htmlspecialchars($pm['middle_name'])?></td>
        <td><?=htmlspecialchars($pm['last_name'])?></td>
        <td><?=htmlspecialchars($pm['phone'])?></td>
        <td>
            <a href="complete_registration_admin.php?id=<?=urlencode($pm['id'])?>" class="btn btn-sm btn-success">Register</a>
            <a href="member_delete.php?id=<?=urlencode($pm['id'])?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this pending member?');">Delete</a>
            <button type="button" class="btn btn-sm btn-info resend-link-btn" data-id="<?=htmlspecialchars($pm['id'])?>">Resend Link</button>
        </td>
    </tr>
<?php endwhile; ?>
</tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.resend-link-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var origText = this.textContent;
            this.textContent = 'Sending...';
            this.disabled = true;
            fetch('ajax_resend_registration_link.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id=' + encodeURIComponent(id)
            })
            .then(resp => resp.json())
            .then(data => {
                this.textContent = origText;
                this.disabled = false;
                alert(data.message);
            })
            .catch(() => {
                this.textContent = origText;
                this.disabled = false;
                alert('Network error.');
            });
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
