<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/csrf.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('manage_roles')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$successMessage = $_SESSION['roles_of_serving_success'] ?? '';
$errorMessage = $_SESSION['roles_of_serving_error'] ?? '';
unset($_SESSION['roles_of_serving_success'], $_SESSION['roles_of_serving_error']);
$repairReviewCount = 0;
$reviewResult = $conn->query(
    "SELECT COUNT(*) AS total
       FROM role_assignment_repair_review review_item
       INNER JOIN members m ON m.id = review_item.member_id
      WHERE review_item.resolved = 0"
);
if ($reviewResult) {
    $repairReviewCount = (int) $reviewResult->fetch_assoc()['total'];
    $reviewResult->free();
}

// Fetch all roles and include assignment counts for safe deletion controls.
$roles = $conn->query(
    "SELECT r.*, COUNT(mrs.member_id) AS assignment_count
       FROM roles_of_serving r
       LEFT JOIN member_roles_of_serving mrs ON mrs.role_id = r.id
      GROUP BY r.id, r.name, r.description, r.created_at, r.updated_at
      ORDER BY r.name ASC"
);
ob_start();
?>
<div class="container mt-4">
  <?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errorMessage): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
  <?php endif; ?>
  <?php if ($repairReviewCount > 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
      <span><?= $repairReviewCount ?> legacy member-role assignment(s) require review.</span>
      <a class="btn btn-sm btn-warning" href="roles_of_serving_repair_review.php">Review assignments</a>
    </div>
  <?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Roles of Serving</h3>
    <a href="roles_of_serving_form.php" class="btn btn-success"><i class="fas fa-plus"></i> Add Role</a>
  </div>
  <div class="card card-body shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>#</th>
            <th>Name</th>
            <th>Description</th>
            <th>Members</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php $i=1; if ($roles && $roles->num_rows > 0): while($r = $roles->fetch_assoc()): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['description']) ?></td>
            <td><?= (int) $r['assignment_count'] ?></td>
            <td>
              <a href="roles_of_serving_form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i> Edit</a>
              <form method="post" action="roles_of_serving_delete.php" style="display:inline;" onsubmit="return confirm('Delete this role?');">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger" <?= (int) $r['assignment_count'] > 0 ? 'disabled title="Remove member assignments before deleting this role."' : '' ?>><i class="fas fa-trash"></i> Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="5" class="text-center">No roles found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
