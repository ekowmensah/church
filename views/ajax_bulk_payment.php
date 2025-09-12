<?php
// --- DEBUG: Log incoming payload for bulk payments ---
file_put_contents(__DIR__.'/bulk_payment_debug.log', "\n--- BULK PAYMENT SUBMIT ".date('Y-m-d H:i:s')." ---\n".file_get_contents('php://input')."\n", FILE_APPEND);

session_start();
// Modern Bulk Payment API Endpoint
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
header('Content-Type: application/json');

// Check if user is logged in first
if (!is_logged_in()) {
    echo json_encode(['error' => 'Not authenticated']);
    http_response_code(401);
    exit;
}

// Canonical permission check for Bulk Payment (AJAX)
require_once __DIR__.'/../helpers/permissions.php';
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('add_payment')) {
    echo json_encode(['error' => 'Permission denied - requires payment management access']);
    http_response_code(403);
    exit;
}

// Utility: send JSON response and exit
function respond($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Helper: fetch POST data
function get_post_json() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        return json_decode($raw, true);
    }
    return $_POST;
}

$data = get_post_json();
$member_ids = $data['member_ids'] ?? [];
$sundayschool_ids = $data['sundayschool_ids'] ?? [];
$amounts = isset($data['amounts_json']) ? json_decode($data['amounts_json'], true) : ($data['amounts'] ?? []);
$descriptions = $data['descriptions'] ?? [];
$modes = $data['modes'] ?? [];
$periods = $data['periods'] ?? [];
$period_descriptions = $data['period_descriptions'] ?? [];




$church_id = intval($data['church_id'] ?? 0);
// Handle payment date - if only date is provided, append current time
$payment_date = $data['payment_date'] ?? date('Y-m-d H:i:s');
if ($payment_date && strlen($payment_date) == 10) { // If date is in Y-m-d format (10 chars), append current time
    $payment_date .= ' ' . date('H:i:s');
}

// Get logged-in user id for recorded_by
$recorded_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// --- Restrict Class Leaders to their own class ---
@session_start();
$is_super_admin = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
$is_class_leader = (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 5);
$linked_member_id = isset($_SESSION['member_id']) ? intval($_SESSION['member_id']) : 0;
$class_leader_class_id = 0;
if ($is_class_leader && $linked_member_id) {
    // Get the class_id of the linked member
    $stmt = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
    $stmt->bind_param('i', $linked_member_id);
    $stmt->execute();
    $stmt->bind_result($class_leader_class_id);
    $stmt->fetch();
    $stmt->close();
    if ($class_leader_class_id) {
        // Get allowed member IDs in this class
        $allowed_ids = [];
        $stmt = $conn->prepare('SELECT id FROM members WHERE class_id = ? AND status = "active"');
        $stmt->bind_param('i', $class_leader_class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $allowed_ids[] = intval($row['id']);
        }
        $stmt->close();
        // Filter member_ids to only those allowed
        $submitted_ids = array_map('intval', $member_ids);
        $filtered_ids = array_intersect($submitted_ids, $allowed_ids);
        $rejected_ids = array_diff($submitted_ids, $filtered_ids);
        $member_ids = array_values($filtered_ids);
        // If any rejected, add error
        if (!empty($rejected_ids)) {
            file_put_contents(__DIR__.'/bulk_payment_debug.log', "Class Leader restriction: rejected member_ids: ".json_encode($rejected_ids)."\n", FILE_APPEND);
            // Optionally: respond with error or just skip
            // respond(['success' => false, 'msg' => 'You can only pay for members in your class.'], 403);
        }
    }
}

// Debug: log incoming payload
file_put_contents(__DIR__.'/bulk_payment_debug.log', "\n====\n".date('c')."\n".json_encode($data,JSON_PRETTY_PRINT)."\n", FILE_APPEND);

if ((!$member_ids && !$sundayschool_ids) || !$amounts || !$church_id || !$payment_date) {
    respond(['success' => false, 'msg' => 'Missing required fields.'], 400);
}

