<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('export_asset_register');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$departmentId = isset($_GET['department_id']) && (int) $_GET['department_id'] > 0 ? (int) $_GET['department_id'] : null;
$condition = trim((string) ($_GET['condition_status'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$conditions = asset_condition_options();

$sql = "
    SELECT a.asset_code, a.item_group, a.item_name, d.name AS department_name, c.name AS church_name,
           a.purchase_date, a.quantity, a.receipt_or_serial_number, a.amount, a.condition_status,
           a.status, a.allocation_note, a.created_at, a.updated_at
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    LEFT JOIN churches c ON c.id = a.church_id
    WHERE 1
";
$types = '';
$params = [];

if ($churchId !== null) {
    $sql .= ' AND a.church_id = ?';
    $types .= 'i';
    $params[] = $churchId;
}
if ($departmentId !== null) {
    $sql .= ' AND a.department_id = ?';
    $types .= 'i';
    $params[] = $departmentId;
}
if ($condition !== '' && in_array($condition, $conditions, true)) {
    $sql .= ' AND a.condition_status = ?';
    $types .= 's';
    $params[] = $condition;
}
if ($status !== '' && in_array($status, ['active', 'disposed'], true)) {
    $sql .= ' AND a.status = ?';
    $types .= 's';
    $params[] = $status;
}
if ($q !== '') {
    $sql .= ' AND (a.asset_code LIKE ? OR a.item_name LIKE ? OR a.item_group LIKE ? OR a.receipt_or_serial_number LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY a.created_at DESC';

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="asset_register_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Asset Code', 'Item Group', 'Item Name', 'Department', 'Church',
    'Purchase Date', 'Quantity', 'Receipt/Serial Number', 'Amount', 'Condition Status',
    'Status', 'Allocation Note', 'Created At', 'Updated At'
]);

while ($row = $res->fetch_assoc()) {
    fputcsv($out, [
        $row['asset_code'],
        $row['item_group'],
        $row['item_name'],
        $row['department_name'],
        $row['church_name'],
        $row['purchase_date'],
        $row['quantity'],
        $row['receipt_or_serial_number'],
        $row['amount'],
        $row['condition_status'],
        $row['status'],
        $row['allocation_note'],
        $row['created_at'],
        $row['updated_at'],
    ]);
}

fclose($out);
$stmt->close();
exit;
?>
