<?php
session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
// Get organizations member is part of
$stmt = $conn->prepare('SELECT o.id, o.name FROM organizations o INNER JOIN member_organizations mo ON mo.organization_id = o.id WHERE mo.member_id = ?');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
$orgs = [];
while ($row = $res->fetch_assoc()) $orgs[] = $row;
$stmt->close();
// Get organization members if org_id is set
$org_members = [];
$org_name = '';
if (isset($_GET['org_id']) && is_numeric($_GET['org_id'])) {
    $org_id = intval($_GET['org_id']);
    $stmt = $conn->prepare('SELECT name FROM organizations WHERE id = ?');
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $stmt->bind_result($org_name);
    $stmt->fetch();
    $stmt->close();
    $stmt = $conn->prepare('SELECT m.id, m.first_name, m.last_name, m.photo FROM members m INNER JOIN member_organizations mo ON mo.member_id = m.id WHERE mo.organization_id = ? ORDER BY m.first_name, m.last_name');
    $stmt->bind_param('i', $org_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $org_members[] = $row;
    $stmt->close();
}
?>
<?php ob_start(); ?>
<div class="card mt-4">
  <div class="d-flex justify-content-end mb-3">
    <a href="<?= BASE_URL ?>/views/member_join_organization.php" class="btn btn-info">
      <i class="fas fa-plus-circle mr-1"></i> Join Organization(s)
    </a>
  </div>
  <div class="card-header bg-info text-white"><i class="fas fa-users-cog mr-2"></i>My Organizations</div>
  <div class="card-body">
    <div class="row">
      <div class="col-md-4">
        <ul class="list-group mb-3">
          <?php foreach ($orgs as $org): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center <?php if(isset($org_id)&&$org_id==$org['id'])echo'active'; ?>">
            <a href="?org_id=<?=$org['id']?>" class="stretched-link text-dark" style="text-decoration:none;<?php if(isset($org_id)&&$org_id==$org['id'])echo'color:white;'; ?>">
              <i class="fas fa-users-cog mr-1"></i> <?=htmlspecialchars($org['name'])?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-8">
        <?php if ($org_name): ?>
        <div class="mb-3"><strong>Members of <?=htmlspecialchars($org_name)?></strong></div>
        <table class="table table-striped table-bordered">
          <thead class="thead-light"><tr><th>Photo</th><th>Name</th></tr></thead>
          <tbody>
            <?php foreach ($org_members as $m): ?>
            <tr>
              <td><img src="<?= BASE_URL ?>/uploads/members/<?=rawurlencode($m['photo'])?>" alt="Photo" style="height:32px;width:32px;object-fit:cover;border-radius:50%;"></td>
              <td><?=htmlspecialchars($m['first_name'].' '.$m['last_name'])?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php elseif (count($orgs) == 0): ?>
          <div class="alert alert-warning">You are not part of any organizations.</div>
        <?php else: ?>
          <div class="alert alert-info">Select an organization to view its members.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</div>
<?php $page_content = ob_get_clean(); ?>
<?php include '../includes/layout.php'; ?>