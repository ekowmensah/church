<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/asset_register_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!asset_is_super_admin() && !has_permission('manage_asset_maintenance')) {
    http_response_code(403);
    exit('You do not have permission to manage asset maintenance.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Maintenance actions must be submitted by POST.');
}
if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh the page and try again.');
}
if (!asset_table_exists($conn, 'asset_maintenance_work_orders')) {
    header('Location: asset_maintenance_list.php?err=' . urlencode('Run Phase 0092 before managing maintenance.'));
    exit;
}

$action = trim((string) ($_POST['maintenance_action'] ?? ''));
$isSuper = asset_is_super_admin();
$scopeChurchId = $isSuper ? null : asset_current_church_id($conn);
$actorId = (int) ($_SESSION['user_id'] ?? 0) ?: null;

$conn->begin_transaction();
try {
    if ($action === 'create') {
        $itemId = (int) ($_POST['asset_item_id'] ?? 0);
        $type = trim((string) ($_POST['maintenance_type'] ?? 'repair'));
        $priority = trim((string) ($_POST['priority'] ?? 'normal'));
        $summary = trim((string) ($_POST['summary'] ?? ''));
        $notes = trim((string) ($_POST['work_notes'] ?? ''));
        $vendor = trim((string) ($_POST['vendor_name'] ?? ''));
        $scheduled = trim((string) ($_POST['scheduled_for'] ?? '')) ?: null;
        $estimated = trim((string) ($_POST['estimated_cost'] ?? ''));
        $estimatedCost = $estimated === '' ? null : (float) $estimated;
        if (!in_array($type, ['inspection','preventive','repair','service','warranty','other'], true)) throw new RuntimeException('Invalid maintenance type.');
        if (!in_array($priority, ['low','normal','high','critical'], true)) throw new RuntimeException('Invalid priority.');
        if ($summary === '') throw new RuntimeException('A maintenance summary is required.');

        $sql = "SELECT item.*, asset.item_name, asset.asset_code
                  FROM asset_items item JOIN assets asset ON asset.id = item.asset_id
                 WHERE item.id = ? AND item.status = 'active'";
        if (!$isSuper) $sql .= ' AND item.church_id = ?';
        $sql .= ' FOR UPDATE';
        $stmt = $conn->prepare($sql);
        if ($isSuper) $stmt->bind_param('i', $itemId); else $stmt->bind_param('ii', $itemId, $scopeChurchId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) throw new RuntimeException('Physical asset item not found in your church scope.');
        if ((string) ($item['custody_status'] ?? 'available') !== 'available') throw new RuntimeException('An issued or reserved item cannot enter maintenance. Receive or release it first.');
        $stmt = $conn->prepare("SELECT id FROM asset_maintenance_work_orders WHERE asset_item_id = ? AND status IN ('open','scheduled','in_progress','on_hold') LIMIT 1 FOR UPDATE");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) throw new RuntimeException('This physical item already has an open maintenance work order.');

        $reference = 'MWO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $churchId = (int) $item['church_id'];
        $assetId = (int) $item['asset_id'];
        $conditionBefore = (string) $item['condition_status'];
        $status = $scheduled ? 'scheduled' : 'open';
        $stmt = $conn->prepare('INSERT INTO asset_maintenance_work_orders (church_id, asset_id, asset_item_id, reference_code, maintenance_type, priority, status, summary, work_notes, vendor_name, estimated_cost, scheduled_for, condition_before, reported_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('iiisssssssdssi', $churchId, $assetId, $itemId, $reference, $type, $priority, $status, $summary, $notes, $vendor, $estimatedCost, $scheduled, $conditionBefore, $actorId);
        $stmt->execute();
        $workOrderId = (int) $conn->insert_id;
        $stmt->close();
        $stmt = $conn->prepare("UPDATE asset_items SET lifecycle_status = 'under_maintenance', condition_status = 'Under Maintenance' WHERE id = ?");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        asset_log_action('asset_maintenance_opened', 'asset_maintenance_work_order', $workOrderId, ['church_id' => $churchId, 'asset_id' => $assetId, 'asset_item_id' => $itemId, 'reference_code' => $reference], ['condition' => $conditionBefore], ['status' => $status]);
    } else {
        $workOrderId = (int) ($_POST['id'] ?? 0);
        $sql = 'SELECT work_order.*, item.condition_status AS current_condition FROM asset_maintenance_work_orders work_order JOIN asset_items item ON item.id = work_order.asset_item_id WHERE work_order.id = ?';
        if (!$isSuper) $sql .= ' AND work_order.church_id = ?';
        $sql .= ' FOR UPDATE';
        $stmt = $conn->prepare($sql);
        if ($isSuper) $stmt->bind_param('i', $workOrderId); else $stmt->bind_param('ii', $workOrderId, $scopeChurchId);
        $stmt->execute();
        $workOrder = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$workOrder) throw new RuntimeException('Maintenance work order not found.');
        if (in_array((string) $workOrder['status'], ['completed','cancelled'], true)) throw new RuntimeException('This work order is already closed.');

        $itemId = (int) $workOrder['asset_item_id'];
        if ($action === 'start') {
            $stmt = $conn->prepare("UPDATE asset_maintenance_work_orders SET status = 'in_progress', started_at = COALESCE(started_at, NOW()), assigned_to_user_id = COALESCE(assigned_to_user_id, ?) WHERE id = ?");
            $stmt->bind_param('ii', $actorId, $workOrderId);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'complete') {
            $condition = trim((string) ($_POST['condition_after'] ?? 'Good'));
            $notes = trim((string) ($_POST['work_notes'] ?? ''));
            $actualRaw = trim((string) ($_POST['actual_cost'] ?? ''));
            $actualCost = $actualRaw === '' ? null : (float) $actualRaw;
            if (!in_array($condition, asset_condition_options(), true) || in_array($condition, ['Under Maintenance','Disposed'], true)) throw new RuntimeException('Select the physical condition after maintenance.');
            $lifecycle = in_array($condition, ['Poor','Damaged'], true) ? 'under_maintenance' : 'in_use';
            $stmt = $conn->prepare("UPDATE asset_maintenance_work_orders SET status = 'completed', completed_at = NOW(), completed_by_user_id = ?, condition_after = ?, actual_cost = ?, work_notes = CONCAT_WS(CHAR(10), NULLIF(work_notes,''), NULLIF(?,'')) WHERE id = ?");
            $stmt->bind_param('isdsi', $actorId, $condition, $actualCost, $notes, $workOrderId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('UPDATE asset_items SET condition_status = ?, lifecycle_status = ? WHERE id = ?');
            $stmt->bind_param('ssi', $condition, $lifecycle, $itemId);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare('UPDATE assets SET last_maintenance_date = CURDATE() WHERE id = ?');
            $assetId = (int) $workOrder['asset_id'];
            $stmt->bind_param('i', $assetId);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'cancel') {
            $cancelReason = trim((string) ($_POST['cancel_reason'] ?? ''));
            if ($cancelReason === '') throw new RuntimeException('A cancellation reason is required.');
            if (mb_strlen($cancelReason) > 500) throw new RuntimeException('The cancellation reason must be 500 characters or fewer.');
            $stmt = $conn->prepare("UPDATE asset_maintenance_work_orders SET status = 'cancelled', completed_at = NOW(), completed_by_user_id = ?, work_notes = CONCAT_WS(CHAR(10), NULLIF(work_notes,''), CONCAT('Cancelled: ', ?)) WHERE id = ?");
            $stmt->bind_param('isi', $actorId, $cancelReason, $workOrderId);
            $stmt->execute();
            $stmt->close();
            $condition = (string) $workOrder['condition_before'];
            $lifecycle = in_array($condition, ['Poor','Under Maintenance','Damaged'], true) ? 'under_maintenance' : 'in_use';
            $stmt = $conn->prepare('UPDATE asset_items SET condition_status = ?, lifecycle_status = ? WHERE id = ?');
            $stmt->bind_param('ssi', $condition, $lifecycle, $itemId);
            $stmt->execute();
            $stmt->close();
        } else {
            throw new RuntimeException('Invalid maintenance action.');
        }
        asset_log_action('asset_maintenance_' . $action, 'asset_maintenance_work_order', $workOrderId, ['church_id' => (int) $workOrder['church_id'], 'asset_id' => (int) $workOrder['asset_id'], 'asset_item_id' => $itemId], ['status' => (string) $workOrder['status']], ['action' => $action]);
    }

    $conn->commit();
    header('Location: asset_maintenance_list.php?done=1');
    exit;
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('Asset maintenance action failed: ' . $exception->getMessage());
    $message = $exception instanceof mysqli_sql_exception
        ? 'The maintenance update could not be completed. Please retry or contact an administrator.'
        : $exception->getMessage();
    header('Location: asset_maintenance_list.php?err=' . urlencode($message));
    exit;
}

