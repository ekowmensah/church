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

function organization_attendance_url(int $organizationId, array $extra = []): string
{
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
    'present' => 'Present',
    'absent' => 'Absent',
    'sick' => 'Sick',
    'permission' => 'Permission',
    'distance' => 'Distance',
    'invalid' => 'Invalid',
];
$backUrl = isset($_SESSION['member_id']) && !isset($_SESSION['user_id'])
    ? BASE_URL . '/views/member_dashboard.php'
    : BASE_URL . '/index.php';

$sessionSummary = ['total' => count($sessions), 'pending' => 0, 'approved' => 0, 'marked' => 0];
foreach ($sessions as $summarySession) {
    $summaryStatus = strtolower((string) ($summarySession['approval_status'] ?? 'draft'));
    if ($summaryStatus === 'submitted') $sessionSummary['pending']++;
    if ($summaryStatus === 'approved') $sessionSummary['approved']++;
    $sessionSummary['marked'] += (int) ($summarySession['marked_count'] ?? 0);
}

$activeStatus = (string) ($activeSession['approval_status'] ?? 'draft');
$activeStatusCounts = array_fill_keys(array_keys($statusLabels), 0);
foreach ($members as $summaryMember) {
    $summaryMemberId = (int) $summaryMember['id'];
    $summaryMemberStatus = $attendance[$summaryMemberId]['status'] ?? 'absent';
    if (isset($activeStatusCounts[$summaryMemberStatus])) {
        $activeStatusCounts[$summaryMemberStatus]++;
    }
}

