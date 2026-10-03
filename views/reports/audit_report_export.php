<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/audit_report_helper.php';
require_once __DIR__ . '/../../helpers/report_export_document.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication required.');
}

$isSuperAdmin = is_super_admin();
if ((!$isSuperAdmin && !has_permission('view_audit_report'))
    || (!$isSuperAdmin && !has_permission('export_audit_report'))) {
    http_response_code(403);
    exit('You do not have permission to export audit reports.');
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'excel')));
if (!in_array($format, ['excel', 'pdf', 'csv'], true)) {
    $format = 'excel';
}

$context = audit_report_context($conn, $_GET, $isSuperAdmin);
$sql = "SELECT a.created_at,
               COALESCE(NULLIF(u.name, ''), 'System') AS user_name,
               a.action, a.entity_type, a.entity_id, a.ip_address, a.details
        FROM audit_log a
        LEFT JOIN users u ON u.id = a.user_id
        {$context['where_sql']}
        ORDER BY a.created_at DESC, a.id DESC";
$statement = $conn->prepare($sql);
audit_report_bind($statement, $context['types'], $context['params']);
$statement->execute();
$result = $statement->get_result();

$headers = ['Date and time', 'Actor', 'Action', 'Target', 'IP address', 'Recorded evidence'];
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        $row['created_at'],
        $row['user_name'],
        ucwords(str_replace('_', ' ', $row['action'])),
        audit_report_entity_label($row['entity_type'], $row['entity_id']),
        $row['ip_address'] ?: '-',
        audit_report_details_text($row['details']),
    ];
}
$statement->close();

$filters = $context['filters'];
$period = ($filters['from_date'] !== '' || $filters['to_date'] !== '')
    ? 'Filtered period: ' . ($filters['from_date'] ?: 'Beginning') . ' to ' . ($filters['to_date'] ?: 'Present')
    : 'Complete authorized audit history';
$branding = report_export_branding_context($conn, $filters['church_id'] ?: null);
$stamp = date('Y-m-d_His');

if ($format === 'csv') {
    $filename = 'audit_security_report_' . $stamp . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, $headers);
    foreach ($rows as $exportRow) {
        fputcsv($output, $exportRow);
    }
    fclose($output);
    exit;
}

if ($format === 'pdf') {
    report_export_download_pdf(
        $branding,
        'Audit & Security Report',
        $period,
        $headers,
        $rows,
        'audit_security_report_' . $stamp . '.pdf',
        [64, 74, 74, 74, 65, 190]
    );
}

report_export_download_excel(
    $branding,
    'Audit & Security Report',
    $period,
    $headers,
    $rows,
    'audit_security_report_' . $stamp . '.xls'
);