class BulkPaymentProcessor {
    private $conn;
    private $recorded_by;
    public $success_count = 0;
    public $error_count = 0;
    public $summary = [];
    public $errors = [];
    public function __construct($conn, $recorded_by) {
        $this->conn = $conn;
        $this->recorded_by = $recorded_by;
    }
    
    public function process($member_ids, $sundayschool_ids, $amounts, $descriptions, $modes, $periods, $period_descriptions, $church_id, $payment_date) {
        // Process member payments
        foreach ($member_ids as $mid) {
            $mid = intval($mid);
            if (!isset($amounts[$mid]) || !is_array($amounts[$mid])) {
                $this->errors[] = "Missing amounts for member $mid.";
                $this->summary[] = ["debug" => "Skipping member $mid: no amounts."];
                continue;
            }
            foreach ($amounts[$mid] as $ptid => $amt) {
                $ptid = intval($ptid);
                $amount = floatval($amt);
                if ($amount <= 0) {
                    $this->errors[] = "Zero or negative amount for member $mid, type $ptid";
                    $this->summary[] = ["debug" => "Skipping member $mid, type $ptid: amount $amount."];
                    continue;
                }
                $mode = isset($modes[$mid][$ptid]) ? $modes[$mid][$ptid] : 'Cash';
                $desc = isset($descriptions[$mid][$ptid]) ? mb_substr($descriptions[$mid][$ptid], 0, 255) : '';
                // Handle payment period - default to first day of current month if not provided
                $period = isset($periods[$mid][$ptid]) ? $periods[$mid][$ptid] : date('Y-m-01');
                $period_description = isset($period_descriptions[$mid][$ptid]) ? trim($period_descriptions[$mid][$ptid]) : '';
                
                // Ensure period_description is not empty - if empty, try to generate from period
                if (empty($period_description) && !empty($period)) {
                    $period_description = date('F Y', strtotime($period));
                }
                

                $this->summary[] = ["debug" => "Attempting insert: member_id=$mid, sundayschool_id=NULL, church_id=$church_id, payment_type_id=$ptid, amount=$amount, mode=$mode, payment_date=$payment_date, payment_period=$period, description=$desc"];
                $stmt = $this->conn->prepare('INSERT INTO payments (member_id, sundayschool_id, church_id, payment_type_id, amount, mode, payment_date, payment_period, payment_period_description, description, recorded_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidsssssi', $mid, $church_id, $ptid, $amount, $mode, $payment_date, $period, $period_description, $desc, $this->recorded_by);
                try {
                    if ($stmt->execute()) {
                        $this->success_count++;
                        $payment_id = $this->conn->insert_id;
                        $this->summary[] = ['member_id' => $mid, 'payment_type_id' => $ptid, 'amount' => $amount, 'payment_id' => $payment_id];
                        file_put_contents(__DIR__.'/bulk_payment_debug.log', "SUCCESS: Inserted payment ID $payment_id for member $mid, type $ptid, amount $amount\n", FILE_APPEND);
                        
                        // Check if this is a harvest payment (payment_type_id = 4) and send special SMS
                        if ($ptid == 4) {
                            require_once __DIR__.'/../includes/payment_sms_template.php';
                            require_once __DIR__.'/../includes/sms.php';
                            
                            // Get member details
                            $member_stmt = $this->conn->prepare('SELECT first_name, last_name, phone FROM members WHERE id = ?');
                            $member_stmt->bind_param('i', $mid);
                            $member_stmt->execute();
                            $member_data = $member_stmt->get_result()->fetch_assoc();
                            $member_stmt->close();
                            
                            if ($member_data && !empty($member_data['phone'])) {
                                // Get church name
                                $church_stmt = $this->conn->prepare('SELECT name FROM churches WHERE id = ?');
                                $church_stmt->bind_param('i', $church_id);
                                $church_stmt->execute();
                                $church_data = $church_stmt->get_result()->fetch_assoc();
                                $church_stmt->close();
                                
                                $member_name = trim($member_data['first_name'] . ' ' . $member_data['last_name']);
                                $church_name = $church_data['name'] ?? 'Freeman Methodist Church - KM';
                                $yearly_total = get_member_yearly_harvest_total($this->conn, $mid);
                                
                                // Generate harvest SMS message
                                $sms_message = get_harvest_payment_sms_message(
                                    $member_name,
                                    $amount,
                                    $church_name,
                                    $desc,
                                    $yearly_total
                                );
                                
                                // Send SMS
                                $sms_result = log_sms($member_data['phone'], $sms_message, $payment_id, 'harvest_payment');
                                
                                // Log SMS attempt
                                error_log('General Bulk Harvest SMS sent to ' . $member_data['phone'] . ': ' . json_encode($sms_result));
                            }
                        }
                        
                        // Queue SMS notification asynchronously - Skip for harvest payments as they have custom SMS
                        if ($ptid != 4) {
                            $this->queueSMS($payment_id, $mid, null, $amount, $ptid, $payment_date, $desc);
                        }
                    } else {
                        $this->error_count++;
                        $this->errors[] = "DB error for member $mid, type $ptid: ".$stmt->error;
                        file_put_contents(__DIR__.'/bulk_payment_debug.log', "ERROR: Failed to insert payment for member $mid, type $ptid: ".$stmt->error."\n", FILE_APPEND);
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $this->errors[] = "Duplicate payment for member $mid, type $ptid.";
                        continue;
                    } else {
                        $this->errors[] = $e->getMessage();
                    }
                }
            }
        }
        // Process Sunday School payments
        foreach ($sundayschool_ids as $sid) {
            // Ensure $sid is always an integer (strip 'ss_' prefix if present)
            $sid = is_numeric($sid) ? intval($sid) : intval(preg_replace('/^ss_/', '', $sid));
            if (!$sid) {
                $this->errors[] = "Invalid Sunday School ID: $sid.";
                $this->summary[] = ["debug" => "Skipping invalid sunday school id: $sid."];
                continue;
            }
            // Only insert as Sunday School payment: member_id=NULL, sundayschool_id=$sid
            if (!isset($amounts['ss_'.$sid]) || !is_array($amounts['ss_'.$sid])) {
                $this->errors[] = "Missing amounts for sunday school $sid.";
                $this->summary[] = ["debug" => "Skipping sunday school $sid: no amounts."];
                continue;
            }
            foreach ($amounts['ss_'.$sid] as $ptid => $amt) {
                $ptid = intval($ptid);
                $amount = floatval($amt);
                if ($amount <= 0) {
                    $this->errors[] = "Zero or negative amount for sunday school $sid, type $ptid";
                    $this->summary[] = ["debug" => "Skipping sunday school $sid, type $ptid: amount $amount."];
                    continue;
                }
                $mode = isset($modes['ss_'.$sid][$ptid]) ? $modes['ss_'.$sid][$ptid] : 'Cash';
                $desc = isset($descriptions['ss_'.$sid][$ptid]) ? mb_substr($descriptions['ss_'.$sid][$ptid], 0, 255) : '';
                // Handle payment period - default to first day of current month if not provided
                $period = isset($periods['ss_'.$sid][$ptid]) ? $periods['ss_'.$sid][$ptid] : date('Y-m-01');
                $period_description = isset($period_descriptions['ss_'.$sid][$ptid]) ? trim($period_descriptions['ss_'.$sid][$ptid]) : '';
                
                // Ensure period_description is not empty - if empty, try to generate from period
                if (empty($period_description) && !empty($period)) {
                    $period_description = date('F Y', strtotime($period));
                }
                $this->summary[] = ["debug" => "Attempting insert: member_id=NULL, sundayschool_id=$sid, church_id=$church_id, payment_type_id=$ptid, amount=$amount, mode=$mode, payment_date=$payment_date, payment_period=$period, description=$desc"];
                $stmt = $this->conn->prepare('INSERT INTO payments (member_id, sundayschool_id, church_id, payment_type_id, amount, mode, payment_date, payment_period, payment_period_description, description, recorded_by) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidsssssi', $sid, $church_id, $ptid, $amount, $mode, $payment_date, $period, $period_description, $desc, $this->recorded_by);
                try {
                    if ($stmt->execute()) {
                        $this->success_count++;
                        $payment_id = $this->conn->insert_id;
                        $this->summary[] = ['sundayschool_id' => $sid, 'payment_type_id' => $ptid, 'amount' => $amount, 'payment_id' => $payment_id];
                        // Queue SMS notification asynchronously
                        $this->queueSMS($payment_id, null, $sid, $amount, $ptid, $payment_date, $desc);
                    } else {
                        $this->error_count++;
                        $this->errors[] = "DB error for sunday school $sid, type $ptid: ".$stmt->error;
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $this->errors[] = "Duplicate payment for sunday school $sid, type $ptid.";
                        continue;
                    } else {
                        $this->errors[] = $e->getMessage();
                    }
                }
            }
        }
    }
    // Queue SMS notification asynchronously (non-blocking)
    private function queueSMS($payment_id, $member_id, $sundayschool_id, $amount, $payment_type_id, $payment_date, $description) {
        // Get payment type name for SMS
        $stmt = $this->conn->prepare('SELECT name FROM payment_types WHERE id = ?');
        $stmt->bind_param('i', $payment_type_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $payment_type_data = $result->fetch_assoc();
        $payment_type_name = $payment_type_data['name'] ?? 'Payment';
        $stmt->close();
        
        $sms_queue_data = [
            'payment_id' => $payment_id,
            'member_id' => $member_id,
            'sundayschool_id' => $sundayschool_id,
            'amount' => $amount,
            'payment_type_name' => $payment_type_name,
            'date' => $payment_date,
            'description' => $description
        ];
        
        // Add small delay to prevent overwhelming the SMS queue
        usleep(100000); // 100ms delay between SMS queue requests
        
        // Use cURL to make non-blocking request to SMS queue
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, BASE_URL . '/ajax_queue_sms.php');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_queue_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '')
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Increased timeout for reliability
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        
        // Execute and log result for debugging
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Log SMS queue attempt for debugging
        $log_data = [
            'payment_id' => $payment_id,
            'member_id' => $member_id,
            'sundayschool_id' => $sundayschool_id,
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        file_put_contents(__DIR__.'/sms_queue_debug.log', json_encode($log_data)."\n", FILE_APPEND);
    }
}

