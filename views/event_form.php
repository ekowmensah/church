<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/EventManagementService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$eventService = EventManagementService::fromSession($conn);
$isSuperAdmin = $eventService->isSuperAdmin();
$id = max(0, (int) ($_GET['id'] ?? 0));
$editing = $id > 0;
$requiredPermission = $editing ? 'edit_event' : 'create_event';
if (!$isSuperAdmin && !has_permission($requiredPermission)) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to manage this event.</p></div>';
    exit;
}

$event = $editing ? $eventService->getEvent($id, true) : null;
if ($editing && !$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">Event not found in your church.</div>';
    exit;
}

$form = [
    'church_id' => (int) ($event['church_id'] ?? $eventService->getChurchId() ?? 0),
    'event_name' => (string) ($event['name'] ?? ''),
    'event_type_id' => (int) ($event['event_type_id'] ?? 0),
    'event_date' => (string) ($event['event_date'] ?? ''),
    'event_time' => substr((string) ($event['event_time'] ?? ''), 0, 5),
    'location' => (string) ($event['location'] ?? ''),
    'description' => (string) ($event['description'] ?? ''),
    'registration_enabled' => (int) ($event['registration_enabled'] ?? 1),
    'registration_deadline' => !empty($event['registration_deadline'])
        ? date('Y-m-d\TH:i', strtotime($event['registration_deadline'])) : '',
    'registration_capacity' => (string) ($event['registration_capacity'] ?? ''),
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your session token expired. Refresh the page and try again.';
    }
    $form['church_id'] = $isSuperAdmin
        ? max(0, (int) ($_POST['church_id'] ?? 0))
        : (int) ($eventService->getChurchId() ?? 0);
    $form['event_name'] = trim((string) ($_POST['event_name'] ?? ''));
    $form['event_type_id'] = max(0, (int) ($_POST['event_type_id'] ?? 0));
    $form['event_date'] = trim((string) ($_POST['event_date'] ?? ''));
    $form['event_time'] = trim((string) ($_POST['event_time'] ?? ''));
    $form['location'] = trim((string) ($_POST['location'] ?? ''));
    $form['description'] = trim((string) ($_POST['description'] ?? ''));
    $form['registration_enabled'] = isset($_POST['registration_enabled']) ? 1 : 0;
    $form['registration_deadline'] = trim((string) ($_POST['registration_deadline'] ?? ''));
    $form['registration_capacity'] = trim((string) ($_POST['registration_capacity'] ?? ''));

    if ($form['church_id'] <= 0) $errors[] = 'Choose the church that owns this event.';
    if ($form['event_name'] === '') $errors[] = 'Event name is required.';
    if ($form['event_type_id'] <= 0) $errors[] = 'Event type is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['event_date'])) $errors[] = 'Enter a valid event date.';
    if (!preg_match('/^\d{2}:\d{2}$/', $form['event_time'])) $errors[] = 'Enter a valid event time.';
    if ($form['location'] === '') $errors[] = 'Location is required.';
    if ($form['registration_capacity'] !== ''
        && (!ctype_digit($form['registration_capacity']) || (int) $form['registration_capacity'] < 1)) {
        $errors[] = 'Registration capacity must be a positive whole number or blank.';
    }
    $deadlineSql = null;
    if ($form['registration_deadline'] !== '') {
        $deadline = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $form['registration_deadline']);
        if (!$deadline || $deadline->format('Y-m-d\TH:i') !== $form['registration_deadline']) {
            $errors[] = 'Enter a valid registration deadline.';
        } else {
            $deadlineSql = $deadline->format('Y-m-d H:i:s');
        }
    }

    $churchCheck = $conn->prepare('SELECT id FROM churches WHERE id = ? LIMIT 1');
    $churchCheck->bind_param('i', $form['church_id']);
    $churchCheck->execute();
    if (!$churchCheck->get_result()->fetch_assoc()) $errors[] = 'The selected church does not exist.';
    $churchCheck->close();

    $photo = (string) ($event['photo'] ?? '');
    $gallery = [];
    if (!empty($event['gallery'])) {
        $decodedGallery = json_decode($event['gallery'], true);
        if (is_array($decodedGallery)) $gallery = $decodedGallery;
    }

    $storeImage = static function (array $upload, string $directory, string $prefix) use (&$errors): ?string {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            $errors[] = 'An event image could not be uploaded.';
            return null;
        }
        if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'Each event image must be 5 MB or smaller.';
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            $errors[] = 'Event images must be JPEG, PNG, or WebP files.';
            return null;
        }
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $errors[] = 'The event upload directory is unavailable.';
            return null;
        }
        $name = $prefix . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $name)) {
            $errors[] = 'The event image could not be stored.';
            return null;
        }
        return $name;
    };

    if (!$errors && isset($_FILES['photo'])) {
        $uploadedPhoto = $storeImage($_FILES['photo'], __DIR__ . '/../uploads/events', 'event_');
        if ($uploadedPhoto !== null) $photo = $uploadedPhoto;
    }
    if (!$errors && !empty($_FILES['gallery']['name'][0])) {
        foreach ($_FILES['gallery']['name'] as $index => $name) {
            $upload = [
                'name' => $name,
                'tmp_name' => $_FILES['gallery']['tmp_name'][$index] ?? '',
                'error' => $_FILES['gallery']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['gallery']['size'][$index] ?? 0,
            ];
            $stored = $storeImage($upload, __DIR__ . '/../uploads/events/gallery', 'gallery_');
            if ($stored !== null) $gallery[] = $stored;
        }
    }

    if (!$errors) {
        $capacity = $form['registration_capacity'] === '' ? null : (int) $form['registration_capacity'];
        $galleryJson = json_encode(array_values(array_unique($gallery)));
        $actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        if ($editing) {
            $stmt = $conn->prepare(
                'UPDATE events SET church_id = ?, name = ?, event_type_id = ?, event_date = ?,
                    event_time = ?, location = ?, description = ?, photo = ?, gallery = ?,
                    registration_enabled = ?, registration_deadline = ?, registration_capacity = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'isissssssisii',
                $form['church_id'], $form['event_name'], $form['event_type_id'],
                $form['event_date'], $form['event_time'], $form['location'],
                $form['description'], $photo, $galleryJson,
                $form['registration_enabled'], $deadlineSql, $capacity, $id
            );
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO events
                    (church_id, name, event_type_id, event_date, event_time,
                     location, description, photo, gallery, registration_enabled,
                     registration_deadline, registration_capacity, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'isissssssisii',
                $form['church_id'], $form['event_name'], $form['event_type_id'],
                $form['event_date'], $form['event_time'], $form['location'],
                $form['description'], $photo, $galleryJson,
                $form['registration_enabled'], $deadlineSql, $capacity, $actorUserId
            );
        }
        $stmt->execute();
        $savedEventId = $editing ? $id : (int) $stmt->insert_id;
        $stmt->close();
        $review = $conn->prepare(
            'UPDATE event_ownership_review
                SET resolved = 1, resolved_by_user_id = ?, resolved_at = NOW()
              WHERE event_id = ? AND resolved = 0'
        );
        $review->bind_param('ii', $actorUserId, $savedEventId);
        $review->execute();
        $review->close();
        header('Location: event_list.php?success=1');
        exit;
    }
}

