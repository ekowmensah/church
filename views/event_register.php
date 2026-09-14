<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/member_auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/EventManagementService.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$memberId = (int) $_SESSION['member_id'];
$eventId = max(0, (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0));
$eventService = EventManagementService::fromSession($conn);
$event = $eventService->getEvent($eventId);
$message = '';
$error = '';
if (!$event) {
    http_response_code(404);
    $error = 'This event is unavailable in your church.';
}

$registration = null;
if ($event) {
    $stmt = $conn->prepare('SELECT * FROM event_registrations WHERE event_id = ? AND member_id = ? LIMIT 1');
    $stmt->bind_param('ii', $eventId, $memberId);
    $stmt->execute();
    $registration = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $event) {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $error = 'Your session token expired. Refresh the page and try again.';
    } else {
        try {
            if ((string) ($_POST['action'] ?? '') === 'cancel') {
                if (!$registration) throw new RuntimeException('No registration was found.');
                $eventService->cancelRegistration((int) $registration['id'], $memberId);
                $message = 'Your registration was cancelled and retained in the event history.';
            } else {
                $result = $eventService->registerMember($eventId, $memberId, 'portal');
                $message = $result['created'] ? 'Registration successful.' : 'You are already registered.';
            }
            $stmt = $conn->prepare('SELECT * FROM event_registrations WHERE event_id = ? AND member_id = ? LIMIT 1');
            $stmt->bind_param('ii', $eventId, $memberId); $stmt->execute();
            $registration = $stmt->get_result()->fetch_assoc() ?: null; $stmt->close();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$isActiveRegistration = $registration
    && in_array($registration['registration_status'], ['registered', 'attended'], true);
ob_start();
?>
<div class="container mt-4"><div class="card card-body shadow-sm">
  <?php if ($event): ?><h3><?= htmlspecialchars($event['name']) ?></h3><div class="mb-3"><strong>Date:</strong> <?= htmlspecialchars($event['event_date']) ?> at <?= htmlspecialchars(substr($event['event_time'], 0, 5)) ?><br><strong>Location:</strong> <?= htmlspecialchars($event['location']) ?><br><?= nl2br(htmlspecialchars((string) $event['description'])) ?></div><?php endif; ?>
  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($event): ?>
    <?php if ($isActiveRegistration): ?><div class="alert alert-info">Status: <?= htmlspecialchars(ucfirst($registration['registration_status'])) ?></div><?php if ($registration['registration_status'] === 'registered'): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-danger" onclick="return confirm('Cancel your event registration?')"><i class="fas fa-user-minus mr-1"></i>Cancel Registration</button></form><?php endif; ?>
    <?php else: ?><form method="post"><?= csrf_input() ?><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="action" value="register"><button class="btn btn-primary"><i class="fas fa-user-plus mr-1"></i><?= $registration ? 'Register Again' : 'Register' ?></button></form><?php endif; ?>
  <?php endif; ?>
  <a href="member_events.php" class="btn btn-link mt-3">Back to Events</a>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
