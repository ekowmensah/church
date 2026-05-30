<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('download_asset_document');

if (!asset_table_exists($conn, 'asset_documents')) {
    http_response_code(404);
    exit('Documents table not found.');
}

$docId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document.');
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);

$sql = 'SELECT ad.*, a.asset_code FROM asset_documents ad INNER JOIN assets a ON a.id = ad.asset_id WHERE ad.id = ? AND ad.is_active = 1';
if (!$isSuper) {
    $sql .= ' AND ad.church_id = ?';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $docId);
} else {
    $stmt->bind_param('ii', $docId, $churchId);
}
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$path = asset_documents_upload_dir() . DIRECTORY_SEPARATOR . $doc['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Stored file missing.');
}

if (asset_table_exists($conn, 'asset_document_downloads')) {
    $downloadedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 64) : null;
    $stmt = $conn->prepare('INSERT INTO asset_document_downloads (document_id, downloaded_by, download_ip) VALUES (?, ?, ?)');
    $stmt->bind_param('iis', $docId, $downloadedBy, $ip);
    $stmt->execute();
    $stmt->close();
}

asset_log_action('asset_document_download', 'asset_document', $docId, [
    'asset_id' => (int) $doc['asset_id'],
    'asset_code' => (string) $doc['asset_code'],
    'church_id' => (int) $doc['church_id'],
    'file_name' => (string) $doc['file_name'],
]);

$mime = (string) ($doc['mime_type'] ?? 'application/octet-stream');
$name = (string) ($doc['file_name'] ?? 'document');
$size = (int) ($doc['file_size'] ?? filesize($path));

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
header('Content-Length: ' . $size);
readfile($path);
exit;

