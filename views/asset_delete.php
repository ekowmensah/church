<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('delete_asset');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header('Location: asset_list.php');
    exit;
}

$churchId = asset_is_super_admin() ? null : asset_current_church_id($conn);
$sql = 'SELECT id, church_id, asset_code, item_name, department_id, status, condition_status FROM assets WHERE id = ?';
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
$asset = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$asset) {
    header('Location: asset_list.php');
    exit;
}

$churchId = (int) $asset['church_id'];
$assetCode = (string) $asset['asset_code'];

$stmt = $conn->prepare('DELETE FROM assets WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->close();

asset_log_action('asset_delete', 'asset', $id, [
    'asset_id' => $id,
    'church_id' => $churchId,
    'asset_code' => $assetCode,
], [
    'item_name' => $asset['item_name'] ?? null,
    'department_id' => $asset['department_id'] ?? null,
    'status' => $asset['status'] ?? null,
    'condition_status' => $asset['condition_status'] ?? null,
]);

header('Location: asset_list.php?deleted=1' . ($churchId ? '&church_id=' . $churchId : ''));
exit;
?>
