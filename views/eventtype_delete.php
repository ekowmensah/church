<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if (!is_super_admin() && !has_permission('delete_event_type')) {
    http_response_code(403);
    exit('You do not have permission to delete event types.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(405);
    $_SESSION['event_type_error'] = 'A valid form submission is required.';
    header('Location: eventtype_list.php');
    exit;
}

$eventTypeId = (int) ($_POST['id'] ?? 0);
if ($eventTypeId < 1) {
    $_SESSION['event_type_error'] = 'The event type selection was invalid.';
    header('Location: eventtype_list.php');
    exit;
}

try {
    $conn->begin_transaction();

    $typeStmt = $conn->prepare('SELECT name FROM event_types WHERE id = ? FOR UPDATE');
    $typeStmt->bind_param('i', $eventTypeId);
    $typeStmt->execute();
    $eventType = $typeStmt->get_result()->fetch_assoc();
    $typeStmt->close();
    if (!$eventType) {
        throw new RuntimeException('Event type not found.');
    }

    $usageStmt = $conn->prepare('SELECT COUNT(*) AS total FROM events WHERE event_type_id = ?');
    $usageStmt->bind_param('i', $eventTypeId);
    $usageStmt->execute();
    $usageCount = (int) $usageStmt->get_result()->fetch_assoc()['total'];
    $usageStmt->close();
    if ($usageCount > 0) {
        throw new RuntimeException(
            'This event type is used by ' . $usageCount . ' event(s). Reassign those events before deleting it.'
        );
    }

    $deleteStmt = $conn->prepare('DELETE FROM event_types WHERE id = ?');
    $deleteStmt->bind_param('i', $eventTypeId);
    $deleteStmt->execute();
    if ($deleteStmt->affected_rows !== 1) {
        throw new RuntimeException('The event type could not be deleted.');
    }
    $deleteStmt->close();

    $conn->commit();
    $_SESSION['event_type_success'] = 'Event type “' . $eventType['name'] . '” was deleted.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['event_type_error'] = $e->getMessage();
}

header('Location: eventtype_list.php');
exit;

