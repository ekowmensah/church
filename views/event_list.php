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
if (!$isSuperAdmin && !has_permission('view_event_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access events.</p></div>';
    exit;
}

$canAdd = $isSuperAdmin || has_permission('create_event');
$canEdit = $isSuperAdmin || has_permission('edit_event');
$canCancel = $isSuperAdmin || has_permission('delete_event');
$canManageRegistrations = $isSuperAdmin || has_permission('manage_event_registrations');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } elseif (!$canCancel) {
        $error = 'You do not have permission to change event status.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $eventId = max(0, (int) ($_POST['event_id'] ?? 0));
            if ($eventId <= 0 || !in_array($action, ['cancel', 'restore'], true)) {
                throw new RuntimeException('Choose a valid event action.');
            }
            $eventService->setEventStatus(
                $eventId,
                $action === 'cancel' ? 'cancelled' : 'active',
                (string) ($_POST['cancellation_reason'] ?? '')
            );
            $message = $action === 'cancel'
                ? 'Event cancelled. Its details and registrations were retained.'
                : 'Event restored.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$events = $eventService->listEvents(true);
ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <div><h2 class="mb-1"><i class="fas fa-calendar-alt mr-2"></i>Events</h2><small class="text-muted">Church-scoped events with retained cancellation and registration history.</small></div>
    <div><?php if ($canManageRegistrations): ?><a href="event_registration_list.php" class="btn btn-outline-primary mr-2"><i class="fas fa-user-check mr-1"></i>Registrations</a><?php endif; ?><?php if ($canAdd): ?><a href="event_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Event</a><?php endif; ?></div>
  </div>
  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="card card-body shadow-sm"><div class="table-responsive"><table class="table table-bordered table-hover">
    <thead class="thead-light"><tr><th>Event</th><?php if ($isSuperAdmin): ?><th>Church</th><?php endif; ?><th>Type</th><th>Date &amp; time</th><th>Location</th><th>Registration</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($events): foreach ($events as $event): ?>
      <tr class="<?= $event['status'] === 'cancelled' ? 'table-secondary' : '' ?>">
        <td><div class="d-flex align-items-center"><?php if (!empty($event['photo']) && file_exists(__DIR__ . '/../uploads/events/' . $event['photo'])): ?><img class="mr-2 rounded" style="width:48px;height:48px;object-fit:cover" src="<?= BASE_URL . '/uploads/events/' . rawurlencode($event['photo']) ?>" alt=""><?php endif; ?><div><strong><?= htmlspecialchars($event['name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars(mb_substr((string) $event['description'], 0, 90)) ?></small></div></div></td>
        <?php if ($isSuperAdmin): ?><td><?= htmlspecialchars((string) ($event['church_id'] ?: 'Unassigned')) ?></td><?php endif; ?>
        <td><?= htmlspecialchars($event['type_name'] ?? '-') ?></td>
        <td><?= htmlspecialchars($event['event_date']) ?><br><small><?= htmlspecialchars(substr($event['event_time'], 0, 5)) ?></small></td>
        <td><?= htmlspecialchars($event['location']) ?></td>
        <td><strong><?= number_format((int) $event['active_registrations']) ?></strong> active<?php if ($event['registration_capacity']): ?> / <?= number_format((int) $event['registration_capacity']) ?><?php endif; ?><br><small class="text-muted"><?= $event['registration_enabled'] ? 'Open' : 'Closed' ?></small></td>
        <td><span class="badge badge-<?= $event['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($event['status'])) ?></span><?php if ($event['status'] === 'cancelled' && $event['cancellation_reason']): ?><br><small><?= htmlspecialchars($event['cancellation_reason']) ?></small><?php endif; ?></td>
        <td class="text-nowrap">
          <?php if ($canManageRegistrations): ?><a href="event_registration_view.php?event_id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-info" title="Registrations"><i class="fas fa-users"></i></a><?php endif; ?>
          <?php if ($canEdit): ?><a href="event_form.php?id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a><?php endif; ?>
          <?php if ($canCancel): ?>
            <?php if ($event['status'] === 'active'): ?><button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#cancelEventModal" data-event-id="<?= (int) $event['id'] ?>" data-event-name="<?= htmlspecialchars($event['name'], ENT_QUOTES) ?>" title="Cancel"><i class="fas fa-ban"></i></button>
            <?php else: ?><form method="post" class="d-inline"><?= csrf_input() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><button class="btn btn-sm btn-success" onclick="return confirm('Restore this event?')" title="Restore"><i class="fas fa-undo"></i></button></form><?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; else: ?><tr><td colspan="<?= $isSuperAdmin ? 8 : 7 ?>" class="text-center text-muted">No events are available in your church.</td></tr><?php endif; ?>
    </tbody>
  </table></div></div>
</div>
<?php if ($canCancel): ?><div class="modal fade" id="cancelEventModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_input() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="event_id" id="cancelEventId"><div class="modal-header"><h5 class="modal-title">Cancel event</h5><button type="button" class="close" data-dismiss="modal">&times;</button></div><div class="modal-body"><p id="cancelEventPrompt"></p><label>Cancellation reason</label><textarea class="form-control" name="cancellation_reason" maxlength="500" required></textarea></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Keep Event</button><button class="btn btn-danger">Cancel Event</button></div></form></div></div>
<script>$('#cancelEventModal').on('show.bs.modal',function(event){var button=$(event.relatedTarget);$('#cancelEventId').val(button.data('event-id'));$('#cancelEventPrompt').text('Cancel '+button.data('event-name')+' while retaining its history?');});</script><?php endif; ?>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
