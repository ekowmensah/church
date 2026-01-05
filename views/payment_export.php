<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log errors to a file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

// Check if export parameter is set
if (!isset($_GET['export']) || $_GET['export'] !== 'excel') {
    header('Location: payment_list.php');
    exit;
}

// Set permission flags
$can_view_all = $is_super_admin || has_permission('view_all_payments');

// Get filter values from GET data (passed via URL query string)
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$filter_gender = $_GET['gender'] ?? '';
$filter_church = $_GET['church_id'] ?? '';
$filter_payment_type = $_GET['payment_type_id'] ?? '';
$filter_mode = $_GET['mode'] ?? '';
$filter_user_id = $_GET['user_id'] ?? '';
$filter_period_from = $_GET['period_from'] ?? '';
$filter_period_to = $_GET['period_to'] ?? '';
$search_term = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';

// Build SQL query (same logic as payment_list.php but without pagination)
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

$sql = "SELECT p.*, 
    m.crn, m.first_name, m.last_name, m.middle_name, m.gender, 
    ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, 
    pt.name AS payment_type,
    c.name AS church_name,
    bc.name AS class_name,
    org.name AS organization_name,
    u.name AS recorded_by_username
FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations org ON mo.organization_id = org.id
    LEFT JOIN users u ON p.recorded_by = u.id
WHERE 1";

$params = [];
$types = '';

// Apply filters (same logic as payment_list.php)
if (($is_super_admin || $can_view_all) && $filter_user_id) {
    $sql .= " AND p.recorded_by = ?";
    $params[] = $filter_user_id;
    $types .= 'i';
}