// Strict SRN/CRN separation: If sundayschool_ids is non-empty, ignore member_ids and only process SRN
if (!empty($sundayschool_ids)) {
    $member_ids = [];
    // Validate all amounts keys for SRN
    foreach ($sundayschool_ids as $sid) {
        if (!isset($amounts['ss_'.$sid]) || !is_array($amounts['ss_'.$sid])) {
            file_put_contents(__DIR__.'/bulk_payment_debug.log', "SRN ERROR: Missing or invalid amounts key for ss_{$sid}\n", FILE_APPEND);
        }
    }
    file_put_contents(__DIR__.'/bulk_payment_debug.log', "Processing as SRN only. sundayschool_ids=".json_encode($sundayschool_ids)."\n", FILE_APPEND);
}
if (!empty($member_ids) && empty($sundayschool_ids)) {
    file_put_contents(__DIR__.'/bulk_payment_debug.log', "Processing as CRN only. member_ids=".json_encode($member_ids)."\n", FILE_APPEND);
}

$processor = new BulkPaymentProcessor($conn, $recorded_by);
$result = $processor->process($member_ids, $sundayschool_ids, $amounts, $descriptions, $modes, $periods, $period_descriptions, $church_id, $payment_date);

respond([
    'success' => $processor->error_count === 0,
    'msg' => $processor->error_count === 0 ? ("Bulk payment successful for {$processor->success_count} members.") : ("{$processor->success_count} succeeded, {$processor->error_count} failed."),
    'summary' => $processor->summary,
    'errors' => $processor->errors
]);
