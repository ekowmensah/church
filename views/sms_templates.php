<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/sms_templates.php';
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

if (!$is_super_admin && !has_permission('manage_sms_templates')) {
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
$can_add = $is_super_admin || has_permission('create_sms_template');
$can_edit = $is_super_admin || has_permission('edit_sms_template');
$can_delete = $is_super_admin || has_permission('delete_sms_template');
$can_view = true; // Already validated above

// Handle add/edit/delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $id = intval($_POST['id'] ?? 0);
    if ($action === 'add' && $name && $body) {
        $stmt = $conn->prepare('INSERT INTO sms_templates (name, type, body) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $name, $type, $body);
        $stmt->execute();
    } elseif ($action === 'edit' && $id && $name && $body) {
        $stmt = $conn->prepare('UPDATE sms_templates SET name=?, type=?, body=? WHERE id=?');
        $stmt->bind_param('sssi', $name, $type, $body, $id);
        $stmt->execute();
    } elseif ($action === 'delete' && $id) {
        $stmt = $conn->prepare('DELETE FROM sms_templates WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }
    header('Location: sms_templates.php');
    exit;
}

// Fetch all templates
$templates = $conn->query('SELECT * FROM sms_templates ORDER BY name');
?>
<?php ob_start(); ?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">SMS Templates</h2>
        <?php if ($can_add): ?>
        <button class="btn btn-success" data-toggle="modal" data-target="#addModal">Add Template</button>
        <?php endif; ?>
    </div>
    <table class="table table-bordered table-striped">
        <thead class="thead-light">
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Body</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while($tpl = $templates->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($tpl['name']) ?></td>
                <td><?= htmlspecialchars($tpl['type']) ?></td>
                <td><small><?= nl2br(htmlspecialchars($tpl['body'])) ?></small></td>
                <td>
                    <?php if ($can_edit): ?>
                    <button class="btn btn-sm btn-info" data-toggle="modal" data-target="#editModal<?= $tpl['id'] ?>">Edit</button>
                    <?php endif; ?>
                    <?php if ($can_delete): ?>
                    <form method="post" action="" style="display:inline-block" onsubmit="return confirm('Delete this template?');">
                        <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button class="btn btn-sm btn-danger">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <!-- Edit Modal -->
            <?php if ($can_edit): ?>
            <div class="modal fade" id="editModal<?= $tpl['id'] ?>" tabindex="-1" role="dialog">
              <div class="modal-dialog" role="document">
                <div class="modal-content">
                  <form method="post" action="">
                    <div class="modal-header">
                      <h5 class="modal-title">Edit Template</h5>
                      <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    </div>
                    <div class="modal-body">
                      <input type="hidden" name="id" value="<?= $tpl['id'] ?>">
                      <input type="hidden" name="action" value="edit">
                      <div class="form-group">
                        <label>Name</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($tpl['name']) ?>" required>
                      </div>
                      <div class="form-group">
                        <label>Type</label>
                        <input type="text" name="type" class="form-control" value="<?= htmlspecialchars($tpl['type']) ?>">
                      </div>
                      <div class="form-group">
                        <label>Body</label>
                        <textarea name="body" class="form-control" rows="4" required><?= htmlspecialchars($tpl['body']) ?></textarea>
                        <small>Use curly braces for variables, e.g. {name}, {crn}</small>
                      </div>
                    </div>
                    <div class="modal-footer">
                      <button type="submit" class="btn btn-primary">Save</button>
                      <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
            <?php endif; ?>
            <?php endwhile; ?>
        </tbody>
    </table>
    <!-- Add Modal -->
    <?php if ($can_add): ?>
    <div class="modal fade" id="addModal" tabindex="-1" role="dialog">
      <div class="modal-dialog" role="document">
        <div class="modal-content">
          <form method="post" action="">
            <div class="modal-header">
              <h5 class="modal-title">Add Template</h5>
              <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="add">
              <div class="form-group">
                <label>Name</label>
                <input type="text" name="name" class="form-control" required>
              </div>
              <div class="form-group">
                <label>Type</label>
                <input type="text" name="type" class="form-control">
              </div>
              <div class="form-group">
                <label>Body</label>
                <textarea name="body" class="form-control" rows="4" required></textarea>
                <small>Use curly braces for variables, e.g. {name}, {crn}</small>
              </div>
            </div>
            <div class="modal-footer">
              <button type="submit" class="btn btn-primary">Add</button>
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
