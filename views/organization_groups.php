<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/leader_helpers.php';
require_once __DIR__.'/../helpers/csrf.php';
require_once __DIR__.'/../services/OrganizationGroupService.php';
require_once __DIR__.'/../services/OrganizationLogoService.php';

function organization_logo_url(?string $relativePath): ?string {
    if (!$relativePath || !preg_match('#^organizations/(?:org|unit)_[A-Za-z0-9_]+\.(?:jpg|png|webp)$#', $relativePath)) {
        return null;
    }
    return BASE_URL . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $relativePath)));
}

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
$sessionMemberId = (int) ($_SESSION['member_id'] ?? 0);
$sessionRoleId = (int) ($_SESSION['role_id'] ?? 0);
$leaderOrganizations = is_organization_leader($conn, $sessionUserId ?: null, $sessionMemberId ?: null);
$leaderOrganizationIds = $leaderOrganizations
    ? array_map('intval', array_column($leaderOrganizations, 'organization_id'))
    : [];
$canManageAll = in_array($sessionRoleId, [1, 2], true);
$canViewByPermission = has_permission('view_organization_groups');
$mustScopeToLedOrganizations = !$canManageAll
    && ($sessionRoleId === 6 || !empty($leaderOrganizationIds));

if (!$canManageAll && (($mustScopeToLedOrganizations && !$leaderOrganizationIds) || (!$mustScopeToLedOrganizations && !$canViewByPermission))) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to view organization groups.</p></div>';
    exit;
}

$organizations = [];
if ($canManageAll || (!$mustScopeToLedOrganizations && $canViewByPermission)) {
    $result = $conn->query('SELECT id, name, assignment_strategy FROM organizations ORDER BY name');
    while ($result && ($row = $result->fetch_assoc())) {
        $organizations[] = $row;
    }
} else {
    $organizationIdList = implode(',', $leaderOrganizationIds);
    $result = $conn->query("SELECT id, name, assignment_strategy FROM organizations WHERE id IN ({$organizationIdList}) ORDER BY name");
    while ($result && ($row = $result->fetch_assoc())) {
        $organizations[] = $row;
    }
}

$allowedOrganizationIds = array_map('intval', array_column($organizations, 'id'));
$organizationId = max(0, intval($_REQUEST['org_id'] ?? 0));
if (!$organizationId || !in_array($organizationId, $allowedOrganizationIds, true)) {
    $organizationId = $allowedOrganizationIds[0] ?? 0;
}

$canManageSelected = $canManageAll
    || in_array($organizationId, $leaderOrganizationIds, true)
    || (!$mustScopeToLedOrganizations && has_permission('manage_organization_groups'));
$canAssignLeaders = $canManageAll
    || in_array($organizationId, $leaderOrganizationIds, true)
    || (!$mustScopeToLedOrganizations && has_permission('assign_organization_group_leaders'));
$canReassignMembers = $canManageAll
    || in_array($organizationId, $leaderOrganizationIds, true)
    || (!$mustScopeToLedOrganizations && has_permission('reassign_organization_groups'));
