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

if (!$is_super_admin && !has_permission('view_event_type_list')) {
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
$can_add = $is_super_admin || has_permission('create_event_type');
$can_edit = $is_super_admin || has_permission('edit_event_type');
$can_delete = $is_super_admin || has_permission('delete_event_type');
$can_view = true; // Already validated above

$types = $conn->query("SELECT * FROM event_types ORDER BY name");
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-tags mr-2"></i>Event Types</h2>
    <?php if ($can_add): ?>
    <a href="eventtype_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Event Type</a>
    <?php endif; ?>
  </div>
  <div class="card card-body shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($types && $types->num_rows > 0): while($t = $types->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($t['id']) ?></td>
              <td><?= htmlspecialchars($t['name']) ?></td>
              <td>
                <?php if ($can_edit): ?>
                <a href="eventtype_form.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                <a href="eventtype_delete.php?id=<?= $t['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this event type?');"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="3" class="text-center">No event types found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
