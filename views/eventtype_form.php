<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_event_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = $id > 0;
$name = '';
$errors = [];
if ($editing) {
    $stmt = $conn->prepare("SELECT * FROM event_types WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $name = $row['name'];
    } else {
        $errors[] = 'Event type not found.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name == '') $errors[] = 'Name is required.';
    // Check for duplicate name
    $stmt = $conn->prepare("SELECT id FROM event_types WHERE name = ? AND id <> ?");
    $stmt->bind_param('si', $name, $id);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) $errors[] = 'Event type name must be unique.';
    if (!$errors) {
        if ($editing) {
            $stmt = $conn->prepare("UPDATE event_types SET name=? WHERE id=?");
            $stmt->bind_param('si', $name, $id);
            $success = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO event_types (name) VALUES (?)");
            $stmt->bind_param('s', $name);
            $success = $stmt->execute();
        }
        if ($success) {
            header('Location: eventtype_list.php?success=1');
            exit;
        } else {
            $errors[] = 'Error saving event type: ' . htmlspecialchars($conn->error);
        }
    }
}
ob_start();
?>
<div class="container mt-4">
  <h2><?= $editing ? 'Edit Event Type' : 'Add Event Type' ?></h2>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post" class="card card-body shadow-sm" autocomplete="off">
    <div class="form-group">
      <label for="name">Name <span class="text-danger">*</span></label>
      <input type="text" class="form-control" name="name" id="name" value="<?= htmlspecialchars($name) ?>" required>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> <?= $editing ? 'Update' : 'Add' ?></button>
    <a href="eventtype_list.php" class="btn btn-secondary ml-2">Cancel</a>
  </form>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
