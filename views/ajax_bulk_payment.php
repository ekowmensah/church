<?php
session_start();
// Modern Bulk Payment API Endpoint
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../helpers/church_helper.php';
header('Content-Type: application/json');

// Check if user is logged in first
if (!is_logged_in()) {
    echo json_encode(['error' => 'Not authenticated']);
    http_response_code(401);
    exit;
}

// Canonical permission check for Bulk Payment (AJAX)
require_once __DIR__.'/../helpers/permissions_v2.php';
$is_super_admin = is_super_admin();
if (!$is_super_admin && !has_permission('create_payment')) {
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
if (!is_array($data)) {
    respond(['success' => false, 'msg' => 'Invalid request body.'], 400);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($data['csrf_token'] ?? null)) {
    respond(['success' => false, 'msg' => 'Your session token is invalid. Refresh the page and try again.'], 405);
}
$member_ids = $data['member_ids'] ?? [];
$sundayschool_ids = $data['sundayschool_ids'] ?? [];
$amounts = isset($data['amounts_json']) ? json_decode($data['amounts_json'], true) : ($data['amounts'] ?? []);
$descriptions = $data['descriptions'] ?? [];
$modes = $data['modes'] ?? [];
$periods = $data['periods'] ?? [];
$period_descriptions = $data['period_descriptions'] ?? [];




$church_id = intval($data['church_id'] ?? 0);
if (!$is_super_admin) {
    $sessionChurchId = (int) get_user_church_id($conn);
    if ($sessionChurchId <= 0 || $church_id !== $sessionChurchId) {
        respond(['success' => false, 'msg' => 'The selected church is outside your authorized scope.'], 403);
    }
}
// Handle payment date - if only date is provided, append current time
$payment_date = $data['payment_date'] ?? date('Y-m-d H:i:s');
if ($payment_date && strlen($payment_date) == 10) { // If date is in Y-m-d format (10 chars), append current time
    $payment_date .= ' ' . date('H:i:s');
}

// Get logged-in user id for recorded_by
$recorded_by = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// --- Restrict Class Leaders to their own class ---
$is_class_leader = has_role('Class Leader') || has_role('Assistant Bible Class Leader');
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
        if (!empty($rejected_ids)) {
            respond(['success' => false, 'msg' => 'One or more members are outside your assigned Bible Class.'], 403);
        }
    }
}

if ((!$member_ids && !$sundayschool_ids) || !$amounts || !$church_id || !$payment_date) {
    respond(['success' => false, 'msg' => 'Missing required fields.'], 400);
}

// Every beneficiary must belong to the authorized church. Never trust IDs
// supplied by the browser, even when the picker itself is scoped.
$memberScope = $conn->prepare("SELECT id, class_id FROM members WHERE id = ? AND church_id = ? AND status = 'active' LIMIT 1");
foreach (array_unique(array_map('intval', (array) $member_ids)) as $memberId) {
    $memberScope->bind_param('ii', $memberId, $church_id);
    $memberScope->execute();
    $scopedMember = $memberScope->get_result()->fetch_assoc();
    if (!$scopedMember || ($is_class_leader && $class_leader_class_id > 0 && (int) $scopedMember['class_id'] !== $class_leader_class_id)) {
        $memberScope->close();
        respond(['success' => false, 'msg' => 'One or more members are outside your authorized scope.'], 403);
    }
}
$memberScope->close();

