<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/bible_class_capacity.php';
require_once __DIR__ . '/../helpers/report_export_document.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('export_bibleclass')) {
    http_response_code(403);
    exit('Export permission is required.');
}

$format = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
if (!in_array($format, ['csv', 'excel', 'pdf', 'print'], true)) {
    http_response_code(400);
    exit('Unsupported export format.');
}

$churchId = max(0, (int) ($_GET['church_id'] ?? 0));
if (!$isSuperAdmin) {
    $churchId = (int) get_user_church_id($conn);
    if ($churchId < 1) {
        http_response_code(403);
        exit('Your account is not linked to an authorized church.');
    }
}
$search = trim((string) ($_GET['q'] ?? ''));
$capacityRulesAvailable = bible_class_rules_table_exists($conn);
$where = [];
$types = '';
$params = [];
if ($churchId > 0) {
    $where[] = 'bc.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($search !== '') {
    $where[] = "(bc.name LIKE CONCAT('%', ?, '%')
        OR bc.code LIKE CONCAT('%', ?, '%')
        OR church.name LIKE CONCAT('%', ?, '%'))";
    $types .= 'sss';
    array_push($params, $search, $search, $search);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT bc.id, bc.name, bc.code, church.name AS church_name,
        COALESCE(member_count.active_member_count, 0) AS active_member_count,
        " . ($capacityRulesAvailable ? 'COALESCE(rule.max_members, 25)' : '25') . " AS max_members,
        " . ($capacityRulesAvailable ? 'COALESCE(rule.enforce_limit, 1)' : '1') . " AS enforce_limit,
        COALESCE(primary_user.name,
            TRIM(CONCAT_WS(' ', primary_member.first_name, primary_member.middle_name, primary_member.last_name)),
            legacy_user.name, 'Not assigned') AS primary_leader,
        (SELECT GROUP_CONCAT(DISTINCT COALESCE(assistant_user.name,
                    TRIM(CONCAT_WS(' ', assistant_member.first_name, assistant_member.middle_name, assistant_member.last_name)))
                    ORDER BY COALESCE(assistant_user.name, assistant_member.first_name) SEPARATOR ', ')
           FROM bible_class_leaders assistant_assignment
           LEFT JOIN users assistant_user ON assistant_user.id = assistant_assignment.user_id
           LEFT JOIN members assistant_member ON assistant_member.id = assistant_assignment.member_id
          WHERE assistant_assignment.class_id = bc.id
            AND assistant_assignment.status = 'active'
            AND assistant_assignment.leader_role = 'assistant') AS assistant_leaders
    FROM bible_classes bc
    LEFT JOIN churches church ON church.id = bc.church_id
    LEFT JOIN users legacy_user ON legacy_user.id = bc.leader_id
    LEFT JOIN bible_class_leaders primary_assignment ON primary_assignment.id = (
        SELECT assignment.id FROM bible_class_leaders assignment
         WHERE assignment.class_id = bc.id
           AND assignment.status = 'active'
           AND assignment.leader_role = 'primary'
         ORDER BY assignment.assigned_date DESC, assignment.id DESC LIMIT 1
    )
    LEFT JOIN users primary_user ON primary_user.id = primary_assignment.user_id
    LEFT JOIN members primary_member ON primary_member.id = primary_assignment.member_id
    " . ($capacityRulesAvailable ? 'LEFT JOIN bible_class_rules rule ON rule.class_id = bc.id' : '') . "
    LEFT JOIN (
        SELECT class_id, COUNT(*) AS active_member_count
          FROM members
         WHERE class_id IS NOT NULL AND status = 'active' AND is_archived = 0
         GROUP BY class_id
    ) member_count ON member_count.class_id = bc.id
    $whereSql
    ORDER BY church.name, bc.name, bc.id";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$branding = report_export_branding_context($conn, $churchId ?: null);
$filterParts = [];
if ($churchId > 0) {
    $filterParts[] = 'Church: ' . ($records[0]['church_name'] ?? ($branding['church_name'] ?? 'Selected church'));
}
if ($search !== '') $filterParts[] = 'Search: ' . $search;
$filterParts[] = 'Generated: ' . date('F j, Y');
$subtitle = implode(' | ', $filterParts);
$headers = ['Code', 'Bible Class', 'Active Members', 'Capacity', 'Primary Leader', 'Assistant Leaders', 'Church'];
$rows = [];
foreach ($records as $record) {
    $capacity = (int) $record['enforce_limit'] === 1
        ? ((int) $record['active_member_count'] . ' / ' . (int) $record['max_members'])
        : 'Not enforced';
    $rows[] = [
        $record['code'] ?: 'Not assigned',
        $record['name'],
        (int) $record['active_member_count'],
        $capacity,
        $record['primary_leader'],
        $record['assistant_leaders'] ?: 'None',
        $record['church_name'] ?: 'Not assigned',
    ];
}

$filenameStem = 'bible_class_directory_' . date('Y-m-d');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameStem . '.csv"');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Bible Class Directory']);
    fputcsv($output, [$subtitle]);
    fputcsv($output, []);
    fputcsv($output, $headers);
    foreach ($rows as $row) fputcsv($output, $row);
    fclose($output);
    exit;
}

if ($format === 'excel') {
    report_export_download_excel($branding, 'Bible Class Directory', $subtitle, $headers, $rows, $filenameStem . '.xls');
    exit;
}

if ($format === 'pdf') {
    report_export_download_pdf(
        $branding,
        'Bible Class Directory',
        $subtitle,
        $headers,
        $rows,
        $filenameStem . '.pdf',
        [70, 115, 58, 65, 115, 125, 110]
    );
    exit;
}

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Bible Class Directory</title>
<style>
@page{size:landscape;margin:12mm}body{font-family:Arial,sans-serif;color:#17233c;margin:0}h1{margin:0;color:#173f5f}.subtitle{color:#596779;font-size:11px;margin:6px 0 18px}table{border-collapse:collapse;width:100%;font-size:10px}th{background:#173f5f;color:#fff;text-align:left}th,td{border:1px solid #cbd4da;padding:6px}tbody tr:nth-child(even){background:#f3f7f9}.footer{text-align:center;color:#236b45;font-size:9px;margin-top:14px}.no-print{margin-bottom:12px}@media print{.no-print{display:none}}
</style></head><body>
<button class="no-print" type="button" onclick="window.print()">Print directory</button>
<h1><?= $h($branding['church_name'] ?? 'Church') ?> &mdash; Bible Class Directory</h1>
<div class="subtitle"><?= $h($subtitle) ?></div>
<table><thead><tr><?php foreach ($headers as $header): ?><th><?= $h($header) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">No Bible Classes matched the selected filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><?php foreach ($row as $value): ?><td><?= $h($value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table>
<div class="footer"><?= $h($branding['footer_line_one'] ?? '') ?><br><?= $h($branding['footer_line_two'] ?? '') ?></div>
<script>window.addEventListener('load', function () { window.print(); });</script>
</body></html>