if ($filter_church) {
    $sql .= " AND m.church_id = ?";
    $params[] = $filter_church;
    $types .= 'i';
}
if ($filter_class) {
    $sql .= " AND m.class_id = ?";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= " AND mo.organization_id = ?";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($filter_gender) {
    $sql .= " AND m.gender = ?";
    $params[] = $filter_gender;
    $types .= 's';
}
if ($filter_payment_type) {
    $sql .= " AND p.payment_type_id = ?";
    $params[] = $filter_payment_type;
    $types .= 'i';
}
if ($filter_mode) {
    $sql .= " AND p.mode = ?";
    $params[] = $filter_mode;
    $types .= 's';
}
if ($search_term) {
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ? OR ss.first_name LIKE ? OR ss.last_name LIKE ? OR ss.srn LIKE ? OR p.description LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 8; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}
if ($date_from) {
    $sql .= " AND DATE(p.payment_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $sql .= " AND DATE(p.payment_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if (!empty($filter_period_from)) {
    $period_from_date = $filter_period_from;
    if (strlen($filter_period_from) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_from)) {
        $period_from_date = $filter_period_from . '-01';
    }
    $sql .= " AND p.payment_period >= ?";
    $params[] = $period_from_date;
    $types .= 's';
}
if (!empty($filter_period_to)) {
    $period_to_date = $filter_period_to;
    if (strlen($filter_period_to) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_to)) {
        try {
            $last_day = date('t', strtotime($filter_period_to . '-01'));
            $period_to_date = $filter_period_to . '-' . $last_day;
        } catch (Exception $e) {
            // If date calculation fails, just use the original value
        }
    }
    $sql .= " AND p.payment_period <= ?";
    $params[] = $period_to_date;
    $types .= 's';
}
if ($amount_min) {
    $sql .= " AND p.amount >= ?";
    $params[] = floatval($amount_min);
    $types .= 'd';
}
if ($amount_max) {
    $sql .= " AND p.amount <= ?";
    $params[] = floatval($amount_max);
    $types .= 'd';
}

// Restrict to payments made by the logged-in user unless super admin or has 'view_all_payments' permission
if (!$can_view_all) {
    $sql .= " AND p.recorded_by = ?";
    $params[] = $user_id;
    $types .= 'i';
}

// Add ordering
$sql .= " ORDER BY p.payment_date DESC, p.id DESC";

// Execute query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Generate filename with timestamp and filters
$filename = 'payments_export_' . date('Y-m-d_H-i-s');
if ($filter_church) {
    $church_result = $conn->query("SELECT name FROM churches WHERE id = " . intval($filter_church));
    if ($church_result && $church_row = $church_result->fetch_assoc()) {
        $filename .= '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $church_row['name']);
    }
}
if ($date_from || $date_to) {
    $filename .= '_' . ($date_from ?: 'start') . '_to_' . ($date_to ?: 'end');
}
$filename .= '.csv';

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for proper UTF-8 encoding in Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers
$headers = [
    'Payment ID',
    'Member/Student',
    'CRN/SRN',
    'Church',
    'Bible Class',
    'Organization',
    'Gender',
    'Payment Type',
    'Amount (₵)',
    'Mode',
    'Description',
    'Payment Date',
    'Payment Time',
    'Payment Period',
    'Period Description',
    'Recorded By',
    'Reversal Status'
];

fputcsv($output, $headers);

// Export data
$total_amount = 0;
$payment_count = 0;

while ($row = $result->fetch_assoc()) {
    $payment_count++;
    $total_amount += $row['amount'];
    
    // Determine member/student name and identifier
    $member_name = '';
    $identifier = '';
    
    if (!empty($row['crn'])) {
        // Regular member
        $member_name = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $identifier = $row['crn'];
    } elseif (!empty($row['srn'])) {
        // Sunday school student
        $member_name = trim(($row['ss_first_name'] ?? '') . ' ' . ($row['ss_middle_name'] ?? '') . ' ' . ($row['ss_last_name'] ?? ''));
        $identifier = $row['srn'];
    } else {
        $member_name = 'Unknown';
        $identifier = 'N/A';
    }
    
    // Format payment mode
    $mode = '';
    if (!empty($row['mode'])) {
        $mode = ucwords(str_replace(['_', '-'], ' ', strtolower($row['mode'])));
    }
    
    // Determine reversal status
    $reversal_status = 'Active';
    if (!empty($row['reversal_requested_at'])) {
        if (!empty($row['reversal_approved_at'])) {
            $reversal_status = 'Reversed';
        } else {
            $reversal_status = 'Reversal Requested';
        }
    }
    
    // Format recorded by
    $recorded_by = 'N/A';
    if (!empty($row['recorded_by_username'])) {
        $recorded_by = $row['recorded_by_username'];
    } elseif (isset($row['recorded_by']) && !is_numeric($row['recorded_by']) && !empty($row['recorded_by'])) {
        $recorded_by = $row['recorded_by']; // External source
    }
    
    $csv_row = [
        $row['id'],
        $member_name,
        $identifier,
        $row['church_name'] ?? 'N/A',
        $row['class_name'] ?? 'N/A',
        $row['organization_name'] ?? 'N/A',
        $row['gender'] ?? 'N/A',
        $row['payment_type'] ?? 'N/A',
        number_format($row['amount'], 2),
        $mode,
        $row['description'] ?? '',
        date('Y-m-d', strtotime($row['payment_date'])),
        date('H:i:s', strtotime($row['payment_date'])),
        $row['payment_period'] ? date('Y-m-d', strtotime($row['payment_period'])) : 'N/A',
        $row['payment_period_description'] ?? 'N/A',
        $recorded_by,
        $reversal_status
    ];
    
    fputcsv($output, $csv_row);
}

// Add summary row
fputcsv($output, []); // Empty row
fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Total Payments:', $payment_count]);
fputcsv($output, ['Total Amount (₵):', number_format($total_amount, 2)]);
fputcsv($output, ['Average Payment (₵):', $payment_count > 0 ? number_format($total_amount / $payment_count, 2) : '0.00']);
fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($output, ['Exported By:', $_SESSION['name'] ?? 'Unknown']);

// Add filter information
if ($filter_church || $filter_class || $filter_org || $filter_gender || $filter_payment_type || $filter_mode || $search_term || $date_from || $date_to || $filter_period_from || $filter_period_to || $amount_min || $amount_max) {
    fputcsv($output, []); // Empty row
    fputcsv($output, ['APPLIED FILTERS:']);
    
    if ($filter_church) {
        $church_result = $conn->query("SELECT name FROM churches WHERE id = " . intval($filter_church));
        if ($church_result && $church_row = $church_result->fetch_assoc()) {
            fputcsv($output, ['Church:', $church_row['name']]);
        }
    }
    
    if ($filter_class) {
        $class_result = $conn->query("SELECT name FROM bible_classes WHERE id = " . intval($filter_class));
        if ($class_result && $class_row = $class_result->fetch_assoc()) {
            fputcsv($output, ['Bible Class:', $class_row['name']]);
        }
    }
    
    if ($filter_org) {
        $org_result = $conn->query("SELECT name FROM organizations WHERE id = " . intval($filter_org));
        if ($org_result && $org_row = $org_result->fetch_assoc()) {
            fputcsv($output, ['Organization:', $org_row['name']]);
        }
    }
    
    if ($filter_gender) {
        fputcsv($output, ['Gender:', $filter_gender]);
    }
    
    if ($filter_payment_type) {
        $pt_result = $conn->query("SELECT name FROM payment_types WHERE id = " . intval($filter_payment_type));
        if ($pt_result && $pt_row = $pt_result->fetch_assoc()) {
            fputcsv($output, ['Payment Type:', $pt_row['name']]);
        }
    }
    
    if ($filter_mode) {
        fputcsv($output, ['Payment Mode:', ucwords(str_replace(['_', '-'], ' ', $filter_mode))]);
    }
    
    if ($search_term) {
        fputcsv($output, ['Search Term:', $search_term]);
    }
    
    if ($date_from) {
        fputcsv($output, ['Payment Date From:', $date_from]);
    }
    
    if ($date_to) {
        fputcsv($output, ['Payment Date To:', $date_to]);
    }
    
    if ($filter_period_from) {
        fputcsv($output, ['Payment Period From:', $filter_period_from]);
    }
    
    if ($filter_period_to) {
        fputcsv($output, ['Payment Period To:', $filter_period_to]);
    }
    
    if ($amount_min) {
        fputcsv($output, ['Minimum Amount (₵):', number_format($amount_min, 2)]);
    }
    
    if ($amount_max) {
        fputcsv($output, ['Maximum Amount (₵):', number_format($amount_max, 2)]);
    }
}

fclose($output);
exit;
?>
