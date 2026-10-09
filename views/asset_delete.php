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
$assetItemId = isset($_POST['asset_item_id']) ? (int) $_POST['asset_item_id'] : 0;
$deletionReason = trim((string) ($_POST['deletion_reason'] ?? ''));
if ($id <= 0) {
    header('Location: asset_list.php');
    exit;
}
if (strlen($deletionReason) < 10 || strlen($deletionReason) > 500) {
    header('Location: asset_list.php?err=' . urlencode('Enter a clear deletion reason between 10 and 500 characters.'));
    exit;
}

$isSuper = asset_is_super_admin();
$scopeChurchId = $isSuper ? null : asset_current_church_id($conn);
$sql = 'SELECT id, church_id, asset_code, item_name, department_id, status, condition_status FROM assets WHERE id = ?';
if (!$isSuper) {
    $sql .= ' AND church_id = ?';
}
$sql .= ' LIMIT 1';

$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $id);
} else {
    $stmt->bind_param('ii', $id, $scopeChurchId);
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
if (!asset_table_exists($conn, 'asset_audit_log')) {
    $blockingReasons[] = 'no deletion-audit storage';
}

$evidenceExists = static function (mysqli $conn, string $table, string $column, int $assetId): bool {
    if (!asset_table_exists($conn, $table) || !asset_column_exists($conn, $table, $column)) {
        return false;
    }
    $sql = sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1', $table, $column);
    $check = $conn->prepare($sql);
    $check->bind_param('i', $assetId);
    $check->execute();
    $exists = $check->get_result()->fetch_row() !== null;
    $check->close();
    return $exists;
};

$assetItems = [];
if (asset_table_exists($conn, 'asset_items')) {
    $columns = 'id, item_number, serial_number, status, lifecycle_status';
    if (asset_column_exists($conn, 'asset_items', 'custody_status')) {
        $columns .= ', custody_status';
    }
    $check = $conn->prepare("SELECT {$columns} FROM asset_items WHERE asset_id = ? ORDER BY id");
    $check->bind_param('i', $id);
    $check->execute();
    $assetItems = $check->get_result()->fetch_all(MYSQLI_ASSOC);
    $check->close();

    if (count($assetItems) > 1) {
        $blockingReasons[] = 'multiple migrated asset identities';
    } elseif ($assetItemId > 0 && isset($assetItems[0]) && (int) $assetItems[0]['id'] !== $assetItemId) {
        $blockingReasons[] = 'an identity mismatch';
    }
}

$evidenceChecks = [
    ['asset_approval_requests', 'asset_id', 'approval history'],
    ['asset_movements', 'asset_id', 'movement history'],
    ['asset_use_requests', 'asset_id', 'borrowing-request history'],
    ['asset_use_request_items', 'asset_id', 'borrowing-request history'],
    ['asset_custody_events', 'asset_id', 'custody history'],
    ['asset_maintenance_work_orders', 'asset_id', 'maintenance history'],
    ['asset_documents', 'asset_id', 'stored documents'],
];
foreach ($evidenceChecks as [$table, $column, $reason]) {
    if ($evidenceExists($conn, $table, $column, $id) && !in_array($reason, $blockingReasons, true)) {
        $blockingReasons[] = $reason;
    }
}

if ($blockingReasons) {
    $message = 'This asset cannot be deleted because it has ' . implode(', ', $blockingReasons)
        . '. Delete is only for an unused mistaken registration; use disposal to retain established records.';
    header('Location: asset_list.php?err=' . urlencode($message) . '&church_id=' . $churchId);
    exit;
}

try {
    $conn->begin_transaction();
    // This compatibility table has no foreign key in older deployments.
    if (asset_table_exists($conn, 'asset_serial_numbers')) {
        $stmt = $conn->prepare('DELETE FROM asset_serial_numbers WHERE asset_id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare('DELETE FROM assets WHERE id = ? AND church_id = ?');
    $stmt->bind_param('ii', $id, $churchId);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('The asset could not be deleted.');
    }
    $stmt->close();

    // Record immutable evidence after the guarded delete succeeds and before
    // commit. asset_audit_log intentionally has no FK to the live register.
    $auditPayload = [
        'asset_id' => $id,
        'church_id' => $churchId,
        'asset_code' => $assetCode,
        'deletion_reason' => $deletionReason,
    ];
    asset_log_action('asset_delete', 'asset', $id, $auditPayload, [
        'item_name' => $asset['item_name'] ?? null,
        'department_id' => $asset['department_id'] ?? null,
        'status' => $asset['status'] ?? null,
        'condition_status' => $asset['condition_status'] ?? null,
        'asset_items' => $assetItems,
    ]);
    $auditJson = json_encode($auditPayload);
    $auditCheck = $conn->prepare(
        "SELECT 1 FROM asset_audit_log
          WHERE asset_id = ? AND action = 'asset_delete' AND meta_json = ?
          ORDER BY id DESC LIMIT 1"
    );
    $auditCheck->bind_param('is', $id, $auditJson);
    $auditCheck->execute();
    $auditStored = $auditCheck->get_result()->fetch_row() !== null;
    $auditCheck->close();
    if (!$auditStored) {
        throw new RuntimeException('Deletion audit evidence could not be stored.');
    }
    $conn->commit();
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Asset deletion failed: ' . $exception->getMessage());
    $message = 'The asset could not be deleted safely. Check for linked operational history and try again.';
    header('Location: asset_list.php?err=' . urlencode($message) . '&church_id=' . $churchId);
    exit;
}

header('Location: asset_list.php?deleted=1' . ($churchId ? '&church_id=' . $churchId : ''));
exit;
?>
