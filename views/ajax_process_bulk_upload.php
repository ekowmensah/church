<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../models/Payment.php';

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Permission check
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('view_payment_bulk')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Validate required fields
$church_id = isset($_POST['church_id']) ? intval($_POST['church_id']) : 0;
$payment_type_id = isset($_POST['payment_type_id']) && $_POST['payment_type_id'] !== '' ? intval($_POST['payment_type_id']) : 0;
$payment_date = isset($_POST['payment_date']) ? $_POST['payment_date'] : '';
$payment_period = isset($_POST['payment_period']) ? $_POST['payment_period'] : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$validate_only = isset($_POST['validate_only']) && $_POST['validate_only'] === 'on';

if (!$church_id || !$payment_date) {
    echo json_encode(['success' => false, 'error' => 'Church and payment date are required']);
    exit;
}

// Validate file upload
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'File upload failed']);
    exit;
}

$file = $_FILES['csv_file'];
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

// Check file size (5MB limit)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File size exceeds 5MB limit']);
    exit;
}

// Process file based on extension
$data = [];
try {
    if ($file_ext === 'csv') {
        $data = processCsvFile($file['tmp_name']);
    } elseif (in_array($file_ext, ['xlsx', 'xls'])) {
        $data = processExcelFile($file['tmp_name']);
    } else {
        throw new Exception('Unsupported file format. Please use CSV or Excel files.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'File processing error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $e->getMessage()]);
    exit;
}

if (empty($data)) {
    echo json_encode(['success' => false, 'error' => 'No valid data found in file']);
    exit;
}

// Get payment types for validation with multiple matching strategies
$payment_types = [];
$payment_type_aliases = [];
$types_result = $conn->query("SELECT id, name FROM payment_types WHERE active = 1");
while ($row = $types_result->fetch_assoc()) {
    $name_lower = strtolower($row['name']);
    $payment_types[$name_lower] = $row['id'];
    
    // Create common aliases for payment types
    if (stripos($row['name'], 'balance') !== false || stripos($row['name'], 'brought') !== false) {
        $payment_type_aliases['b/f'] = $row['id'];
        $payment_type_aliases['bf'] = $row['id'];
        $payment_type_aliases['balance forward'] = $row['id'];
        $payment_type_aliases['balance brought forward'] = $row['id'];
    }
    if (stripos($row['name'], 'tithe') !== false) {
        $payment_type_aliases['tithe'] = $row['id'];
    }
    if (stripos($row['name'], 'offering') !== false) {
        $payment_type_aliases['offering'] = $row['id'];
    }
}

// Merge aliases with main payment types
$payment_types = array_merge($payment_types, $payment_type_aliases);

// Debug: Log available payment types
error_log("Available payment types: " . print_r($payment_types, true));

// Process and validate data
try {
    $results = processPaymentData($data, $church_id, $payment_type_id, $payment_date, $payment_period, $description, $payment_types, $validate_only);
    echo json_encode($results);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Processing error: ' . $e->getMessage()]);
} catch (Error $e) {
    echo json_encode(['success' => false, 'error' => 'Fatal processing error: ' . $e->getMessage()]);
}

function processCsvFile($file_path) {
    $data = [];
    if (($handle = fopen($file_path, "r")) !== FALSE) {
        $header = fgetcsv($handle, 1000, ",");
        if (!$header) {
            throw new Exception('Invalid CSV format - no header row found');
        }
        
        // Normalize headers - convert spaces to underscores and lowercase
        $header = array_map(function($h) {
            return str_replace(' ', '_', strtolower(trim($h)));
        }, $header);
        
        while (($row = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($row) === count($header)) {
                // Trim whitespace from all values
                $row = array_map('trim', $row);
                $data[] = array_combine($header, $row);
            }
        }
        fclose($handle);
    }
    return $data;
}

function processExcelFile($file_path) {
    // For Excel files, we'll need a library like PhpSpreadsheet
    // For now, return error suggesting CSV format
    throw new Exception('Excel files not yet supported. Please convert to CSV format.');
}

