<?php

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/report_export_document.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!has_permission('view_sundayschool_list')) {
    http_response_code(403);
    exit('Access denied');
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'excel', 'pdf'], true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}

$churchId = max(0, (int) ($_GET['church_id'] ?? 0));
$isSuperAdmin = report_export_branding_is_super_admin($conn);
if (!$isSuperAdmin) {
    $churchId = report_export_branding_resolve_church_id($conn, $churchId ?: null);
    if ($churchId < 1) {
        http_response_code(403);
        exit('Your account is not linked to an authorized church.');
    }
}

$gender = trim((string) ($_GET['gender'] ?? ''));
$ageFrom = max(0, (int) ($_GET['age_from'] ?? 0));
$ageTo = max(0, (int) ($_GET['age_to'] ?? 0));
$baptized = trim((string) ($_GET['baptized'] ?? ''));
$educationLevel = trim((string) ($_GET['education_level'] ?? ''));

$whereConditions = ['ss.is_duplicate_archived = 0'];
$params = [];
$types = '';
if ($churchId > 0) {
    $whereConditions[] = 'ss.church_id = ?';
    $params[] = $churchId;
    $types .= 'i';
}
if ($gender !== '') {
    $whereConditions[] = 'ss.gender = ?';
    $params[] = $gender;
    $types .= 's';
}
if ($baptized !== '') {
    $whereConditions[] = 'ss.baptized = ?';
    $params[] = $baptized;
    $types .= 's';
}
if ($educationLevel !== '') {
    $whereConditions[] = 'ss.education_level = ?';
    $params[] = $educationLevel;
    $types .= 's';
}
if ($ageFrom > 0 && $ageTo > 0) {
    $whereConditions[] = 'TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) BETWEEN ? AND ?';
    $params[] = $ageFrom;
    $params[] = $ageTo;
    $types .= 'ii';
} elseif ($ageFrom > 0) {
    $whereConditions[] = 'TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) >= ?';
    $params[] = $ageFrom;
    $types .= 'i';
} elseif ($ageTo > 0) {
    $whereConditions[] = 'TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) <= ?';
    $params[] = $ageTo;
    $types .= 'i';
}

$sql = "SELECT
            ss.srn, ss.last_name, ss.middle_name, ss.first_name, ss.dob,
            TIMESTAMPDIFF(YEAR, ss.dob, CURDATE()) AS age,
            ss.gender, ss.dayborn, ss.contact, ss.gps_address,
            ss.residential_address, ss.church_id, church.name AS church_name,
            bible_class.name AS class_name, ss.organization, ss.school_attend,
            ss.school_location, ss.education_level, ss.baptized,
            ss.baptism_date, ss.father_name, ss.father_contact,
            ss.father_occupation, ss.father_is_member, ss.mother_name,
            ss.mother_contact, ss.mother_occupation, ss.mother_is_member,
            CONCAT_WS(' ', father.first_name, father.middle_name, father.last_name) AS father_member_name,
            CONCAT_WS(' ', mother.first_name, mother.middle_name, mother.last_name) AS mother_member_name
        FROM sunday_school ss
        LEFT JOIN churches church ON church.id = ss.church_id
        LEFT JOIN bible_classes bible_class ON bible_class.id = ss.class_id
        LEFT JOIN members father ON father.id = ss.father_member_id
        LEFT JOIN members mother ON mother.id = ss.mother_member_id
        WHERE " . implode(' AND ', $whereConditions) . '
        ORDER BY ss.last_name, ss.first_name';
$statement = $conn->prepare($sql);
if ($params) {
    $statement->bind_param($types, ...$params);
}
$statement->execute();
$data = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
$statement->close();

$resolvedBrandChurchId = $churchId;
if ($resolvedBrandChurchId < 1 && $data) {
    $churchIds = array_values(array_unique(array_map('intval', array_column($data, 'church_id'))));
    if (count($churchIds) === 1) {
        $resolvedBrandChurchId = $churchIds[0];
    }
}
$branding = report_export_branding_context($conn, $resolvedBrandChurchId ?: null);

$filterSummary = [];
if ($gender !== '') $filterSummary[] = 'Gender: ' . $gender;
if ($ageFrom > 0 || $ageTo > 0) {
    $filterSummary[] = 'Age: ' . ($ageFrom ?: '0') . '-' . ($ageTo ?: 'Any');
}
if ($baptized !== '') $filterSummary[] = 'Baptized: ' . ucfirst($baptized);
if ($educationLevel !== '') {
    $filterSummary[] = 'Education: ' . ucwords(str_replace('_', ' ', $educationLevel));
}
$filterSummary[] = 'Generated: ' . date('F j, Y');
$subtitle = implode(' | ', $filterSummary);

$headers = [
    'SRN', 'Last Name', 'Middle Name', 'First Name', 'Date of Birth', 'Age',
    'Gender', 'Day Born', 'Contact', 'GPS Address', 'Residential Address',
    'Church', 'Class', 'Organization', 'School Attend', 'School Location',
    'Education Level', 'Baptized', 'Baptism Date', 'Father Name',
    'Father Contact', 'Father Occupation', 'Father is Member', 'Mother Name',
    'Mother Contact', 'Mother Occupation', 'Mother is Member',
];
$rows = [];
$pdfRows = [];
foreach ($data as $row) {
    $fatherName = strtolower((string) $row['father_is_member']) === 'yes'
        && trim((string) $row['father_member_name']) !== ''
        ? $row['father_member_name'] : $row['father_name'];
    $motherName = strtolower((string) $row['mother_is_member']) === 'yes'
        && trim((string) $row['mother_member_name']) !== ''
        ? $row['mother_member_name'] : $row['mother_name'];
    $education = ucwords(str_replace('_', ' ', (string) $row['education_level']));
    $rows[] = [
        $row['srn'], $row['last_name'], $row['middle_name'], $row['first_name'],
        $row['dob'], $row['age'], $row['gender'], $row['dayborn'], $row['contact'],
        $row['gps_address'], $row['residential_address'], $row['church_name'],
        $row['class_name'], $row['organization'], $row['school_attend'],
        $row['school_location'], $education, $row['baptized'], $row['baptism_date'],
        $fatherName, $row['father_contact'], $row['father_occupation'],
        $row['father_is_member'], $motherName, $row['mother_contact'],
        $row['mother_occupation'], $row['mother_is_member'],
    ];
    $pdfRows[] = [
        $row['srn'],
        trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name']]))),
        $row['age'], $row['gender'], $row['church_name'], $row['contact'],
        $row['baptized'], $education, $fatherName, $motherName,
    ];
}

$filenameStem = 'sunday_school_report_' . date('Y-m-d');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameStem . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

if ($format === 'excel') {
    report_export_download_excel(
        $branding,
        'Sunday School Report',
        $subtitle,
        $headers,
        $rows,
        $filenameStem . '.xls'
    );
    exit;
}

report_export_download_pdf(
    $branding,
    'Sunday School Report',
    $subtitle,
    ['SRN', 'Name', 'Age', 'Gender', 'Church', 'Contact', 'Baptized', 'Education', 'Father', 'Mother'],
    $pdfRows,
    $filenameStem . '.pdf',
    [65, 120, 30, 45, 90, 65, 50, 75, 115, 115]
);
exit;
