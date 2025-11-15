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
$event_id = $member_id = $registered_at = $notes = '';
$errors = [];

// Fetch events and members for dropdowns
$events = $conn->query("SELECT id, name FROM events ORDER BY event_date DESC");
$members = $conn->query("SELECT id, name FROM members WHERE status = 'active' ORDER BY name");

if ($editing) {
    $stmt = $conn->prepare("SELECT * FROM event_registrations WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $event_id = $row['event_id'];
        $member_id = $row['member_id'];
        $registered_at = $row['registered_at'];
        $notes = $row['notes'];
    } else {
        $errors[] = 'Registration not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = intval($_POST['event_id'] ?? 0);
    $member_id = intval($_POST['member_id'] ?? 0);
    $registered_at = $_POST['registered_at'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($event_id == 0) $errors[] = 'Event is required.';
    if ($member_id == 0) $errors[] = 'Member is required.';
    if ($registered_at == '') $errors[] = 'Registration date is required.';

    if (!$errors) {
        if ($editing) {
            $stmt = $conn->prepare("UPDATE event_registrations SET event_id=?, member_id=?, registered_at=?, notes=? WHERE id=?");
            $stmt->bind_param('iisss', $event_id, $member_id, $registered_at, $notes, $id);
            $success = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO event_registrations (event_id, member_id, registered_at, notes) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('iiss', $event_id, $member_id, $registered_at, $notes);
            $success = $stmt->execute();
        }
        if ($success) {
            header('Location: eventregistration_list.php?success=1');
            exit;
        } else {
            $errors[] = 'Error saving registration: ' . htmlspecialchars($conn->error);
        }
    }
}

ob_start();
?>
<div class="container mt-4">
  <h2><?= $editing ? 'Edit Event Registration' : 'Add Event Registration' ?></h2>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post" class="card card-body shadow-sm" autocomplete="off">
    <div class="form-group">
      <label for="event_id">Event <span class="text-danger">*</span></label>
      <select class="form-control" name="event_id" id="event_id" required>
        <option value="">Select event</option>
        <?php if ($events) while($ev = $events->fetch_assoc()): ?>
          <option value="<?= $ev['id'] ?>" <?= $event_id == $ev['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ev['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="member_id">Member <span class="text-danger">*</span></label>
      <select class="form-control" name="member_id" id="member_id" required>
        <option value="">Select member</option>
        <?php if ($members) while($m = $members->fetch_assoc()): ?>
          <option value="<?= $m['id'] ?>" <?= $member_id == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-group">
      <label for="registered_at">Registration Date <span class="text-danger">*</span></label>
      <input type="date" class="form-control" name="registered_at" id="registered_at" value="<?= htmlspecialchars($registered_at ?: date('Y-m-d')) ?>" required>
    </div>
    <div class="form-group">
      <label for="notes">Notes</label>
      <textarea class="form-control" name="notes" id="notes" rows="2"><?= htmlspecialchars($notes) ?></textarea>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> <?= $editing ? 'Update Registration' : 'Add Registration' ?></button>
    <a href="eventregistration_list.php" class="btn btn-secondary ml-2">Cancel</a>
  </form>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
