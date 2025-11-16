<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

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
$event_name = $event_type_id = $event_date = $event_time = $location = $description = '';
$errors = [];

// Fetch event types for dropdown
$event_types = $conn->query("SELECT id, name FROM event_types ORDER BY name");

if ($editing) {
    $stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $event_name = $row['name'];
        $event_type_id = $row['event_type_id'];
        $event_date = $row['event_date'];
        $event_time = $row['event_time'];
        $location = $row['location'];
        $description = $row['description'];
    } else {
        $errors[] = 'Event not found.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_name = trim($_POST['event_name'] ?? '');
    $event_type_id = intval($_POST['event_type_id'] ?? 0);
    $event_date = $_POST['event_date'] ?? '';
    $event_time = $_POST['event_time'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Basic validation
    if ($event_name == '') $errors[] = 'Event name is required.';
    if ($event_type_id == 0) $errors[] = 'Event type is required.';
    if ($event_date == '') $errors[] = 'Event date is required.';
    if ($event_time == '') $errors[] = 'Event time is required.';
    if ($location == '') $errors[] = 'Location is required.';

    if (!$errors) {
    // Handle event photo upload
    $photo = '';
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif'];
        if (in_array($ext, $allowed)) {
            if (!is_dir(__DIR__.'/../uploads/events')) mkdir(__DIR__.'/../uploads/events', 0777, true);
            $photo = uniqid('event_').'.'.$ext;
            $target = __DIR__.'/../uploads/events/'.$photo;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $target)) $photo = '';
        }
    } elseif ($editing && !empty($row['photo'])) {
        $photo = $row['photo'];
    }
    // Handle gallery upload
    $gallery = [];
    if (!empty($_FILES['gallery']['name'][0])) {
        if (!is_dir(__DIR__.'/../uploads/events/gallery')) mkdir(__DIR__.'/../uploads/events/gallery', 0777, true);
        foreach ($_FILES['gallery']['tmp_name'] as $i => $tmp) {
            if ($_FILES['gallery']['error'][$i] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['gallery']['name'][$i], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                    $gname = uniqid('gallery_').'.'.$ext;
                    $gtarget = __DIR__.'/../uploads/events/gallery/'.$gname;
                    if (move_uploaded_file($tmp, $gtarget)) $gallery[] = $gname;
                }
            }
        }
    } elseif ($editing && !empty($row['gallery'])) {
        $gallery = json_decode($row['gallery'], true) ?: [];
    }
    $gallery_json = json_encode($gallery);
    if ($editing) {
        $stmt = $conn->prepare("UPDATE events SET name=?, event_type_id=?, event_date=?, event_time=?, location=?, description=?, photo=?, gallery=? WHERE id=?");
        $stmt->bind_param('sissssssi', $event_name, $event_type_id, $event_date, $event_time, $location, $description, $photo, $gallery_json, $id);
        $success = $stmt->execute();
    } else {
        $stmt = $conn->prepare("INSERT INTO events (name, event_type_id, event_date, event_time, location, description, photo, gallery) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sissssss', $event_name, $event_type_id, $event_date, $event_time, $location, $description, $photo, $gallery_json);
        $success = $stmt->execute();
    }
    if ($success) {
            header('Location: event_list.php?success=1');
            exit;
        } else {
            $errors[] = 'Error saving event: ' . htmlspecialchars($conn->error);
        }
    }
}

ob_start();
?>
<div class="container mt-4">
  <h2><?= $editing ? 'Edit Event' : 'Add Event' ?></h2>
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post" class="card card-body shadow-sm" autocomplete="off" enctype="multipart/form-data">
    <div class="form-group">
      <label for="event_name">Event Name <span class="text-danger">*</span></label>
      <input type="text" class="form-control" name="event_name" id="event_name" value="<?= htmlspecialchars($event_name) ?>" required>
    </div>
    <div class="form-group">
      <label for="event_type_id">Event Type <span class="text-danger">*</span></label>
      <select class="form-control" name="event_type_id" id="event_type_id" required>
        <option value="">Select type</option>
        <?php if ($event_types) while($et = $event_types->fetch_assoc()): ?>
          <option value="<?= $et['id'] ?>" <?= $event_type_id == $et['id'] ? 'selected' : '' ?>><?= htmlspecialchars($et['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group col-md-6">
        <label for="event_date">Date <span class="text-danger">*</span></label>
        <input type="date" class="form-control" name="event_date" id="event_date" value="<?= htmlspecialchars($event_date) ?>" required>
      </div>
      <div class="form-group col-md-6">
        <label for="event_time">Time <span class="text-danger">*</span></label>
        <input type="time" class="form-control" name="event_time" id="event_time" value="<?= htmlspecialchars($event_time) ?>" required>
      </div>
    </div>
    <div class="form-group">
      <label for="location">Location <span class="text-danger">*</span></label>
      <input type="text" class="form-control" name="location" id="location" value="<?= htmlspecialchars($location) ?>" required>
    </div>
    <div class="form-group">
      <label for="description">Description</label>
      <textarea class="form-control" name="description" id="description" rows="3"><?= htmlspecialchars($description) ?></textarea>
    </div>
    <div class="form-group">
      <label for="photo">Event Photo</label>
      <?php if ($editing && !empty($row['photo'])): ?>
        <div class="mb-2"><img src="<?= BASE_URL . '/uploads/events/' . htmlspecialchars($row['photo']) ?>" alt="Event Photo" style="height:80px;object-fit:cover;border-radius:8px;"></div>
      <?php endif; ?>
      <input type="file" class="form-control-file" name="photo" id="photo" accept="image/*">
    </div>
    <div class="form-group">
      <label for="gallery">Event Photo Gallery</label>
      <?php if ($editing && !empty($row['gallery'])):
        $gallery_imgs = json_decode($row['gallery'], true) ?: [];
        if ($gallery_imgs): ?>
        <div class="mb-2 d-flex flex-wrap">
          <?php foreach ($gallery_imgs as $img): ?>
            <img src="<?= BASE_URL . '/uploads/events/gallery/' . htmlspecialchars($img) ?>" alt="Gallery Image" style="height:60px;width:60px;object-fit:cover;border-radius:6px;margin-right:6px;margin-bottom:6px;">
          <?php endforeach; ?>
        </div>
      <?php endif; endif; ?>
      <input type="file" class="form-control-file" name="gallery[]" id="gallery" accept="image/*" multiple>
      <small class="form-text text-muted">You can select multiple images for the gallery.</small>
    </div>
    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i> <?= $editing ? 'Update Event' : 'Add Event' ?></button>
    <a href="event_list.php" class="btn btn-secondary ml-2">Cancel</a>
  </form>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
