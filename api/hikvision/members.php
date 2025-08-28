<?php
// api/hikvision/members.php
// Returns a list of members and their device mappings for the agent

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$API_KEY = getenv('HIKVISION_AGENT_API_KEY') ?: 'replace_this_with_a_real_key';
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || ($_GET['key'] ?? '') !== $API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$conn = get_db_connection();

$sql = 'SELECT m.id as member_id, m.full_name, m.phone, m.email, h.device_id, h.hikvision_user_id FROM members m LEFT JOIN member_hikvision_data h ON m.id = h.member_id';
$result = $conn->query($sql);
$members = [];
while ($row = $result->fetch_assoc()) {
    $members[] = $row;
}
$conn->close();

echo json_encode(['success' => true, 'members' => $members]);
