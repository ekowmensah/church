<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Fetch all events and registration counts
$sql = "SELECT e.*, et.name AS type_name, COUNT(er.id) AS registrations
        FROM events e
        LEFT JOIN event_types et ON e.event_type_id = et.id
        LEFT JOIN event_registrations er ON er.event_id = e.id
        GROUP BY e.id
        ORDER BY e.event_date DESC, e.event_time DESC";
$events = $conn->query($sql);
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-users mr-2"></i>Event Registrations</h2>
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
            <th>Registrations</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($events && $events->num_rows > 0): while($e = $events->fetch_assoc()): ?>
          <tr>
            <td><?= htmlspecialchars($e['id']) ?></td>
            <td>
              <?php if (!empty($e['photo']) && file_exists(__DIR__.'/../uploads/events/' . $e['photo'])): ?>
                <img src="<?= BASE_URL . '/uploads/events/' . rawurlencode($e['photo']) ?>" alt="Event Photo" style="height:48px;width:48px;object-fit:cover;border-radius:8px;">
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($e['name']) ?></td>
            <td><?= htmlspecialchars($e['type_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($e['event_date']) ?></td>
            <td><?= htmlspecialchars($e['event_time']) ?></td>
            <td><?= htmlspecialchars($e['location']) ?></td>
            <td><?= (int)$e['registrations'] ?></td>
            <td>
              <a href="event_register.php?event_id=<?= $e['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-user-plus"></i> Register</a>
              <a href="event_registration_view.php?event_id=<?= $e['id'] ?>" class="btn btn-sm btn-info"><i class="fas fa-list"></i> View</a>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="9" class="text-center">No events found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
