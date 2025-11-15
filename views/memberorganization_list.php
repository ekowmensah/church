<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_organization_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}


$sql = "SELECT mo.id, o.name AS organization, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS member, mo.role, mo.joined_at
        FROM member_organizations mo
        LEFT JOIN organizations o ON mo.organization_id = o.id
        LEFT JOIN members m ON mo.member_id = m.id
        ORDER BY mo.joined_at DESC, mo.id DESC";
$result = $conn->query($sql);

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Member Organizations</h1>
    <a href="#" class="btn btn-primary btn-sm disabled"><i class="fas fa-plus"></i> Add Member Organization</a>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">List of Member Organizations</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>ID</th>
                        <th>Organization</th>
                        <th>Member</th>
                        <th>Role</th>
                        <th>Joined At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['organization']) ?></td>
                        <td><?= htmlspecialchars($row['member']) ?></td>
                        <td><?= htmlspecialchars($row['role']) ?></td>
                        <td><?= htmlspecialchars($row['joined_at']) ?></td>
                        <td>
                            <a href="#" class="btn btn-sm btn-warning disabled"><i class="fas fa-edit"></i> Edit</a>
                            <a href="#" class="btn btn-sm btn-danger disabled"><i class="fas fa-trash"></i> Delete</a>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
