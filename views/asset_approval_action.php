<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';

asset_require_permission('approve_asset_request');

if (!asset_table_exists($conn, 'asset_approval_requests')) {
    header('Location: asset_approval_list.php?err=' . urlencode('Approval workflow not available.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Approval decisions must be submitted by POST.');
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh the page and try again.');
}

$requestId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$decision = trim((string) ($_POST['decision'] ?? ''));
$reviewNoteInput = trim((string) ($_POST['review_note'] ?? ''));
if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    header('Location: asset_approval_list.php?err=' . urlencode('Invalid approval request.'));
    exit;
}
if ($decision === 'reject' && $reviewNoteInput === '') {
    header('Location: asset_approval_list.php?err=' . urlencode('A rejection reason is required.'));
    exit;
}
if (mb_strlen($reviewNoteInput) > 255) {
    header('Location: asset_approval_list.php?err=' . urlencode('The review note must be 255 characters or fewer.'));
    exit;
}

$isSuper = asset_is_super_admin();
$churchId = $isSuper ? null : asset_current_church_id($conn);

$hasAssetSnapshots = asset_column_exists($conn, 'asset_approval_requests', 'asset_code_snapshot')
    && asset_column_exists($conn, 'asset_approval_requests', 'asset_name_snapshot');
$snapshotSelect = $hasAssetSnapshots
    ? ', aar.asset_code_snapshot, aar.asset_name_snapshot'
    : ', NULL AS asset_code_snapshot, NULL AS asset_name_snapshot';
$sql = 'SELECT aar.*, a.id AS linked_asset_id, a.asset_code, a.item_name,
               a.church_id AS asset_church_id, a.department_id AS current_department_id'
    . $snapshotSelect
    . ' FROM asset_approval_requests aar LEFT JOIN assets a ON a.id = aar.asset_id WHERE aar.id = ?';
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

$assetExists = (int) ($request['linked_asset_id'] ?? 0) > 0;
$assetLabel = trim((string) ($request['asset_code'] ?? $request['asset_code_snapshot'] ?? ''));
$assetName = trim((string) ($request['item_name'] ?? $request['asset_name_snapshot'] ?? ''));

$reviewedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$payload = json_decode((string) ($request['payload_json'] ?? '{}'), true);
if (!is_array($payload)) {
    $payload = [];
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare('SELECT status FROM asset_approval_requests WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $lockedRequest = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$lockedRequest || (string) $lockedRequest['status'] !== 'pending') {
        throw new RuntimeException('This approval request was already finalized by another reviewer.');
    }

    if ($decision === 'approve') {
        if (!$assetExists) {
            throw new RuntimeException(
                'The linked asset no longer exists, so this request cannot be approved. Reject the retained request to close it.'
            );
        }
        if ((int) ($request['asset_church_id'] ?? 0) !== (int) $request['church_id']) {
            throw new RuntimeException('The linked asset no longer belongs to the request church.');
        }
        $assetId = (int) $request['asset_id'];
        $requestType = (string) $request['request_type'];

        if ($requestType === 'transfer') {
            $toDepartmentId = (int) ($payload['to_department_id'] ?? 0);
            $assetItemId = (int) ($payload['asset_item_id'] ?? 0);
            $fromDepartmentId = (int) ($payload['from_department_id'] ?? $request['current_department_id'] ?? 0);
            $note = (string) ($payload['note'] ?? '');
            $codeChange = [
                'old_asset_code' => (string) ($request['asset_code'] ?? ''),
                'new_asset_code' => (string) ($request['asset_code'] ?? ''),
            ];
            if ($toDepartmentId <= 0 || $toDepartmentId === $fromDepartmentId) {
                throw new RuntimeException('Invalid transfer payload.');
            }

            if (asset_item_tracking_available($conn) && $assetItemId <= 0) {
                throw new RuntimeException(
                    'This legacy transfer request does not identify an exact asset. Reject it and submit a new transfer request from the asset record.'
                );
            }

            if ($assetItemId > 0 && asset_item_tracking_available($conn)) {
                $movement = asset_transfer_registered_item(
                    $conn,
                    $assetId,
                    $assetItemId,
                    $toDepartmentId,
                    $reviewedBy,
                    $note,
                    $requestId,
                    $fromDepartmentId
                );
                $fromDepartmentId = (int) ($movement['from_department_id'] ?? 0);
                $codeChange = [
                    'old_asset_code' => $movement['old_asset_code'],
                    'new_asset_code' => $movement['new_asset_code'],
                ];
                $payload['old_item_number'] = $movement['old_item_number'];
                $payload['new_item_number'] = $movement['new_item_number'];
                $payload['movement_id'] = $movement['movement_id'];
            } else {
                // Compatibility for installations that have not introduced
                // item-level asset tracking. Current installations never use
                // this parent-only branch because it cannot identify custody.
                if (trim($note) === '') {
                    throw new RuntimeException('A transfer reason is required.');
                }
                $codeChange = asset_move_parent_to_department($conn, $assetId, $toDepartmentId);
                $assetItemId = null;
                $stmt = $conn->prepare('INSERT INTO asset_movements (asset_id, asset_item_id, from_department_id, to_department_id, moved_by, notes) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('iiiiis', $assetId, $assetItemId, $fromDepartmentId, $toDepartmentId, $reviewedBy, $note);
                $stmt->execute();
                $payload['movement_id'] = (int) $conn->insert_id;
                $stmt->close();
            }

            $payload['old_asset_code'] = $codeChange['old_asset_code'];
            $payload['new_asset_code'] = $codeChange['new_asset_code'];
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
                    throw new RuntimeException('An asset is required for this status change.');
                }
                $stmt = $conn->prepare('SELECT item_number, status, lifecycle_status, condition_status, custody_status FROM asset_items WHERE id = ? AND asset_id = ? FOR UPDATE');
                $stmt->bind_param('ii', $assetItemId, $assetId);
                $stmt->execute();
                $item = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$item) {
                    throw new RuntimeException('Asset no longer exists.');
                }

                if ($newStatus === 'disposed') {
                    if ((string) ($item['custody_status'] ?? 'available') !== 'available') {
                        throw new RuntimeException('A reserved or issued asset cannot be disposed. Release or receive it first.');
                    }
                    $newLifecycle = 'disposed';
                    $reason = trim((string) ($payload['note'] ?? ''));
                    $stmt = $conn->prepare(
                        "UPDATE asset_items
                         SET status = 'disposed', lifecycle_status = 'disposed', condition_status = 'Disposed', disposed_by_user_id = ?,
                             disposed_at = NOW(), disposal_reason = ?
                         WHERE id = ?"
                    );
                    $stmt->bind_param('isi', $reviewedBy, $reason, $assetItemId);
                } else {
                    if ($newLifecycle === 'disposed') {
                        $newLifecycle = 'in_use';
                    }
                    $restoredCondition = (string) ($item['condition_status'] ?? 'Good') === 'Disposed'
                        ? 'Good'
                        : (string) ($item['condition_status'] ?? 'Good');
                    $stmt = $conn->prepare(
                        "UPDATE asset_items
                         SET status = 'active', lifecycle_status = ?, condition_status = ?, disposed_by_user_id = NULL,
                             disposed_at = NULL, disposal_reason = NULL
                         WHERE id = ?"
                    );
                    $stmt->bind_param('ssi', $newLifecycle, $restoredCondition, $assetItemId);
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
    $reviewNote = $reviewNoteInput !== ''
        ? $reviewNoteInput
        : ($decision === 'approve' ? 'Approved from queue' : 'Rejected from queue');
    $stmt = $conn->prepare('UPDATE asset_approval_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
    $stmt->bind_param('sisi', $newStatus, $reviewedBy, $reviewNote, $requestId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    asset_log_action('asset_approval_' . $newStatus, 'asset_approval_request', $requestId, [
        'asset_id' => (int) $request['asset_id'],
        'asset_code' => (string) ($payload['new_asset_code'] ?? $assetLabel),
        'asset_name' => $assetName,
        'church_id' => (int) $request['church_id'],
        'request_type' => (string) $request['request_type'],
    ], [], $payload);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Asset operational approval failed: ' . $e->getMessage());
    $message = $e instanceof mysqli_sql_exception
        ? 'The approval could not be completed. Please retry or contact an administrator.'
        : $e->getMessage();
    header('Location: asset_approval_list.php?err=' . urlencode($message));
    exit;
}

header('Location: asset_approval_list.php?done=1');
exit;
