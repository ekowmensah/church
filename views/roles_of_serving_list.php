<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Fetch all roles
$roles = $conn->query("SELECT * FROM roles_of_serving ORDER BY name ASC");
ob_start();
?>
<div class="container mt-4">
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
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php $i=1; if ($roles && $roles->num_rows > 0): while($r = $roles->fetch_assoc()): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['description']) ?></td>
            <td>
              <a href="roles_of_serving_form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-edit"></i> Edit</a>
              <form method="post" action="roles_of_serving_delete.php" style="display:inline;" onsubmit="return confirm('Delete this role?');">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> Delete</button>
              </form>
            </td>
          </tr>
        <?php endwhile; else: ?>
          <tr><td colspan="4" class="text-center">No roles found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
