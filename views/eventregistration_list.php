<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Fetch event registrations with event and member name
$sql = "SELECT er.*, e.name AS event_name, m.name AS member_name FROM event_registrations er LEFT JOIN events e ON er.event_id = e.id LEFT JOIN members m ON er.member_id = m.id ORDER BY er.registered_at DESC";
$regs = $conn->query($sql);
ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-user-check mr-2"></i>Event Registrations</h2>
    <a href="eventregistration_form.php" class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Registration</a>
  </div>
  <div class="card card-body shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>ID</th>
            <th>Event</th>
            <th>Member</th>
            <th>Registered At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($regs && $regs->num_rows > 0): while($r = $regs->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($r['id']) ?></td>
              <td><?= htmlspecialchars($r['event_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['member_name'] ?? '-') ?></td>
              <td><?= htmlspecialchars($r['registered_at']) ?></td>
              <td>
                <a href="eventregistration_form.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>
                <a href="eventregistration_delete.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Delete this registration?');"><i class="fas fa-trash"></i></a>
              </td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="5" class="text-center">No event registrations found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
