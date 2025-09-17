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

if (!$is_super_admin && !has_permission('view_user_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../views/errors/403.php')) {
        include __DIR__.'/../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../../views/errors/403.php')) {
        include __DIR__.'/../../views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_user');
$can_edit = $is_super_admin || has_permission('edit_user');
$can_delete = $is_super_admin || has_permission('delete_user');
$can_view = true; // Already validated above

// Build filter SQL - properly initialize from GET parameters
$filter_church = isset($_GET['church_id']) ? intval($_GET['church_id']) : null;
$filter_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$filter_org = isset($_GET['organization_id']) ? intval($_GET['organization_id']) : null;
$where = [];
$params = [];
$types = '';
if ($filter_church) {
    $where[] = 'm.church_id = ?';
    $params[] = $filter_church;
    $types .= 'i';
}
if ($filter_class) {
    $where[] = 'm.class_id = ?';
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $where[] = 'EXISTS (SELECT 1 FROM member_organizations mo WHERE mo.member_id = m.id AND mo.organization_id = ?)';
    $params[] = $filter_org;
    $types .= 'i';
}
$sql = "SELECT DISTINCT u.id AS user_id, u.name AS user_name, u.email AS user_email, u.status AS user_status, u.member_id, m.crn, m.phone, m.email AS member_email, m.class_id, m.church_id, m.last_name, m.first_name, m.middle_name, bc.name AS class_name
FROM users u
LEFT JOIN members m ON u.member_id = m.id
LEFT JOIN bible_classes bc ON m.class_id = bc.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY u.name';
$users = null;
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users = $stmt->get_result();
} else {
    $users = $conn->query($sql);
}
ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0 text-gray-800"><i class="fas fa-users mr-2"></i>Users</h1>
    <?php if ($can_add): ?>
        <a href="user_form.php" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> Add User</a>
    <?php endif; ?>
</div>
<!-- Filter Form -->
<form method="get" class="form-row align-items-end mb-3" id="userFilterForm">
    <div class="form-group col-md-3 mb-2">
        <label for="church_id" class="font-weight-bold">Church</label>
        <select class="form-control" id="church_id" name="church_id">
            <option value="">All</option>
            <?php $churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
            while($ch = $churches->fetch_assoc()): ?>
                <option value="<?= $ch['id'] ?>" <?= ($filter_church==$ch['id']?'selected':'') ?>><?= htmlspecialchars($ch['name']) ?></option>
            <?php endwhile; ?>
        </select>
    </div>
    <div class="form-group col-md-3 mb-2">
        <label for="class_id" class="font-weight-bold">Bible Class</label>
        <select class="form-control" id="class_id" name="class_id" <?= !$filter_church ? 'disabled' : '' ?>>
            <option value="">All</option>
            <?php
            if ($filter_church) {
                $bible_classes = $conn->query("SELECT id, name FROM bible_classes WHERE church_id = $filter_church ORDER BY name ASC");
                while($cl = $bible_classes->fetch_assoc()): ?>
                    <option value="<?= $cl['id'] ?>" <?= ($filter_class==$cl['id']?'selected':'') ?>><?= htmlspecialchars($cl['name']) ?></option>
                <?php endwhile; }
            ?>
        </select>
    </div>
    <div class="form-group col-md-3 mb-2">
        <label for="organization_id" class="font-weight-bold">Organization</label>
        <select class="form-control" id="organization_id" name="organization_id" <?= !$filter_church ? 'disabled' : '' ?>>
            <option value="">All</option>
            <?php
            if ($filter_church) {
                $orgs = $conn->query("SELECT id, name FROM organizations WHERE church_id = $filter_church ORDER BY name ASC");
                while($o = $orgs->fetch_assoc()): ?>
                    <option value="<?= $o['id'] ?>" <?= ($filter_org==$o['id']?'selected':'') ?>><?= htmlspecialchars($o['name']) ?></option>
                <?php endwhile; }
            ?>
        </select>
    </div>
    <div class="form-group col-md-2 mb-2">
        <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Filter</button>
    </div>
</form>
<script>
$(function(){
    $('#church_id').on('change', function(){
        var churchId = $(this).val();
        // Bible Classes
        $('#class_id').prop('disabled', !churchId);
        $('#class_id').html('<option value="">All</option>');
        if (churchId) {
            $.get('ajax_get_classes_by_church.php', {church_id: churchId}, function(data){
                $('#class_id').append(data);
            });
        }
        // Organizations
        $('#organization_id').prop('disabled', !churchId);
        $('#organization_id').html('<option value="">All</option>');
        if (churchId) {
            $.get('ajax_get_organizations_by_church.php', {church_id: churchId}, function(data){
                $('#organization_id').append(data);
            });
        }
    });
});
</script>

<div class="card shadow mb-4">
    <div class="card-header py-3 bg-primary text-white">
        <h6 class="m-0 font-weight-bold">User List</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
