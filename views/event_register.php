<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$is_super_admin = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
$member_id = $_SESSION['member_id'] ?? null;
if ($is_super_admin) {
    // Allow super admin to pick a member to register as
    $members = $conn->query("SELECT id, crn, last_name, first_name FROM members ORDER BY last_name, first_name");
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $member_id = intval($_POST['member_id'] ?? 0);
    }
}
$errors = [];
$success = false;
// Fetch event details
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) {
    $errors[] = 'Event not found.';
}
// Check if already registered
if ($member_id && $event) {
    $stmt = $conn->prepare("SELECT id FROM event_registrations WHERE event_id = ? AND member_id = ?");
    $stmt->bind_param('ii', $event_id, $member_id);
    $stmt->execute();
    $already_registered = $stmt->get_result()->fetch_assoc();
} else {
    $already_registered = false;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event && $member_id) {
    if (isset($_POST['unregister']) && $already_registered) {
        // Unregister
        $stmt = $conn->prepare("DELETE FROM event_registrations WHERE event_id = ? AND member_id = ?");
        $stmt->bind_param('ii', $event_id, $member_id);
        if ($stmt->execute()) {
            $success = 'unregister';
            $already_registered = false;
        } else {
            $errors[] = 'Un-registration failed: ' . htmlspecialchars($conn->error);
        }
    } elseif (!$already_registered) {
        // Register
        $stmt = $conn->prepare("INSERT INTO event_registrations (event_id, member_id, registered_at) VALUES (?, ?, NOW())");
        $stmt->bind_param('ii', $event_id, $member_id);
        if ($stmt->execute()) {
            $success = true;
            $already_registered = true;
        } else {
            $errors[] = 'Registration failed: ' . htmlspecialchars($conn->error);
        }
    }
}
ob_start();
?>
<div class="container mt-4">
  <div class="card card-body shadow-sm">
    <?php if ($event): ?>
      <h3 class="mb-3">Register for Event: <?= htmlspecialchars($event['name']) ?></h3>
      <div class="mb-3">
        <?php if (!empty($event['photo']) && file_exists(__DIR__.'/../uploads/events/' . $event['photo'])): ?>
          <a href="<?= BASE_URL . '/uploads/events/' . rawurlencode($event['photo']) ?>" target="_blank" data-toggle="modal" data-target="#eventPhotoModal">
            <img src="<?= BASE_URL . '/uploads/events/' . rawurlencode($event['photo']) ?>" alt="Event Photo" style="max-height:120px;object-fit:cover;border-radius:10px;cursor:pointer;">
          </a>
          <!-- Modal for full image (Bootstrap 4/5)-->
          <div class="modal fade" id="eventPhotoModal" tabindex="-1" role="dialog" aria-labelledby="eventPhotoModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="eventPhotoModalLabel">Event Image</h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
                <div class="modal-body text-center">
                  <img src="<?= BASE_URL . '/uploads/events/' . rawurlencode($event['photo']) ?>" alt="Event Photo" style="max-width:100%;max-height:70vh;">
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <?php
        // Show gallery images if gallery is present (JSON array or comma-separated)
        $gallery_imgs = [];
        if (!empty($event['gallery'])) {
          $gallery_imgs = json_decode($event['gallery'], true);
          if (!is_array($gallery_imgs)) {
            // fallback: treat as comma-separated
            $gallery_imgs = array_map('trim', explode(',', $event['gallery']));
          }
        }
        if ($gallery_imgs): ?>
          <div class="mt-2">
            <strong>Gallery:</strong><br>
            <?php foreach ($gallery_imgs as $g):
              if ($g && file_exists(__DIR__.'/../uploads/events/gallery/' . $g)):
            ?>
              <a href="<?= BASE_URL . '/uploads/events/gallery/' . rawurlencode($g) ?>" target="_blank">
                <img src="<?= BASE_URL . '/uploads/events/gallery/' . rawurlencode($g) ?>" alt="Gallery Image" style="height:50px;width:50px;object-fit:cover;border-radius:6px;margin:2px;cursor:pointer;">
              </a>
            <?php endif; endforeach; ?>
          </div>
        <?php endif; ?>
        <div><strong>Date:</strong> <?= htmlspecialchars($event['event_date']) ?> <strong>Time:</strong> <?= htmlspecialchars($event['event_time']) ?></div>
        <div><strong>Location:</strong> <?= htmlspecialchars($event['location']) ?></div>
        <div><strong>Description:</strong> <?= nl2br(htmlspecialchars($event['description'])) ?></div>
      </div>
      <?php if ($already_registered): ?>
        <div class="alert alert-info">You are already registered for this event.</div>
        <form method="post" class="mt-2">
          <?php if ($is_super_admin): ?>
            <input type="hidden" name="member_id" value="<?= htmlspecialchars($member_id) ?>">
          <?php endif; ?>
          <button type="submit" name="unregister" value="1" class="btn btn-danger"><i class="fas fa-user-minus mr-1"></i> Unregister</button>
        </form>
      <?php elseif ($success === 'unregister'): ?>
        <div class="alert alert-success">You have been unregistered from this event.</div>
        <form method="post">
          <?php if ($is_super_admin): ?>
            <div class="form-group">
              <label for="member_id">Register as Member:</label>
              <select name="member_id" id="member_id" class="form-control" required>
                <option value="">Select member</option>
                <?php if ($members) while($m = $members->fetch_assoc()): ?>
                  <option value="<?= $m['id'] ?>" <?= $member_id == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['crn']." - ".$m['last_name'].", ".$m['first_name']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> Register</button>
          <a href="event_registration_list.php" class="btn btn-secondary ml-2">Back to Events</a>
        </form>
      <?php elseif ($success): ?>
        <div class="alert alert-success">Registration successful!</div>
      <?php else: ?>
        <form method="post">
          <?php if ($is_super_admin): ?>
            <div class="form-group">
              <label for="member_id">Register as Member:</label>
              <select name="member_id" id="member_id" class="form-control" required>
                <option value="">Select member</option>
                <?php if ($members) while($m = $members->fetch_assoc()): ?>
                  <option value="<?= $m['id'] ?>" <?= $member_id == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['crn']." - ".$m['last_name'].", ".$m['first_name']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i> Register</button>
          <a href="event_registration_list.php" class="btn btn-secondary ml-2">Back to Events</a>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-danger">Event not found.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert alert-danger mt-3">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
