<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/RoleOfServingAccessService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = in_array(1, $roleIds, true);
if (!$isSuperAdmin && !has_permission('manage_role_of_serving_access')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$service = new RoleOfServingAccessService($conn);
$message = '';
$error = '';
$actorUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your form expired. Refresh and try again.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_mapping') {
            $servingRoleId = (int) ($_POST['role_of_serving_id'] ?? 0);
            $accessRoleId = (int) ($_POST['access_role_id'] ?? 0);
            $isActive = isset($_POST['is_active']);
            $service->saveMapping($servingRoleId, $accessRoleId, $isActive, $actorUserId);
            $message = $isActive
                ? 'Mapping saved and linked user accounts synchronized.'
                : 'Mapping disabled and only access derived exclusively from it was revoked.';
        } elseif ($action === 'toggle_mapping') {
            $mappingId = (int) ($_POST['mapping_id'] ?? 0);
            $stmt = $conn->prepare(
                'SELECT role_of_serving_id, access_role_id, is_active
                   FROM role_of_serving_access_mappings WHERE id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $mappingId);
            $stmt->execute();
            $mapping = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$mapping) throw new RuntimeException('Mapping not found.');
            $newActive = !(bool) $mapping['is_active'];
            $service->saveMapping(
                (int) $mapping['role_of_serving_id'], (int) $mapping['access_role_id'],
                $newActive, $actorUserId
            );
            $message = $newActive ? 'Mapping activated and synchronized.' : 'Mapping disabled and synchronized.';
        } elseif ($action === 'sync_all') {
            $outcome = $service->syncAll($actorUserId);
            $message = sprintf(
                'Synchronization complete: %d users checked, %d derived grants added, %d obsolete derived grants revoked.',
                $outcome['processed'], $outcome['granted'], $outcome['revoked']
            );
        } elseif ($action === 'delete_mapping') {
            $outcome = $service->deleteMapping((int) ($_POST['mapping_id'] ?? 0), $actorUserId);
            $message = sprintf(
                'Mapping removed safely: %d users checked and %d obsolete derived grants revoked.',
                $outcome['processed'], $outcome['revoked']
            );
        } else {
            throw new RuntimeException('Choose a valid access-mapping action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$servingRoles = $conn->query(
    "SELECT id, name FROM roles_of_serving
      WHERE name NOT LIKE 'LEGACY %REVIEW REQUIRED'
      ORDER BY name"
)->fetch_all(MYSQLI_ASSOC);
$accessRoles = $conn->query(
    'SELECT id, name FROM roles WHERE is_active = 1 ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);
$mappings = $service->getMappings();
$policyRows = $conn->query(
    "SELECT review.issue_type, COUNT(*) AS total
       FROM user_account_policy_review review
      WHERE review.resolved = 0
      GROUP BY review.issue_type ORDER BY review.issue_type"
)->fetch_all(MYSQLI_ASSOC);
$recentAudit = $conn->query(
    "SELECT audit.action, audit.reason, audit.created_at,
            member.crn, TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)) AS member_name,
            access_role.name AS access_role_name, serving_role.name AS serving_role_name
       FROM role_access_sync_audit audit
       JOIN members member ON member.id = audit.member_id
       JOIN roles access_role ON access_role.id = audit.access_role_id
       LEFT JOIN roles_of_serving serving_role ON serving_role.id = audit.role_of_serving_id
      ORDER BY audit.id DESC LIMIT 50"
)->fetch_all(MYSQLI_ASSOC);

