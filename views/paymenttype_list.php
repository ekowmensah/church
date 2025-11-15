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

if (!$is_super_admin && !has_permission('view_paymenttype_list')) {
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
$can_add = $is_super_admin || has_permission('create_paymenttype');
$can_edit = $is_super_admin || has_permission('edit_paymenttype');
$can_delete = $is_super_admin || has_permission('delete_paymenttype');
$can_view = true; // Already validated above

$result = $conn->query("SELECT id, name, description, active FROM payment_types ORDER BY name ASC");

ob_start();
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800">Payment Types</h1>
    <?php if ($can_add): ?>
<a href="paymenttype_add.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Add Payment Type</a>
<?php endif; ?>
</div>
<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success">Payment type added successfully!</div>
<?php elseif (isset($_GET['updated'])): ?>
    <div class="alert alert-success">Payment type updated successfully!</div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Payment type deleted successfully!</div>
<?php endif; ?>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">List of Payment Types</h6>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-hover" width="100%">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php $i=1; while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['description']) ?></td>
                        <td>
                            <a href="paymenttype_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i> Edit</a>
                            <?php if ($row['active']): ?>
                                <a href="paymenttype_toggle.php?id=<?= $row['id'] ?>&action=disable" class="btn btn-sm btn-secondary" onclick="return confirm('Disable this payment type?');"><i class="fas fa-ban"></i> Disable</a>
                            <?php else: ?>
                                <a href="paymenttype_toggle.php?id=<?= $row['id'] ?>&action=enable" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Enable</a>
                            <?php endif; ?>
                            <?php if (!$row['active']): ?>
                                <a href="paymenttype_delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this payment type?');"><i class="fas fa-trash"></i> Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
