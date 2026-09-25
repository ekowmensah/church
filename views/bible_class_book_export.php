<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/leader_helpers.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/bible_class_book_helper.php';
require_once __DIR__ . '/../helpers/report_export_branding.php';
require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication required.');
}

$classId = (int) ($_GET['class_id'] ?? 0);
$year = (int) ($_GET['year'] ?? date('Y'));
$quarter = (int) ($_GET['quarter'] ?? bcb_quarter_from_month((int) date('n')));
$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if ($classId < 1 || $year < 2020 || $year > ((int) date('Y') + 1) || !in_array($quarter, [1, 2, 3, 4], true)) {
    http_response_code(400);
    exit('Invalid export selection.');
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$memberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
$isLeader = (bool) is_bible_class_leader($conn, $userId, $memberId);
$isSuper = has_permission('*') || has_role('Super Admin');
$hasExportPermission = has_permission('export_bible_class_book');
$leaderScoped = $isLeader && !$hasExportPermission;
if (!$hasExportPermission && !$isLeader) {
    http_response_code(403);
    exit('You do not have permission to export Bible Class Books.');
}

$classStmt = $conn->prepare(
    'SELECT class.id, class.name, class.code, class.church_id, church.name AS church_name
       FROM bible_classes class JOIN churches church ON church.id = class.church_id
      WHERE class.id = ? LIMIT 1'
);
$classStmt->bind_param('i', $classId);
$classStmt->execute();
$class = $classStmt->get_result()->fetch_assoc();
$classStmt->close();
if (!$class) {
    http_response_code(404);
    exit('Bible class not found.');
}

if ($leaderScoped) {
    $scheduleService = BibleClassAttendanceScheduleService::fromSession($conn);
    if (!$scheduleService->canAccessClass($classId)) {
        http_response_code(403);
        exit('You cannot export that Bible class.');
    }
} elseif (!$isSuper && (int) $class['church_id'] !== (int) (get_user_church_id($conn) ?: 0)) {
    http_response_code(403);
    exit('You cannot export that church data.');
}

$bookData = bcb_build_quarter_book_data($conn, (int) $class['church_id'], $classId, $year, $quarter);
$title = 'Bible Class Book - ' . $class['name'] . ' - Q' . $quarter . ' ' . $year;
$exportBranding = report_export_branding_context($conn, (int) $class['church_id'], (string) $class['church_name']);
$filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $title);
$headers = ['No.', 'Member Status', 'CRN', 'Full Name', 'DOB', 'Marital Status', 'Contact', 'Profession'];
foreach ($bookData['slots'] as $slot) {
    $headers[] = ($slot['entry_type'] === 'attendance' ? 'Attendance ' : 'Payment Week ')
        . $slot['record_date'];
}
$headers[] = 'Quarter Payment Total';

$exportRows = [];
foreach ($bookData['rows'] as $index => $row) {
    $member = $row['member'];
    $exportRow = [
        $index + 1,
        $member['member_status_code'] ?? '',
        $member['crn'] ?? '',
        $member['full_name'] ?? '',
        $member['dob'] ?? '',
        $member['marital_status'] ?? '',
        $member['phone'] ?? '',
        $member['profession'] ?? '',
    ];
    foreach ($bookData['slots'] as $slot) {
        $cell = $row['slots'][$slot['slot_key']] ?? [];
        $exportRow[] = $slot['entry_type'] === 'attendance'
            ? ($cell['attendance_code'] ?? '')
            : number_format((float) ($cell['payment_amount'] ?? 0), 2, '.', '');
    }
    $exportRow[] = number_format((float) ($row['total_amount'] ?? 0), 2, '.', '');
    $exportRows[] = $exportRow;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [$title]);
    fputcsv($output, ['Period', $bookData['start_date'] . ' to ' . $bookData['end_date']]);
    fputcsv($output, $headers);
    foreach ($exportRows as $row) fputcsv($output, $row);
    fputcsv($output, []);
    fputcsv($output, ['Powered By: MyFreeman Digital NetWorks - Evangelism, Through Digitalization!']);
    fputcsv($output, ['FDN: Extenditque Manum Omni Membri, Ubique!!']);
    fclose($output);
    exit;
}

if (!in_array($format, ['xls', 'print'], true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}
if ($format === 'xls') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
}
?>
<!doctype html><html><head><meta charset="utf-8"><title><?= htmlspecialchars($title) ?></title><style>
<?= report_export_branding_css($exportBranding) ?>
body{font-family:Arial,sans-serif;color:#182b3a;margin:24px}.meta{margin:8px 0 16px;color:#526575}table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #8da2b3;padding:5px;white-space:nowrap}.member-col{background:#eaf2f8}.attendance{background:#eef7ff;text-align:center}.payment{background:#eef9ef;text-align:right}@media print{body{margin:10mm}.no-print{display:none}.mf-report-footer{position:fixed;bottom:0;left:0;right:0}}
</style></head><body>
<?= report_export_branding_header_html($exportBranding, $title, 'Reporting period: ' . $bookData['start_date'] . ' to ' . $bookData['end_date']) ?>
<?php if ($format === 'print'): ?><button class="no-print" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>
<table><thead><tr><?php foreach ($headers as $header): ?><th><?= htmlspecialchars($header) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($exportRows as $row): ?><tr><?php foreach ($row as $index => $value): $className = $index < 8 ? 'member-col' : ($index === count($row)-1 ? 'payment' : (($bookData['slots'][$index-8]['entry_type'] ?? '') === 'attendance' ? 'attendance' : 'payment')); ?><td class="<?= $className ?>"><?= htmlspecialchars((string) $value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table>
<?= report_export_branding_footer_html($exportBranding) ?>
<?php if ($format === 'print'): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html>
