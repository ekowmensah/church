<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!asset_request_lines_available($conn)) {
    header('Location: asset_request_list.php?err=' . urlencode('Run Phase 0024 before managing requests.'));
    exit;
}

$requestId = (int) ($_POST['id'] ?? 0);
$action = trim((string) ($_POST['request_action'] ?? ''));
$allowedActions = ['approve', 'reject', 'checkout', 'return', 'cancel'];
if ($requestId < 1 || !in_array($action, $allowedActions, true)) {
    header('Location: asset_request_list.php?err=' . urlencode('Invalid asset request action.'));
    exit;
}

$isSuper = asset_is_super_admin();
$canApprove = $isSuper || has_permission('approve_asset_use_request');
$churchId = $isSuper ? null : asset_current_church_id($conn);
$sql = 'SELECT request.* FROM asset_use_requests request WHERE request.id = ?';
if (!$isSuper) $sql .= ' AND request.church_id = ?';
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if ($isSuper) $stmt->bind_param('i', $requestId); else $stmt->bind_param('ii', $requestId, $churchId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$request) {
    header('Location: asset_request_list.php?err=' . urlencode('Request not found.'));
    exit;
}

$currentMemberId = (int) ($_SESSION['member_id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if ($action === 'cancel') {
    $ownsRequest = ((int) ($request['requested_by_member_id'] ?? 0) === $currentMemberId && $currentMemberId > 0)
        || ((int) ($request['requested_by_user_id'] ?? 0) === $currentUserId && $currentUserId > 0);
    if (!$ownsRequest || (string) $request['status'] !== 'pending') {
        header('Location: asset_request_list.php?err=' . urlencode('Only the requester can cancel a pending request.'));
        exit;
    }
    $conn->begin_transaction();
    try {
        $lines = asset_fetch_request_lines($conn, $requestId);
        $conn->query('UPDATE asset_use_request_items SET line_status = "cancelled" WHERE request_id = ' . $requestId . ' AND line_status = "pending"');
        foreach ($lines as $line) {
            if ((string) $line['line_status'] === 'pending') {
                asset_request_line_audit($conn, $requestId, (int) $line['id'], 'cancelled', $line, ['line_status' => 'cancelled'], $currentUserId ?: null);
            }
        }
        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "cancelled" WHERE id = ?');
        $stmt->bind_param('i', $requestId); $stmt->execute(); $stmt->close();
        $conn->commit();
        header('Location: asset_request_list.php?done=1'); exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        header('Location: asset_request_list.php?err=' . urlencode($exception->getMessage())); exit;
    }
}

if (!$canApprove) {
    header('Location: asset_request_list.php?err=' . urlencode('You do not have permission to manage asset requests.'));
    exit;
}

$approvalNote = trim((string) ($_POST['approval_note'] ?? ''));
$borrowStartDate = trim((string) ($_POST['borrow_start_date'] ?? $request['borrow_start_date']));
$expectedReturnDate = trim((string) ($_POST['expected_return_date'] ?? $request['expected_return_date']));
$actualReturnDate = trim((string) ($_POST['actual_return_date'] ?? date('Y-m-d')));
$returnNote = trim((string) ($_POST['return_note'] ?? ''));
$reviewedBy = $currentUserId ?: null;

$conn->begin_transaction();
try {
    if ($action === 'reject') {
        if ((string) $request['status'] !== 'pending') throw new RuntimeException('Only pending requests can be rejected.');
        $lines = asset_fetch_request_lines($conn, $requestId);
        $stmt = $conn->prepare('UPDATE asset_use_request_items SET line_status = "rejected", decision_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW() WHERE request_id = ? AND line_status = "pending"');
        $stmt->bind_param('sii', $approvalNote, $reviewedBy, $requestId); $stmt->execute(); $stmt->close();
        foreach ($lines as $line) {
            if ((string) $line['line_status'] === 'pending') {
                asset_request_line_audit($conn, $requestId, (int) $line['id'], 'rejected', $line, ['line_status' => 'rejected', 'decision_note' => $approvalNote], $reviewedBy);
            }
        }
        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "rejected", approved_by = ?, approved_at = NOW(), approval_note = ?, approved_quantity = 0, last_edited_by_user_id = ?, last_edited_at = NOW() WHERE id = ?');
        $stmt->bind_param('isii', $reviewedBy, $approvalNote, $reviewedBy, $requestId); $stmt->execute(); $stmt->close();
    } elseif ($action === 'approve') {
        if ((string) $request['status'] !== 'pending') throw new RuntimeException('Only pending requests can be reviewed.');
        if ($borrowStartDate === '' || $expectedReturnDate === '' || $expectedReturnDate < $borrowStartDate) {
            throw new RuntimeException('Enter a valid borrowing and expected return period.');
        }
        $lineIds = array_values((array) ($_POST['line_id'] ?? []));
        $assetIds = array_values((array) ($_POST['line_asset_id'] ?? []));
        $decisions = array_values((array) ($_POST['line_decision'] ?? []));
        if (!$assetIds || count($assetIds) !== count($decisions) || count($lineIds) !== count($assetIds)) {
            throw new RuntimeException('The reviewed request lines are incomplete.');
        }
        $existingLines = [];
        foreach (asset_fetch_request_lines($conn, $requestId) as $line) $existingLines[(int) $line['id']] = $line;
        $seen = [];
        $approvedByAsset = [];
        $approvedCount = 0;
        $firstAssetId = 0;
        foreach ($assetIds as $index => $postedAssetId) {
            $assetId = (int) $postedAssetId;
            $lineId = (int) $lineIds[$index];
            $decision = $decisions[$index] === 'approved' ? 'approved' : 'rejected';
            if ($lineId > 0 && isset($seen[$lineId])) throw new RuntimeException('A request line was submitted more than once.');
            $stmt = $conn->prepare('SELECT id, church_id, status FROM assets WHERE id = ? AND church_id = ? LIMIT 1');
            $requestChurch = (int) $request['church_id'];
            $stmt->bind_param('ii', $assetId, $requestChurch); $stmt->execute();
            $validAsset = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$validAsset || (string) $validAsset['status'] !== 'active') throw new RuntimeException('A reviewed asset is unavailable.');
            if ($firstAssetId === 0) $firstAssetId = $assetId;
            if ($decision === 'approved') {
                $approvedByAsset[$assetId] = ($approvedByAsset[$assetId] ?? 0) + 1;
                $approvedCount++;
            }
            if ($lineId > 0) {
                if (!isset($existingLines[$lineId])) throw new RuntimeException('A request line does not belong to this request.');
                $before = $existingLines[$lineId];
                $stmt = $conn->prepare('UPDATE asset_use_request_items SET asset_id = ?, line_status = ?, decision_note = ?, reviewed_by_user_id = ?, reviewed_at = NOW() WHERE id = ? AND request_id = ?');
                $stmt->bind_param('issiii', $assetId, $decision, $approvalNote, $reviewedBy, $lineId, $requestId); $stmt->execute(); $stmt->close();
                asset_request_line_audit($conn, $requestId, $lineId, $decision, $before, ['asset_id' => $assetId, 'line_status' => $decision], $reviewedBy);
                $seen[$lineId] = true;
            } else {
                $stmt = $conn->prepare('INSERT INTO asset_use_request_items (request_id, asset_id, line_status, decision_note, reviewed_by_user_id, reviewed_at) VALUES (?, ?, ?, ?, ?, NOW())');
                $stmt->bind_param('iissi', $requestId, $assetId, $decision, $approvalNote, $reviewedBy); $stmt->execute();
                $newLineId = (int) $conn->insert_id; $stmt->close();
                asset_request_line_audit($conn, $requestId, $newLineId, 'added', null, ['asset_id' => $assetId, 'line_status' => $decision], $reviewedBy);
            }
        }
        foreach ($existingLines as $lineId => $line) {
            if (isset($seen[$lineId]) || !in_array($line['line_status'], ['pending'], true)) continue;
            $stmt = $conn->prepare('UPDATE asset_use_request_items SET line_status = "rejected", decision_note = "Removed during administrator review", reviewed_by_user_id = ?, reviewed_at = NOW() WHERE id = ?');
            $stmt->bind_param('ii', $reviewedBy, $lineId); $stmt->execute(); $stmt->close();
            asset_request_line_audit($conn, $requestId, $lineId, 'rejected', $line, ['line_status' => 'rejected', 'reason' => 'removed'], $reviewedBy);
        }
        foreach ($approvedByAsset as $assetId => $needed) {
            $stmt = $conn->prepare("SELECT COUNT(*) AS available FROM asset_items item
                WHERE item.asset_id = ? AND item.status = 'active'
                  AND NOT EXISTS (
                      SELECT 1 FROM asset_use_request_items used
                      WHERE used.asset_item_id = item.id AND used.line_status = 'checked_out'
                  )");
            $stmt->bind_param('i', $assetId); $stmt->execute(); $available = (int) $stmt->get_result()->fetch_assoc()['available']; $stmt->close();
            if ($needed > $available) throw new RuntimeException('Approved quantity exceeds active physical items for one selected asset.');
        }
        $newStatus = $approvedCount > 0 ? 'approved' : 'rejected';
        $requestedCount = count($assetIds);
        $stmt = $conn->prepare('UPDATE asset_use_requests SET asset_id = ?, status = ?, approved_by = ?, approved_at = NOW(), approval_note = ?, quantity_requested = ?, approved_quantity = ?, borrow_start_date = ?, expected_return_date = ?, last_edited_by_user_id = ?, last_edited_at = NOW() WHERE id = ?');
        $stmt->bind_param('isisiissii', $firstAssetId, $newStatus, $reviewedBy, $approvalNote, $requestedCount, $approvedCount, $borrowStartDate, $expectedReturnDate, $reviewedBy, $requestId);
        $stmt->execute(); $stmt->close();
    } elseif ($action === 'checkout') {
        if ((string) $request['status'] !== 'approved') throw new RuntimeException('Only approved requests can be checked out.');
        $lines = asset_fetch_request_lines($conn, $requestId);
        foreach ($lines as $line) {
            if ((string) $line['line_status'] !== 'approved') continue;
            $assetId = (int) $line['asset_id'];
            $stmt = $conn->prepare("SELECT item.id FROM asset_items item
                WHERE item.asset_id = ? AND item.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM asset_use_request_items used WHERE used.asset_item_id = item.id AND used.line_status = 'checked_out')
                ORDER BY item.id LIMIT 1 FOR UPDATE");
            $stmt->bind_param('i', $assetId); $stmt->execute(); $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$item) throw new RuntimeException('No unallocated physical item is available for checkout.');
            $lineId = (int) $line['id']; $itemId = (int) $item['id'];
            $stmt = $conn->prepare('UPDATE asset_use_request_items SET asset_item_id = ?, line_status = "checked_out", checked_out_by_user_id = ?, checked_out_at = NOW() WHERE id = ?');
            $stmt->bind_param('iii', $itemId, $reviewedBy, $lineId); $stmt->execute(); $stmt->close();
            asset_request_line_audit($conn, $requestId, $lineId, 'checked_out', $line, ['asset_item_id' => $itemId, 'line_status' => 'checked_out'], $reviewedBy);
        }
        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "checked_out", checked_out_by = ?, checked_out_at = NOW(), approval_note = ? WHERE id = ?');
        $stmt->bind_param('isi', $reviewedBy, $approvalNote, $requestId); $stmt->execute(); $stmt->close();
    } elseif ($action === 'return') {
        if (!in_array((string) $request['status'], ['checked_out', 'overdue'], true)) throw new RuntimeException('Only checked-out requests can be returned.');
        if ($actualReturnDate === '') throw new RuntimeException('Actual return date is required.');
        $lines = asset_fetch_request_lines($conn, $requestId);
        $stmt = $conn->prepare('UPDATE asset_use_request_items SET line_status = "returned", returned_by_user_id = ?, returned_at = NOW() WHERE request_id = ? AND line_status = "checked_out"');
        $stmt->bind_param('ii', $reviewedBy, $requestId); $stmt->execute(); $stmt->close();
        foreach ($lines as $line) {
            if ((string) $line['line_status'] === 'checked_out') {
                asset_request_line_audit($conn, $requestId, (int) $line['id'], 'returned', $line, ['line_status' => 'returned'], $reviewedBy);
            }
        }
        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "returned", actual_return_date = ?, returned_by = ?, returned_at = NOW(), return_note = ? WHERE id = ?');
        $stmt->bind_param('sisi', $actualReturnDate, $reviewedBy, $returnNote, $requestId); $stmt->execute(); $stmt->close();
    }
    $conn->commit();
    asset_log_action('asset_use_request_' . $action, 'asset_use_request', $requestId, ['church_id' => (int) $request['church_id']], [], ['approval_note' => $approvalNote]);
    header('Location: asset_request_list.php?done=1');
    exit;
} catch (Throwable $exception) {
    $conn->rollback();
    header('Location: asset_request_list.php?err=' . urlencode($exception->getMessage()));
    exit;
}
