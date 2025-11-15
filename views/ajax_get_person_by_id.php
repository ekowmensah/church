<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

header('Content-Type: application/json');

// Only allow logged-in users
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('access_ajax_get_person_by_id')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$id = trim($_GET['id'] ?? '');
if (!$id) {
    echo json_encode(['success'=>false, 'msg'=>'No ID provided']);
    exit;
}

try {
    // Check database connection
    if (!$conn) {
        throw new Exception('Database connection not available');
    }
    
    // 1. Try to find in members by CRN
    $stmt = $conn->prepare("SELECT m.id, m.crn, m.first_name, m.last_name, m.gender, DATE_FORMAT(m.dob, '%Y-%m-%d') as dob, m.status, m.church_id, m.phone, m.photo, bc.name as class_name FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.crn = ? LIMIT 1");
    if (!$stmt) throw new Exception('Member query prepare failed: ' . $conn->error);
    $stmt->bind_param('s', $id);
    if (!$stmt->execute()) throw new Exception('Member query execute failed: ' . $stmt->error);
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if (strtolower($row['status']) === 'pending') {
            echo json_encode(['success'=>false, 'msg'=>'Member Not Active']);
            exit;
        }
        // Calculate age
        $dob = $row['dob'];
        $age = null;
        if ($dob) {
            $from = new DateTime($dob);
            $to = new DateTime('now');
            $age = $from->diff($to)->y;
        }
        $row['age'] = $age;
        echo json_encode(['success'=>true, 'type'=>'member', 'data'=>$row]);
        exit;
    }
    $stmt->close();

    // 2. Try to find in sunday_school by SRN
    $stmt2 = $conn->prepare("SELECT id, srn, first_name, last_name, middle_name, dob, gender, contact, school_attend, father_name, father_contact, mother_name, mother_contact, church_id, class_id, father_member_id, mother_member_id, father_is_member, mother_is_member FROM sunday_school WHERE srn = ? LIMIT 1");
    if (!$stmt2) throw new Exception('Sunday School query prepare failed: ' . $conn->error);
    $stmt2->bind_param('s', $id);
    if (!$stmt2->execute()) throw new Exception('Sunday School query execute failed: ' . $stmt2->error);
    $result2 = $stmt2->get_result();
    if ($row2 = $result2->fetch_assoc()) {
        // Calculate age
        $dob = $row2['dob'];
        $age = null;
        if ($dob) {
            $from = new DateTime($dob);
            $to = new DateTime('now');
            $age = $from->diff($to)->y;
        }
        $row2['age'] = $age;
        echo json_encode(['success'=>true, 'type'=>'sundayschool', 'data'=>$row2]);
        exit;
    }
    $stmt2->close();
    echo json_encode(['success'=>false, 'msg'=>'ID not found']);
} catch (Exception $ex) {
    echo json_encode(['success'=>false, 'msg'=>'ERROR: '.$ex->getMessage()]);
}
