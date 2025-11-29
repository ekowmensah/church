<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    die('Access denied');
}

$format = isset($_GET['format']) ? $_GET['format'] : 'csv';
$church_id = isset($_GET['church_id']) ? intval($_GET['church_id']) : 0;
$gender = isset($_GET['gender']) ? $_GET['gender'] : '';
$age_from = isset($_GET['age_from']) ? intval($_GET['age_from']) : 0;
$age_to = isset($_GET['age_to']) ? intval($_GET['age_to']) : 0;
$baptized = isset($_GET['baptized']) ? $_GET['baptized'] : '';
$education_level = isset($_GET['education_level']) ? $_GET['education_level'] : '';

// Build WHERE clause
$where_conditions = [];
$params = [];
$types = '';

if ($church_id > 0) {
    $where_conditions[] = "ss.church_id = ?";
    $params[] = $church_id;
    $types .= 'i';
}

if ($gender) {
    $where_conditions[] = "ss.gender = ?";
    $params[] = $gender;
    $types .= 's';
}

if ($baptized) {
    $where_conditions[] = "ss.baptized = ?";
    $params[] = $baptized;
    $types .= 's';
}

if ($education_level) {
    $where_conditions[] = "ss.education_level = ?";
    $params[] = $education_level;
    $types .= 's';
}

// Age filter
if ($age_from > 0 || $age_to > 0) {
    if ($age_from > 0 && $age_to > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) BETWEEN ? AND ?";
        $params[] = $age_from;
        $params[] = $age_to;
        $types .= 'ii';
    } elseif ($age_from > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) >= ?";
        $params[] = $age_from;
        $types .= 'i';
    } elseif ($age_to > 0) {
        $where_conditions[] = "TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) <= ?";
        $params[] = $age_to;
        $types .= 'i';
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get data
$sql = "SELECT 
    ss.srn,
    ss.last_name,
    ss.middle_name,
    ss.first_name,
    ss.dob,
    TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) as age,
    ss.gender,
    ss.dayborn,
    ss.contact,
    ss.gps_address,
    ss.residential_address,
    c.name as church_name,
    bc.name as class_name,
    ss.organization,
    ss.school_attend,
    ss.school_location,
    ss.education_level,
    ss.baptized,
    ss.baptism_date,
    ss.father_name,
    ss.father_contact,
    ss.father_occupation,
    ss.father_is_member,
    ss.mother_name,
    ss.mother_contact,
    ss.mother_occupation,
    ss.mother_is_member,
    CONCAT(fm.last_name, ' ', fm.first_name) as father_member_name,
    CONCAT(mm.last_name, ' ', mm.first_name) as mother_member_name
FROM sunday_school ss
LEFT JOIN churches c ON ss.church_id = c.id
LEFT JOIN bible_classes bc ON ss.class_id = bc.id
LEFT JOIN members fm ON ss.father_member_id = fm.id
LEFT JOIN members mm ON ss.mother_member_id = mm.id
$where_clause
ORDER BY ss.last_name, ss.first_name";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Export based on format
if ($format === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sunday_school_report_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    fputcsv($output, [
        'SRN', 'Last Name', 'Middle Name', 'First Name', 'Date of Birth', 'Age', 'Gender', 
        'Day Born', 'Contact', 'GPS Address', 'Residential Address', 'Church', 'Class',
        'Organization', 'School Attend', 'School Location', 'Education Level',
        'Baptized', 'Baptism Date', 'Father Name', 'Father Contact', 'Father Occupation',
        'Father is Member', 'Mother Name', 'Mother Contact', 'Mother Occupation', 'Mother is Member'
    ]);
    
    // Data
    foreach ($data as $row) {
        // Use member name if parent is a member
        $father_name = $row['father_is_member'] === 'yes' && $row['father_member_name'] 
            ? $row['father_member_name'] : $row['father_name'];
        $mother_name = $row['mother_is_member'] === 'yes' && $row['mother_member_name'] 
            ? $row['mother_member_name'] : $row['mother_name'];
            
        fputcsv($output, [
            $row['srn'],
            $row['last_name'],
            $row['middle_name'],
            $row['first_name'],
            $row['dob'],
            $row['age'],
            $row['gender'],
            $row['dayborn'],
            $row['contact'],
            $row['gps_address'],
            $row['residential_address'],
            $row['church_name'],
            $row['class_name'],
            $row['organization'],
            $row['school_attend'],
            $row['school_location'],
            ucwords(str_replace('_', ' ', $row['education_level'])),
            $row['baptized'],
            $row['baptism_date'],
            $father_name,
            $row['father_contact'],
            $row['father_occupation'],
            $row['father_is_member'],
            $mother_name,
            $row['mother_contact'],
            $row['mother_occupation'],
            $row['mother_is_member']
        ]);
    }
    
    fclose($output);
    exit;
}

