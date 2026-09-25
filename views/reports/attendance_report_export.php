<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/report_export_branding.php';
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
    $churchStatistics = $service->canViewChurchStatistics()
        ? $service->buildChurchStatistics($churchId, $fromDate, $toDate)
        : ['rows' => [], 'totals' => ['male' => 0, 'female' => 0, 'unspecified' => 0, 'total' => 0]];
    $includeChurchStatistics = $service->canViewChurchStatistics() && $status === 'present' && $categoryId === null;

    $churchName = 'Church';
    foreach ($service->getAllowedChurches() as $church) {
        if ((int) $church['id'] === $churchId) $churchName = $church['name'];
    }
    $exportBranding = report_export_branding_context($conn, $churchId, $churchName);
    $filename = 'attendance_report_' . $fromDate . '_to_' . $toDate;

    $rows = [['Attendance Type', 'Breakdown', 'Aggregation', 'Male', 'Female', 'Unspecified', 'Total', 'Sessions']];
    foreach ($report['summary'] as $main) {
        $rows[] = [$main['main_name'], 'TOTAL', 'sum', exported_attendance_number($main['male']), exported_attendance_number($main['female']), exported_attendance_number($main['unspecified']), exported_attendance_number($main['total']), (int) $main['sessions']];
        foreach ($report['breakdown'] as $detail) {
            if ((int) $detail['main_id'] !== (int) $main['main_id']) continue;
            $rows[] = [$main['main_name'], $detail['breakdown_name'], $detail['aggregation_method'], exported_attendance_number($detail['male']), exported_attendance_number($detail['female']), exported_attendance_number($detail['unspecified']), exported_attendance_number($detail['total']), (int) $detail['sessions']];
        }
    }
    $combinedTotals = $report['totals'];
    if ($includeChurchStatistics && $churchStatistics['rows']) {
        $rows[] = ['Membership & Pastoral Events', '', '', '', '', '', '', ''];
        foreach ($churchStatistics['rows'] as $statistic) {
            $rows[] = [$statistic['label'], '', 'count', $statistic['male'], $statistic['female'], $statistic['unspecified'], $statistic['total'], ''];
        }
        foreach (['male', 'female', 'unspecified', 'total'] as $field) {
            $combinedTotals[$field] = (float) $combinedTotals[$field] + (int) $churchStatistics['totals'][$field];
        }
    }
    $rows[] = ['GRAND TOTAL', '', '', exported_attendance_number($combinedTotals['male']), exported_attendance_number($combinedTotals['female']), exported_attendance_number($combinedTotals['unspecified']), exported_attendance_number($combinedTotals['total']), ''];

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
<?= report_export_branding_css($exportBranding) ?>
body{font-family:Arial,sans-serif;color:#1f2933;margin:28px}.meta{text-align:right;font-size:13px}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #9aa8b3;padding:7px}th{text-align:left}.num{text-align:right}.main td{background:#eaf2f8;font-weight:bold}.grand td{font-weight:bold;background:#dcebdd}.no-print{margin-bottom:16px;padding:8px 12px}@media print{.no-print{display:none}body{margin:12mm}tr{break-inside:avoid}}
</style></head><body>
<?php if ($format === 'print'): ?><button class="no-print" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>
<?= report_export_branding_header_html($exportBranding, 'Unified Attendance Report', $fromDate . ' to ' . $toDate . ' | Status: ' . ucfirst($status)) ?>
<table><thead><tr><?php foreach ($rows[0] as $heading): ?><th><?= htmlspecialchars((string) $heading) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (array_slice($rows, 1) as $row): $class = $row[0] === 'GRAND TOTAL' ? 'grand' : ($row[1] === 'TOTAL' || $row[0] === 'Membership & Pastoral Events' ? 'main' : ''); ?>
<tr class="<?= $class ?>"><?php foreach ($row as $index => $cell): ?><td class="<?= $index >= 3 ? 'num' : '' ?>"><?= htmlspecialchars((string) $cell) ?></td><?php endforeach; ?></tr>
<?php endforeach; ?></tbody></table>
<?= report_export_branding_footer_html($exportBranding) ?>
<?php if ($format === 'print'): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html>
