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

$is_super_admin = is_super_admin();

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
$successMessage = (string) ($_SESSION['event_type_success'] ?? '');
$errorMessage = (string) ($_SESSION['event_type_error'] ?? '');
unset($_SESSION['event_type_success'], $_SESSION['event_type_error']);
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-tags mr-2"></i>Event Types</h2>
    <?php if ($can_add): ?>
    <a href="eventtype_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Event Type</a>
    <?php endif; ?>
  </div>
  <?php if ($successMessage !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
  <?php endif; ?>
  <?php if ($errorMessage !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
  <?php endif; ?>
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
                <form method="post" action="eventtype_delete.php" class="d-inline"
                      onsubmit="return confirm('Delete this event type? This is allowed only when no event uses it.');">
                  <?= csrf_input() ?>
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                    <i class="fas fa-trash"></i>
                  </button>
                </form>
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
