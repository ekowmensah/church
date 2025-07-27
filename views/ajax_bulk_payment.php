<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Modern Bulk Payment API Endpoint
require_once __DIR__.'/../config/config.php';
header('Content-Type: application/json');

// Canonical permission check for Bulk Payment (AJAX)
require_once __DIR__.'/../helpers/permissions.php';
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
if (!$is_super_admin && !has_permission('access_ajax_bulk_payment')) {
    echo json_encode(['error' => 'Permission denied']);
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
$church_id = intval($data['church_id'] ?? 0);
$payment_date = $data['payment_date'] ?? '';

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
    public $success_count = 0;
    public $error_count = 0;
    public $summary = [];
    public $errors = [];
    public function __construct($conn) {
        $this->conn = $conn;
    }
    public function process($member_ids, $sundayschool_ids, $amounts, $descriptions, $church_id, $payment_date) {
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
                $mode = 'Cash';
                $desc = isset($descriptions[$mid][$ptid]) ? mb_substr($descriptions[$mid][$ptid], 0, 255) : '';
                $this->summary[] = ["debug" => "Attempting insert: member_id=$mid, sundayschool_id=NULL, church_id=$church_id, payment_type_id=$ptid, amount=$amount, mode=$mode, payment_date=$payment_date, description=$desc"];
                $stmt = $this->conn->prepare('INSERT INTO payments (member_id, sundayschool_id, church_id, payment_type_id, amount, mode, payment_date, description, recorded_by) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidsssi', $mid, $church_id, $ptid, $amount, $mode, $payment_date, $desc, $GLOBALS['recorded_by']);
                try {
                    if ($stmt->execute()) {
                        $this->success_count++;
                        $this->summary[] = ['member_id' => $mid, 'payment_type_id' => $ptid, 'amount' => $amount];
                    } else {
                        $this->error_count++;
                        $this->errors[] = "DB error for member $mid, type $ptid: ".$stmt->error;
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
            $sid = intval($sid);
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
                $mode = 'Cash';
                $desc = isset($descriptions['ss_'.$sid][$ptid]) ? mb_substr($descriptions['ss_'.$sid][$ptid], 0, 255) : '';
                $this->summary[] = ["debug" => "Attempting insert: member_id=NULL, sundayschool_id=$sid, church_id=$church_id, payment_type_id=$ptid, amount=$amount, mode=$mode, payment_date=$payment_date, description=$desc"];
                $stmt = $this->conn->prepare('INSERT INTO payments (member_id, sundayschool_id, church_id, payment_type_id, amount, mode, payment_date, description, recorded_by) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiidsssi', $sid, $church_id, $ptid, $amount, $mode, $payment_date, $desc, $GLOBALS['recorded_by']);
                try {
                    if ($stmt->execute()) {
                        $this->success_count++;
                        $this->summary[] = ['sundayschool_id' => $sid, 'payment_type_id' => $ptid, 'amount' => $amount];
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
    // Example for extensibility
    // private function notify($mid, $amount, $ptid, $payment_date) {
    //     // Plugin/hook logic here
    // }
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

$processor = new BulkPaymentProcessor($conn);
if (!empty($sundayschool_ids)) {
    $processor->process([], $sundayschool_ids, $amounts, $descriptions, $church_id, $payment_date);
} else {
    $processor->process($member_ids, [], $amounts, $descriptions, $church_id, $payment_date);
}

respond([
    'success' => $processor->error_count === 0,
    'msg' => $processor->error_count === 0 ? ("Bulk payment successful for {$processor->success_count} members.") : ("{$processor->success_count} succeeded, {$processor->error_count} failed."),
    'summary' => $processor->summary,
    'errors' => $processor->errors
]);