<th>Name</th>
<th>CRN</th>
<th>Phone</th>
<th>Email</th>
<th>Class</th>
<th>Roles/Leadership</th>
<th>Status</th>
<th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
    $displayed_users = [];
    if ($users && $users->num_rows > 0):
    while($u = $users->fetch_assoc()):
        $user_id = $u['user_id'];
        if (in_array($user_id, $displayed_users)) continue;
        $displayed_users[] = $user_id;
        $member_id = $u['member_id'];
        // Fetch all roles for this user (including secondary roles)
        $roles = [];
        $role_q = $conn->query("SELECT r.name FROM user_roles ur JOIN roles r ON ur.role_id = r.id WHERE ur.user_id = ".$user_id);
        if ($role_q) {
            while($r = $role_q->fetch_assoc()) $roles[] = $r['name'];
        }
        // Fetch class leadership (robust to missing columns)
        $class_leadership = '';
        if ($member_id) {
            $class_leader_col_exists = false;
            $class_check = $conn->query("SHOW COLUMNS FROM bible_classes LIKE 'leader_id'");
            if ($class_check && $class_check->num_rows > 0) $class_leader_col_exists = true;
            if ($class_leader_col_exists) {
                $clq = $conn->query("SELECT bc.name FROM bible_classes bc WHERE bc.leader_id = ".$member_id);
                if ($clq && $clq->num_rows > 0) {
                    $cls = [];
                    while($c = $clq->fetch_assoc()) $cls[] = $c['name'];
                    $class_leadership = 'Class Leader: '.implode(', ', $cls);
                }
            }
        }
        // Fetch org leadership (skip if organizations.leader_id does not exist)
        $org_leadership = '';
        if ($member_id) {
            $org_leader_col_exists = false;
            $org_check = $conn->query("SHOW COLUMNS FROM organizations LIKE 'leader_id'");
            if ($org_check && $org_check->num_rows > 0) $org_leader_col_exists = true;
            if ($org_leader_col_exists) {
                $orgq = $conn->query("SELECT o.name FROM organizations o WHERE o.leader_id = ".$member_id);
                if ($orgq && $orgq->num_rows > 0) {
                    $orgs = [];
                    while($o = $orgq->fetch_assoc()) $orgs[] = $o['name'];
                    $org_leadership = 'Org Leader: '.implode(', ', $orgs);
                }
            }
        }
        // Fetch org memberships (robust to missing tables)
        $org_memberships = [];
        if ($member_id) {
            $org_membership_table_exists = false;
            $orgm_check = $conn->query("SHOW TABLES LIKE 'member_organizations'");
            if ($orgm_check && $orgm_check->num_rows > 0) $org_membership_table_exists = true;
            if ($org_membership_table_exists) {
                $orgm_q = $conn->query("SELECT o.name FROM member_organizations mo JOIN organizations o ON mo.organization_id = o.id WHERE mo.member_id = ".$member_id);
                if ($orgm_q) {
                    while($o = $orgm_q->fetch_assoc()) $org_memberships[] = $o['name'];
                }
            }
        }
        ?>
        <tr>
            <td><?= htmlspecialchars($u['user_name']) ?></td>
            <td><?= htmlspecialchars($u['crn'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['phone'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['user_email'] ?? $u['member_email'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['class_name'] ?? '-') ?></td>
            <td style="font-size:0.98em;line-height:1.2">
                <?php if ($roles): ?>
                    <span class="text-primary">Roles:</span> <?= htmlspecialchars(implode(', ', $roles)) ?><br>
                <?php endif; ?>
                <?php if ($class_leadership): ?>
                    <span class="text-success"><?= htmlspecialchars($class_leadership) ?></span><br>
                <?php endif; ?>
                <?php if ($org_leadership): ?>
                    <span class="text-info"><?= htmlspecialchars($org_leadership) ?></span><br>
                <?php endif; ?>
                <?php if ($org_memberships): ?>
                    <span class="text-muted">Member:</span> <?= htmlspecialchars(implode(', ', $org_memberships)) ?><br>
                <?php endif; ?>
            </td>
            <td><span class="badge badge-<?= $u['user_status']==='active'?'success':'secondary' ?>"><?= htmlspecialchars(ucfirst($u['user_status'])) ?></span></td>
            <td>
                <?php if ($user_id == 1): ?>
                    <a class="btn btn-sm btn-warning mr-1 disabled" title="Not allowed for Super Admin" tabindex="-1" aria-disabled="true"><i class="fas fa-edit"></i></a>
                    <a class="btn btn-outline-danger btn-sm mr-1 disabled" title="Not allowed for Super Admin" tabindex="-1" aria-disabled="true"><i class="fas fa-user-slash"></i></a>
                    <a class="btn btn-outline-success btn-sm mr-1 disabled" title="Not allowed for Super Admin" tabindex="-1" aria-disabled="true"><i class="fas fa-user-check"></i></a>
                    <a class="btn btn-sm btn-danger disabled" title="Not allowed for Super Admin" tabindex="-1" aria-disabled="true"><i class="fas fa-trash"></i></a>
                <?php else: ?>
                    <?php if ($can_edit): ?>
                        <a href="user_form.php?id=<?= $user_id ?>" class="btn btn-sm btn-warning mr-1" title="Edit"><i class="fas fa-edit"></i></a>
                    <?php endif; ?>
                    <?php if ($u['user_status'] === 'active' && $can_delete): ?>
                    <a href="user_deactivate.php?id=<?= $user_id ?>" class="btn btn-outline-danger btn-sm mr-1" title="De-Activate" onclick="return confirm('Deactivate this user?');"><i class="fas fa-user-slash"></i></a>
                    <?php elseif ($u['user_status'] === 'inactive' && $can_edit): ?>
                    <a href="user_activate.php?id=<?= $user_id ?>" class="btn btn-outline-success btn-sm mr-1" title="Activate" onclick="return confirm('Activate this user?');"><i class="fas fa-user-check"></i></a>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                    <a href="user_delete.php?id=<?= $user_id ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this user?');"><i class="fas fa-trash"></i></a>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; else: ?>
    <tr><td colspan="8" class="text-center">No users found.</td></tr>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
