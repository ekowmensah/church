<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!(isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1) && (!function_exists('has_permission') || !has_permission('view_payment'))){
    http_response_code(403);
    exit('No permission to view reversal log.');
}
$sql = "SELECT l.*, u.name AS actor_name, p.amount, p.payment_date, p.id AS payment_id
FROM payment_reversal_log l
JOIN users u ON l.actor_id = u.id
JOIN payments p ON l.payment_id = p.id
ORDER BY l.action_at DESC, l.id DESC";
$res = $conn->query($sql);
ob_start();
?>
<div class="container mt-4">
  <h2>Payment Reversal Log</h2>
  <table class="table table-bordered table-hover">
    <thead class="thead-light">
      <tr>
        <th>Date/Time</th>
        <th>Payment ID</th>
        <th>Action</th>
        <th>Actor</th>
        <th>Amount</th>
        <th>Payment Date</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $res->fetch_assoc()): ?>
      <tr>
        <td><?=htmlspecialchars($row['action_at'])?></td>
        <td><?=htmlspecialchars($row['payment_id'])?></td>
        <td><?=htmlspecialchars(ucfirst($row['action']))?></td>
        <td><?=htmlspecialchars($row['actor_name'])?></td>
        <td>₵<?=number_format($row['amount'],2)?></td>
        <td><?=htmlspecialchars($row['payment_date'])?></td>
        <td><?=htmlspecialchars($row['reason'])?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
