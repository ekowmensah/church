<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';

asset_require_permission('delete_asset');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Asset deletion must be submitted by POST.');
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh the asset register and try again.');
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
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

$blockingReasons = [];
if (asset_table_exists($conn, 'asset_approval_requests')) {
    $check = $conn->prepare('SELECT COUNT(*) AS total FROM asset_approval_requests WHERE asset_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if ((int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
        $blockingReasons[] = 'approval history';
    }
    $check->close();
}
if (asset_table_exists($conn, 'asset_items')) {
    $check = $conn->prepare('SELECT COUNT(*) AS total FROM asset_items WHERE asset_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if ((int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
        $blockingReasons[] = 'physical-item records';
    }
    $check->close();
}
if (asset_table_exists($conn, 'asset_movements')) {
    $check = $conn->prepare('SELECT COUNT(*) AS total FROM asset_movements WHERE asset_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if ((int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
        $blockingReasons[] = 'movement history';
    }
    $check->close();
}
if (asset_table_exists($conn, 'asset_use_request_items')) {
    $check = $conn->prepare('SELECT COUNT(*) AS total FROM asset_use_request_items WHERE asset_id = ?');
    $check->bind_param('i', $id);
    $check->execute();
    if ((int) ($check->get_result()->fetch_assoc()['total'] ?? 0) > 0) {
        $blockingReasons[] = 'member-request history';
    }
    $check->close();
}

if ($blockingReasons) {
    $message = 'This asset cannot be deleted because it has ' . implode(', ', $blockingReasons)
        . '. Use the governed disposal/status workflow instead.';
    header('Location: asset_list.php?err=' . urlencode($message) . '&church_id=' . $churchId);
    exit;
}

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
