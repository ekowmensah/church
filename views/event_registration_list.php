<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/EventManagementService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$eventService = EventManagementService::fromSession($conn);
if (!$eventService->isSuperAdmin() && !has_permission('manage_event_registrations')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to manage event registrations.</p></div>';
    exit;
}
$events = $eventService->listEvents(true);
ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="mb-1"><i class="fas fa-user-check mr-2"></i>Event Registrations</h2><small class="text-muted">Registration and attendance totals for events in your authorized church.</small></div><a href="event_list.php" class="btn btn-outline-secondary">Back to Events</a></div>
  <div class="card card-body shadow-sm"><div class="table-responsive"><table class="table table-bordered table-hover">
    <thead class="thead-light"><tr><th>Event</th><th>Date</th><th>Status</th><th>Registered</th><th>Attended</th><th>No-show</th><th>Cancelled</th><th></th></tr></thead>
    <tbody><?php if ($events): foreach ($events as $event): ?>
      <tr><td><strong><?= htmlspecialchars($event['name']) ?></strong><br><small><?= htmlspecialchars($event['location']) ?></small></td><td><?= htmlspecialchars($event['event_date']) ?><br><small><?= htmlspecialchars(substr($event['event_time'], 0, 5)) ?></small></td><td><span class="badge badge-<?= $event['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($event['status'])) ?></span></td><td><?= number_format((int) $event['active_registrations'] - (int) $event['attended_count']) ?></td><td><?= number_format((int) $event['attended_count']) ?></td><td><?= number_format((int) $event['no_show_count']) ?></td><td><?= number_format((int) $event['cancelled_registrations']) ?></td><td><a class="btn btn-sm btn-primary" href="event_registration_view.php?event_id=<?= (int) $event['id'] ?>"><i class="fas fa-list mr-1"></i>Manage</a></td></tr>
    <?php endforeach; else: ?><tr><td colspan="8" class="text-center text-muted">No scoped events are available.</td></tr><?php endif; ?></tbody>
  </table></div></div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
