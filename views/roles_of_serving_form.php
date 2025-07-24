<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
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
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($name == '') $errors[] = 'Role name is required.';
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
            header('Location: roles_of_serving_list.php?success=1');
            exit;
        } else {
            $errors[] = 'Error saving role: ' . htmlspecialchars($conn->error);
        }
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