function processPaymentData($data, $church_id, $default_payment_type_id, $default_payment_date, $payment_period, $default_description, $payment_types, $validate_only) {
    global $conn;
    
    $stats = ['total' => 0, 'successful' => 0, 'failed' => 0];
    $errors = [];
    $user_id = $_SESSION['user_id'] ?? 0;
    
    // Get all members for this church for CRN validation
    $members = [];
    $members_result = $conn->query("SELECT id, crn, CONCAT(first_name, ' ', last_name) as name FROM members WHERE church_id = $church_id AND status = 'active'");
    while ($row = $members_result->fetch_assoc()) {
        $members[strtoupper($row['crn'])] = $row;
    }
    
    $paymentModel = new Payment();
    
    foreach ($data as $index => $row) {
        $stats['total']++;
        $row_num = $index + 2; // Account for header row
        
        // Debug: Log the row data
        error_log("Row $row_num data: " . print_r($row, true));
        
        // Validate required fields
        $crn = isset($row['crn']) ? strtoupper(trim($row['crn'])) : '';
        $amount = isset($row['amount']) ? floatval($row['amount']) : 0;
        
        if (empty($crn)) {
            $errors[] = "Row $row_num: CRN is required";
            $stats['failed']++;
            continue;
        }
        
        if ($amount <= 0) {
            $errors[] = "Row $row_num: Invalid amount ($amount)";
            $stats['failed']++;
            continue;
        }
        
        // Validate member exists
        if (!isset($members[$crn])) {
            $errors[] = "Row $row_num: Member with CRN '$crn' not found in selected church";
            $stats['failed']++;
            continue;
        }
        
        $member = $members[$crn];
        
        // Determine payment type
        $payment_type_id = $default_payment_type_id;
        if (!empty($row['payment_type'])) {
            $type_name = strtolower(trim($row['payment_type']));
            error_log("Row $row_num: Looking for payment type '$type_name' in CSV");
            
            if (isset($payment_types[$type_name])) {
                $payment_type_id = $payment_types[$type_name];
                error_log("Row $row_num: Found matching payment type ID: $payment_type_id");
            } else {
                error_log("Row $row_num: Payment type '$type_name' not found in available types");
                $errors[] = "Row $row_num: Invalid payment type '{$row['payment_type']}' - using default or first available";
            }
        }
        
        // If no payment type is set (neither default nor from CSV), use first available payment type
        if (!$payment_type_id) {
            if (!empty($payment_types)) {
                $payment_type_id = reset($payment_types); // Get first payment type
                $errors[] = "Row $row_num: No payment type specified - using first available payment type";
            } else {
                $errors[] = "Row $row_num: No payment type specified and no payment types available in system";
                $stats['failed']++;
                continue;
            }
        }
        
        // Determine payment date
        $row_payment_date = !empty($row['payment_date']) ? $row['payment_date'] : $default_payment_date;
        $validated_date = validateDate($row_payment_date);
        if ($validated_date === false) {
            $errors[] = "Row $row_num: Invalid payment date '$row_payment_date' - using default";
            $row_payment_date = $default_payment_date;
        } else {
            $row_payment_date = $validated_date; // Use the normalized date format
        }
        
        // Determine payment period
        $row_payment_period = !empty($payment_period) ? $payment_period : $row_payment_date;
        $period_description = !empty($payment_period) ? date('F Y', strtotime($payment_period)) : '';
        
        // Build description
        $row_description = !empty($row['description']) ? trim($row['description']) : $default_description;
        if (!empty($default_description) && !empty($row_description) && $row_description !== $default_description) {
            $row_description = $default_description . ' - ' . $row_description;
        } elseif (empty($row_description)) {
            $row_description = 'Bulk upload payment';
        }
        
        // If validation only, don't save
        if ($validate_only) {
            $stats['successful']++;
            continue;
        }
        
        // Prepare payment data
        $payment_data = [
            'member_id' => $member['id'],
            'amount' => $amount,
            'payment_type_id' => $payment_type_id,
            'payment_date' => $row_payment_date,
            'payment_period' => $row_payment_period,
            'payment_period_description' => $period_description,
            'description' => $row_description,
            'church_id' => $church_id,
            'recorded_by' => $user_id,
            'mode' => 'Bulk Upload'
        ];
        
        try {
            $result = $paymentModel->add($conn, $payment_data);
            if ($result && isset($result['id'])) {
                $stats['successful']++;
            } else {
                $errors[] = "Row $row_num: Failed to save payment for {$member['name']} (CRN: $crn)";
                $stats['failed']++;
            }
        } catch (Exception $e) {
            $errors[] = "Row $row_num: Database error for {$member['name']} (CRN: $crn): " . $e->getMessage();
            $stats['failed']++;
        }
    }
    
    return [
        'success' => true,
        'validate_only' => $validate_only,
        'stats' => $stats,
        'errors' => $errors
    ];
}

function validateDate($date, $format = 'Y-m-d') {
    // Try multiple date formats
    $formats = ['Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d', 'm-d-Y', 'd-m-Y'];
    
    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $date);
        if ($d && $d->format($fmt) === $date) {
            return $d->format('Y-m-d'); // Always return in Y-m-d format
        }
    }
    
    return false;
}
?>
