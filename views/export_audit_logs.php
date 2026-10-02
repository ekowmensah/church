<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/audit_log_view.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication is required.');
}

$globalScope = is_super_admin();
if (!$globalScope && !has_permission('view_audit_log')) {
    http_response_code(403);
    exit('You do not have permission to export audit logs.');
}

$filters = audit_log_view_filters($_GET);
$rows = audit_log_view_rows(
    $conn,
    $filters,
    $globalScope,
    $globalScope ? null : audit_log_view_church_id($conn)
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store, no-cache, must-revalidate');

$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Date/Time', 'User', 'Email', 'Action', 'Entity Type', 'Entity ID', 'IP Address', 'Details']);
foreach ($rows as $row) {
    $values = [
        $row['created_at'], $row['actor_name'], $row['actor_email'], $row['action'],
        $row['entity_type'], $row['entity_id'], $row['ip_address'], $row['details'],
    ];
    $values = array_map(static function ($value) {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
    }, $values);
    fputcsv($output, $values);
}
fclose($output);
exit;

