<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';
require_once __DIR__.'/../services/EventManagementService.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$member_id = intval($_SESSION['member_id']);
$event_service = EventManagementService::fromSession($conn);
$church_id = (int) ($event_service->getChurchId() ?? 0);
$sql = "SELECT e.*, er.registered_at, er.registration_status
          FROM event_registrations er
          JOIN events e ON er.event_id = e.id
         WHERE er.member_id = ? AND e.church_id = ? AND e.status = 'active'
           AND er.registration_status IN ('registered', 'attended', 'no_show')
         ORDER BY e.event_date DESC, e.event_time DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ii', $member_id, $church_id);
$stmt->execute();
$res = $stmt->get_result();

ob_start();
?>
<div class="container mt-4">
  <div class="card card-body shadow-sm">
    <h3 class="mb-3"><i class="fas fa-calendar-check mr-1"></i> My Registered Events</h3>
    <?php if ($res->num_rows > 0): ?>
      <table class="table table-bordered table-striped">
        <thead>
          <tr>
            <th>Event</th>
            <th>Date</th>
            <th>Time</th>
            <th>Location</th>
            <th>Registered At</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php while ($row = $res->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($row['name']) ?></td>
              <td><?= htmlspecialchars($row['event_date']) ?></td>
              <td><?= htmlspecialchars(substr($row['event_time'],0,5)) ?></td>
              <td><?= htmlspecialchars($row['location']) ?></td>
              <td><?= htmlspecialchars($row['registered_at']) ?></td>
              <td><a href="event_register.php?event_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary">View/Unregister</a></td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    <?php else: ?>
      <div class="alert alert-info">You have not registered for any events yet.</div>
    <?php endif; ?>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
