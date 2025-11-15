<?php
/**
 * AJAX endpoint to generate the next CRN for a class/church
 * Returns the next available Church Registration Number
 */

session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Permission check
if (!has_permission('manage_members')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Get and validate input parameters
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;

if (!$class_id || !$church_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing class_id or church_id']);
    exit;
}

// Get class code and validate it belongs to the selected church
$stmt = $conn->prepare('SELECT code FROM bible_classes WHERE id = ? AND church_id = ? LIMIT 1');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
$class = $result->fetch_assoc();
$stmt->close();

if (!$class) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Class not found or does not belong to church']);
    exit;
}
$class_code = $class['code'];

// Get church code and circuit/location code
$stmt = $conn->prepare('SELECT church_code, circuit_code FROM churches WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $church_id);
$stmt->execute();
$result = $stmt->get_result();
$church = $result->fetch_assoc();
$stmt->close();

if (!$church) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Church not found']);
    exit;
}

$church_code = $church['church_code'];
$circuit_code = $church['circuit_code'];

// Get max sequence number used in CRN/SRN for this church/class in both tables
$max_seq = 0;
$pattern = $church_code . '-' . $class_code . '([0-9]+)-' . $circuit_code;

// Check members
$stmt = $conn->prepare('SELECT crn FROM members WHERE class_id = ? AND church_id = ? AND crn IS NOT NULL');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['crn'], $m)) {
        $num = intval($m[1]);
        if ($num > $max_seq) $max_seq = $num;
    }
}
$stmt->close();
// Check sunday_school
$stmt = $conn->prepare('SELECT srn FROM sunday_school WHERE class_id = ? AND church_id = ? AND srn IS NOT NULL');
$stmt->bind_param('ii', $class_id, $church_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (preg_match('/'.preg_quote($church_code.'-'.$class_code, '/').'([0-9]+)-'.preg_quote($circuit_code, '/').'/i', $row['srn'], $m)) {
        $num = intval($m[1]);
        if ($num > $max_seq) $max_seq = $num;
    }
}
$stmt->close();
// Generate next sequence number (minimum 2 digits)
$seq = str_pad($max_seq + 1, 2, '0', STR_PAD_LEFT);

// Compose CRN
$crn = $church_code . '-' . $class_code . $seq . '-' . $circuit_code;

// Debug mode
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo json_encode([
        'success' => true,
        'crn' => $crn,
        'debug' => [
            'class_id' => $class_id,
            'church_id' => $church_id,
            'church_code' => $church_code,
            'class_code' => $class_code,
            'sequence' => $seq,
            'circuit_code' => $circuit_code,
            'max_seq_found' => $max_seq
        ]
    ]);
} else {
    // Return just the CRN for backward compatibility
    echo $crn;
}