$page_title = 'Role Access Mapping';
ob_start();
?>
<style>
.role-map-page{background:#f5f7fb;min-height:calc(100vh - 70px);padding:1rem 0 2rem}.role-map-hero{background:linear-gradient(135deg,#263c69,#496aa5);color:#fff;border-radius:15px;padding:1.25rem 1.5rem;box-shadow:0 8px 22px rgba(38,60,105,.22)}.role-map-card{border:0;border-radius:13px;box-shadow:0 5px 17px rgba(30,42,65,.09)}.role-map-table thead th{background:#263c69;color:#fff;white-space:nowrap}.policy-pill{border-radius:10px;background:#fff3cd;padding:.6rem .8rem;margin:.2rem;display:inline-block}
</style>
<div class="role-map-page"><div class="container-fluid">
  <div class="role-map-hero mb-3 d-flex flex-wrap justify-content-between align-items-center"><div><h2 class="mb-1"><i class="fas fa-project-diagram mr-2"></i>Role of Serving Access Mapping</h2><div>Controlled bridge between church offices and back-office permissions</div></div><a class="btn btn-light btn-sm" href="roles_of_serving_list.php">Roles of Serving</a></div>
  <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <div class="alert alert-info"><strong>Safe by design:</strong> existing user roles remain manual. Only mappings explicitly activated here create derived access, and disabling a mapping cannot remove a separately assigned manual role.</div>

  <div class="row">
    <div class="col-lg-4 mb-3"><div class="card role-map-card"><div class="card-header bg-white font-weight-bold">Add or Update Mapping</div><div class="card-body">
      <form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="save_mapping">
        <div class="form-group"><label>Role of Serving</label><select class="form-control" name="role_of_serving_id" required><option value="">Select office</option><?php foreach ($servingRoles as $role): ?><option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>System Access Role</label><select class="form-control" name="access_role_id" required><option value="">Select permission bundle</option><?php foreach ($accessRoles as $role): ?><option value="<?= (int) $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option><?php endforeach; ?></select></div>
        <div class="custom-control custom-checkbox mb-3"><input type="checkbox" class="custom-control-input" id="mappingActive" name="is_active" value="1" checked><label class="custom-control-label" for="mappingActive">Active and synchronized</label></div>
        <button class="btn btn-primary btn-block">Save and Synchronize</button>
      </form>
      <hr><form method="post" onsubmit="return confirm('Synchronize all linked users from active mappings?')"><?= csrf_input() ?><input type="hidden" name="action" value="sync_all"><button class="btn btn-outline-secondary btn-block"><i class="fas fa-sync mr-1"></i>Synchronize All Linked Users</button></form>
    </div></div>
    <div class="card role-map-card"><div class="card-header bg-white font-weight-bold">Legacy Policy Review</div><div class="card-body"><?php if (!$policyRows): ?><span class="text-success">No open policy exceptions.</span><?php endif; ?><?php foreach ($policyRows as $row): ?><span class="policy-pill"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['issue_type']))) ?>: <strong><?= number_format((int) $row['total']) ?></strong></span><?php endforeach; ?><div class="small text-muted mt-2">Legacy accounts are queued for review; this migration does not disable them automatically.</div></div></div>
    </div>
    <div class="col-lg-8">
      <div class="card role-map-card mb-3"><div class="card-header bg-white font-weight-bold">Configured Mappings</div><div class="table-responsive"><table class="table table-bordered table-hover role-map-table mb-0"><thead><tr><th>Role of Serving</th><th>Access Role</th><th>Members</th><th>Linked Users</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php if (!$mappings): ?><tr><td colspan="6" class="text-center text-muted py-4">No mappings are configured. Existing access remains unchanged.</td></tr><?php endif; ?>
      <?php foreach ($mappings as $mapping): ?><tr><td><?= htmlspecialchars($mapping['serving_role_name']) ?></td><td><?= htmlspecialchars($mapping['access_role_name']) ?></td><td><?= number_format((int) $mapping['assigned_members']) ?></td><td><?= number_format((int) $mapping['linked_users']) ?></td><td><span class="badge badge-<?= $mapping['is_active'] ? 'success' : 'secondary' ?>"><?= $mapping['is_active'] ? 'Active' : 'Disabled' ?></span></td><td><div class="d-flex"><form method="post" class="mr-1"><?= csrf_input() ?><input type="hidden" name="action" value="toggle_mapping"><input type="hidden" name="mapping_id" value="<?= (int) $mapping['id'] ?>"><button class="btn btn-sm btn-outline-<?= $mapping['is_active'] ? 'warning' : 'success' ?>"><?= $mapping['is_active'] ? 'Disable' : 'Activate' ?></button></form><form method="post" onsubmit="return confirm('Remove this mapping and synchronize all linked users?')"><?= csrf_input() ?><input type="hidden" name="action" value="delete_mapping"><input type="hidden" name="mapping_id" value="<?= (int) $mapping['id'] ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></div></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
      <div class="card role-map-card"><div class="card-header bg-white font-weight-bold">Recent Synchronization Audit</div><div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr><th>When</th><th>Member</th><th>Serving Role</th><th>Access Role</th><th>Action</th></tr></thead><tbody><?php if (!$recentAudit): ?><tr><td colspan="5" class="text-center text-muted py-3">No derived access changes recorded.</td></tr><?php endif; ?><?php foreach ($recentAudit as $audit): ?><tr><td><?= htmlspecialchars($audit['created_at']) ?></td><td><?= htmlspecialchars(($audit['crn'] ?: 'No CRN') . ' - ' . $audit['member_name']) ?></td><td><?= htmlspecialchars($audit['serving_role_name'] ?: '-') ?></td><td><?= htmlspecialchars($audit['access_role_name']) ?></td><td><span class="badge badge-<?= $audit['action'] === 'granted' ? 'success' : ($audit['action'] === 'revoked' ? 'danger' : 'secondary') ?>"><?= htmlspecialchars(ucfirst($audit['action'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
  </div>
</div></div>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
