<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Authentication check
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Permission check
if (!has_permission('view_member')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}
?>
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
// Get member's class
$stmt = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$stmt->bind_result($class_id);
$stmt->fetch();
$stmt->close();
$class_name = 'Not Assigned';
$members = [];
$class_leader = null;
if ($class_id) {
    // Get class name
    $stmt = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $stmt->bind_result($class_name);
    $stmt->fetch();
    $stmt->close();
    // Get class leader (using leader_id field in bible_classes, now referencing users)
    $stmt = $conn->prepare('SELECT leader_id FROM bible_classes WHERE id = ?');
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $stmt->bind_result($leader_id);
    $stmt->fetch();
    $stmt->close();
    if ($leader_id) {
        $stmt = $conn->prepare('SELECT id, name, email, photo FROM users WHERE id = ?');
        $stmt->bind_param('i', $leader_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $class_leader = $res->fetch_assoc();
        $stmt->close();
    }
    // Get all members in class (excluding leader)
    $stmt = $conn->prepare('SELECT id, first_name, last_name, photo FROM members WHERE class_id = ? ORDER BY first_name, last_name');
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (!$class_leader || $row['id'] != $class_leader['id']) $members[] = $row;
    }
    $stmt->close();
}
?>
<?php ob_start(); ?>
<div class="card mt-4">
        <div class="card-header bg-primary text-white"><i class="fas fa-book mr-2"></i>Bible Class: <?=htmlspecialchars($class_name)?></div>
        <div class="card-body">
    <?php if ($class_leader): ?>
    <div class="mb-3 p-3 bg-light border rounded">
      <div class="d-flex align-items-center mb-2">
  <img src="<?php
    if (!empty($class_leader['photo']) && file_exists(__DIR__.'/../uploads/members/' . $class_leader['photo'])) {
        echo BASE_URL . '/uploads/members/' . rawurlencode($class_leader['photo']);
    } else {
        echo BASE_URL . '/assets/img/undraw_profile.svg';
    }
?>" alt="Photo" style="height:48px;width:48px;object-fit:cover;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,0.10);margin-right:16px;">
  <div>
    <span class="badge badge-primary px-2 py-1" style="font-size:0.95rem;">Class Leader</span><br>
    <span class="font-weight-bold text-primary" style="font-size:1.25rem;">
      <?=htmlspecialchars($class_leader['name'])?>
    </span><br>
    <span class="text-muted" style="font-size:0.95rem;">
      <?=htmlspecialchars($class_leader['email'])?>
    </span>
  </div>
</div>
    </div>
    <?php endif; ?>
    <table class="table table-striped table-bordered">
      <thead class="thead-light"><tr><th>Photo</th><th>Name</th></tr></thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td><img src="<?php
    if (!empty($m['photo']) && file_exists(__DIR__.'/../uploads/members/' . $m['photo'])) {
        echo BASE_URL . '/uploads/members/' . rawurlencode($m['photo']);
    } else {
        echo BASE_URL . '/assets/img/undraw_profile.svg';
    }
?>" alt="Photo" style="height:32px;width:32px;object-fit:cover;border-radius:50%;"></td>
          <td><?=htmlspecialchars($m['first_name'].' '.$m['last_name'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$class_id): ?>
      <div class="alert alert-warning mt-3">You are not assigned to any class.</div>
    <?php endif; ?>
  </div>
</div>
  </div>
</div>
<?php $page_content = ob_get_clean(); ?>
<?php include '../includes/layout.php'; ?>