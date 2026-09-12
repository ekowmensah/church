<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/AttendanceScopeService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$attendanceService = AttendanceScopeService::fromSession($conn);
$contexts = $attendanceService->getOrganizationContexts();
if (!$contexts) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$organizations = [];
foreach ($contexts as $context) {
    $organizationId = (int) $context['organization_id'];
    if (!isset($organizations[$organizationId])) {
        $organizations[$organizationId] = [
            'id' => $organizationId,
            'name' => $context['organization_name'],
            'church_id' => (int) $context['church_id'],
            'can_review' => false,
            'units' => [],
        ];
    }
    if (!empty($context['can_review'])) {
        $organizations[$organizationId]['can_review'] = true;
    }
    if (!empty($context['unit_id'])) {
        $organizations[$organizationId]['units'][(int) $context['unit_id']] = [
            'id' => (int) $context['unit_id'],
            'name' => $context['unit_name'],
            'leader_role' => $context['leader_role'],
        ];
    }
}
uasort($organizations, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$selectedOrganizationId = (int) ($_REQUEST['org_id'] ?? array_key_first($organizations));
if (!isset($organizations[$selectedOrganizationId])) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You cannot access attendance for that organization.</div>';
    exit;
}
$selectedOrganization = $organizations[$selectedOrganizationId];
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));

