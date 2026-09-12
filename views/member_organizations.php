<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

function member_organization_logo_url(?string $relativePath): ?string {
    if (!$relativePath || !preg_match('#^organizations/org_[A-Za-z0-9_]+\.(?:jpg|png|webp)$#', $relativePath)) {
        return null;
    }
    return BASE_URL . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
// Get organizations and current group/part/section assignments for this member.
$stmt = $conn->prepare(
    "SELECT o.id, o.name, o.logo_path, o.logo_alt_text,
            GROUP_CONCAT(CONCAT(unit.name, ' (', REPLACE(assignment.assignment_type, '_', ' '), ')')
                         ORDER BY assignment.assignment_type SEPARATOR ', ') AS assignment_summary
       FROM organizations o
       INNER JOIN member_organizations membership ON membership.organization_id = o.id
       LEFT JOIN organization_unit_assignments assignment ON assignment.member_organization_id = membership.id
       LEFT JOIN organization_units unit ON unit.id = assignment.unit_id
      WHERE membership.member_id = ?
      GROUP BY o.id, o.name, o.logo_path, o.logo_alt_text
      ORDER BY o.name"
);
$stmt->bind_param('i', $member_id);
$stmt->execute();
$res = $stmt->get_result();
$orgs = [];
while ($row = $res->fetch_assoc()) $orgs[] = $row;
$stmt->close();
// Get organization members if org_id is set
$org_members = [];
$org_name = '';
$selected_org = null;
if (isset($_GET['org_id']) && is_numeric($_GET['org_id'])) {
    $org_id = intval($_GET['org_id']);
    foreach ($orgs as $org) {
        if ((int) $org['id'] === $org_id) {
            $selected_org = $org;
            $org_name = $org['name'];
            break;
        }
    }
    if (!$selected_org) {
        http_response_code(403);
        exit('You can only view members of organizations you have joined.');
    }
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
              <?php $orgLogoUrl = member_organization_logo_url($org['logo_path'] ?? null); ?>
              <?php if ($orgLogoUrl): ?><img src="<?= htmlspecialchars($orgLogoUrl) ?>" alt="" style="height:28px;width:28px;object-fit:contain" class="mr-1"><?php else: ?><i class="fas fa-users-cog mr-1"></i><?php endif; ?>
              <?=htmlspecialchars($org['name'])?>
              <?php if ($org['assignment_summary']): ?><small class="d-block ml-4"><?= htmlspecialchars($org['assignment_summary']) ?></small><?php endif; ?>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="col-md-8">
        <?php if ($org_name): ?>
        <div class="d-flex align-items-center mb-3">
          <?php $selectedLogoUrl = member_organization_logo_url($selected_org['logo_path'] ?? null); ?>
          <?php if ($selectedLogoUrl): ?><img src="<?= htmlspecialchars($selectedLogoUrl) ?>" alt="<?= htmlspecialchars($selected_org['logo_alt_text'] ?: $org_name . ' logo') ?>" class="img-thumbnail mr-3" style="height:64px;width:64px;object-fit:contain"><?php endif; ?>
          <div><strong>Members of <?=htmlspecialchars($org_name)?></strong><?php if ($selected_org['assignment_summary']): ?><div class="text-muted">Your assignment: <?= htmlspecialchars($selected_org['assignment_summary']) ?></div><?php endif; ?></div>
        </div>
        <table class="table table-striped table-bordered">
          <thead class="thead-light"><tr><th>Photo</th><th>Name</th></tr></thead>
          <tbody>
            <?php foreach ($org_members as $m): ?>
            <tr>
              <td><?php if ($m['photo']): ?><img src="<?= BASE_URL ?>/uploads/members/<?=rawurlencode($m['photo'])?>" alt="" style="height:32px;width:32px;object-fit:cover;border-radius:50%;"><?php else: ?><i class="fas fa-user-circle text-muted"></i><?php endif; ?></td>
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
