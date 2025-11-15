<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Canonical permission check for User Audit List
require_once __DIR__.'/../helpers/permissions_v2.php';
if (!has_permission('user_audit')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

// Fetch user audit logs
$sql = "SELECT ua.*, u.name AS user_name FROM user_audit ua LEFT JOIN users u ON ua.user_id = u.id ORDER BY ua.created_at DESC";
$audit_logs = $conn->query($sql);

ob_start();
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><i class="fas fa-user-shield mr-2"></i>User Audit Logs</h2>
    <a href="useraudit_list.php?export=csv" class="btn btn-outline-primary"><i class="fas fa-file-csv mr-1"></i>Export CSV</a>
  </div>
  <div class="card card-body shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover">
        <thead class="thead-light">
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Action</th>
            <th>Details</th>
            <th>Created At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($audit_logs && $audit_logs->num_rows > 0): while($log = $audit_logs->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars($log['id']) ?></td>
              <td><?= htmlspecialchars($log['user_name'] ?? 'Unknown') ?></td>
              <td><?= htmlspecialchars($log['action']) ?></td>
              <td><?= htmlspecialchars($log['details']) ?></td>
              <td><?= htmlspecialchars($log['created_at']) ?></td>
              <td><!-- Actions: e.g. view details, delete (optional) --></td>
            </tr>
          <?php endwhile; else: ?>
            <tr><td colspan="6" class="text-center">No audit logs found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_audit_logs.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'User', 'Action', 'Details', 'Created At']);
    $audit_logs = $conn->query($sql);
    if ($audit_logs) {
        while($log = $audit_logs->fetch_assoc()) {
            fputcsv($out, [
                $log['id'],
                $log['user_name'] ?? 'Unknown',
                $log['action'],
                $log['details'],
                $log['created_at']
            ]);
        }
    }
    fclose($out);
    exit;
}
