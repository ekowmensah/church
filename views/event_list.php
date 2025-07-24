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

if (!$is_super_admin && !has_permission('view_event_list')) {
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
$can_add = $is_super_admin || has_permission('create_event');
$can_edit = $is_super_admin || has_permission('edit_event');
$can_delete = $is_super_admin || has_permission('delete_event');
$can_view = true; // Already validated above

// Fetch events with type name
$sql = "SELECT e.*, et.name AS type_name FROM events e LEFT JOIN event_types et ON e.event_type_id = et.id ORDER BY e.event_date DESC, e.event_time DESC";
$events = $conn->query($sql);
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-calendar-alt mr-2"></i>Events</h2>
    <?php if ($can_add): ?>
    <a href="event_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Event</a>
    <?php endif; ?>
  </div>
  <div class="card card-body shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>ID</th>
            <th>Photo</th>
            <th>Name</th>
            <th>Type</th>
            <th>Date</th>
            <th>Time</th>
            <th>Location</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($events && $events->num_rows > 0): while($e = $events->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($e['id']) ?></td>
              <td>
                <?php if (!empty($e['photo']) && file_exists(__DIR__.'/../uploads/events/' . $e['photo'])): ?>
                  <a href="#" class="event-photo-preview" data-img="<?= BASE_URL . '/uploads/events/' . rawurlencode($e['photo']) ?>">
                    <img src="<?= BASE_URL . '/uploads/events/' . rawurlencode($e['photo']) ?>" alt="Event Photo" style="height:48px;width:48px;object-fit:cover;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                  </a>
                <?php else: ?>
                  <span class="text-muted">-</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($e['name']) ?></td>
              <td><?= htmlspecialchars($e['type_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($e['event_date']) ?></td>
              <td><?= htmlspecialchars($e['event_time']) ?></td>
              <td><?= htmlspecialchars($e['location']) ?></td>
              <td>
                <?php if ($can_edit): ?>
                <a href="event_form.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                <a href="event_delete.php?id=<?= $e['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this event?');"><i class="fas fa-trash"></i></a>
                <?php endif; ?>
              </td>
            </tr>
            <?php if (!empty($e['gallery'])):
              $gallery_imgs = json_decode($e['gallery'], true) ?: [];
              if ($gallery_imgs): ?>
            <tr>
              <td></td>
              <td colspan="7">
                <div class="d-flex flex-wrap align-items-center">
                  <?php foreach ($gallery_imgs as $img):
                    $img_path = __DIR__.'/../uploads/events/gallery/' . $img;
                    if (file_exists($img_path)): ?>
                    <img src="<?= BASE_URL . '/uploads/events/gallery/' . rawurlencode($img) ?>" alt="Gallery Image" style="height:44px;width:44px;object-fit:cover;border-radius:6px;margin-right:6px;margin-bottom:6px;">
                  <?php endif; endforeach; ?>
                </div>
              </td>
            </tr>
            <?php endif; endif; ?>
          <?php endwhile; else: ?>
            <tr><td colspan="7" class="text-center">No events found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
<!-- Event Photo Preview Modal -->
<div class="modal fade" id="photoPreviewModal" tabindex="-1" role="dialog" aria-labelledby="photoPreviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="photoPreviewModalLabel">Event Photo</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body text-center">
        <img src="" id="photoPreviewImg" alt="Event Photo" style="max-width:100%;max-height:70vh;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,0.08);">
      </div>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    var modal = $('#photoPreviewModal');
    var img = $('#photoPreviewImg');
    $(document).on('click', '.event-photo-preview', function(e) {
      e.preventDefault();
      var src = $(this).data('img');
      img.attr('src', src);
      modal.modal('show');
    });
    modal.on('hidden.bs.modal', function(){ img.attr('src',''); });
  });
</script>
