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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$name = $description = '';
$errors = [];
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM roles_of_serving WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $role = $stmt->get_result()->fetch_assoc();
    if ($role) {
        $name = $role['name'];
        $description = $role['description'];
    } else {
        $errors[] = 'Role not found.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? max(0, intval($_POST['id'])) : $id;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Refresh the page and try again.';
    }
    if ($name === '') {
        $errors[] = 'Role name is required.';
    }
    if (strlen($name) > 100) {
        $errors[] = 'Role name cannot exceed 100 characters.';
    }

    if (!$errors) {
        $duplicate = $conn->prepare('SELECT id FROM roles_of_serving WHERE name = ? AND id <> ? LIMIT 1');
        $duplicate->bind_param('si', $name, $id);
        $duplicate->execute();
        if ($duplicate->get_result()->fetch_assoc()) {
            $errors[] = 'A role with this name already exists.';
        }
        $duplicate->close();
    }

    if (!$errors) {
        if ($id) {
            $stmt = $conn->prepare("UPDATE roles_of_serving SET name=?, description=? WHERE id=?");
            $stmt->bind_param('ssi', $name, $description, $id);
            $success = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO roles_of_serving (name, description) VALUES (?, ?)");
            $stmt->bind_param('ss', $name, $description);
            $success = $stmt->execute();
        }
        if ($success) {
            $_SESSION['roles_of_serving_success'] = $id ? 'Role updated successfully.' : 'Role added successfully.';
            header('Location: roles_of_serving_list.php');
            exit;
        } else {
            $errors[] = 'Error saving role: ' . ($stmt->error ?: $conn->error);
        }
        $stmt->close();
    }
}
ob_start();
?>
<div class="container mt-4">
  <h3><?= $id ? 'Edit Role of Serving' : 'Add Role of Serving' ?></h3>
  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>
  <form method="post" class="card card-body shadow-sm">
    <?= csrf_input() ?>
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="form-group">
      <label for="name">Role Name <span class="text-danger">*</span></label>
      <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($name) ?>" required>
    </div>
    <div class="form-group">
      <label for="description">Description</label>
      <textarea class="form-control" name="description" id="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> Save</button>
    <a href="roles_of_serving_list.php" class="btn btn-secondary ml-2">Cancel</a>
  </form>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
