<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!asset_use_requests_available($conn)) {
    header('Location: asset_request_list.php?err=' . urlencode('Asset request workflow not available.'));
    exit;
}

$canApprove = asset_is_super_admin() || has_permission('approve_asset_use_request');
$requestId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$action = trim((string) ($_POST['request_action'] ?? ''));

if ($requestId <= 0 || !in_array($action, ['approve', 'reject', 'checkout', 'return', 'cancel'], true)) {
    header('Location: asset_request_list.php?err=' . urlencode('Invalid asset request action.'));
    exit;
}

$churchId = asset_is_super_admin() ? null : asset_current_church_id($conn);
$sql = '
    SELECT aur.*, a.asset_code, a.item_name
    FROM asset_use_requests aur
    INNER JOIN assets a ON a.id = aur.asset_id
    WHERE aur.id = ?
';
if (!asset_is_super_admin()) {
    $sql .= ' AND aur.church_id = ?';
}
$sql .= ' LIMIT 1';
$stmt = $conn->prepare($sql);
if (asset_is_super_admin()) {
    $stmt->bind_param('i', $requestId);
} else {
    $stmt->bind_param('ii', $requestId, $churchId);
}
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    header('Location: asset_request_list.php?err=' . urlencode('Request not found.'));
    exit;
}

$currentMemberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : 0;
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

if ($action === 'cancel') {
    if ((int) ($request['requested_by_member_id'] ?? 0) !== $currentMemberId && (int) ($request['requested_by_user_id'] ?? 0) !== $currentUserId) {
        header('Location: asset_request_list.php?err=' . urlencode('You cannot cancel this request.'));
        exit;
    }
    if ((string) ($request['status'] ?? '') !== 'pending') {
        header('Location: asset_request_list.php?err=' . urlencode('Only pending requests can be cancelled.'));
        exit;
    }

    $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "cancelled" WHERE id = ?');
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $stmt->close();

    asset_log_action('asset_use_request_cancel', 'asset_use_request', $requestId, [
        'asset_id' => (int) $request['asset_id'],
        'asset_code' => (string) $request['asset_code'],
        'church_id' => (int) $request['church_id'],
    ]);
    header('Location: asset_request_list.php?done=1');
    exit;
}

if (!$canApprove) {
    header('Location: asset_request_list.php?err=' . urlencode('You do not have permission to manage asset requests.'));
    exit;
}

$approvalNote = trim((string) ($_POST['approval_note'] ?? ''));
$approvedQuantity = max(1, (int) ($_POST['approved_quantity'] ?? ($request['quantity_requested'] ?? 1)));
$borrowStartDate = trim((string) ($_POST['borrow_start_date'] ?? (string) ($request['borrow_start_date'] ?? '')));
$expectedReturnDate = trim((string) ($_POST['expected_return_date'] ?? (string) ($request['expected_return_date'] ?? '')));
$actualReturnDate = trim((string) ($_POST['actual_return_date'] ?? date('Y-m-d')));
$returnNote = trim((string) ($_POST['return_note'] ?? ''));
$reviewedBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$conn->begin_transaction();
try {
    if ($action === 'approve') {
        if ((string) ($request['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Only pending requests can be approved.');
        }
        if ($borrowStartDate === '' || $expectedReturnDate === '') {
            throw new RuntimeException('Borrow dates are required for approval.');
        }
        if ($expectedReturnDate < $borrowStartDate) {
            throw new RuntimeException('Expected return date cannot be earlier than the borrow start date.');
        }

        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "approved", approved_by = ?, approved_at = NOW(), approval_note = ?, approved_quantity = ?, borrow_start_date = ?, expected_return_date = ? WHERE id = ?');
        $stmt->bind_param('isissi', $reviewedBy, $approvalNote, $approvedQuantity, $borrowStartDate, $expectedReturnDate, $requestId);
        $stmt->execute();
        $stmt->close();

        asset_log_action('asset_use_request_approve', 'asset_use_request', $requestId, [
            'asset_id' => (int) $request['asset_id'],
            'asset_code' => (string) $request['asset_code'],
            'church_id' => (int) $request['church_id'],
        ], [], [
            'approved_quantity' => $approvedQuantity,
            'borrow_start_date' => $borrowStartDate,
            'expected_return_date' => $expectedReturnDate,
            'approval_note' => $approvalNote,
        ]);
    } elseif ($action === 'reject') {
        if ((string) ($request['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Only pending requests can be rejected.');
        }

        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "rejected", approved_by = ?, approved_at = NOW(), approval_note = ? WHERE id = ?');
        $stmt->bind_param('isi', $reviewedBy, $approvalNote, $requestId);
        $stmt->execute();
        $stmt->close();

        asset_log_action('asset_use_request_reject', 'asset_use_request', $requestId, [
            'asset_id' => (int) $request['asset_id'],
            'asset_code' => (string) $request['asset_code'],
            'church_id' => (int) $request['church_id'],
        ], [], [
            'approval_note' => $approvalNote,
        ]);
    } elseif ($action === 'checkout') {
        if (!in_array((string) ($request['status'] ?? ''), ['approved'], true)) {
            throw new RuntimeException('Only approved requests can be checked out.');
        }

        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "checked_out", checked_out_by = ?, checked_out_at = NOW(), approval_note = ? WHERE id = ?');
        $stmt->bind_param('isi', $reviewedBy, $approvalNote, $requestId);
        $stmt->execute();
        $stmt->close();

        asset_log_action('asset_use_request_checkout', 'asset_use_request', $requestId, [
            'asset_id' => (int) $request['asset_id'],
            'asset_code' => (string) $request['asset_code'],
            'church_id' => (int) $request['church_id'],
        ], [], [
            'approval_note' => $approvalNote,
        ]);
    } elseif ($action === 'return') {
        if (!in_array((string) ($request['status'] ?? ''), ['checked_out', 'overdue'], true)) {
            throw new RuntimeException('Only checked out requests can be returned.');
        }
        if ($actualReturnDate === '') {
            throw new RuntimeException('Actual return date is required.');
        }

        $stmt = $conn->prepare('UPDATE asset_use_requests SET status = "returned", actual_return_date = ?, returned_by = ?, returned_at = NOW(), return_note = ? WHERE id = ?');
        $stmt->bind_param('sisi', $actualReturnDate, $reviewedBy, $returnNote, $requestId);
        $stmt->execute();
        $stmt->close();

        asset_log_action('asset_use_request_return', 'asset_use_request', $requestId, [
            'asset_id' => (int) $request['asset_id'],
            'asset_code' => (string) $request['asset_code'],
            'church_id' => (int) $request['church_id'],
        ], [], [
            'actual_return_date' => $actualReturnDate,
            'return_note' => $returnNote,
        ]);
    }

    $conn->commit();
    header('Location: asset_request_list.php?done=1');
    exit;
} catch (Throwable $e) {
    $conn->rollback();
    header('Location: asset_request_list.php?err=' . urlencode($e->getMessage()));
    exit;
}