$childScope = $conn->prepare('SELECT id, class_id FROM sunday_school WHERE id = ? AND church_id = ? LIMIT 1');
foreach ((array) $sundayschool_ids as $childIdValue) {
    $childId = is_numeric($childIdValue) ? (int) $childIdValue : (int) preg_replace('/^ss_/', '', (string) $childIdValue);
    $childScope->bind_param('ii', $childId, $church_id);
    $childScope->execute();
    $scopedChild = $childScope->get_result()->fetch_assoc();
    if (!$scopedChild || ($is_class_leader && $class_leader_class_id > 0 && (int) $scopedChild['class_id'] !== $class_leader_class_id)) {
        $childScope->close();
        respond(['success' => false, 'msg' => 'One or more Sunday School beneficiaries are outside your authorized scope.'], 403);
    }
}
$childScope->close();

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
                $this->error_count++;
                $this->errors[] = "Missing amounts for member $mid.";
                continue;
            }
            foreach ($amounts[$mid] as $ptid => $amt) {
                $ptid = intval($ptid);
                $amount = floatval($amt);
                if ($amount <= 0) {
                    $this->error_count++;
                    $this->errors[] = "Zero or negative amount for member $mid, type $ptid";
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
                

                $stmt = $this->conn->prepare('INSERT INTO payments (member_id, sundayschool_id, church_id, payment_type_id, amount, mode, payment_date, payment_period, payment_period_description, description, recorded_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidsssssi', $mid, $church_id, $ptid, $amount, $mode, $payment_date, $period, $period_description, $desc, $this->recorded_by);
                try {
                    if ($stmt->execute()) {
                        $this->success_count++;
                        $payment_id = $this->conn->insert_id;
                        $this->summary[] = ['member_id' => $mid, 'payment_type_id' => $ptid, 'amount' => $amount, 'payment_id' => $payment_id];
                        // Send SMS immediately for all payment types (harvest and non-harvest)
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
                            // Always get payment type name for SMS
                            $pt_stmt = $this->conn->prepare('SELECT name FROM payment_types WHERE id = ?');
                            $pt_stmt->bind_param('i', $ptid);
                            $pt_stmt->execute();
                            $pt_result = $pt_stmt->get_result();
                            $payment_type_name = ($pt_result && $pt_result->num_rows > 0) ? $pt_result->fetch_assoc()['name'] : 'Payment';
                            $pt_stmt->close();
                            $harvest_year = null;
                            $harvest_total = null;
                            if (is_harvest_payment_type($payment_type_name)) {
                                $harvest_year = get_payment_period_year($period, $period_description, $payment_date);
                                $harvest_total = get_member_yearly_harvest_total(
                                    $this->conn,
                                    $mid,
                                    $harvest_year,
                                    $ptid
                                );
                            }
                            $sms_message = build_manual_payment_sms(
                                $member_name,
                                $amount,
                                $period_description,
                                $payment_type_name,
                                $church_name,
                                $harvest_year,
                                $harvest_total,
                                $period,
                                $payment_date,
                                $desc
                            );
                            $sms_type = $harvest_year !== null ? 'harvest_payment' : 'payment';
                            // Send SMS
                            log_sms($member_data['phone'], $sms_message, $payment_id, $sms_type);
                        }
                        // (No queueing, all SMS are sent immediately)

                    } else {
                        $this->error_count++;
                        $this->errors[] = "Payment could not be recorded for member $mid, type $ptid.";
                        error_log('Bulk payment insert failed: ' . $stmt->error);
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $this->error_count++;
                        $this->errors[] = "Duplicate payment for member $mid, type $ptid.";
                        continue;
                    } else {
                        $this->error_count++;
                        $this->errors[] = "Payment could not be recorded for member $mid, type $ptid.";
                        error_log('Bulk payment insert exception: ' . $e->getMessage());
                    }
                }
            }
        }
        // Process Sunday School payments
        foreach ($sundayschool_ids as $sid) {
            // Ensure $sid is always an integer (strip 'ss_' prefix if present)
            $sid = is_numeric($sid) ? intval($sid) : intval(preg_replace('/^ss_/', '', $sid));
            if (!$sid) {
                $this->error_count++;
                $this->errors[] = "Invalid Sunday School ID: $sid.";
                continue;
            }
            // Only insert as Sunday School payment: member_id=NULL, sundayschool_id=$sid
            if (!isset($amounts['ss_'.$sid]) || !is_array($amounts['ss_'.$sid])) {
                $this->error_count++;
                $this->errors[] = "Missing amounts for sunday school $sid.";
                continue;
            }
            foreach ($amounts['ss_'.$sid] as $ptid => $amt) {
                $ptid = intval($ptid);
                $amount = floatval($amt);
                if ($amount <= 0) {
                    $this->error_count++;
                    $this->errors[] = "Zero or negative amount for sunday school $sid, type $ptid";
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
                        $this->errors[] = "Payment could not be recorded for Sunday School beneficiary $sid, type $ptid.";
                        error_log('Bulk Sunday School payment insert failed: ' . $stmt->error);
                    }
                } catch (mysqli_sql_exception $e) {
                    if ($e->getCode() == 1062) {
                        $this->error_count++;
                        $this->errors[] = "Duplicate payment for sunday school $sid, type $ptid.";
                        continue;
                    } else {
                        $this->error_count++;
                        $this->errors[] = "Payment could not be recorded for Sunday School beneficiary $sid, type $ptid.";
                        error_log('Bulk Sunday School payment insert exception: ' . $e->getMessage());
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
        curl_setopt($ch, CURLOPT_URL, BASE_URL . '/views/ajax_queue_sms.php');
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
        
        if ($curl_error !== '') {
            error_log('Bulk-payment SMS queue transport failed: ' . $curl_error);
        }
    }
}

// Strict SRN/CRN separation: If sundayschool_ids is non-empty, ignore member_ids and only process SRN
if (!empty($sundayschool_ids)) {
    $member_ids = [];
}

try {
    $processor = new BulkPaymentProcessor($conn, $recorded_by);
    $result = $processor->process($member_ids, $sundayschool_ids, $amounts, $descriptions, $modes, $periods, $period_descriptions, $church_id, $payment_date);
    respond([
        'success' => $processor->error_count === 0,
        'msg' => $processor->error_count === 0 ? ("Bulk payment successful for {$processor->success_count} members.") : ("{$processor->success_count} succeeded, {$processor->error_count} failed."),
        'summary' => $processor->summary,
        'errors' => $processor->errors
    ]);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'msg' => 'The bulk payment could not be completed. Please try again or contact support.'
    ], 500);
}
