<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/csrf.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('manage_roles')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $_SESSION['roles_of_serving_error'] = 'Role deletion requires a valid form submission.';
    header('Location: roles_of_serving_list.php');
    exit;
}

$id = max(0, intval($_POST['id'] ?? 0));
if ($id < 1 || !csrf_is_valid($_POST['csrf_token'] ?? null)) {
    $_SESSION['roles_of_serving_error'] = 'The delete request was invalid or expired.';
    header('Location: roles_of_serving_list.php');
    exit;
}

try {
    $conn->begin_transaction();

    $roleStmt = $conn->prepare('SELECT name FROM roles_of_serving WHERE id = ? FOR UPDATE');
    $roleStmt->bind_param('i', $id);
    $roleStmt->execute();
    $role = $roleStmt->get_result()->fetch_assoc();
    $roleStmt->close();

    if (!$role) {
        throw new RuntimeException('Role not found.');
    }

    $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM member_roles_of_serving WHERE role_id = ?');
    $countStmt->bind_param('i', $id);
    $countStmt->execute();
    $assignmentCount = (int) $countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    if ($assignmentCount > 0) {
        throw new RuntimeException('This role is assigned to ' . $assignmentCount . ' member(s). Reassign them before deleting it.');
    }

    $stmt = $conn->prepare('DELETE FROM roles_of_serving WHERE id = ?');
    $stmt->bind_param('i', $id);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException($stmt->error ?: 'The role could not be deleted.');
    }
    $stmt->close();

    $conn->commit();
    $_SESSION['roles_of_serving_success'] = 'Role deleted successfully.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['roles_of_serving_error'] = $e->getMessage();
}
header('Location: roles_of_serving_list.php');
exit;
