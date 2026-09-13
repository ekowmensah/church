<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/UnifiedAttendanceReportService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true);
if (!$isSuperAdmin && !has_permission('manage_church_statistical_events')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh and try again.');
}

$reportService = UnifiedAttendanceReportService::fromSession($conn);
$churches = $reportService->getAllowedChurches();
$allowedChurchIds = array_map('intval', array_column($churches, 'id'));
$churchId = isset($_REQUEST['church_id']) ? (int) $_REQUEST['church_id'] : (int) ($allowedChurchIds[0] ?? 0);
if (!in_array($churchId, $allowedChurchIds, true)) $churchId = (int) ($allowedChurchIds[0] ?? 0);
$message = '';
$error = '';
$actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($churchId <= 0) throw new RuntimeException('No church is available for your role.');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $eventType = (string) ($_POST['event_type'] ?? '');
            $eventDate = trim((string) ($_POST['event_date'] ?? ''));
            $memberId = isset($_POST['member_id']) && $_POST['member_id'] !== '' ? (int) $_POST['member_id'] : null;
            $personName = trim((string) ($_POST['person_name'] ?? ''));
            $gender = (string) ($_POST['gender'] ?? 'Unspecified');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
            if (!in_array($eventType, ['naming', 'death'], true)) throw new RuntimeException('Choose Naming or Death.');
            if (!$date || $date->format('Y-m-d') !== $eventDate || $eventDate < '1900-01-01') {
                throw new RuntimeException('Enter a valid event date on or after 1900-01-01.');
            }
            if (!in_array($gender, ['Male', 'Female', 'Unspecified'], true)) $gender = 'Unspecified';

            if ($memberId !== null) {
                $memberStmt = $conn->prepare(
                    "SELECT id, gender, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
                       FROM members WHERE id = ? AND church_id = ? LIMIT 1"
                );
                $memberStmt->bind_param('ii', $memberId, $churchId);
                $memberStmt->execute();
                $member = $memberStmt->get_result()->fetch_assoc();
                $memberStmt->close();
                if (!$member) throw new RuntimeException('Select a member from the chosen church.');
                if ($personName === '') $personName = trim((string) $member['full_name']);
                if (in_array($member['gender'], ['Male', 'Female'], true)) $gender = $member['gender'];
            }
            if ($personName === '') throw new RuntimeException('Enter the person’s name or select a member.');

            $stmt = $conn->prepare(
                "INSERT INTO church_statistical_events
                    (church_id, event_type, event_date, member_id, person_name, gender,
                     notes, recorded_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ississsi', $churchId, $eventType, $eventDate, $memberId, $personName, $gender, $notes, $actorUserId);
            $stmt->execute();
            $stmt->close();
            $message = ucfirst($eventType) . ' event recorded.';
        } elseif ($action === 'cancel') {
            $eventId = (int) ($_POST['event_id'] ?? 0);
            $reason = trim((string) ($_POST['cancellation_reason'] ?? ''));
            if ($eventId <= 0 || $reason === '') throw new RuntimeException('A cancellation reason is required.');
            $stmt = $conn->prepare(
                "UPDATE church_statistical_events
                    SET status = 'cancelled', cancelled_by_user_id = ?,
                        cancelled_at = NOW(), cancellation_reason = ?
                  WHERE id = ? AND church_id = ? AND status = 'active'"
            );
            $stmt->bind_param('isii', $actorUserId, $reason, $eventId, $churchId);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) throw new RuntimeException('The active event was not found in your church.');
            $stmt->close();
            $message = 'Statistical event cancelled; its audit record was retained.';
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$members = [];
if ($churchId > 0) {
    $memberStmt = $conn->prepare(
        "SELECT id, crn, TRIM(CONCAT_WS(' ', first_name, middle_name, last_name)) AS full_name
           FROM members WHERE church_id = ? ORDER BY last_name, first_name, middle_name"
    );
    $memberStmt->bind_param('i', $churchId);
    $memberStmt->execute();
    $members = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $memberStmt->close();
}

$filterType = in_array($_GET['event_type'] ?? '', ['naming', 'death'], true) ? $_GET['event_type'] : '';
$filterStatus = in_array($_GET['status'] ?? '', ['active', 'cancelled'], true) ? $_GET['status'] : '';
$events = [];
if ($churchId > 0) {
    $where = ['event.church_id = ?'];
    $params = [$churchId];
    $types = 'i';
    if ($filterType !== '') { $where[] = 'event.event_type = ?'; $params[] = $filterType; $types .= 's'; }
    if ($filterStatus !== '') { $where[] = 'event.status = ?'; $params[] = $filterStatus; $types .= 's'; }
    $stmt = $conn->prepare(
        "SELECT event.*, member.crn, recorder.name AS recorded_by_name,
                canceller.name AS cancelled_by_name
           FROM church_statistical_events event
           LEFT JOIN members member ON member.id = event.member_id
           LEFT JOIN users recorder ON recorder.id = event.recorded_by_user_id
           LEFT JOIN users canceller ON canceller.id = event.cancelled_by_user_id
          WHERE " . implode(' AND ', $where) . '
          ORDER BY event.event_date DESC, event.id DESC LIMIT 500'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$page_title = 'Statistical Events';
ob_start();
?>
<style>
.stat-event-page{background:#f5f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.stat-hero{background:linear-gradient(135deg,#3d255f,#7651a8);color:#fff;border-radius:15px;padding:1.25rem 1.5rem;box-shadow:0 8px 22px rgba(61,37,95,.22)}.stat-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.audit-note{border-left:4px solid #7651a8;background:#f2edfa;padding:.7rem .9rem}.table thead th{white-space:nowrap;background:#432867;color:#fff;border-color:#63478a}
</style>
<div class="stat-event-page"><div class="container-fluid">
  <div class="stat-hero mb-3 d-flex flex-wrap justify-content-between align-items-center"><div><h2 class="mb-1"><i class="fas fa-clipboard-list mr-2"></i>Statistical Events</h2><div>Auditable naming and death entries for church reports</div></div><a class="btn btn-light btn-sm mt-2 mt-md-0" href="reports/attendance_report.php"><i class="fas fa-chart-bar mr-1"></i>Unified Report</a></div>
  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="audit-note mb-3"><strong>Important:</strong> cancelling retains the audit record and removes it from totals. Recording a death does not automatically deactivate or delete a member.</div>

  <div class="card stat-card mb-3"><div class="card-body">
    <form method="get" class="form-row">
      <div class="form-group col-md-5"><label>Church</label><select class="form-control" name="church_id" onchange="this.form.submit()" required><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= (int) $church['id'] === $churchId ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-md-3"><label>Event Type</label><select class="form-control" name="event_type"><option value="">All</option><option value="naming" <?= $filterType === 'naming' ? 'selected' : '' ?>>Naming</option><option value="death" <?= $filterType === 'death' ? 'selected' : '' ?>>Death</option></select></div>
      <div class="form-group col-md-2"><label>Status</label><select class="form-control" name="status"><option value="">All</option><option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option><option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option></select></div>
      <div class="form-group col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary btn-block">Filter</button></div>
    </form>
  </div></div>

  <div class="row">
    <div class="col-lg-4 mb-3"><div class="card stat-card"><div class="card-header bg-white font-weight-bold">Record Event</div><div class="card-body">
      <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="create"><input type="hidden" name="church_id" value="<?= $churchId ?>">
        <div class="form-group"><label>Event Type</label><select class="form-control" name="event_type" required><option value="naming">Naming</option><option value="death">Death</option></select></div>
        <div class="form-group"><label>Event Date</label><input type="date" class="form-control" name="event_date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="form-group"><label>Linked Member <small class="text-muted">(optional)</small></label><select class="form-control" name="member_id"><option value="">Not linked to a member</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars(($member['crn'] ? $member['crn'] . ' — ' : '') . $member['full_name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Person’s Name</label><input class="form-control" name="person_name" maxlength="255" placeholder="Filled automatically when a member is selected"></div>
        <div class="form-group"><label>Gender</label><select class="form-control" name="gender"><option>Male</option><option>Female</option><option selected>Unspecified</option></select></div>
        <div class="form-group"><label>Notes</label><textarea class="form-control" name="notes" maxlength="500" rows="3"></textarea></div>
        <button class="btn btn-primary btn-block"><i class="fas fa-save mr-1"></i>Record Event</button>
      </form>
    </div></div></div>
    <div class="col-lg-8"><div class="card stat-card"><div class="card-header bg-white font-weight-bold">Event Register</div><div class="card-body p-0"><div class="table-responsive"><table class="table table-bordered table-hover mb-0"><thead><tr><th>Date</th><th>Type</th><th>Person</th><th>Gender</th><th>Status</th><th>Audit / Action</th></tr></thead><tbody>
      <?php if (!$events): ?><tr><td colspan="6" class="text-center text-muted py-4">No manual statistical events found.</td></tr><?php endif; ?>
      <?php foreach ($events as $event): ?><tr><td><?= htmlspecialchars($event['event_date']) ?></td><td><?= htmlspecialchars(ucfirst($event['event_type'])) ?></td><td><?= htmlspecialchars($event['person_name']) ?><?php if ($event['crn']): ?><br><small class="text-muted"><?= htmlspecialchars($event['crn']) ?></small><?php endif; ?></td><td><?= htmlspecialchars($event['gender']) ?></td><td><span class="badge badge-<?= $event['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($event['status'])) ?></span></td><td><small>Recorded by <?= htmlspecialchars($event['recorded_by_name'] ?: 'system') ?></small><?php if ($event['status'] === 'active'): ?><form method="post" class="mt-2" onsubmit="return confirm('Cancel this event while retaining its audit record?')"><?= csrf_input() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="church_id" value="<?= $churchId ?>"><input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>"><input class="form-control form-control-sm mb-1" name="cancellation_reason" maxlength="500" placeholder="Cancellation reason" required><button class="btn btn-sm btn-outline-danger">Cancel</button></form><?php else: ?><br><small class="text-muted"><?= htmlspecialchars($event['cancellation_reason'] ?: '') ?></small><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div></div>
  </div>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