ob_start();
?>
<style>
.oa-page{--ink:#17324d;--muted:#6b7c8f;--line:#dfe8ef;--primary:#145c72;--dark:#0c4054;--accent:#2c9b7c;--soft:#f4f8fa;max-width:1280px;margin:0 auto;color:var(--ink)}
.oa-hero{position:relative;overflow:hidden;padding:30px;border-radius:24px;color:#fff;background:linear-gradient(130deg,#0d3b50 0%,#14637a 55%,#278d80 100%);box-shadow:0 18px 45px rgba(17,74,94,.22)}
.oa-hero:before,.oa-hero:after{content:"";position:absolute;border-radius:50%;background:rgba(255,255,255,.08)}
.oa-hero:before{width:260px;height:260px;right:-80px;top:-150px}.oa-hero:after{width:170px;height:170px;right:150px;bottom:-125px}.oa-hero-content{position:relative;z-index:1}
.oa-eyebrow{display:inline-flex;align-items:center;gap:7px;margin-bottom:10px;font-size:.76rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;opacity:.8}.oa-hero h1{margin:0;font-size:clamp(1.65rem,3vw,2.35rem);font-weight:800}.oa-hero p{max-width:680px;margin:8px 0 0;color:rgba(255,255,255,.82)}.oa-hero .btn{border:0;border-radius:12px;padding:10px 16px;font-weight:700}
.oa-panel{border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 8px 28px rgba(35,61,81,.07)}.oa-panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:19px 22px;border-bottom:1px solid var(--line)}.oa-panel-title{margin:0;font-size:1.02rem;font-weight:800;color:var(--ink)}.oa-panel-subtitle{margin-top:3px;color:var(--muted);font-size:.84rem}.oa-panel-body{padding:22px}.oa-context-panel{margin-top:-18px;position:relative;z-index:2}.oa-context-panel .oa-panel-body{padding:18px 22px}
.oa-access-pill,.oa-status-pill{display:inline-flex;align-items:center;gap:7px;border-radius:999px;padding:7px 12px;font-size:.76rem;font-weight:800;white-space:nowrap}.oa-access-pill{color:#0f6a52;background:#e7f7f1}
.oa-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:18px 0}.oa-stat{display:flex;align-items:center;gap:14px;min-height:94px;padding:17px;border:1px solid var(--line);border-radius:17px;background:#fff;box-shadow:0 6px 20px rgba(35,61,81,.05)}.oa-stat-icon{display:grid;place-items:center;width:45px;height:45px;flex:0 0 45px;border-radius:14px;color:var(--primary);background:#eaf5f7;font-size:1.08rem}.oa-stat:nth-child(2) .oa-stat-icon{color:#9a6b00;background:#fff5d9}.oa-stat:nth-child(3) .oa-stat-icon{color:#197055;background:#e6f7f0}.oa-stat:nth-child(4) .oa-stat-icon{color:#5d4db4;background:#f0edff}.oa-stat-value{display:block;font-size:1.55rem;font-weight:850;line-height:1}.oa-stat-label{display:block;margin-top:6px;color:var(--muted);font-size:.8rem;font-weight:700}
.oa-form-label{color:#40566b;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.oa-page .form-control,.oa-page .custom-select{min-height:43px;border-color:#d7e2e9;border-radius:11px;color:var(--ink);box-shadow:none}.oa-page .form-control:focus,.oa-page .custom-select:focus{border-color:#4a9aac;box-shadow:0 0 0 .2rem rgba(37,133,151,.12)}
.oa-create-panel{background:linear-gradient(180deg,#fff,#fbfdfd)}.oa-create-icon{display:grid;place-items:center;width:40px;height:40px;border-radius:12px;color:#fff;background:linear-gradient(135deg,var(--primary),var(--accent))}.oa-primary-btn,.oa-success-btn{border:0;border-radius:11px;padding:11px 17px;color:#fff!important;font-weight:800;box-shadow:0 8px 18px rgba(20,92,114,.16)}.oa-primary-btn{background:linear-gradient(135deg,var(--dark),var(--primary))}.oa-success-btn{background:linear-gradient(135deg,#157052,#2c9b7c)}
.oa-session-date{color:var(--muted);font-size:.84rem}.oa-status-pill{text-transform:capitalize}.oa-status-draft{color:#596a7b;background:#edf1f4}.oa-status-submitted{color:#896100;background:#fff1c7}.oa-status-approved{color:#12694f;background:#dcf5eb}.oa-status-rejected{color:#a4333d;background:#fde7e9}.oa-session-metrics{display:flex;flex-wrap:wrap;gap:9px;margin-bottom:19px}.oa-metric-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);border-radius:999px;padding:7px 11px;background:var(--soft);color:#4f6476;font-size:.78rem;font-weight:750}.oa-metric-chip strong{color:var(--ink)}
.oa-roster-tools{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;padding:13px;border-radius:14px;background:var(--soft)}.oa-search-wrap{position:relative;min-width:240px;flex:1;max-width:390px}.oa-search-wrap i{position:absolute;top:50%;left:14px;transform:translateY(-50%);color:#8192a2}.oa-search-wrap input{padding-left:39px;background:#fff}.oa-bulk-actions{display:flex;flex-wrap:wrap;gap:8px}.oa-bulk-actions .btn{border-radius:9px;font-size:.78rem;font-weight:750}
.oa-roster-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.oa-member-card{display:grid;grid-template-columns:44px minmax(0,1fr) minmax(135px,.7fr);align-items:center;gap:12px;padding:13px;border:1px solid var(--line);border-left:4px solid #91a7b5;border-radius:14px;background:#fff;transition:.18s ease}.oa-member-card:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(35,61,81,.08)}.oa-member-card[data-status="present"]{border-left-color:#2c9b7c}.oa-member-card[data-status="absent"]{border-left-color:#dc5964}.oa-member-card[data-status="sick"]{border-left-color:#d48a2d}.oa-member-card[data-status="permission"]{border-left-color:#6f65c8}.oa-member-avatar{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;color:#fff;background:linear-gradient(135deg,#17677e,#32a286);font-size:.78rem;font-weight:850}.oa-member-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:800}.oa-member-ref{margin-top:3px;color:var(--muted);font-size:.76rem}.oa-member-card .custom-select{min-height:39px;font-size:.84rem;font-weight:650}
.oa-review-box{margin-top:22px;padding:19px;border:1px solid #d5e9e1;border-radius:15px;background:#f2fbf7}.oa-review-actions{display:flex;flex-wrap:wrap;gap:9px}.oa-review-actions .btn{border-radius:10px;font-weight:800}.oa-table thead th{border-top:0;border-bottom:1px solid var(--line);color:#657789;background:#f7fafb;font-size:.73rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase}.oa-table td{vertical-align:middle;border-color:#edf1f4}.oa-date-tile{display:inline-flex;flex-direction:column;align-items:center;min-width:50px;padding:6px 8px;border-radius:10px;color:var(--primary);background:#eaf5f7;font-weight:850;line-height:1.05}.oa-date-tile small{margin-top:4px;font-size:.62rem;text-transform:uppercase}.oa-session-link{color:var(--ink);font-weight:800}.oa-session-link:hover{color:var(--primary);text-decoration:none}
.oa-empty{padding:45px 20px;text-align:center;color:var(--muted)}.oa-empty-icon{display:grid;place-items:center;width:58px;height:58px;margin:0 auto 13px;border-radius:18px;color:var(--primary);background:#eaf5f7;font-size:1.35rem}.oa-page .alert{border:0;border-radius:13px;box-shadow:0 5px 15px rgba(35,61,81,.05)}
@media(max-width:991px){.oa-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.oa-roster-grid{grid-template-columns:1fr}}
@media(max-width:767px){.oa-hero{padding:23px 19px 38px;border-radius:18px}.oa-context-panel{margin-left:8px;margin-right:8px}.oa-panel-header,.oa-panel-body{padding:17px}.oa-context-access{margin-top:12px}.oa-member-card{grid-template-columns:40px minmax(0,1fr)}.oa-member-status{grid-column:1/-1}.oa-search-wrap{min-width:100%;max-width:none}.oa-table thead{display:none}.oa-table,.oa-table tbody,.oa-table tr,.oa-table td{display:block;width:100%}.oa-table tr{position:relative;padding:14px 65px 14px 15px;border-bottom:1px solid var(--line)}.oa-table td{padding:3px 0;border:0}.oa-table td:last-child{position:absolute;right:14px;top:50%;transform:translateY(-50%)}.oa-mobile-muted{color:var(--muted);font-size:.78rem}}
@media(max-width:520px){.oa-stats{grid-template-columns:1fr}.oa-stat{min-height:78px}.oa-hero-actions{width:100%;margin-top:16px}.oa-hero-actions .btn{width:100%}}
</style>

<div class="container-fluid py-4">
  <div class="oa-page">
    <section class="oa-hero">
      <div class="oa-hero-content d-flex flex-wrap justify-content-between align-items-center">
        <div>
          <div class="oa-eyebrow"><i class="fas fa-users"></i> Organization workspace</div>
          <h1>Attendance Management</h1>
          <p>Open a session, mark the roster, and complete the leader-review workflow from one focused workspace.</p>
        </div>
        <div class="oa-hero-actions">
          <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-light"><i class="fas fa-arrow-left mr-2"></i>Back to dashboard</a>
        </div>
      </div>
    </section>

    <section class="oa-panel oa-context-panel mb-3">
      <div class="oa-panel-body">
        <form method="get" class="row align-items-end">
          <div class="col-lg-7 col-md-7">
            <label class="oa-form-label" for="org_id">Working organization</label>
            <select id="org_id" name="org_id" class="custom-select" onchange="this.form.submit()">
              <?php foreach ($organizations as $organization): ?>
                <option value="<?= (int) $organization['id'] ?>" <?= $selectedOrganizationId === (int) $organization['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($organization['name']) ?> — <?= $organization['can_review'] ? 'Organization leader' : 'Group leader' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-lg-5 col-md-5 text-md-right oa-context-access">
            <span class="oa-access-pill"><i class="fas <?= $selectedOrganization['can_review'] ? 'fa-shield-alt' : 'fa-user-check' ?>"></i><?= $selectedOrganization['can_review'] ? 'Organization-wide review access' : 'Assigned group access' ?></span>
          </div>
        </form>
      </div>
    </section>

    <?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <section class="oa-stats" aria-label="Attendance summary">
      <div class="oa-stat"><span class="oa-stat-icon"><i class="fas fa-calendar-alt"></i></span><span><strong class="oa-stat-value"><?= $sessionSummary['total'] ?></strong><span class="oa-stat-label">Accessible sessions</span></span></div>
      <div class="oa-stat"><span class="oa-stat-icon"><i class="fas fa-hourglass-half"></i></span><span><strong class="oa-stat-value"><?= $sessionSummary['pending'] ?></strong><span class="oa-stat-label">Awaiting review</span></span></div>
      <div class="oa-stat"><span class="oa-stat-icon"><i class="fas fa-check-double"></i></span><span><strong class="oa-stat-value"><?= $sessionSummary['approved'] ?></strong><span class="oa-stat-label">Approved sessions</span></span></div>
      <div class="oa-stat"><span class="oa-stat-icon"><i class="fas fa-clipboard-list"></i></span><span><strong class="oa-stat-value"><?= $sessionSummary['marked'] ?></strong><span class="oa-stat-label">Recorded entries</span></span></div>
    </section>

    <?php if ($selectedOrganization['can_review']): ?>
      <section class="oa-panel oa-create-panel mb-4">
        <div class="oa-panel-header">
          <div class="d-flex align-items-center">
            <span class="oa-create-icon mr-3"><i class="fas fa-calendar-plus"></i></span>
            <div><h2 class="oa-panel-title">Create attendance session</h2><div class="oa-panel-subtitle">Start an organization-wide meeting or target one group/unit.</div></div>
          </div>
        </div>
        <div class="oa-panel-body">
          <form method="post" class="row align-items-end">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="create_session"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>">
            <div class="col-lg-4 col-md-6 form-group mb-lg-0"><label class="oa-form-label" for="title">Session title</label><input id="title" name="title" class="form-control" maxlength="255" required placeholder="e.g. Weekly fellowship meeting"></div>
            <div class="col-lg-3 col-md-6 form-group mb-lg-0"><label class="oa-form-label" for="service_date">Meeting date</label><input id="service_date" name="service_date" type="date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            <div class="col-lg-3 col-md-7 form-group mb-md-0"><label class="oa-form-label" for="organization_unit_id">Attendance scope</label><select id="organization_unit_id" name="organization_unit_id" class="custom-select"><option value="">Whole organization</option><?php foreach ($allUnits as $unit): ?><option value="<?= (int) $unit['id'] ?>"><?= htmlspecialchars($unit['name']) ?><?= !empty($unit['branch']) && $unit['branch'] !== 'mixed' ? ' (' . htmlspecialchars(ucfirst($unit['branch'])) . ')' : '' ?></option><?php endforeach; ?></select></div>
            <div class="col-lg-2 col-md-5 form-group mb-0"><button type="submit" class="btn oa-primary-btn btn-block"><i class="fas fa-plus mr-2"></i>Create</button></div>
          </form>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($activeSession): ?>
      <section class="oa-panel mb-4" id="active-session">
        <div class="oa-panel-header align-items-start">
          <div>
            <div class="oa-eyebrow text-muted mb-2"><i class="fas fa-clipboard-check"></i> Active session</div>
            <h2 class="oa-panel-title mb-1"><?= htmlspecialchars($activeSession['title']) ?></h2>
            <div class="oa-session-date"><i class="far fa-calendar mr-1"></i><?= htmlspecialchars(date('l, d F Y', strtotime((string) $activeSession['service_date']))) ?><span class="mx-2">•</span><i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($activeSession['unit_name'] ?: 'Whole organization') ?></div>
          </div>
          <span class="oa-status-pill oa-status-<?= htmlspecialchars($activeStatus) ?>"><i class="fas <?= $activeStatus === 'approved' ? 'fa-check-circle' : ($activeStatus === 'rejected' ? 'fa-times-circle' : ($activeStatus === 'submitted' ? 'fa-clock' : 'fa-pencil-alt')) ?>"></i><?= htmlspecialchars($activeStatus) ?></span>
        </div>
        <div class="oa-panel-body">
          <?php if ($members): ?>
            <div class="oa-session-metrics">
              <span class="oa-metric-chip"><i class="fas fa-users"></i><strong><?= count($members) ?></strong> roster</span>
              <?php foreach ($statusLabels as $statusValue => $statusLabel): ?><span class="oa-metric-chip" data-status-count="<?= htmlspecialchars($statusValue) ?>"><strong><?= $activeStatusCounts[$statusValue] ?></strong> <?= htmlspecialchars($statusLabel) ?></span><?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($canMark): ?>
            <?php if (!$members): ?>
              <div class="oa-empty"><span class="oa-empty-icon"><i class="fas fa-user-slash"></i></span><h3 class="h6 font-weight-bold">No members in this attendance scope</h3><p class="mb-0">Assign active members to this organization or group before marking attendance.</p></div>
            <?php elseif (!$canReview && in_array($activeStatus, ['submitted', 'approved'], true)): ?>
              <div class="alert alert-info mb-0"><i class="fas fa-lock mr-2"></i>This submission is locked while the organization leader completes its review.</div>
            <?php else: ?>
              <form method="post" id="attendanceRosterForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="submit_attendance"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <div class="oa-roster-tools">
                  <div class="oa-search-wrap"><i class="fas fa-search"></i><input type="search" class="form-control" id="rosterSearch" placeholder="Search name or CRN" aria-label="Search attendance roster"></div>
                  <div class="oa-bulk-actions" aria-label="Bulk attendance controls"><button type="button" class="btn btn-outline-success" data-set-attendance="present"><i class="fas fa-user-check mr-1"></i>All present</button><button type="button" class="btn btn-outline-danger" data-set-attendance="absent"><i class="fas fa-user-times mr-1"></i>All absent</button></div>
                </div>
                <div class="oa-roster-grid" id="attendanceRoster">
                  <?php foreach ($members as $member):
                    $memberId = (int) $member['id'];
                    $currentStatus = $attendance[$memberId]['status'] ?? 'absent';
                    $fullName = trim(implode(' ', array_filter([$member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? ''])));
                    $initials = strtoupper(substr((string) ($member['first_name'] ?? ''), 0, 1) . substr((string) ($member['last_name'] ?? ''), 0, 1));
                    $memberReference = trim((string) ($member['crn'] ?? ''));
                  ?>
                    <article class="oa-member-card" data-member-row data-status="<?= htmlspecialchars($currentStatus) ?>" data-search="<?= htmlspecialchars(strtolower($fullName . ' ' . $memberReference)) ?>">
                      <span class="oa-member-avatar" aria-hidden="true"><?= htmlspecialchars($initials ?: 'M') ?></span>
                      <div><div class="oa-member-name" title="<?= htmlspecialchars($fullName) ?>"><?= htmlspecialchars($fullName) ?></div><div class="oa-member-ref"><i class="far fa-id-card mr-1"></i><?= htmlspecialchars($memberReference !== '' ? $memberReference : 'No CRN') ?></div></div>
                      <div class="oa-member-status"><label class="sr-only" for="attendance_<?= $memberId ?>">Attendance for <?= htmlspecialchars($fullName) ?></label><select id="attendance_<?= $memberId ?>" class="custom-select" name="attendance[<?= $memberId ?>]" data-attendance-select><?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                    </article>
                  <?php endforeach; ?>
                </div>
                <div id="rosterEmptySearch" class="oa-empty py-4 d-none"><span class="oa-empty-icon"><i class="fas fa-search"></i></span><p class="mb-0">No roster member matches that search.</p></div>
                <div class="d-flex flex-wrap justify-content-between align-items-center mt-4"><span class="small text-muted mb-2 mb-md-0"><i class="fas fa-info-circle mr-1"></i>Submitting sends this session into the leader-review workflow.</span><button type="submit" class="btn oa-success-btn" onclick="return confirm('Submit this attendance for organization review?')"><i class="fas fa-paper-plane mr-2"></i>Submit attendance</button></div>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <div class="alert alert-info mb-0"><i class="fas fa-eye mr-2"></i>You have read-only access to this session.</div>
          <?php endif; ?>

          <?php if ($canReview && $activeStatus === 'submitted'): ?>
            <div class="oa-review-box">
              <div class="d-flex align-items-start mb-3"><span class="oa-create-icon mr-3"><i class="fas fa-user-shield"></i></span><div><h3 class="oa-panel-title">Leader review</h3><div class="oa-panel-subtitle">Approve this submission or return it with a clear correction note.</div></div></div>
              <form method="post">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="review_attendance"><input type="hidden" name="org_id" value="<?= $selectedOrganizationId ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
                <div class="form-group"><label class="oa-form-label" for="review_notes">Review note <span class="text-muted text-lowercase">(required when rejecting)</span></label><textarea id="review_notes" name="review_notes" class="form-control" maxlength="500" rows="3" placeholder="Add a concise note for the submitting leader"></textarea></div>
                <div class="oa-review-actions"><button type="submit" name="decision" value="approved" class="btn btn-success"><i class="fas fa-check mr-2"></i>Approve submission</button><button type="submit" name="decision" value="rejected" class="btn btn-outline-danger" onclick="return confirm('Reject and return this attendance for correction?')"><i class="fas fa-undo mr-2"></i>Return for correction</button></div>
              </form>
            </div>
          <?php elseif ($activeStatus === 'rejected' && !empty($activeSession['review_notes'])): ?>
            <div class="alert alert-danger mt-4 mb-0"><i class="fas fa-exclamation-triangle mr-2"></i><strong>Correction requested:</strong> <?= htmlspecialchars($activeSession['review_notes']) ?></div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <section class="oa-panel">
      <div class="oa-panel-header"><div><h2 class="oa-panel-title">Attendance sessions</h2><div class="oa-panel-subtitle">All sessions currently available within your assigned scope.</div></div><span class="oa-access-pill"><i class="fas fa-filter"></i><?= count($sessions) ?> shown</span></div>
      <?php if ($sessions): ?>
        <div class="table-responsive">
          <table class="table oa-table table-hover mb-0">
            <thead><tr><th>Date</th><th>Session</th><th>Scope</th><th>Status</th><th>Records</th><th><span class="sr-only">Action</span></th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $session):
                $workflowStatus = $session['approval_status'] ?? 'draft';
                $sessionTimestamp = strtotime((string) $session['service_date']);
                $isCurrentSession = $sessionId === (int) $session['id'];
              ?>
                <tr>
                  <td><span class="oa-date-tile"><?= htmlspecialchars(date('d', $sessionTimestamp)) ?><small><?= htmlspecialchars(date('M', $sessionTimestamp)) ?></small></span></td>
                  <td><a class="oa-session-link" href="<?= htmlspecialchars(organization_attendance_url($selectedOrganizationId, ['session_id' => (int) $session['id']])) ?>"><?= htmlspecialchars($session['title']) ?></a></td>
                  <td class="oa-mobile-muted"><?= htmlspecialchars($session['unit_name'] ?: 'Whole organization') ?></td>
                  <td><span class="oa-status-pill oa-status-<?= htmlspecialchars($workflowStatus) ?>"><?= htmlspecialchars($workflowStatus) ?></span></td>
                  <td class="oa-mobile-muted"><i class="fas fa-user-check mr-1"></i><?= (int) $session['marked_count'] ?></td>
                  <td><a class="btn btn-sm <?= $isCurrentSession ? 'btn-success' : 'btn-outline-primary' ?>" href="<?= htmlspecialchars(organization_attendance_url($selectedOrganizationId, ['session_id' => (int) $session['id']])) ?>" aria-label="Open <?= htmlspecialchars($session['title']) ?>"><?= $isCurrentSession ? '<i class="fas fa-check"></i>' : '<i class="fas fa-chevron-right"></i>' ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="oa-empty"><span class="oa-empty-icon"><i class="far fa-calendar"></i></span><h3 class="h6 font-weight-bold">No attendance sessions yet</h3><p class="mb-0"><?= $selectedOrganization['can_review'] ? 'Create the first session above to begin attendance.' : 'An organization leader must create a session before you can mark attendance.' ?></p></div>
      <?php endif; ?>
    </section>
  </div>
</div>
<script>
(function(){
  const roster=document.getElementById('attendanceRoster');
  if(!roster)return;
  const rows=Array.from(roster.querySelectorAll('[data-member-row]'));
  const search=document.getElementById('rosterSearch');
  const empty=document.getElementById('rosterEmptySearch');
  function updateCounts(){
    const counts={};
    rows.forEach(function(row){const select=row.querySelector('[data-attendance-select]');if(!select)return;row.dataset.status=select.value;counts[select.value]=(counts[select.value]||0)+1});
    document.querySelectorAll('[data-status-count]').forEach(function(chip){const node=chip.querySelector('strong');if(node)node.textContent=String(counts[chip.getAttribute('data-status-count')]||0)});
  }
  roster.addEventListener('change',function(event){if(event.target.matches('[data-attendance-select]'))updateCounts()});
  document.querySelectorAll('[data-set-attendance]').forEach(function(button){button.addEventListener('click',function(){const status=button.getAttribute('data-set-attendance');rows.forEach(function(row){if(row.classList.contains('d-none'))return;const select=row.querySelector('[data-attendance-select]');if(select)select.value=status});updateCounts()})});
  if(search)search.addEventListener('input',function(){const term=search.value.trim().toLowerCase();let visible=0;rows.forEach(function(row){const matches=term===''||(row.dataset.search||'').indexOf(term)!==-1;row.classList.toggle('d-none',!matches);if(matches)visible++});if(empty)empty.classList.toggle('d-none',visible!==0)});
  updateCounts();
})();
</script>
<?php
$page_content = ob_get_clean();
$page_title = 'Organization Attendance - ' . $selectedOrganization['name'];
include __DIR__ . '/../includes/layout.php';