if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="sunday_school_report_' . date('Y-m-d') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>';
    echo '<x:Name>Sunday School Report</x:Name><x:WorksheetOptions><x:Print><x:ValidPrinterInfo/></x:Print></x:WorksheetOptions>';
    echo '</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml></head><body>';
    
    echo '<table border="1">';
    echo '<thead><tr style="background-color: #667eea; color: white; font-weight: bold;">';
    echo '<th>SRN</th><th>Last Name</th><th>Middle Name</th><th>First Name</th>';
    echo '<th>Date of Birth</th><th>Age</th><th>Gender</th><th>Day Born</th>';
    echo '<th>Contact</th><th>GPS Address</th><th>Residential Address</th>';
    echo '<th>Church</th><th>Class</th><th>Organization</th>';
    echo '<th>School Attend</th><th>School Location</th><th>Education Level</th>';
    echo '<th>Baptized</th><th>Baptism Date</th>';
    echo '<th>Father Name</th><th>Father Contact</th><th>Father Occupation</th><th>Father is Member</th>';
    echo '<th>Mother Name</th><th>Mother Contact</th><th>Mother Occupation</th><th>Mother is Member</th>';
    echo '</tr></thead><tbody>';
    
    foreach ($data as $row) {
        $father_name = $row['father_is_member'] === 'yes' && $row['father_member_name'] 
            ? $row['father_member_name'] : $row['father_name'];
        $mother_name = $row['mother_is_member'] === 'yes' && $row['mother_member_name'] 
            ? $row['mother_member_name'] : $row['mother_name'];
            
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['srn']) . '</td>';
        echo '<td>' . htmlspecialchars($row['last_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['middle_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['first_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['dob']) . '</td>';
        echo '<td>' . htmlspecialchars($row['age']) . '</td>';
        echo '<td>' . htmlspecialchars($row['gender']) . '</td>';
        echo '<td>' . htmlspecialchars($row['dayborn']) . '</td>';
        echo '<td>' . htmlspecialchars($row['contact']) . '</td>';
        echo '<td>' . htmlspecialchars($row['gps_address']) . '</td>';
        echo '<td>' . htmlspecialchars($row['residential_address']) . '</td>';
        echo '<td>' . htmlspecialchars($row['church_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['class_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['organization']) . '</td>';
        echo '<td>' . htmlspecialchars($row['school_attend']) . '</td>';
        echo '<td>' . htmlspecialchars($row['school_location']) . '</td>';
        echo '<td>' . htmlspecialchars(ucwords(str_replace('_', ' ', $row['education_level']))) . '</td>';
        echo '<td>' . htmlspecialchars($row['baptized']) . '</td>';
        echo '<td>' . htmlspecialchars($row['baptism_date']) . '</td>';
        echo '<td>' . htmlspecialchars($father_name) . '</td>';
        echo '<td>' . htmlspecialchars($row['father_contact']) . '</td>';
        echo '<td>' . htmlspecialchars($row['father_occupation']) . '</td>';
        echo '<td>' . htmlspecialchars($row['father_is_member']) . '</td>';
        echo '<td>' . htmlspecialchars($mother_name) . '</td>';
        echo '<td>' . htmlspecialchars($row['mother_contact']) . '</td>';
        echo '<td>' . htmlspecialchars($row['mother_occupation']) . '</td>';
        echo '<td>' . htmlspecialchars($row['mother_is_member']) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody></table></body></html>';
    exit;
}

if ($format === 'pdf') {
    // Simple HTML to PDF conversion
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="sunday_school_report_' . date('Y-m-d') . '.pdf"');
    
    // For a simple implementation, we'll output HTML that browsers can print to PDF
    // For production, consider using libraries like TCPDF or mPDF
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Sunday School Report</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 10px; }
            h1 { text-align: center; color: #667eea; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background-color: #667eea; color: white; padding: 8px; text-align: left; }
            td { border: 1px solid #ddd; padding: 6px; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .header { text-align: center; margin-bottom: 20px; }
            .date { text-align: right; color: #666; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Sunday School Report</h1>
            <div class="date">Generated: <?=date('F d, Y')?></div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>SRN</th>
                    <th>Name</th>
                    <th>Age</th>
                    <th>Gender</th>
                    <th>Church</th>
                    <th>Contact</th>
                    <th>Baptized</th>
                    <th>Education</th>
                    <th>Father</th>
                    <th>Mother</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): 
                    $father_name = $row['father_is_member'] === 'yes' && $row['father_member_name'] 
                        ? $row['father_member_name'] : $row['father_name'];
                    $mother_name = $row['mother_is_member'] === 'yes' && $row['mother_member_name'] 
                        ? $row['mother_member_name'] : $row['mother_name'];
                ?>
                <tr>
                    <td><?=htmlspecialchars($row['srn'])?></td>
                    <td><?=htmlspecialchars($row['last_name'] . ' ' . $row['first_name'])?></td>
                    <td><?=$row['age']?></td>
                    <td><?=htmlspecialchars($row['gender'])?></td>
                    <td><?=htmlspecialchars($row['church_name'])?></td>
                    <td><?=htmlspecialchars($row['contact'])?></td>
                    <td><?=htmlspecialchars($row['baptized'])?></td>
                    <td><?=htmlspecialchars(ucwords(str_replace('_', ' ', $row['education_level'])))?></td>
                    <td><?=htmlspecialchars($father_name)?></td>
                    <td><?=htmlspecialchars($mother_name)?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <script>
            window.onload = function() {
                window.print();
            }
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
