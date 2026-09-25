<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

asset_require_permission('approve_asset_request');

if (!asset_table_exists($conn, 'asset_approval_requests')) {
    header('Location: asset_approval_list.php?err=' . urlencode('Approval workflow not available.'));
    exit;
}

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$decision = trim((string) ($_GET['decision'] ?? ''));
if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    header('Location: asset_approval_list.php?err=' . urlencode('Invalid approval request.'));
    exit;
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);

$sql = 'SELECT aar.*, a.asset_code, a.item_name, a.department_id AS current_department_id FROM asset_approval_requests aar INNER JOIN assets a ON a.id = aar.asset_id WHERE aar.id = ?';
if (!$isSuper) {
    $sql .= ' AND aar.church_id = ?';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) {
    $stmt->bind_param('i', $requestId);
} else {
    $stmt->bind_param('ii', $requestId, $churchId);
}
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    header('Location: asset_approval_list.php?err=' . urlencode('Request not found.'));
    exit;
}
if ((string) $request['status'] !== 'pending') {
    header('Location: asset_approval_list.php?err=' . urlencode('Request is already finalized.'));
    exit;
}

$reviewedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$payload = json_decode((string) ($request['payload_json'] ?? '{}'), true);
if (!is_array($payload)) {
    $payload = [];
}

$conn->begin_transaction();
try {
    if ($decision === 'approve') {
        $assetId = (int) $request['asset_id'];
        $requestType = (string) $request['request_type'];

        if ($requestType === 'transfer') {
            $toDepartmentId = (int) ($payload['to_department_id'] ?? 0);
            $assetItemId = (int) ($payload['asset_item_id'] ?? 0);
            $fromDepartmentId = (int) ($payload['from_department_id'] ?? $request['current_department_id'] ?? 0);
            $note = (string) ($payload['note'] ?? '');
            if ($toDepartmentId <= 0 || $toDepartmentId === $fromDepartmentId) {
                throw new RuntimeException('Invalid transfer payload.');
            }

            if ($assetItemId > 0 && asset_item_tracking_available($conn)) {
                $stmt = $conn->prepare('SELECT item_number, department_id FROM asset_items WHERE id = ? AND asset_id = ? FOR UPDATE');
                $stmt->bind_param('ii', $assetItemId, $assetId); $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$item) throw new RuntimeException('Physical item no longer exists.');
                $fromDepartmentId = (int) ($item['department_id'] ?? 0);
                $stmt = $conn->prepare('SELECT name, department_code FROM asset_departments WHERE id = ? AND church_id = ? LIMIT 1');
                $requestChurchId = (int) $request['church_id'];
                $stmt->bind_param('ii', $toDepartmentId, $requestChurchId); $stmt->execute();
                $destination = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$destination) throw new RuntimeException('Destination department not found.');
                $newItemNumber = asset_replace_department_segment((string) $item['item_number'], (string) ($destination['department_code'] ?? $destination['name']));
                $stmt = $conn->prepare('UPDATE asset_items SET department_id = ?, item_number = ? WHERE id = ?');
                $stmt->bind_param('isi', $toDepartmentId, $newItemNumber, $assetItemId); $stmt->execute(); $stmt->close();
                asset_sync_parent_from_items($conn, $assetId);
            } else {
                $stmt = $conn->prepare('UPDATE assets SET department_id = ? WHERE id = ?');
                $stmt->bind_param('ii', $toDepartmentId, $assetId); $stmt->execute(); $stmt->close();
                $assetItemId = null;
            }

            $stmt = $conn->prepare('INSERT INTO asset_movements (asset_id, asset_item_id, from_department_id, to_department_id, moved_by, notes) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('iiiiis', $assetId, $assetItemId, $fromDepartmentId, $toDepartmentId, $reviewedBy, $note);
            $stmt->execute();
            $stmt->close();
        } elseif ($requestType === 'dispose' || $requestType === 'status_change') {
            $newStatus = (string) ($payload['new_status'] ?? 'active');
            $assetItemId = (int) ($payload['asset_item_id'] ?? 0);
            if (!in_array($newStatus, ['active', 'disposed'], true)) {
                throw new RuntimeException('Invalid status payload.');
            }

            $newLifecycle = (string) ($payload['new_lifecycle_status'] ?? asset_default_lifecycle($newStatus, 'Good'));
            if (!in_array($newLifecycle, asset_lifecycle_options(), true)) {
                throw new RuntimeException('Invalid lifecycle payload.');
            }

            if (asset_item_tracking_available($conn)) {
                if ($assetItemId <= 0) {
                    throw new RuntimeException('A physical item is required for this status change.');
                }
                $stmt = $conn->prepare('SELECT item_number, status, lifecycle_status FROM asset_items WHERE id = ? AND asset_id = ? FOR UPDATE');
                $stmt->bind_param('ii', $assetItemId, $assetId);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$item) {
                    throw new RuntimeException('Physical item no longer exists.');
                }

                if ($newStatus === 'disposed') {
                    $reason = trim((string) ($payload['note'] ?? ''));
                    $stmt = $conn->prepare(
                        "UPDATE asset_items
                         SET status = 'disposed', lifecycle_status = 'disposed', disposed_by_user_id = ?,
                             disposed_at = NOW(), disposal_reason = ?
                         WHERE id = ?"
                    );
                    $stmt->bind_param('isi', $reviewedBy, $reason, $assetItemId);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE asset_items
                         SET status = 'active', lifecycle_status = ?, disposed_by_user_id = NULL,
                             disposed_at = NULL, disposal_reason = NULL
                         WHERE id = ?"
                    );
                    $stmt->bind_param('si', $newLifecycle, $assetItemId);
                }
                $stmt->execute();
                $stmt->close();
                asset_sync_parent_from_items($conn, $assetId);
            } else {
                if (asset_can_use_lifecycle($conn)) {
                    $stmt = $conn->prepare('UPDATE assets SET status = ?, lifecycle_status = ? WHERE id = ?');
                    $stmt->bind_param('ssi', $newStatus, $newLifecycle, $assetId);
                } else {
                    $stmt = $conn->prepare('UPDATE assets SET status = ? WHERE id = ?');
                    $stmt->bind_param('si', $newStatus, $assetId);
                }
                $stmt->execute();
                $stmt->close();
            }
        } else {
            throw new RuntimeException('Unsupported request type.');
        }
    }

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $reviewNote = $decision === 'approve' ? 'Approved from queue' : 'Rejected from queue';
    $stmt = $conn->prepare('UPDATE asset_approval_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
    $stmt->bind_param('sisi', $newStatus, $reviewedBy, $reviewNote, $requestId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    asset_log_action('asset_approval_' . $newStatus, 'asset_approval_request', $requestId, [
        'asset_id' => (int) $request['asset_id'],
        'asset_code' => (string) $request['asset_code'],
        'church_id' => (int) $request['church_id'],
        'request_type' => (string) $request['request_type'],
    ], [], $payload);
} catch (Throwable $e) {
    $conn->rollback();
    header('Location: asset_approval_list.php?err=' . urlencode($e->getMessage()));
    exit;
}

header('Location: asset_approval_list.php?done=1');
exit;
