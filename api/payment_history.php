<?php
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');
$member_id = $_GET['member_id'] ?? null;
$phone = $_GET['phone'] ?? null;
$where = '';
$params = [];
if ($member_id) {
    $where = 'member_id = ?';
    $params[] = $member_id;
} elseif ($phone) {
    $where = 'phone = ?';
    $params[] = $phone;
}
if ($where) {
    $stmt = $conn->prepare("SELECT id, amount, description, payment_date, payment_period_description FROM payments WHERE $where ORDER BY payment_date DESC LIMIT 50");
    $stmt->bind_param('s', ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = [];
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    echo json_encode($payments);
} else {
    echo json_encode([]);
}
