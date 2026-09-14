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
$canManage = $eventService->isSuperAdmin() || has_permission('manage_event_registrations');
if (!$canManage) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to manage event registrations.</p></div>';
    exit;
}
$eventId = max(0, (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0));
$event = $eventService->getEvent($eventId, true);
if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">Event not found in your church.</div>';
    exit;
}
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'register') {
                $memberId = max(0, (int) ($_POST['member_id'] ?? 0));
                if ($memberId <= 0) throw new RuntimeException('Choose a member to register.');
                $result = $eventService->registerMember($eventId, $memberId, 'admin', (string) ($_POST['notes'] ?? ''));
                $message = $result['created'] ? 'Member registration saved.' : 'The member is already registered.';
            } elseif ($action === 'status') {
                $registrationId = max(0, (int) ($_POST['registration_id'] ?? 0));
                $eventService->updateRegistrationStatus(
                    $registrationId,
                    (string) ($_POST['registration_status'] ?? ''),
                    (string) ($_POST['cancellation_reason'] ?? '')
                );
                $message = 'Registration status updated.';
            } else {
                throw new RuntimeException('Choose a valid registration action.');
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$registrations = $eventService->listRegistrations($eventId);
$members = $event['status'] === 'active' ? $eventService->listEligibleMembers($eventId) : [];
ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h3 class="mb-1">Registrations: <?= htmlspecialchars($event['name']) ?></h3><small class="text-muted"><?= htmlspecialchars($event['event_date']) ?> at <?= htmlspecialchars(substr($event['event_time'], 0, 5)) ?> · <?= htmlspecialchars($event['location']) ?></small></div><a href="event_registration_list.php" class="btn btn-outline-secondary">Back to Queue</a></div>
  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($event['status'] === 'active'): ?><div class="card card-body shadow-sm mb-3"><h5>Register a member</h5><form method="post" class="form-row align-items-end"><?= csrf_input() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="register"><div class="form-group col-md-6"><label>Member</label><select class="form-control" name="member_id" required><option value="">Choose member</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars(($member['crn'] ?: 'No CRN') . ' — ' . trim($member['last_name'] . ' ' . $member['first_name'] . ' ' . $member['middle_name']) . ($member['registration_status'] ? ' [' . $member['registration_status'] . ']' : '')) ?></option><?php endforeach; ?></select></div><div class="form-group col-md-4"><label>Notes</label><input class="form-control" name="notes" maxlength="255"></div><div class="form-group col-md-2"><button class="btn btn-primary btn-block">Register</button></div></form></div><?php endif; ?>
  <div class="card card-body shadow-sm"><div class="table-responsive"><table class="table table-bordered table-hover"><thead class="thead-light"><tr><th>CRN</th><th>Member</th><th>Phone</th><th>Registered</th><th>Source</th><th>Status</th><th>Update</th></tr></thead><tbody>
  <?php if ($registrations): foreach ($registrations as $registration): ?><tr><td><?= htmlspecialchars($registration['crn']) ?></td><td><?= htmlspecialchars(trim($registration['last_name'] . ' ' . $registration['first_name'] . ' ' . $registration['middle_name'])) ?></td><td><?= htmlspecialchars($registration['phone']) ?></td><td><?= htmlspecialchars($registration['registered_at']) ?></td><td><?= htmlspecialchars(ucfirst($registration['registration_source'])) ?></td><td><span class="badge badge-<?= $registration['registration_status'] === 'attended' ? 'success' : ($registration['registration_status'] === 'cancelled' ? 'secondary' : 'info') ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $registration['registration_status']))) ?></span><?php if ($registration['cancellation_reason']): ?><br><small><?= htmlspecialchars($registration['cancellation_reason']) ?></small><?php endif; ?></td><td><form method="post" class="form-inline"><?= csrf_input() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="registration_id" value="<?= (int) $registration['id'] ?>"><select class="form-control form-control-sm mr-1" name="registration_status"><?php foreach (['registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'] as $value => $label): ?><option value="<?= $value ?>" <?= $registration['registration_status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select><input class="form-control form-control-sm mr-1" name="cancellation_reason" maxlength="500" placeholder="Reason if cancelling"><button class="btn btn-sm btn-outline-primary">Save</button></form></td></tr><?php endforeach; else: ?><tr><td colspan="7" class="text-center text-muted">No registrations yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
</div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
