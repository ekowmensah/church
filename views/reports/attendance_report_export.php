<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../services/UnifiedAttendanceReportService.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Sign in to export attendance reports.');
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true);
if ((!$isSuperAdmin && !has_permission('view_attendance_report')) || (!$isSuperAdmin && !has_permission('export_attendance_report'))) {
    http_response_code(403);
    exit('You do not have permission to export attendance reports.');
}

function exported_attendance_number($value): string {
    $number = (float) $value;
    return abs($number - round($number)) < 0.001 ? number_format($number, 0, '.', '') : number_format($number, 2, '.', '');
}

try {
    $format = (string) ($_GET['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'excel', 'print'], true)) throw new InvalidArgumentException('Invalid export format.');
    $churchId = (int) ($_GET['church_id'] ?? 0);
    $preset = (string) ($_GET['period'] ?? 'custom');
    $status = (string) ($_GET['status'] ?? 'present');
    $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int) $_GET['category_id'] : null;
    $service = UnifiedAttendanceReportService::fromSession($conn);
    [$fromDate, $toDate] = $service->resolvePeriod($preset, $_GET['from_date'] ?? null, $_GET['to_date'] ?? null);
    $report = $service->buildReport($churchId, $fromDate, $toDate, $status, $categoryId);
    $status = $report['status'];

    $churchName = 'Church';
    foreach ($service->getAllowedChurches() as $church) {
        if ((int) $church['id'] === $churchId) $churchName = $church['name'];
    }
    $filename = 'attendance_report_' . $fromDate . '_to_' . $toDate;

    $rows = [['Attendance Type', 'Breakdown', 'Aggregation', 'Male', 'Female', 'Unspecified', 'Total', 'Sessions']];
    foreach ($report['summary'] as $main) {
        $rows[] = [$main['main_name'], 'TOTAL', 'sum', exported_attendance_number($main['male']), exported_attendance_number($main['female']), exported_attendance_number($main['unspecified']), exported_attendance_number($main['total']), (int) $main['sessions']];
        foreach ($report['breakdown'] as $detail) {
            if ((int) $detail['main_id'] !== (int) $main['main_id']) continue;
            $rows[] = [$main['main_name'], $detail['breakdown_name'], $detail['aggregation_method'], exported_attendance_number($detail['male']), exported_attendance_number($detail['female']), exported_attendance_number($detail['unspecified']), exported_attendance_number($detail['total']), (int) $detail['sessions']];
        }
    }
    $rows[] = ['GRAND TOTAL', '', '', exported_attendance_number($report['totals']['male']), exported_attendance_number($report['totals']['female']), exported_attendance_number($report['totals']['unspecified']), exported_attendance_number($report['totals']['total']), ''];

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF";
        $stream = fopen('php://output', 'w');
        fputcsv($stream, [$churchName, 'Unified Attendance Report']);
        fputcsv($stream, ['Period', $fromDate . ' to ' . $toDate, 'Status', ucfirst($status)]);
        fputcsv($stream, []);
        foreach ($rows as $row) fputcsv($stream, $row);
        fclose($stream);
        exit;
    }

    if ($format === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    }
} catch (Throwable $exception) {
    http_response_code(400);
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Unified Attendance Report</title>
<style>
body{font-family:Arial,sans-serif;color:#1f2933;margin:28px}.header{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #236b45;padding-bottom:12px;margin-bottom:18px}.header h1{font-size:22px;font-style:italic;margin:0;color:#173f60}.meta{text-align:right;font-size:13px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #9aa8b3;padding:7px}th{background:#173f60;color:#fff;text-align:left}.num{text-align:right}.main td{background:#eaf2f8;font-weight:bold}.grand td{font-weight:bold;background:#dcebdd}.footer{text-align:center;color:#236b45;margin-top:20px;font-size:11px}.no-print{margin-bottom:16px;padding:8px 12px}@media print{.no-print{display:none}body{margin:12mm}.header{break-after:avoid}tr{break-inside:avoid}}
</style></head><body>
<?php if ($format === 'print'): ?><button class="no-print" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>
<div class="header"><div><strong><?= htmlspecialchars($churchName) ?></strong><div><?= htmlspecialchars($fromDate) ?> to <?= htmlspecialchars($toDate) ?></div></div><div class="meta"><h1>Unified Attendance Report</h1>Status: <?= htmlspecialchars(ucfirst($status)) ?></div></div>
<table><thead><tr><?php foreach ($rows[0] as $heading): ?><th><?= htmlspecialchars((string) $heading) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (array_slice($rows, 1) as $row): $class = $row[0] === 'GRAND TOTAL' ? 'grand' : ($row[1] === 'TOTAL' ? 'main' : ''); ?>
<tr class="<?= $class ?>"><?php foreach ($row as $index => $cell): ?><td class="<?= $index >= 3 ? 'num' : '' ?>"><?= htmlspecialchars((string) $cell) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table>
<div class="footer">Generated by MyFreeman Church Portal on <?= htmlspecialchars(gmdate('Y-m-d H:i')) ?> UTC</div>
<?php if ($format === 'print'): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html>
