<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Canonical permission check with robust super admin bypass
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_top_payment_types')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

global $conn;
$start = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-01-01');
$end = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$sql = "SELECT pt.name, COALESCE(SUM(p.amount),0) as total FROM payment_types pt LEFT JOIN payments p ON p.payment_type_id = pt.id AND p.payment_date >= ? AND p.payment_date <= ? GROUP BY pt.id ORDER BY total DESC LIMIT 5";
$stmt = $conn->prepare($sql);
$stmt->bind_param('ss', $start, $end);
$stmt->execute();
$res = $stmt->get_result();
while($type = $res->fetch_assoc()): ?>
    <li class="list-group-item d-flex justify-content-between align-items-center p-2">
        <span><?= htmlspecialchars($type['name']) ?></span>
        <span class="badge badge-primary">₵ <?= number_format($type['total'],2) ?></span>
    </li>
<?php endwhile; ?>
