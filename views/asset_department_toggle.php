<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';

asset_require_permission('manage_asset_departments');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    exit('Use the protected status form.');
}
$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: asset_department_list.php');
    exit;
}

$churchId = asset_is_super_admin() ? null : asset_current_church_id($conn);

$sql = 'SELECT id, church_id, is_active FROM asset_departments WHERE id = ?';
if (!asset_is_super_admin()) {
    $sql .= ' AND church_id = ?';
}
$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);
if (asset_is_super_admin()) {
    $stmt->bind_param('i', $id);
} else {
    $stmt->bind_param('ii', $id, $churchId);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header('Location: asset_department_list.php');
    exit;
}

$newState = ((int) $row['is_active'] === 1) ? 0 : 1;
$churchId = (int) $row['church_id'];

$stmt = $conn->prepare('UPDATE asset_departments SET is_active = ? WHERE id = ?');
$stmt->bind_param('ii', $newState, $id);
$stmt->execute();
$stmt->close();

asset_log_action('asset_department_toggle', 'asset_department', $id, [
    'is_active' => $newState,
]);

header('Location: asset_department_list.php?toggled=1' . ($churchId ? '&church_id=' . $churchId : ''));
exit;
?>
