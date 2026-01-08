<?php
require_once __DIR__.'/../../config/config.php';
require_once __DIR__.'/../../helpers/auth.php';
require_once __DIR__.'/../../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('export_payment_report')) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Check if export parameter is set
if (!isset($_GET['export']) || $_GET['export'] !== 'excel') {
    header('Location: ../payment_report.php');
    exit;
}

// Get filter values
$filter_church = $_GET['church_id'] ?? '';
$filter_class = $_GET['class_id'] ?? '';
$filter_type = $_GET['type_id'] ?? '';
$filter_crn = $_GET['member_crn'] ?? '';
$date_from = $_GET['from_date'] ?? '';
$date_to = $_GET['to_date'] ?? '';

// Build WHERE clause
$where = "WHERE 1=1";
$params = [];
$types = '';

if ($filter_church) {
    $where .= " AND m.church_id = ?";
    $params[] = intval($filter_church);
    $types .= 'i';
}
if ($filter_class) {
    $where .= " AND m.class_id = ?";
    $params[] = intval($filter_class);
    $types .= 'i';
}
if ($filter_type) {
    $where .= " AND p.payment_type_id = ?";
    $params[] = intval($filter_type);
    $types .= 'i';
}
if ($filter_crn) {
    $where .= " AND m.crn LIKE ?";
    $params[] = '%' . $filter_crn . '%';
    $types .= 's';
}
if ($date_from) {
    $where .= " AND p.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where .= " AND p.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

// Query: Group by month
$sql = "SELECT 
    DATE_FORMAT(p.payment_date, '%Y-%m') AS month,
    DATE_FORMAT(p.payment_date, '%M %Y') AS month_name,
    COUNT(p.id) AS payment_count,
    SUM(p.amount) AS total_amount,
    AVG(p.amount) AS avg_amount,
    MIN(p.amount) AS min_amount,
    MAX(p.amount) AS max_amount
FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
$where
GROUP BY month
ORDER BY month DESC";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Generate filename
$filename = 'payment_by_month_' . date('Y-m-d_H-i-s') . '.csv';

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
fputcsv($output, ['Month', 'Number of Payments', 'Total Amount (₵)', 'Average Amount (₵)', 'Min Amount (₵)', 'Max Amount (₵)']);

// Export data
$grand_total = 0;
$grand_count = 0;

while ($row = $result->fetch_assoc()) {
    $grand_total += $row['total_amount'];
    $grand_count += $row['payment_count'];
    
    fputcsv($output, [
        $row['month_name'],
        $row['payment_count'],
        number_format($row['total_amount'], 2),
        number_format($row['avg_amount'], 2),
        number_format($row['min_amount'], 2),
        number_format($row['max_amount'], 2)
    ]);
}

// Add summary
fputcsv($output, []);
fputcsv($output, ['SUMMARY']);
fputcsv($output, ['Total Payments:', $grand_count]);
fputcsv($output, ['Grand Total (₵):', number_format($grand_total, 2)]);
fputcsv($output, ['Overall Average (₵):', $grand_count > 0 ? number_format($grand_total / $grand_count, 2) : '0.00']);
fputcsv($output, ['Export Date:', date('Y-m-d H:i:s')]);
fputcsv($output, ['Exported By:', $_SESSION['name'] ?? 'Unknown']);

// Add filter information
if ($filter_church || $filter_class || $filter_type || $filter_crn || $date_from || $date_to) {
    fputcsv($output, []);
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
    
    if ($filter_type) {
        $type_result = $conn->query("SELECT name FROM payment_types WHERE id = " . intval($filter_type));
        if ($type_result && $type_row = $type_result->fetch_assoc()) {
            fputcsv($output, ['Payment Type:', $type_row['name']]);
        }
    }
    
    if ($filter_crn) {
        fputcsv($output, ['CRN:', $filter_crn]);
    }
    
    if ($date_from) {
        fputcsv($output, ['From Date:', $date_from]);
    }
    
    if ($date_to) {
        fputcsv($output, ['To Date:', $date_to]);
    }
}

fclose($output);
exit;