function organization_attendance_url(int $organizationId, array $extra = []): string {
    return 'my_organization_attendance.php?' . http_build_query(array_merge(['org_id' => $organizationId], $extra));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        try {
            if ($action === 'create_session') {
                $unitId = (int) ($_POST['organization_unit_id'] ?? 0);
                $sessionId = $attendanceService->createOrganizationSession(
                    $selectedOrganizationId,
                    $unitId > 0 ? $unitId : null,
                    (string) ($_POST['title'] ?? ''),
                    (string) ($_POST['service_date'] ?? '')
                );
                header('Location: ' . organization_attendance_url($selectedOrganizationId, [
                    'session_id' => $sessionId,
                    'message' => 'Attendance session created.',
                ]));
                exit;
            }

            $sessionId = (int) ($_POST['session_id'] ?? 0);
            if ($sessionId < 1) {
                throw new RuntimeException('Select a valid attendance session.');
            }
            $targetSession = $attendanceService->getSession($sessionId);
            if ((int) ($targetSession['scope_id'] ?? 0) !== $selectedOrganizationId) {
                throw new RuntimeException('That session does not belong to this organization.');
            }

            if ($action === 'submit_attendance') {
                $savedCount = $attendanceService->submitAttendance(
                    $sessionId,
                    is_array($_POST['attendance'] ?? null) ? $_POST['attendance'] : []
                );
                header('Location: ' . organization_attendance_url($selectedOrganizationId, [
                    'session_id' => $sessionId,
                    'message' => "Attendance submitted for {$savedCount} members.",
                ]));
                exit;
            }

            if ($action === 'review_attendance') {
                $attendanceService->reviewAttendance(
                    $sessionId,
                    (string) ($_POST['decision'] ?? ''),
                    (string) ($_POST['review_notes'] ?? '')
                );
                header('Location: ' . organization_attendance_url($selectedOrganizationId, [
                    'session_id' => $sessionId,
                    'message' => 'Attendance review recorded.',
                ]));
                exit;
            }

            throw new RuntimeException('Unsupported attendance action.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$sessions = $attendanceService->listOrganizationSessions($selectedOrganizationId);
$allUnits = $selectedOrganization['can_review']
    ? $attendanceService->getOrganizationUnits($selectedOrganizationId)
    : array_values($selectedOrganization['units']);

$sessionId = (int) ($_GET['session_id'] ?? 0);
$activeSession = null;
$members = [];
$attendance = [];
$canMark = false;
$canReview = false;
if ($sessionId > 0) {
    try {
        $candidate = $attendanceService->getSession($sessionId);
        if ((int) ($candidate['scope_id'] ?? 0) !== $selectedOrganizationId
            || !$attendanceService->canView($candidate)) {
            throw new RuntimeException('You cannot access that attendance session.');
        }
        $activeSession = $candidate;
        $canMark = $attendanceService->canMark($candidate);
        $canReview = $attendanceService->canReview($candidate);
        $members = $attendanceService->getEligibleMembers($candidate);
        $attendance = $attendanceService->getAttendanceMap($sessionId, array_column($members, 'id'));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $sessionId = 0;
    }
}

$statusLabels = [
    'present' => 'Present', 'absent' => 'Absent', 'sick' => 'Sick',
    'permission' => 'Permission', 'distance' => 'Distance', 'invalid' => 'Invalid',
];
$badgeClasses = ['draft' => 'secondary', 'submitted' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$backUrl = isset($_SESSION['member_id']) && !isset($_SESSION['user_id'])
    ? BASE_URL . '/views/member_dashboard.php' : BASE_URL . '/index.php';

ob_start();
?>
<style>
.org-attendance-shell { max-width: 1180px; margin: 0 auto; }
.org-attendance-hero { background: linear-gradient(135deg, #173f5f, #20639b); color: #fff; border-radius: 18px; padding: 24px; box-shadow: 0 12px 28px rgba(23,63,95,.18); }
.org-attendance-card { border: 1px solid #dde6ef; border-radius: 14px; box-shadow: 0 5px 16px rgba(18,52,77,.06); }
.member-attendance-row { border: 1px solid #e4ebf2; border-radius: 10px; padding: 12px; margin-bottom: 10px; background: #fff; }
.member-attendance-row .member-name { font-weight: 700; color: #173f5f; }
.workflow-status { text-transform: capitalize; }
@media (max-width: 767px) { .org-attendance-hero { padding: 18px; } .table-responsive { font-size: .9rem; } }
</style>

<div class="container-fluid py-4">
  <div class="org-attendance-shell">
    <div class="org-attendance-hero mb-4 d-flex flex-wrap justify-content-between align-items-center">
      <div>
        <h2 class="mb-1"><i class="fas fa-clipboard-check mr-2"></i>Organization Attendance</h2>
        <p class="mb-0">Scoped marking, submission, and organization-leader approval.</p>
      </div>
      <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-light mt-2 mt-md-0"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card org-attendance-card mb-4"><div class="card-body">
      <form method="get" class="form-row align-items-end">
        <div class="col-md-6 form-group mb-md-0">
          <label for="org_id">Organization</label>
          <select id="org_id" name="org_id" class="form-control" onchange="this.form.submit()">
            <?php foreach ($organizations as $organization): ?>
              <option value="<?= (int) $organization['id'] ?>" <?= $selectedOrganizationId === (int) $organization['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($organization['name']) ?><?= $organization['can_review'] ? ' — organization leader' : ' — group leader' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 text-md-right"><span class="badge badge-info p-2"><?= $selectedOrganization['can_review'] ? 'Organization-wide review access' : 'Assigned group access only' ?></span></div>
      </form>
    </div></div>

    <?php if ($selectedOrganization['can_review']): ?>
    <div class="card org-attendance-card mb-4">
      <div class="card-header bg-white"><strong>Create an organization or group session</strong></div>
      <div class="card-body"><form method="post" class="form-row align-items-end">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create_session"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>">
        <div class="col-md-4 form-group"><label for="title">Title</label><input id="title" name="title" class="form-control" maxlength="255" required placeholder="Weekly group meeting"></div>
        <div class="col-md-3 form-group"><label for="service_date">Date</label><input id="service_date" name="service_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
        <div class="col-md-3 form-group"><label for="organization_unit_id">Group / unit</label><select id="organization_unit_id" name="organization_unit_id" class="form-control">
          <option value="">Whole organization</option>
          <?php foreach ($allUnits as $unit): ?><option value="<?= (int) $unit['id'] ?>"><?= htmlspecialchars($unit['name']) ?><?= !empty($unit['branch']) && $unit['branch'] !== 'mixed' ? ' (' . htmlspecialchars(ucfirst($unit['branch'])) . ')' : '' ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-2 form-group"><button class="btn btn-primary btn-block"><i class="fas fa-plus mr-1"></i>Create</button></div>
      </form></div>
    </div>
    <?php endif; ?>

    <?php if ($activeSession): ?>
    <div class="card org-attendance-card mb-4">
      <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center">
        <div><strong><?= htmlspecialchars($activeSession['title']) ?></strong><div class="small text-muted"><?= htmlspecialchars((string) $activeSession['service_date']) ?> · <?= htmlspecialchars($activeSession['unit_name'] ?: 'Whole organization') ?></div></div>
        <?php $activeStatus = $activeSession['approval_status'] ?? 'draft'; ?><span class="badge badge-<?= $badgeClasses[$activeStatus] ?? 'secondary' ?> p-2 workflow-status"><?= htmlspecialchars($activeStatus) ?></span>
      </div>
      <div class="card-body">
        <?php if ($canMark): ?>
          <?php if (!$members): ?><div class="alert alert-warning mb-0">No active members are assigned to this scope. Assign members to the group before marking attendance.</div>
          <?php elseif (!$canReview && in_array($activeStatus, ['submitted', 'approved'], true)): ?><div class="alert alert-info mb-0">This submission is locked while the organization leader reviews it.</div>
          <?php else: ?>
            <form method="post">
              <?= csrf_input() ?><input type="hidden" name="action" value="submit_attendance"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
              <div class="row">
                <?php foreach ($members as $member): $memberId = (int) $member['id']; $currentStatus = $attendance[$memberId]['status'] ?? 'absent'; ?>
                  <div class="col-lg-6"><div class="member-attendance-row">
                    <div class="member-name"><?= htmlspecialchars(trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name'])) ?></div>
                    <div class="small text-muted mb-2"><?= htmlspecialchars($member['crn'] ?: 'No CRN') ?></div>
                    <select class="form-control" name="attendance[<?= $memberId ?>]">
                      <?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select>
                  </div></div>
                <?php endforeach; ?>
              </div>
              <button class="btn btn-success" onclick="return confirm('Submit this attendance for organization review?')"><i class="fas fa-paper-plane mr-1"></i>Submit Attendance</button>
            </form>
          <?php endif; ?>
        <?php else: ?><div class="alert alert-info">You have read-only access to this session.</div><?php endif; ?>

        <?php if ($canReview && $activeStatus === 'submitted'): ?>
          <hr><form method="post">
            <?= csrf_input() ?><input type="hidden" name="action" value="review_attendance"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
            <div class="form-group"><label for="review_notes">Review note <small class="text-muted">(required when rejecting)</small></label><textarea id="review_notes" name="review_notes" class="form-control" maxlength="500" rows="2"></textarea></div>
            <button name="decision" value="approved" class="btn btn-success mr-2"><i class="fas fa-check mr-1"></i>Approve</button>
            <button name="decision" value="rejected" class="btn btn-danger" onclick="return confirm('Reject and return this attendance for correction?')"><i class="fas fa-times mr-1"></i>Reject</button>
          </form>
        <?php elseif ($activeStatus === 'rejected' && !empty($activeSession['review_notes'])): ?><div class="alert alert-danger mt-3 mb-0"><strong>Correction requested:</strong> <?= htmlspecialchars($activeSession['review_notes']) ?></div><?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="card org-attendance-card">
      <div class="card-header bg-white"><strong>Accessible sessions</strong></div>
      <div class="table-responsive"><table class="table table-hover mb-0">
        <thead class="thead-light"><tr><th>Date</th><th>Session</th><th>Scope</th><th>Status</th><th>Records</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $session): $workflowStatus = $session['approval_status'] ?? 'draft'; ?>
          <tr><td><?= htmlspecialchars((string) $session['service_date']) ?></td><td><?= htmlspecialchars($session['title']) ?></td><td><?= htmlspecialchars($session['unit_name'] ?: 'Whole organization') ?></td><td><span class="badge badge-<?= $badgeClasses[$workflowStatus] ?? 'secondary' ?> workflow-status"><?= htmlspecialchars($workflowStatus) ?></span></td><td><?= (int) $session['marked_count'] ?></td><td><a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(organization_attendance_url($selectedOrganizationId, ['session_id' => (int) $session['id']])) ?>">Open</a></td></tr>
        <?php endforeach; ?>
        <?php if (!$sessions): ?><tr><td colspan="6" class="text-center text-muted py-4">No scoped attendance sessions yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<?php
$page_content = ob_get_clean();
$page_title = 'Organization Attendance - ' . $selectedOrganization['name'];
include __DIR__ . '/../includes/layout.php';
