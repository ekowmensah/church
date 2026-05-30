<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('delete_asset_document');

if (!asset_table_exists($conn, 'asset_documents')) {
    header('Location: asset_list.php');
    exit;
}

$docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$assetId = isset($_GET['asset_id']) ? (int) $_GET['asset_id'] : 0;
if ($docId <= 0 || $assetId <= 0) {
    header('Location: asset_list.php');
    exit;
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);

$sql = 'SELECT * FROM asset_documents WHERE id = ? AND asset_id = ? AND is_active = 1';
if (!$isSuper) {
    $sql .= ' AND church_id = ?';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('ii', $docId, $assetId);
} else {
    $stmt->bind_param('iii', $docId, $assetId, $churchId);
}
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    header('Location: asset_view.php?id=' . $assetId . '&tab=documents');
    exit;
}

$stmt = $conn->prepare('UPDATE asset_documents SET is_active = 0 WHERE id = ?');
$stmt->bind_param('i', $docId);
$stmt->execute();
$stmt->close();

asset_log_action('asset_document_delete', 'asset_document', $docId, [
    'asset_id' => $assetId,
    'church_id' => (int) $doc['church_id'],
    'file_name' => (string) $doc['file_name'],
]);

header('Location: asset_view.php?id=' . $assetId . '&tab=documents');
exit;

