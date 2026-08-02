<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('export_asset_register');

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? (isset($_GET['church_id']) && (int) $_GET['church_id'] > 0 ? (int) $_GET['church_id'] : null) : asset_current_church_id($conn);
$departmentId = isset($_GET['department_id']) && (int) $_GET['department_id'] > 0 ? (int) $_GET['department_id'] : null;
$assetGroupId = isset($_GET['asset_group_id']) && (int) $_GET['asset_group_id'] > 0 ? (int) $_GET['asset_group_id'] : null;
$condition = trim((string) ($_GET['condition_status'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$acquisitionMode = trim((string) ($_GET['acquisition_mode'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));

$conditions = asset_condition_options();
$hasLifecycle = asset_can_use_lifecycle($conn);
$hasMaintenanceFields = asset_can_use_maintenance_fields($conn);
$hasGroups = asset_can_use_groups($conn);
$hasAcquisitionMode = asset_column_exists($conn, 'assets', 'acquisition_mode');
$acquisitionModes = asset_acquisition_mode_options();

$sql = "
    SELECT a.asset_code" . ($hasGroups ? ", g.name AS asset_group_name, g.group_code AS asset_group_code" : "") . ",
           a.item_group, a.item_name" . ($hasAcquisitionMode ? ", a.acquisition_mode, a.acquisition_mode_other" : "") . ",
           d.name AS department_name, c.name AS church_name,
           a.purchase_date" . ($hasMaintenanceFields ? ", a.warranty_expiry_date, a.last_maintenance_date, a.next_maintenance_date" : "") . ",
           a.quantity, a.receipt_or_serial_number" . (asset_column_exists($conn, 'assets', 'receipt_number') ? ", a.receipt_number" : "") . (asset_column_exists($conn, 'assets', 'serial_number') ? ", a.serial_number" : "") . ",
           a.amount, a.condition_status,
           a.status" . ($hasLifecycle ? ", a.lifecycle_status" : "") . ", a.allocation_note, a.created_at, a.updated_at
    FROM assets a
    LEFT JOIN asset_departments d ON d.id = a.department_id
    LEFT JOIN churches c ON c.id = a.church_id
    " . ($hasGroups ? "LEFT JOIN asset_groups g ON g.id = a.asset_group_id" : "") . "
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
if ($hasGroups && $assetGroupId !== null) {
    $sql .= ' AND a.asset_group_id = ?';
    $types .= 'i';
    $params[] = $assetGroupId;
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
if ($hasAcquisitionMode && $acquisitionMode !== '' && array_key_exists($acquisitionMode, $acquisitionModes)) {
    $sql .= ' AND a.acquisition_mode = ?';
    $types .= 's';
    $params[] = $acquisitionMode;
}
if ($q !== '') {
    $sql .= ' AND (a.asset_code LIKE ? OR a.item_name LIKE ? OR a.item_group LIKE ? OR a.receipt_or_serial_number LIKE ?';
    if (asset_column_exists($conn, 'assets', 'receipt_number')) {
        $sql .= ' OR a.receipt_number LIKE ?';
    }
    if (asset_column_exists($conn, 'assets', 'serial_number')) {
        $sql .= ' OR a.serial_number LIKE ?';
    }
    if ($hasGroups) {
        $sql .= ' OR g.name LIKE ? OR g.group_code LIKE ?';
    }
    $sql .= ')';
    $types .= 'ssss';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    if (asset_column_exists($conn, 'assets', 'receipt_number')) {
        $types .= 's';
        $params[] = $like;
    }
    if (asset_column_exists($conn, 'assets', 'serial_number')) {
        $types .= 's';
        $params[] = $like;
    }
    if ($hasGroups) {
        $types .= 'ss';
        $params[] = $like;
        $params[] = $like;
    }
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

$header = [
    'Asset Code'
];
if ($hasGroups) {
    $header[] = 'Item Category';
    $header[] = 'Category Code';
} else {
    $header[] = 'Item Category';
}
$header[] = 'Item Name';
if ($hasAcquisitionMode) {
    $header[] = 'Acquisition Mode';
    $header[] = 'Acquisition Mode Other';
}
$header = array_merge($header, [
    'Department', 'Church',
    'Purchase Date'
]);
if ($hasMaintenanceFields) {
    $header[] = 'Warranty Expiry';
    $header[] = 'Last Maintenance';
    $header[] = 'Next Maintenance';
}
$header = array_merge($header, [
    'Quantity', 'Receipt/Serial Number'
]);
if (asset_column_exists($conn, 'assets', 'receipt_number')) {
    $header[] = 'Receipt Number';
}
if (asset_column_exists($conn, 'assets', 'serial_number')) {
    $header[] = 'Primary Serial Number';
}
$header = array_merge($header, [
    'Amount', 'Condition Status',
    'Status'
]);
if ($hasLifecycle) {
    $header[] = 'Lifecycle Status';
}
$header[] = 'Allocation Note';
$header[] = 'Created At';
$header[] = 'Updated At';
fputcsv($out, $header);

while ($row = $res->fetch_assoc()) {
    $line = [
        $row['asset_code']
    ];

    if ($hasGroups) {
        $line[] = $row['asset_group_name'] !== null && $row['asset_group_name'] !== '' ? $row['asset_group_name'] : $row['item_group'];
        $line[] = $row['asset_group_code'];
    } else {
        $line[] = $row['item_group'];
    }
    $line[] = $row['item_name'];
    if ($hasAcquisitionMode) {
        $line[] = asset_acquisition_mode_options()[(string) ($row['acquisition_mode'] ?? '')] ?? $row['acquisition_mode'];
        $line[] = $row['acquisition_mode_other'];
    }
    $line[] = $row['department_name'];
    $line[] = $row['church_name'];
    $line[] = $row['purchase_date'];

    if ($hasMaintenanceFields) {
        $line[] = $row['warranty_expiry_date'];
        $line[] = $row['last_maintenance_date'];
        $line[] = $row['next_maintenance_date'];
    }

    $line = array_merge($line, [
        $row['quantity'],
        $row['receipt_or_serial_number'],
    ]);
    if (array_key_exists('receipt_number', $row)) {
        $line[] = $row['receipt_number'];
    }
    if (array_key_exists('serial_number', $row)) {
        $line[] = $row['serial_number'];
    }
    $line[] = $row['amount'];
    $line[] = $row['condition_status'];
    $line[] = $row['status'];

    if ($hasLifecycle) {
        $line[] = (string) ($row['lifecycle_status'] ?? asset_default_lifecycle((string) ($row['status'] ?? 'active'), (string) ($row['condition_status'] ?? '')));
    }

    $line[] = $row['allocation_note'];
    $line[] = $row['created_at'];
    $line[] = $row['updated_at'];
    fputcsv($out, $line);
}

fclose($out);
$stmt->close();
exit;
?>
