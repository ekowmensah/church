<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Permission check
if (!has_permission('view_dashboard')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$unreg_success = false;
$unreg_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unreg_id'])) {
    $unreg_id = intval($_POST['unreg_id']);
    // Fetch registration to check permissions
    $stmt = $conn->prepare("SELECT * FROM event_registrations WHERE id = ?");
    $stmt->bind_param('i', $unreg_id);
    $stmt->execute();
    $reg = $stmt->get_result()->fetch_assoc();
    $can_unreg = false;
    if ($reg) {
        if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) $can_unreg = true;
        elseif (isset($_SESSION['member_id']) && $_SESSION['member_id'] == $reg['member_id']) $can_unreg = true;
    }
    if ($can_unreg) {
        $stmt = $conn->prepare("DELETE FROM event_registrations WHERE id = ?");
        $stmt->bind_param('i', $unreg_id);
        if ($stmt->execute()) {
            $unreg_success = true;
        } else {
            $unreg_error = 'Failed to unregister: ' . htmlspecialchars($conn->error);
        }
    } else {
        $unreg_error = 'Permission denied.';
    }
}
// Fetch event details
$stmt = $conn->prepare("SELECT * FROM events WHERE id = ?");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
if (!$event) {
    die('<div class="alert alert-danger">Event not found.</div>');
}
// Fetch registrations
$sql = "SELECT er.*, m.crn, m.first_name, m.last_name, m.phone FROM event_registrations er LEFT JOIN members m ON er.member_id = m.id WHERE er.event_id = ? ORDER BY er.registered_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $event_id);
$stmt->execute();
$registrations = $stmt->get_result();
ob_start();
?>
<div class="container mt-4">
  <h3>Registrations for: <?= htmlspecialchars($event['name']) ?></h3>
  <div class="mb-3">
    <strong>Date:</strong> <?= htmlspecialchars($event['event_date']) ?> <strong>Time:</strong> <?= htmlspecialchars($event['event_time']) ?> <strong>Location:</strong> <?= htmlspecialchars($event['location']) ?>
  </div>
  <div class="card card-body shadow-sm">
    <?php if ($unreg_success): ?>
      <div class="alert alert-success">Un-registration successful.</div>
    <?php elseif ($unreg_error): ?>
      <div class="alert alert-danger"><?= $unreg_error ?></div>
    <?php endif; ?>
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>#</th>
            <th>CRN</th>
            <th>Full Name</th>
            <th>Phone</th>
            <th>Registered At</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; if ($registrations && $registrations->num_rows > 0): while($r = $registrations->fetch_assoc()): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($r['crn']) ?></td>
            <td><?= htmlspecialchars($r['last_name'] . ' ' . $r['first_name']) ?></td>
            <td><?= htmlspecialchars($r['phone']) ?></td>
            <td><?= htmlspecialchars($r['registered_at']) ?></td>
            <td>
              <?php
                $can_unreg = false;
                if (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) $can_unreg = true;
                elseif (isset($_SESSION['member_id']) && $_SESSION['member_id'] == $r['member_id']) $can_unreg = true;
              ?>
              <?php if ($can_unreg): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="unreg_id" value="<?= $r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Unregister this member from event?')">
                    <i class="fas fa-user-minus"></i> Unregister
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endwhile; else: ?>
          <tr><td colspan="5" class="text-center">No registrations yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <a href="event_registration_list.php" class="btn btn-secondary mt-3">Back to Events</a>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
