<?php
/**
 * AJAX endpoint to generate the next SRN for Sunday School children
 * New format: FMC-SYYNN-KM where YY = birth year, NN = sequence within year
 * 
 * Example: Child born in 2021 → FMC-S2101-KM (first child), FMC-S2102-KM (second child)
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
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// Get and validate input parameters
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$dob = isset($_GET['dob']) ? trim($_GET['dob']) : '';
$current_id = isset($_GET['id']) ? intval($_GET['id']) : 0; // For editing existing records

// Validate required parameters
if (!$church_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing church_id']);
    exit;
}

if (!$dob) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Date of birth is required to generate SRN']);
    exit;
}

// Validate DOB format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

// Extract birth year
$birth_year = date('Y', strtotime($dob));
$year_suffix = substr($birth_year, -2); // Last 2 digits

// Get church code and circuit code
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

$church_code = $church['church_code']; // e.g., "FMC"
$circuit_code = $church['circuit_code']; // e.g., "KM"

// Sunday School class code is always "S"
$class_code = 'S';

// Find the maximum sequence number for this birth year
// Pattern: FMC-S{YY}{NN}-KM where YY is year suffix
$max_seq = 0;

// Query to find all SRNs for this church and birth year
$pattern_prefix = $church_code . '-' . $class_code . $year_suffix;

$query = 'SELECT srn FROM sunday_school WHERE church_id = ? AND srn LIKE ? AND srn IS NOT NULL';
$params = [$church_id, $pattern_prefix . '%'];
$types = 'is';

// Exclude current record if editing
if ($current_id > 0) {
    $query .= ' AND id != ?';
    $params[] = $current_id;
    $types .= 'i';
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Extract sequence number from SRN
    // Pattern: FMC-SYYNN-KM → extract NN
    if (preg_match('/'.preg_quote($church_code.'-'.$class_code.$year_suffix, '/').'(\d{2})-'.preg_quote($circuit_code, '/').'/i', $row['srn'], $matches)) {
        $seq_num = intval($matches[1]);
        if ($seq_num > $max_seq) {
            $max_seq = $seq_num;
        }
    }
}
$stmt->close();

// Generate next sequence number (always 2 digits)
$next_seq = str_pad($max_seq + 1, 2, '0', STR_PAD_LEFT);

// Compose new SRN: FMC-SYYNN-KM
$srn = $church_code . '-' . $class_code . $year_suffix . $next_seq . '-' . $circuit_code;

// Debug mode
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo json_encode([
        'success' => true,
        'srn' => $srn,
        'debug' => [
            'church_id' => $church_id,
            'dob' => $dob,
            'birth_year' => $birth_year,
            'year_suffix' => $year_suffix,
            'church_code' => $church_code,
            'class_code' => $class_code,
            'circuit_code' => $circuit_code,
            'sequence' => $next_seq,
            'max_seq_found' => $max_seq,
            'pattern_searched' => $pattern_prefix,
            'current_id' => $current_id
        ]
    ]);
} else {
    // Return just the SRN for backward compatibility
    echo $srn;
}
