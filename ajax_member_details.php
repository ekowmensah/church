<?php
// ajax_member_details.php
require_once __DIR__.'/config/config.php';
header('Content-Type: application/json');

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) { echo json_encode(['error'=>'No ID']); exit; }

$stmt = $conn->prepare('SELECT phone, profession FROM members WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($phone, $profession);
if ($stmt->fetch()) {
    echo json_encode(['phone'=>$phone, 'profession'=>$profession]);
} else {
    echo json_encode(['phone'=>'', 'profession'=>'']);
}
$stmt->close();