$service = new OrganizationGroupService($conn);
$logoService = new OrganizationLogoService($conn);
$success = $_SESSION['organization_groups_success'] ?? '';
$error = $_SESSION['organization_groups_error'] ?? '';
unset($_SESSION['organization_groups_success'], $_SESSION['organization_groups_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectUrl = 'organization_groups.php?org_id=' . $organizationId;
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        $_SESSION['organization_groups_error'] = 'Your form session expired. Refresh the page and try again.';
        header('Location: ' . $redirectUrl);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $transactionStarted = false;
    try {
        if (!$organizationId) {
            throw new RuntimeException('Select an organization first.');
        }

        if (in_array($action, ['upload_organization_logo', 'remove_organization_logo', 'upload_unit_logo', 'remove_unit_logo'], true)) {
            if (!$canManageSelected) {
                throw new RuntimeException('You cannot manage logos for this organization.');
            }
            $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
            $unitId = max(0, intval($_POST['unit_id'] ?? 0));
            if ($action === 'upload_organization_logo') {
                $logoService->uploadOrganizationLogo(
                    $organizationId,
                    $_FILES['logo'] ?? [],
                    (string) ($_POST['logo_alt_text'] ?? ''),
                    $actorUserId
                );
                $message = 'Organization logo uploaded.';
            } elseif ($action === 'remove_organization_logo') {
                $message = $logoService->removeOrganizationLogo($organizationId, $actorUserId)
                    ? 'Organization logo removed.'
                    : 'This organization does not have a logo.';
            } elseif ($action === 'upload_unit_logo') {
                $logoService->uploadUnitLogo(
                    $organizationId,
                    $unitId,
                    $_FILES['logo'] ?? [],
                    (string) ($_POST['logo_alt_text'] ?? ''),
                    $actorUserId
                );
                $message = 'Organization unit logo uploaded.';
            } else {
                $message = $logoService->removeUnitLogo($organizationId, $unitId, $actorUserId)
                    ? 'Organization unit logo removed.'
                    : 'This organization unit does not have a logo.';
            }

            $_SESSION['organization_groups_success'] = $message;
            header('Location: ' . $redirectUrl);
            exit;
        }

        $conn->begin_transaction();
        $transactionStarted = true;
        if ($action === 'update_strategy') {
            if (!$canManageSelected) {
                throw new RuntimeException('You cannot change this organization configuration.');
            }
            $strategy = (string) ($_POST['assignment_strategy'] ?? '');
            $validStrategies = ['none', 'balanced_auto', 'manual_vocal_part', 'balanced_plus_section'];
            if (!in_array($strategy, $validStrategies, true)) {
                throw new RuntimeException('Select a valid assignment strategy.');
            }
            $stmt = $conn->prepare('UPDATE organizations SET assignment_strategy = ? WHERE id = ?');
            $stmt->bind_param('si', $strategy, $organizationId);
            $stmt->execute();
            $stmt->close();
            $message = 'Assignment strategy updated.';
        } elseif ($action === 'create_unit') {
            if (!$canManageSelected) {
                throw new RuntimeException('You cannot add units to this organization.');
            }
            $name = trim((string) ($_POST['name'] ?? ''));
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
            $unitType = (string) ($_POST['unit_type'] ?? 'group');
            $branch = (string) ($_POST['branch'] ?? 'mixed');
            if ($name === '' || strlen($name) > 100) {
                throw new RuntimeException('Enter a unit name of 100 characters or fewer.');
            }
            if (!preg_match('/^[A-Z0-9-]{2,40}$/', $code)) {
                throw new RuntimeException('Unit code must contain 2-40 uppercase letters, numbers, or hyphens.');
            }
            if (!in_array($unitType, ['group', 'vocal_part', 'brigade_section'], true)) {
                throw new RuntimeException('Select a valid unit type.');
            }
            if (!in_array($branch, ['mixed', 'boys', 'girls'], true)) {
                throw new RuntimeException('Select a valid branch.');
            }
            if ($unitType !== 'brigade_section') {
                $branch = 'mixed';
            }
            $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
            $stmt = $conn->prepare(
                'INSERT INTO organization_units
                    (organization_id, name, code, unit_type, branch, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('issssi', $organizationId, $name, $code, $unitType, $branch, $actorUserId);
            if (!$stmt->execute()) {
                throw new RuntimeException($stmt->errno === 1062 ? 'That unit name or code already exists.' : $stmt->error);
            }
            $stmt->close();
            $message = 'Organization unit created.';
        } elseif ($action === 'assign_leader') {
            if (!$canAssignLeaders) {
                throw new RuntimeException('You cannot assign leaders for this organization.');
            }
            $unitId = max(0, intval($_POST['unit_id'] ?? 0));
            $memberId = max(0, intval($_POST['member_id'] ?? 0));
            $leaderRole = (string) ($_POST['leader_role'] ?? 'leader');
            $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
            $service->assignLeader($organizationId, $unitId, $memberId, $actorUserId, $leaderRole);
            $message = 'Unit leader updated.';
        } elseif ($action === 'assign_member') {
            if (!$canReassignMembers) {
                throw new RuntimeException('You cannot assign members for this organization.');
            }
            $unitId = max(0, intval($_POST['unit_id'] ?? 0));
            $memberId = max(0, intval($_POST['member_id'] ?? 0));
            $rankOrLevel = trim((string) ($_POST['rank_or_level'] ?? ''));
            $reason = trim((string) ($_POST['reason'] ?? ''));
            $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
            $assignedUnit = $service->assignMemberToUnit(
                $organizationId,
                $unitId,
                $memberId,
                $actorUserId,
                $rankOrLevel,
                $reason
            );
            $message = 'Member assignment updated to ' . $assignedUnit['name'] . '.';
        } elseif ($action === 'backfill') {
            if (!$canManageSelected) {
                throw new RuntimeException('You cannot run a group backfill for this organization.');
            }
            $actorUserId = $sessionUserId > 0 ? $sessionUserId : null;
            $assignedCount = $service->backfillBalancedOrganization($organizationId, $actorUserId, 500);
            $message = $assignedCount > 0
                ? $assignedCount . ' existing member(s) assigned to balanced groups.'
                : 'All eligible members already have a primary group.';
        } else {
            throw new RuntimeException('Invalid organization-group action.');
        }

        $conn->commit();
        $transactionStarted = false;
        $_SESSION['organization_groups_success'] = $message;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        $_SESSION['organization_groups_error'] = $e->getMessage();
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$organization = null;
$units = [];
$leadersByUnit = [];
$eligibleMembersByUnit = [];
$organizationMemberOptions = [];
$currentAssignments = [];
$assignmentHistory = [];
$mediaHistory = [];
$unassignedPrimary = 0;
$unassignedLabel = 'primary group';
if ($organizationId) {
    $organization = $service->getOrganizationConfig($organizationId);
    $units = $service->getUnits($organizationId, null, false);

    $leaderStmt = $conn->prepare(
        "SELECT leader.unit_id, leader.leader_role, m.id AS member_id,
                m.crn, m.first_name, m.middle_name, m.last_name
           FROM organization_unit_leaders leader
           INNER JOIN members m ON m.id = leader.member_id
          WHERE leader.status = 'active'
            AND leader.unit_id IN (SELECT id FROM organization_units WHERE organization_id = ?)
          ORDER BY leader.leader_role, m.last_name, m.first_name"
    );
    $leaderStmt->bind_param('i', $organizationId);
    $leaderStmt->execute();
    $leaderResult = $leaderStmt->get_result();
    while ($leader = $leaderResult->fetch_assoc()) {
        $leadersByUnit[(int) $leader['unit_id']][] = $leader;
    }
    $leaderStmt->close();

    $memberStmt = $conn->prepare(
        "SELECT mo.id AS membership_id, m.id, m.crn, m.first_name, m.middle_name, m.last_name,
                assignment.unit_id, assignment.assignment_type
           FROM member_organizations mo
           INNER JOIN members m ON m.id = mo.member_id
           LEFT JOIN organization_unit_assignments assignment ON assignment.member_organization_id = mo.id
          WHERE mo.organization_id = ? AND m.status = 'active'
          ORDER BY m.last_name, m.first_name"
    );
    $memberStmt->bind_param('i', $organizationId);
    $memberStmt->execute();
    $organizationMembers = $memberStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $memberStmt->close();

    foreach ($organizationMembers as $member) {
        $organizationMemberOptions[(int) $member['id']] = $member;
    }

    foreach ($units as $unit) {
        $unitId = (int) $unit['id'];
        foreach ($organizationMembers as $member) {
            if ($unit['unit_type'] === 'brigade_section' || (int) ($member['unit_id'] ?? 0) === $unitId) {
                $eligibleMembersByUnit[$unitId][(int) $member['id']] = $member;
            }
        }
    }

    $expectedAssignmentType = $organization['assignment_strategy'] === 'manual_vocal_part'
        ? 'vocal_part'
        : 'primary_group';
    $unassignedLabel = $expectedAssignmentType === 'vocal_part' ? 'vocal part' : 'primary group';
    $unassignedStmt = $conn->prepare(
        "SELECT COUNT(*) AS total
           FROM member_organizations mo
           LEFT JOIN organization_unit_assignments assignment
                   ON assignment.member_organization_id = mo.id
                  AND assignment.assignment_type = ?
          WHERE mo.organization_id = ? AND assignment.id IS NULL"
    );
    $unassignedStmt->bind_param('si', $expectedAssignmentType, $organizationId);
    $unassignedStmt->execute();
    $unassignedPrimary = (int) $unassignedStmt->get_result()->fetch_assoc()['total'];
    $unassignedStmt->close();

    $assignmentStmt = $conn->prepare(
        'SELECT m.id AS member_id, m.crn, m.first_name, m.middle_name, m.last_name,
                assignment.assignment_type, assignment.assignment_method,
                assignment.rank_or_level, assignment.effective_from,
                unit.id AS unit_id, unit.name AS unit_name, unit.unit_type, unit.branch
           FROM organization_unit_assignments assignment
           INNER JOIN member_organizations membership ON membership.id = assignment.member_organization_id
           INNER JOIN members m ON m.id = membership.member_id
           INNER JOIN organization_units unit ON unit.id = assignment.unit_id
          WHERE membership.organization_id = ?
          ORDER BY m.last_name, m.first_name, assignment.assignment_type'
    );
    $assignmentStmt->bind_param('i', $organizationId);
    $assignmentStmt->execute();
    $currentAssignments = $assignmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $assignmentStmt->close();

    $historyStmt = $conn->prepare(
        'SELECT history.*, m.crn, m.first_name, m.middle_name, m.last_name,
                from_unit.name AS from_unit_name, to_unit.name AS to_unit_name,
                actor.name AS actor_name
           FROM organization_unit_assignment_history history
           LEFT JOIN members m ON m.id = history.member_id
           LEFT JOIN organization_units from_unit ON from_unit.id = history.from_unit_id
           LEFT JOIN organization_units to_unit ON to_unit.id = history.to_unit_id
           LEFT JOIN users actor ON actor.id = history.actor_user_id
          WHERE history.organization_id = ?
          ORDER BY history.created_at DESC, history.id DESC
          LIMIT 50'
    );
    $historyStmt->bind_param('i', $organizationId);
    $historyStmt->execute();
    $assignmentHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $historyStmt->close();

    $mediaStmt = $conn->prepare(
        'SELECT history.*, actor.name AS actor_name
           FROM organization_media_history history
           LEFT JOIN users actor ON actor.id = history.actor_user_id
          WHERE history.organization_id = ?
          ORDER BY history.created_at DESC, history.id DESC
          LIMIT 25'
    );
    $mediaStmt->bind_param('i', $organizationId);
    $mediaStmt->execute();
    $mediaHistory = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $mediaStmt->close();
}

$unitTypeLabels = [
    'group' => 'Groups',
    'vocal_part' => 'Vocal Parts',
    'brigade_section' => 'Brigade Sections',
];
$strategyLabels = [
    'none' => 'No automatic assignment',
    'balanced_auto' => 'Balanced automatic groups',
    'manual_vocal_part' => 'Manual vocal-part assignment',
    'balanced_plus_section' => 'Balanced groups + manual rank section',
];
$assignmentTypeLabels = [
    'primary_group' => 'Group',
    'vocal_part' => 'Vocal Part',
    'brigade_section' => 'Brigade Section',
];

ob_start();
?>
<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2 class="mb-1"><i class="fas fa-layer-group mr-2"></i>Organization Groups</h2>
      <p class="text-muted mb-0">Manage groups, vocal parts, Brigade sections, leaders, and legacy backfill.</p>
    </div>
    <a href="organization_list.php" class="btn btn-secondary">Organizations</a>
  </div>

  <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <form method="get" class="form-row align-items-end">
      <div class="col-md-8">
        <label for="org_id">Organization</label>
        <select class="form-control" id="org_id" name="org_id" onchange="this.form.submit()">
          <?php foreach ($organizations as $item): ?>
            <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $organizationId ? 'selected' : '' ?>><?= htmlspecialchars($item['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div></div>

  <?php if ($organization): ?>
    <div class="row mb-4">
      <div class="col-lg-4">
        <div class="card shadow-sm h-100"><div class="card-body">
          <h5><?= htmlspecialchars($organization['name']) ?></h5>
          <p class="mb-3"><strong>Strategy:</strong> <?= htmlspecialchars($strategyLabels[$organization['assignment_strategy']] ?? $organization['assignment_strategy']) ?></p>
          <?php if ($canManageSelected): ?>
            <form method="post" class="form-inline">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="update_strategy">
              <input type="hidden" name="org_id" value="<?= $organizationId ?>">
              <select name="assignment_strategy" class="form-control mr-2">
                <?php foreach ($strategyLabels as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $organization['assignment_strategy'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-primary">Save Strategy</button>
            </form>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-lg-4 mt-3 mt-lg-0">
        <div class="card shadow-sm h-100"><div class="card-body">
          <h5>Organization Logo</h5>
          <?php $organizationLogoUrl = organization_logo_url($organization['logo_path'] ?? null); ?>
          <?php if ($organizationLogoUrl): ?>
            <img src="<?= htmlspecialchars($organizationLogoUrl) ?>" alt="<?= htmlspecialchars($organization['logo_alt_text'] ?: $organization['name'] . ' logo') ?>" class="img-thumbnail mb-3" style="max-height: 90px; max-width: 180px;">
          <?php else: ?>
            <p class="text-muted">No logo uploaded.</p>
          <?php endif; ?>
          <?php if ($canManageSelected): ?>
            <form method="post" enctype="multipart/form-data" class="mb-2">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="upload_organization_logo">
              <input type="hidden" name="org_id" value="<?= $organizationId ?>">
              <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
              <div class="form-group mb-2"><input type="file" name="logo" class="form-control-file" accept="image/jpeg,image/png,image/webp" required></div>
              <div class="form-group mb-2"><input type="text" name="logo_alt_text" class="form-control form-control-sm" maxlength="160" value="<?= htmlspecialchars($organization['logo_alt_text'] ?? '') ?>" placeholder="Logo description (optional)"></div>
              <button class="btn btn-sm btn-primary"><i class="fas fa-upload mr-1"></i><?= $organizationLogoUrl ? 'Replace Logo' : 'Upload Logo' ?></button>
              <small class="form-text text-muted">JPG, PNG, or WebP; maximum 2 MB.</small>
            </form>
            <?php if ($organizationLogoUrl): ?>
              <form method="post" onsubmit="return confirm('Remove this organization logo?');">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="remove_organization_logo">
                <input type="hidden" name="org_id" value="<?= $organizationId ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash mr-1"></i>Remove</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </div></div>
      </div>
      <div class="col-lg-4 mt-3 mt-lg-0">
        <div class="card shadow-sm h-100"><div class="card-body">
          <h5>Existing Member Backfill</h5>
          <p class="mb-3"><strong><?= $unassignedPrimary ?></strong> member(s) do not have a <?= htmlspecialchars($unassignedLabel) ?>.</p>
          <?php if ($canManageSelected && in_array($organization['assignment_strategy'], ['balanced_auto', 'balanced_plus_section'], true)): ?>
            <form method="post" onsubmit="return confirm('Assign up to 500 existing members evenly across active groups?');">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="backfill">
              <input type="hidden" name="org_id" value="<?= $organizationId ?>">
              <button class="btn btn-warning">Run Balanced Backfill</button>
            </form>
            <?php if ($organization['assignment_strategy'] === 'balanced_plus_section'): ?>
              <small class="text-muted d-block mt-2">This assigns Brigade groups only. Rank-based sections remain a manual review.</small>
            <?php endif; ?>
          <?php else: ?>
            <small class="text-muted">Automatic backfill is unavailable for this assignment strategy.</small>
          <?php endif; ?>
        </div></div>
      </div>
    </div>

    <?php if ($canManageSelected): ?>
      <div class="card shadow-sm mb-4"><div class="card-header"><strong>Add Organization Unit</strong></div><div class="card-body">
        <form method="post" class="form-row align-items-end">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="create_unit">
          <input type="hidden" name="org_id" value="<?= $organizationId ?>">
          <div class="col-md-3"><label>Name</label><input class="form-control" name="name" maxlength="100" required></div>
          <div class="col-md-2"><label>Code</label><input class="form-control text-uppercase" name="code" maxlength="40" required></div>
          <div class="col-md-3"><label>Type</label><select class="form-control" name="unit_type"><option value="group">Group</option><option value="vocal_part">Vocal Part</option><option value="brigade_section">Brigade Section</option></select></div>
          <div class="col-md-2"><label>Branch</label><select class="form-control" name="branch"><option value="mixed">Mixed</option><option value="boys">Boys</option><option value="girls">Girls</option></select></div>
          <div class="col-md-2"><button class="btn btn-success btn-block">Add Unit</button></div>
        </form>
      </div></div>
    <?php endif; ?>

    <?php if ($canReassignMembers && $units): ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header"><strong>Assign or Reassign Member</strong></div>
        <div class="card-body">
          <form method="post" class="form-row align-items-end">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="assign_member">
            <input type="hidden" name="org_id" value="<?= $organizationId ?>">
            <div class="col-lg-3 col-md-6 mb-2">
              <label>Member</label>
              <select class="form-control" name="member_id" required>
                <option value="">Select organization member</option>
                <?php foreach ($organizationMemberOptions as $member): ?>
                  <option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars(($member['crn'] ? $member['crn'] . ' - ' : '') . $member['first_name'] . ' ' . $member['last_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
              <label>Destination</label>
              <select class="form-control" name="unit_id" required>
                <option value="">Select group, part, or section</option>
                <?php foreach ($units as $unit): ?>
                  <?php if (!$unit['is_active']) continue; ?>
                  <option value="<?= (int) $unit['id'] ?>"><?= htmlspecialchars(($unitTypeLabels[$unit['unit_type']] ?? 'Unit') . ': ' . $unit['name'] . ($unit['branch'] !== 'mixed' ? ' (' . ucfirst($unit['branch']) . ')' : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-lg-2 col-md-6 mb-2">
              <label>Brigade Rank</label>
              <input class="form-control" name="rank_or_level" maxlength="100" placeholder="Required for section">
            </div>
            <div class="col-lg-3 col-md-6 mb-2">
              <label>Reason</label>
              <input class="form-control" name="reason" maxlength="255" placeholder="Reason for assignment/change">
            </div>
            <div class="col-lg-1 col-md-12 mb-2"><button class="btn btn-primary btn-block">Save</button></div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach ($unitTypeLabels as $type => $label): ?>
      <?php $typeUnits = array_values(array_filter($units, static fn($unit) => $unit['unit_type'] === $type)); ?>
      <?php if (!$typeUnits) continue; ?>
      <div class="card shadow-sm mb-4">
        <div class="card-header"><strong><?= htmlspecialchars($label) ?></strong></div>
        <div class="table-responsive"><table class="table table-bordered mb-0">
          <thead><tr><th>Name</th><th>Logo</th><th>Code</th><th>Branch</th><th>Members</th><th>Leadership</th></tr></thead>
          <tbody>
          <?php foreach ($typeUnits as $unit): $unitId = (int) $unit['id']; ?>
            <tr class="<?= $unit['is_active'] ? '' : 'table-secondary' ?>">
              <td><?= htmlspecialchars($unit['name']) ?><?= $unit['is_active'] ? '' : ' (inactive)' ?></td>
              <td style="min-width: 220px;">
                <?php $unitLogoUrl = organization_logo_url($unit['logo_path'] ?? null); ?>
                <?php if ($unitLogoUrl): ?>
                  <img src="<?= htmlspecialchars($unitLogoUrl) ?>" alt="<?= htmlspecialchars($unit['logo_alt_text'] ?: $unit['name'] . ' logo') ?>" class="img-thumbnail mb-2" style="max-height: 55px; max-width: 110px;">
                <?php else: ?>
                  <span class="text-muted d-block mb-2">No logo</span>
                <?php endif; ?>
                <?php if ($canManageSelected && $unit['is_active']): ?>
                  <form method="post" enctype="multipart/form-data" class="mb-2">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="upload_unit_logo">
                    <input type="hidden" name="org_id" value="<?= $organizationId ?>">
                    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                    <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                    <input type="file" name="logo" class="form-control-file form-control-sm mb-1" accept="image/jpeg,image/png,image/webp" required>
                    <input type="text" name="logo_alt_text" class="form-control form-control-sm mb-1" maxlength="160" value="<?= htmlspecialchars($unit['logo_alt_text'] ?? '') ?>" placeholder="Logo description">
                    <button class="btn btn-sm btn-outline-primary"><?= $unitLogoUrl ? 'Replace' : 'Upload' ?></button>
                  </form>
                  <?php if ($unitLogoUrl): ?>
                    <form method="post" onsubmit="return confirm('Remove this unit logo?');">
                      <?= csrf_input() ?>
                      <input type="hidden" name="action" value="remove_unit_logo">
                      <input type="hidden" name="org_id" value="<?= $organizationId ?>">
                      <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                      <button class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($unit['code']) ?></td>
              <td><?= htmlspecialchars(ucfirst($unit['branch'])) ?></td>
              <td><?= (int) $unit['member_count'] ?></td>
              <td>
                <?php foreach ($leadersByUnit[$unitId] ?? [] as $leader): ?>
                  <div><strong><?= htmlspecialchars(ucfirst($leader['leader_role'])) ?>:</strong> <?= htmlspecialchars(trim($leader['first_name'].' '.$leader['middle_name'].' '.$leader['last_name'])) ?></div>
                <?php endforeach; ?>
                <?php if ($canAssignLeaders && $unit['is_active']): ?>
                  <form method="post" class="form-inline mt-2">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="assign_leader">
                    <input type="hidden" name="org_id" value="<?= $organizationId ?>">
                    <input type="hidden" name="unit_id" value="<?= $unitId ?>">
                    <select class="form-control form-control-sm mr-2" name="member_id" required>
                      <option value="">Select leader</option>
                      <?php foreach ($eligibleMembersByUnit[$unitId] ?? [] as $member): ?>
                        <option value="<?= (int) $member['id'] ?>"><?= htmlspecialchars(($member['crn'] ? $member['crn'] . ' - ' : '') . trim($member['first_name'] . ' ' . $member['last_name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <select class="form-control form-control-sm mr-2" name="leader_role"><option value="leader">Leader</option><option value="assistant">Assistant</option></select>
                    <button class="btn btn-sm btn-outline-primary">Assign</button>
                  </form>
                  <?php if ($type === 'brigade_section'): ?><small class="text-muted">The assigning leader must verify Brigade officer eligibility.</small><?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-4">
      <div class="card-header"><strong>Current Assignment Roster</strong></div>
      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
          <thead><tr><th>CRN</th><th>Member</th><th>Assignment</th><th>Unit</th><th>Rank / Branch</th><th>Effective</th></tr></thead>
          <tbody>
          <?php if (!$currentAssignments): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No members have been assigned yet.</td></tr>
          <?php else: ?>
            <?php foreach ($currentAssignments as $assignment): ?>
              <tr>
                <td><?= htmlspecialchars($assignment['crn'] ?: '-') ?></td>
                <td><?= htmlspecialchars(trim($assignment['first_name'] . ' ' . $assignment['middle_name'] . ' ' . $assignment['last_name'])) ?></td>
                <td><?= htmlspecialchars($assignmentTypeLabels[$assignment['assignment_type']] ?? $assignment['assignment_type']) ?></td>
                <td><?= htmlspecialchars($assignment['unit_name']) ?></td>
                <td><?= htmlspecialchars($assignment['rank_or_level'] ?: ucfirst($assignment['branch'])) ?></td>
                <td><?= htmlspecialchars($assignment['effective_from']) ?><br><small class="text-muted"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $assignment['assignment_method']))) ?></small></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-header"><strong>Recent Assignment History</strong> <span class="text-muted">(latest 50)</span></div>
      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
          <thead><tr><th>Date</th><th>Member</th><th>Type</th><th>Change</th><th>Reason / Rank</th><th>Changed By</th></tr></thead>
          <tbody>
          <?php if (!$assignmentHistory): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No assignment history recorded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($assignmentHistory as $history): ?>
              <tr>
                <td><?= htmlspecialchars($history['created_at']) ?></td>
                <td><?= htmlspecialchars(($history['crn'] ? $history['crn'] . ' - ' : '') . trim(($history['first_name'] ?? '') . ' ' . ($history['middle_name'] ?? '') . ' ' . ($history['last_name'] ?? ''))) ?></td>
                <td><?= htmlspecialchars($assignmentTypeLabels[$history['assignment_type']] ?? $history['assignment_type']) ?></td>
                <td><?= htmlspecialchars(($history['from_unit_name'] ?: 'Unassigned') . ' -> ' . ($history['to_unit_name'] ?: 'Removed')) ?></td>
                <td><?= htmlspecialchars($history['reason'] ?: '-') ?><?php if ($history['rank_or_level']): ?><br><small>Rank: <?= htmlspecialchars($history['rank_or_level']) ?></small><?php endif; ?></td>
                <td><?= htmlspecialchars($history['actor_name'] ?: 'System') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card shadow-sm mb-4">
      <div class="card-header"><strong>Logo Change History</strong> <span class="text-muted">(latest 25)</span></div>
      <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0">
          <thead><tr><th>Date</th><th>Item</th><th>Action</th><th>Image Details</th><th>Changed By</th></tr></thead>
          <tbody>
          <?php if (!$mediaHistory): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">No logo changes recorded yet.</td></tr>
          <?php else: ?>
            <?php foreach ($mediaHistory as $history): ?>
              <tr>
                <td><?= htmlspecialchars($history['created_at']) ?></td>
                <td><?= htmlspecialchars($history['entity_name']) ?><br><small class="text-muted"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $history['entity_type']))) ?></small></td>
                <td><?= htmlspecialchars(ucfirst($history['action'])) ?></td>
                <td>
                  <?php if ($history['new_path']): ?>
                    <?= htmlspecialchars((string) $history['mime_type']) ?>,
                    <?= number_format(((int) $history['file_size']) / 1024, 1) ?> KB,
                    <?= (int) $history['image_width'] ?> x <?= (int) $history['image_height'] ?>
                  <?php else: ?>
                    Removed
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($history['actor_name'] ?: 'System') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
