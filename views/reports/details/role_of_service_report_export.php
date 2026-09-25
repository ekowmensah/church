<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../helpers/report_export_branding.php';
require_once __DIR__ . '/../../../services/RoleOfServingReportService.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Sign in to export this report.');
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true);
if ((!$isSuperAdmin && !has_permission('view_role_of_service_report'))
    || (!$isSuperAdmin && !has_permission('export_role_of_service_report'))) {
    http_response_code(403);
    exit('You do not have permission to export this report.');
}

try {
    $format = (string) ($_GET['format'] ?? 'csv');
    if (!in_array($format, ['csv', 'excel', 'print'], true)) {
        throw new InvalidArgumentException('Choose CSV, Excel, or print/PDF.');
    }
    $churchId = (int) ($_GET['church_id'] ?? 0);
    $servingRoleId = !empty($_GET['role_id']) ? (int) $_GET['role_id'] : null;
    $organizationId = !empty($_GET['organization_id']) ? (int) $_GET['organization_id'] : null;
    $gender = in_array($_GET['gender'] ?? '', ['Male', 'Female', 'Unspecified'], true) ? $_GET['gender'] : null;
    $service = RoleOfServingReportService::fromSession($conn);
    $report = $service->build($churchId, $servingRoleId, $organizationId, $gender);
    $churchName = 'Church';
    foreach ($service->getAllowedChurches() as $church) {
        if ((int) $church['id'] === $churchId) $churchName = $church['name'];
    }
    $exportBranding = report_export_branding_context($conn, $churchId, $churchName);
    $generatedAt = gmdate('Y-m-d H:i') . ' UTC';
    $filename = 'role_of_serving_report_' . gmdate('Y-m-d');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        echo "\xEF\xBB\xBF";
        $stream = fopen('php://output', 'w');
        fputcsv($stream, [$churchName, 'Role of Serving Report']);
        fputcsv($stream, ['Generated', $generatedAt, 'Scope', 'Current active role holders']);
        fputcsv($stream, []);
        fputcsv($stream, ['Role of Serving', 'Male', 'Female', 'Unspecified', 'Total']);
        foreach ($report['summary'] as $row) {
            fputcsv($stream, [$row['role_name'], $row['male'], $row['female'], $row['unspecified'], $row['total']]);
        }
        fputcsv($stream, ['UNIQUE MEMBERS', $report['totals']['male'], $report['totals']['female'], $report['totals']['unspecified'], $report['totals']['total']]);
        fputcsv($stream, []);
        fputcsv($stream, ['CRN', 'Member', 'Role of Serving', 'Gender', 'Bible Class', 'Organization(s)', 'Contact', 'Email']);
        foreach ($report['members'] as $member) {
            fputcsv($stream, [$member['crn'], $member['member_name'], $member['role_name'], $member['gender'], $member['class_name'], $member['organizations'], $member['phone'], $member['email']]);
        }
        fputcsv($stream, []);
        fputcsv($stream, ['Powered By: MyFreeman Digital Networks - Evangelism, Through Digitalization!']);
        fputcsv($stream, ['FDN: Extenditque Manum Omni Membri, Ubique!!']);
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
<html lang="en"><head><meta charset="utf-8"><title>Role of Serving Report</title>
<style>
<?= report_export_branding_css($exportBranding) ?>
body{font-family:Arial,sans-serif;color:#25212b;margin:28px}.meta{text-align:right;font-size:12px}h2{font-size:17px;color:<?= htmlspecialchars($exportBranding['primary_color']) ?>;margin-top:22px}table{border-collapse:collapse;width:100%;font-size:11px}th,td{border:1px solid #9b91a2;padding:6px}th{text-align:left}.num{text-align:right}.total td{font-weight:bold;background:#eee5f3}.no-print{margin-bottom:14px;padding:8px 12px}@media print{.no-print{display:none}body{margin:12mm}tr{break-inside:avoid}}
</style></head><body>
<?php if ($format === 'print'): ?><button class="no-print" onclick="window.print()">Print / Save as PDF</button><?php endif; ?>
<?= report_export_branding_header_html($exportBranding, 'Role of Serving Report', 'Current active role holders | Generated ' . $generatedAt) ?>
<h2>Gender Summary by Role</h2><table><thead><tr><th>Role of Serving</th><th>Male</th><th>Female</th><th>Unspecified</th><th>Total</th></tr></thead><tbody><?php foreach ($report['summary'] as $row): ?><tr><td><?= htmlspecialchars($row['role_name']) ?></td><td class="num"><?= (int) $row['male'] ?></td><td class="num"><?= (int) $row['female'] ?></td><td class="num"><?= (int) $row['unspecified'] ?></td><td class="num"><?= (int) $row['total'] ?></td></tr><?php endforeach; ?><tr class="total"><td>Unique Members</td><td class="num"><?= $report['totals']['male'] ?></td><td class="num"><?= $report['totals']['female'] ?></td><td class="num"><?= $report['totals']['unspecified'] ?></td><td class="num"><?= $report['totals']['total'] ?></td></tr></tbody></table>
<h2>Role Holder Details</h2><table><thead><tr><th>CRN</th><th>Member</th><th>Role</th><th>Gender</th><th>Bible Class</th><th>Organization(s)</th><th>Contact</th></tr></thead><tbody><?php foreach ($report['members'] as $member): ?><tr><td><?= htmlspecialchars($member['crn'] ?: '-') ?></td><td><?= htmlspecialchars($member['member_name']) ?></td><td><?= htmlspecialchars($member['role_name']) ?></td><td><?= htmlspecialchars($member['gender']) ?></td><td><?= htmlspecialchars($member['class_name'] ?: '-') ?></td><td><?= htmlspecialchars($member['organizations'] ?: '-') ?></td><td><?= htmlspecialchars($member['phone'] ?: '-') ?></td></tr><?php endforeach; ?></tbody></table>
<?= report_export_branding_footer_html($exportBranding) ?>
<?php if ($format === 'print'): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html>
