<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../services/HealthEncounterService.php';

header('Content-Type: application/json; charset=utf-8');
if (!is_logged_in() || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}
$actorUserId = (int) $_SESSION['user_id'];
$actorChurchId = (int) ($_SESSION['church_id'] ?? 0);
if ($actorChurchId <= 0) {
    $stmt = $conn->prepare('SELECT church_id FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $actorUserId);
    $stmt->execute();
    $actorChurchId = (int) ($stmt->get_result()->fetch_assoc()['church_id'] ?? 0);
    $stmt->close();
}
$superAdmin = is_super_admin();
$service = new HealthEncounterService($conn, $actorUserId, $actorChurchId, $superAdmin);
$churchId = $superAdmin ? max(0, (int) ($_GET['church_id'] ?? $actorChurchId)) : $actorChurchId;
if (!$service->authorize('create_health_record', $churchId)
    && !$service->authorize('edit_health_record', $churchId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit;
}
$person = $service->findPersonByIdentifier((string) ($_GET['identifier'] ?? ''), $churchId);
if (!$person) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No active person was found for that CRN or SRN in your church.']);
    exit;
}
echo json_encode(['success' => true, 'person' => $person], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
