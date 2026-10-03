<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/feedback_report_helper.php';
require_once __DIR__ . '/../../helpers/report_export_document.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Authentication required.');
}
$isSuperAdmin = is_super_admin();
if ((!$isSuperAdmin && !has_permission('view_feedback_report'))
    || (!$isSuperAdmin && !has_permission('export_feedback_report'))) {
    http_response_code(403);
    exit('You do not have permission to export feedback reports.');
}

$context = feedback_report_context($_GET, $isSuperAdmin);
$statement = $conn->prepare("SELECT f.submitted_at,m.crn,
    TRIM(CONCAT_WS(' ',m.first_name,m.middle_name,m.last_name)) member_name,
    c.name church_name,f.message
    FROM member_feedback f LEFT JOIN members m ON m.id=f.member_id
    LEFT JOIN churches c ON c.id=m.church_id {$context['where_sql']}
    ORDER BY f.submitted_at DESC,f.id DESC");
feedback_report_bind($statement, $context['types'], $context['params']);
$statement->execute();
$result = $statement->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [$row['submitted_at'],$row['crn']?:'-',$row['member_name']?:'Unknown member',$row['church_name']?:'-',$row['message']?:''];
}
$statement->close();
$filters = $context['filters'];
$subtitle = ($filters['from_date'] || $filters['to_date'])
    ? 'Submitted: ' . ($filters['from_date'] ?: 'Beginning') . ' to ' . ($filters['to_date'] ?: 'Present')
    : 'Complete authorized feedback history';
$branding = report_export_branding_context($conn, $filters['church_id'] ?: null);
report_export_download_excel(
    $branding,
    'Feedback Report',
    $subtitle,
    ['Submitted','CRN','Contributor','Church','Message'],
    $rows,
    'feedback_report_' . date('Y-m-d_His') . '.xls'
);
