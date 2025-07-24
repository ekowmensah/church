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
if (!$is_super_admin && !has_permission('access_ajax_get_health_records')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$member_id = intval($_GET['member_id'] ?? 0);
if (!$member_id) { 
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No member selected']);
    exit; 
}

$sql = "SELECT h.*, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS member_name FROM health_records h JOIN members m ON h.member_id = m.id WHERE h.member_id = ? ORDER BY h.recorded_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $member_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) { 
    echo json_encode(['success' => true, 'data' => '<div class="alert alert-info">No health records found for this member.</div>']);
    exit; 
}

// Build HTML table as a string
$html = '<table class="table table-bordered" id="healthRecordsTable">
    <thead>
        <tr>
            <th>Date/Time</th>
            <th>Vitals</th>
            <th>Tests</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>';

while($row = $result->fetch_assoc()) {
    $html .= '<tr>';
    $html .= '<td>'.htmlspecialchars($row['recorded_at']).'</td>';
    $html .= '<td>';
    $html .= 'Weight: '.htmlspecialchars($row['weight'] ?? '-') . ' kg<br>';
    $html .= 'Temp: '.htmlspecialchars($row['temperature'] ?? '-') . ' °C<br>';
    $html .= 'BP: '.htmlspecialchars($row['bp_systolic'] ?? '-') . '/' . htmlspecialchars($row['bp_diastolic'] ?? '-') . ' mmHg<br>';
    $html .= 'Pulse: '.htmlspecialchars($row['pulse'] ?? '-') . ' bpm<br>';
    $html .= 'Sugar: '.htmlspecialchars($row['sugar'] ?? '-') . ' mmol/L';
    $html .= '</td>';
    $html .= '<td>';
    $html .= 'Malaria: '.htmlspecialchars($row['malaria_test'] ?? '-') . '<br>';
    $html .= 'Pregnancy: '.htmlspecialchars($row['pregnancy_test'] ?? '-') . '<br>';
    $html .= 'Other: '.htmlspecialchars($row['other_tests'] ?? '-');
    $html .= '</td>';
    $html .= '<td>'.nl2br(htmlspecialchars($row['notes'])).'</td>';
    $html .= '</tr>';
}

$html .= '</tbody></table>';

// Return proper JSON response
echo json_encode(['success' => true, 'data' => $html]);