$eventTypes = $conn->query('SELECT id, name FROM event_types ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$churches = $isSuperAdmin
    ? $conn->query('SELECT id, name FROM churches ORDER BY name')->fetch_all(MYSQLI_ASSOC)
    : [];

ob_start();
?>
<div class="container mt-4">
  <h2><?= $editing ? 'Edit Event' : 'Add Event' ?></h2>
  <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" class="card card-body shadow-sm" enctype="multipart/form-data" autocomplete="off">
    <?= csrf_input() ?>
    <?php if ($isSuperAdmin): ?><div class="form-group"><label>Church <span class="text-danger">*</span></label><select class="form-control" name="church_id" required><option value="">Choose church</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $form['church_id'] === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="form-group"><label>Event Name <span class="text-danger">*</span></label><input class="form-control" name="event_name" maxlength="255" value="<?= htmlspecialchars($form['event_name']) ?>" required></div>
    <div class="form-group"><label>Event Type <span class="text-danger">*</span></label><select class="form-control" name="event_type_id" required><option value="">Select type</option><?php foreach ($eventTypes as $type): ?><option value="<?= (int) $type['id'] ?>" <?= $form['event_type_id'] === (int) $type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div>
    <div class="form-row"><div class="form-group col-md-6"><label>Date</label><input type="date" class="form-control" name="event_date" value="<?= htmlspecialchars($form['event_date']) ?>" required></div><div class="form-group col-md-6"><label>Time</label><input type="time" class="form-control" name="event_time" value="<?= htmlspecialchars($form['event_time']) ?>" required></div></div>
    <div class="form-group"><label>Location</label><input class="form-control" name="location" maxlength="255" value="<?= htmlspecialchars($form['location']) ?>" required></div>
    <div class="form-group"><label>Description</label><textarea class="form-control" name="description" rows="3"><?= htmlspecialchars($form['description']) ?></textarea></div>
    <div class="card bg-light border-0 mb-3"><div class="card-body"><h6>Registration controls</h6><div class="custom-control custom-checkbox mb-3"><input type="checkbox" class="custom-control-input" id="registration_enabled" name="registration_enabled" value="1" <?= $form['registration_enabled'] ? 'checked' : '' ?>><label class="custom-control-label" for="registration_enabled">Allow member registration</label></div><div class="form-row"><div class="form-group col-md-6"><label>Registration deadline</label><input type="datetime-local" class="form-control" name="registration_deadline" value="<?= htmlspecialchars($form['registration_deadline']) ?>"></div><div class="form-group col-md-6"><label>Capacity</label><input type="number" min="1" class="form-control" name="registration_capacity" value="<?= htmlspecialchars($form['registration_capacity']) ?>" placeholder="Unlimited"></div></div></div></div>
    <div class="form-group"><label>Event Photo</label><input type="file" class="form-control-file" name="photo" accept="image/jpeg,image/png,image/webp"><small class="text-muted">JPEG, PNG or WebP; maximum 5 MB.</small></div>
    <div class="form-group"><label>Gallery</label><input type="file" class="form-control-file" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple></div>
    <div><button class="btn btn-success"><i class="fas fa-save mr-1"></i><?= $editing ? 'Update Event' : 'Add Event' ?></button><a href="event_list.php" class="btn btn-secondary ml-2">Cancel</a></div>
  </form>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
