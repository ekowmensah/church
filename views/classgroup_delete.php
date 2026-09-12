<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
if ((int) ($_SESSION['role_id'] ?? 0) !== 1 && !has_permission('delete_classgroup')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    exit('Your form expired. Refresh and try again.');
}

$id = (int) ($_POST['id'] ?? 0);
if ($id < 1) {
    http_response_code(400);
    exit('Invalid class group ID.');
}

$count = $conn->prepare('SELECT COUNT(*) AS total FROM bible_classes WHERE class_group_id = ?');
$count->bind_param('i', $id);
$count->execute();
$assignedClasses = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
$count->close();
if ($assignedClasses > 0) {
    header('Location: classgroup_list.php?delete_blocked=1');
    exit;
}

$stmt = $conn->prepare('DELETE FROM class_groups WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$deleted = $stmt->affected_rows > 0;
$stmt->close();

header('Location: classgroup_list.php?' . ($deleted ? 'deleted=1' : 'delete_missing=1'));
exit;
