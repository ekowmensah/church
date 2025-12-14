<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is a leader
$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$is_bible_class_leader = is_bible_class_leader($conn, $user_id, $member_id);
$is_org_leader = is_organization_leader($conn, $user_id, $member_id);

if (!$is_bible_class_leader && !$is_org_leader) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as a leader.</div>';
    exit;
}

// Get report type and format
$report_type = $_GET['type'] ?? 'attendance'; // attendance, payments, members
$format = $_GET['format'] ?? 'csv'; // csv, excel
$group_type = $_GET['group_type'] ?? 'class'; // class, org
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Determine group info
if ($group_type === 'class' && $is_bible_class_leader) {
    $group_id = $is_bible_class_leader['class_id'];
    $group_name = $is_bible_class_leader['class_name'];
    $members = get_bible_class_members($conn, $group_id);
} elseif ($group_type === 'org' && $is_org_leader) {
    // is_org_leader now returns array of organizations
    // Get org_id from URL to determine which organization to export
    $org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;
    
    // Find the matching organization
    $found_org = null;
    foreach ($is_org_leader as $org) {
        if ($org['organization_id'] == $org_id) {
            $found_org = $org;
            break;
        }
    }
    
    if (!$found_org) {
        die('Invalid organization or unauthorized');
    }
    
    $group_id = $found_org['organization_id'];
    $group_name = $found_org['org_name'];
    $members = get_organization_members($conn, $group_id);
} else {
    die('Invalid group type or unauthorized');
}

// Generate filename
$filename = strtolower(str_replace(' ', '_', $group_name)) . '_' . $report_type . '_' . date('Y-m-d');

// Set headers for download
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    
    if ($report_type === 'members') {
        // Members Report
        fputcsv($output, ['CRN', 'First Name', 'Last Name', 'Gender', 'Phone', 'Email', 'Address', 'Bible Class', 'Church']);
        
        foreach ($members as $member) {
            fputcsv($output, [
                $member['crn'] ?? '',
                $member['first_name'],
                $member['last_name'],
                $member['gender'] ?? '',
                $member['phone'] ?? '',
                $member['email'] ?? '',
                $member['address'] ?? '',
                $member['class_name'] ?? '',
                $member['church_name'] ?? ''
            ]);
        }
        
    } elseif ($report_type === 'attendance') {
        // Attendance Report
        fputcsv($output, ['Session', 'Date', 'Member CRN', 'Member Name', 'Status']);
        
        $member_ids = array_column($members, 'id');
        if (count($member_ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
            $sql = "
                SELECT ar.*, ats.title, ats.service_date,
                       m.crn, CONCAT(m.first_name, ' ', m.last_name) as member_name
                FROM attendance_records ar
                INNER JOIN attendance_sessions ats ON ar.session_id = ats.id
                INNER JOIN members m ON ar.member_id = m.id
                WHERE ar.member_id IN ($placeholders) 
                AND ats.service_date BETWEEN ? AND ?
                ORDER BY ats.service_date DESC, m.last_name
            ";
            
            $stmt = $conn->prepare($sql);
            $types = str_repeat('i', count($member_ids)) . 'ss';
            $params = array_merge($member_ids, [$start_date, $end_date]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    $row['title'],
                    date('Y-m-d', strtotime($row['service_date'])),
                    $row['crn'],
                    $row['member_name'],
                    ucfirst($row['status'])
                ]);
            }
            $stmt->close();
        }
        
    } elseif ($report_type === 'payments') {
        // Payments Report
        fputcsv($output, ['Date', 'Member CRN', 'Member Name', 'Payment Type', 'Amount', 'Description', 'Recorded By']);
        
        $member_ids = array_column($members, 'id');
        if (count($member_ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
            $sql = "
                SELECT p.*, pt.name as payment_type_name,
                       m.crn, CONCAT(m.first_name, ' ', m.last_name) as member_name,
                       u.name as recorded_by_name
                FROM payments p
                LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
                INNER JOIN members m ON p.member_id = m.id
                LEFT JOIN users u ON p.recorded_by = u.id
                WHERE p.member_id IN ($placeholders)
                AND p.payment_date BETWEEN ? AND ?
                ORDER BY p.payment_date DESC, m.last_name
            ";
            
            $stmt = $conn->prepare($sql);
            $types = str_repeat('i', count($member_ids)) . 'ss';
            $params = array_merge($member_ids, [$start_date, $end_date]);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, [
                    date('Y-m-d', strtotime($row['payment_date'])),
                    $row['crn'],
                    $row['member_name'],
                    $row['payment_type_name'] ?? 'N/A',
                    number_format($row['amount'], 2),
                    $row['description'] ?? '',
                    $row['recorded_by_name'] ?? ''
                ]);
            }
            $stmt->close();
        }
    }
    
    fclose($output);
    exit;
    
} else {
    // Excel format would require PHPSpreadsheet library
    // For now, redirect to CSV
    header('Location: ?type=' . $report_type . '&format=csv&group_type=' . $group_type . '&start_date=' . $start_date . '&end_date=' . $end_date);
    exit;
}
?>
