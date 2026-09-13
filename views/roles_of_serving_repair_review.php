<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../services/RoleOfServingAccessService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!has_permission('manage_roles')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$successMessage = $_SESSION['roles_of_serving_review_success'] ?? '';
$errorMessage = $_SESSION['roles_of_serving_review_error'] ?? '';
unset($_SESSION['roles_of_serving_review_success'], $_SESSION['roles_of_serving_review_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reviewId = max(0, intval($_POST['review_id'] ?? 0));
    $resolvedRoleId = max(0, intval($_POST['resolved_role_id'] ?? 0));

    if (!csrf_is_valid($_POST['csrf_token'] ?? null) || $reviewId < 1 || $resolvedRoleId < 1) {
        $_SESSION['roles_of_serving_review_error'] = 'The review request was invalid or expired.';
        header('Location: roles_of_serving_repair_review.php');
        exit;
    }

    try {
        $conn->begin_transaction();

        $reviewStmt = $conn->prepare(
            'SELECT review_item.*, m.id AS valid_member_id
               FROM role_assignment_repair_review review_item
               LEFT JOIN members m ON m.id = review_item.member_id
              WHERE review_item.id = ? AND review_item.resolved = 0
              FOR UPDATE'
        );
        $reviewStmt->bind_param('i', $reviewId);
        $reviewStmt->execute();
        $review = $reviewStmt->get_result()->fetch_assoc();
        $reviewStmt->close();

        if (!$review || empty($review['valid_member_id'])) {
            throw new RuntimeException('This review item is no longer actionable.');
        }

        $roleStmt = $conn->prepare('SELECT id FROM roles_of_serving WHERE id = ?');
        $roleStmt->bind_param('i', $resolvedRoleId);
        $roleStmt->execute();
        $validRole = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();
        if (!$validRole) {
            throw new RuntimeException('Select a valid replacement role.');
        }

        $memberId = (int) $review['member_id'];
        $mappedRoleId = (int) ($review['mapped_role_id'] ?? 0);
        if ($mappedRoleId > 0 && $mappedRoleId !== $resolvedRoleId) {
            $deleteStmt = $conn->prepare('DELETE FROM member_roles_of_serving WHERE member_id = ? AND role_id = ?');
            $deleteStmt->bind_param('ii', $memberId, $mappedRoleId);
            $deleteStmt->execute();
            $deleteStmt->close();
        }

        $assignStmt = $conn->prepare(
            'INSERT INTO member_roles_of_serving (member_id, role_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)'
        );
        $assignStmt->bind_param('ii', $memberId, $resolvedRoleId);
        $assignStmt->execute();
        $assignStmt->close();

        $resolvedBy = (int) ($_SESSION['user_id'] ?? 0);
        $resolveStmt = $conn->prepare(
            'UPDATE role_assignment_repair_review
                SET resolved = 1, resolved_role_id = ?, resolved_by = ?, resolved_at = NOW()
              WHERE id = ?'
        );
        $resolveStmt->bind_param('iii', $resolvedRoleId, $resolvedBy, $reviewId);
        $resolveStmt->execute();
        $resolveStmt->close();

        $roleAccessService = new RoleOfServingAccessService($conn);
        $roleAccessService->syncMember($memberId, $resolvedBy, 'Role assignment repair completed.');

        $conn->commit();
        $_SESSION['roles_of_serving_review_success'] = 'The member role was reassigned successfully.';
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['roles_of_serving_review_error'] = $e->getMessage();
    }

    header('Location: roles_of_serving_repair_review.php');
    exit;
}

$roles = [];
$rolesResult = $conn->query("SELECT id, name FROM roles_of_serving WHERE name NOT LIKE 'LEGACY %REVIEW REQUIRED' ORDER BY name");
while ($rolesResult && ($role = $rolesResult->fetch_assoc())) {
    $roles[] = $role;
}

$items = $conn->query(
    "SELECT review_item.*, m.crn, m.first_name, m.middle_name, m.last_name,
            mapped_role.name AS mapped_role_name
       FROM role_assignment_repair_review review_item
       INNER JOIN members m ON m.id = review_item.member_id
       LEFT JOIN roles_of_serving mapped_role ON mapped_role.id = review_item.mapped_role_id
      WHERE review_item.resolved = 0
      ORDER BY review_item.issue_type, m.last_name, m.first_name, review_item.id"
);

ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-1">Legacy Role Assignment Review</h3>
      <p class="text-muted mb-0">Resolve assignments that could not be reconstructed safely from the live database.</p>
    </div>
    <a href="roles_of_serving_list.php" class="btn btn-secondary">Back to Roles</a>
  </div>

  <?php if ($successMessage): ?><div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div><?php endif; ?>
  <?php if ($errorMessage): ?><div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div><?php endif; ?>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-bordered table-hover mb-0">
        <thead class="thead-light">
          <tr>
            <th>CRN</th>
            <th>Member</th>
            <th>Issue</th>
            <th>Temporary Mapping</th>
            <th style="min-width:280px">Correct Role</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($items && $items->num_rows): ?>
          <?php while ($item = $items->fetch_assoc()): ?>
            <tr>
              <td><?= htmlspecialchars((string) $item['crn']) ?></td>
              <td><?= htmlspecialchars(trim($item['first_name'].' '.$item['middle_name'].' '.$item['last_name'])) ?></td>
              <td>
                <strong><?= htmlspecialchars(str_replace('_', ' ', $item['issue_type'])) ?></strong>
                <div class="small text-muted"><?= htmlspecialchars($item['details']) ?></div>
              </td>
              <td><?= htmlspecialchars($item['mapped_role_name'] ?: ('Role ID '.$item['mapped_role_id'])) ?></td>
              <td>
                <form method="post" class="form-inline">
                  <?= csrf_input() ?>
                  <input type="hidden" name="review_id" value="<?= (int) $item['id'] ?>">
                  <select name="resolved_role_id" class="form-control form-control-sm mr-2" required>
                    <option value="">Select role</option>
                    <?php foreach ($roles as $role): ?>
                      <option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-success">Resolve</button>
                </form>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No actionable assignments remain.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
