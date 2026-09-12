<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$scheduleService = BibleClassAttendanceScheduleService::fromSession($conn);
$accessibleClasses = $scheduleService->getLeaderClasses();
if (!$accessibleClasses) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$classContexts = [];
foreach ($accessibleClasses as $context) {
    $classContexts[(int) $context['class_id']] = $context;
}
$selectedClassId = (int) ($_REQUEST['class_id'] ?? array_key_first($classContexts));
if (!isset($classContexts[$selectedClassId]) || !$scheduleService->canAccessClass($selectedClassId)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You cannot access attendance for that Bible class.</div>';
    exit;
}

$selectedClass = $scheduleService->getClass($selectedClassId);
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));
$today = date('Y-m-d');
$todayDay = (int) date('w');
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$meetingDay = $selectedClass['meeting_day'] === null ? null : (int) $selectedClass['meeting_day'];
$todaySessionId = 0;

if ($meetingDay !== null && $meetingDay === $todayDay) {
    try {
        $todaySession = $scheduleService->ensureForClassDate($selectedClassId, $today, 'dashboard');
        $todaySessionId = (int) $todaySession['session_id'];
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

function bible_class_attendance_url(int $classId, array $extra = []): string {
    return 'my_bible_class_attendance.php?' . http_build_query(array_merge(['class_id' => $classId], $extra));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $sessionId = (int) ($_POST['session_id'] ?? 0);
            if ($sessionId < 1) {
                throw new RuntimeException('Select a valid attendance session.');
            }
            $savedCount = $scheduleService->submitAttendance(
                $selectedClassId,
                $sessionId,
                is_array($_POST['attendance'] ?? null) ? $_POST['attendance'] : []
            );
            header('Location: ' . bible_class_attendance_url($selectedClassId, [
                'session_id' => $sessionId,
                'message' => "Attendance saved for {$savedCount} members.",
            ]));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$sessions = $scheduleService->getClassSessions($selectedClassId);
$sessionId = (int) ($_GET['session_id'] ?? 0);
$activeSession = null;
$members = [];
$attendance = [];
if ($sessionId > 0) {
    try {
        $activeSession = $scheduleService->getClassSession($selectedClassId, $sessionId);
        $members = $scheduleService->getActiveMembers($selectedClassId);
        $attendance = $scheduleService->getAttendanceMap($selectedClassId, $sessionId, array_column($members, 'id'));
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
        $sessionId = 0;
    }
}

$statusLabels = [
    'present' => 'Present', 'absent' => 'Absent', 'sick' => 'Sick',
    'permission' => 'Permission', 'distance' => 'Distance', 'invalid' => 'Invalid',
];
$backUrl = isset($_SESSION['member_id']) && !isset($_SESSION['user_id'])
    ? BASE_URL . '/views/member_dashboard.php' : BASE_URL . '/views/my_bible_class_leader.php';

ob_start();
?>
<style>
.class-attendance-shell { max-width: 1120px; margin: 0 auto; }
.class-attendance-hero { background: linear-gradient(135deg, #234e70, #2a7f9e); color: #fff; border-radius: 18px; padding: 24px; box-shadow: 0 12px 28px rgba(35,78,112,.18); }
.class-attendance-card { border: 1px solid #dce7ee; border-radius: 14px; box-shadow: 0 5px 16px rgba(18,52,77,.06); }
.member-row { border: 1px solid #e4ebf2; border-radius: 10px; padding: 12px; margin-bottom: 10px; }
.member-name { font-weight: 700; color: #234e70; }
</style>
<div class="container-fluid py-4"><div class="class-attendance-shell">
    <div class="class-attendance-hero mb-4 d-flex flex-wrap justify-content-between align-items-center">
        <div><h2 class="mb-1"><i class="fas fa-clipboard-check mr-2"></i>Bible Class Attendance</h2><p class="mb-0"><?= htmlspecialchars($selectedClass['name']) ?> · <?= htmlspecialchars($selectedClass['group_name']) ?></p></div>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-light mt-2 mt-md-0"><i class="fas fa-arrow-left mr-1"></i> Back</a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($meetingDay === null): ?>
        <div class="alert alert-warning"><strong>Schedule incomplete:</strong> an administrator must set a meeting day for the <?= htmlspecialchars($selectedClass['group_name']) ?> group before weekly sessions can be generated.</div>
    <?php elseif ($todaySessionId > 0): ?>
        <div class="alert alert-info d-flex justify-content-between align-items-center"><span>Today is this group’s meeting day. Attendance is ready.</span><a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(bible_class_attendance_url($selectedClassId, ['session_id' => $todaySessionId])) ?>">Open today</a></div>
    <?php endif; ?>

    <div class="card class-attendance-card mb-4"><div class="card-body">
        <form method="get" class="form-row align-items-end">
            <div class="col-md-6 form-group mb-md-0"><label for="class_id">Bible class</label><select id="class_id" name="class_id" class="form-control" onchange="this.form.submit()">
                <?php foreach ($classContexts as $context): ?>
                    <option value="<?= (int) $context['class_id'] ?>" <?= $selectedClassId === (int) $context['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($context['class_name'] . ' — ' . $context['group_name']) ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="col-md-6 text-md-right"><span class="badge badge-info p-2"><?= $meetingDay === null ? 'Meeting day not configured' : htmlspecialchars($dayNames[$meetingDay] . ' meetings') ?></span></div>
        </form>
    </div></div>

    <?php if ($activeSession): ?>
        <div class="card class-attendance-card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center"><div><strong><?= htmlspecialchars($activeSession['title']) ?></strong><div class="small text-muted"><?= htmlspecialchars((string) $activeSession['service_date']) ?></div></div><span class="badge badge-<?= ($activeSession['approval_status'] ?? '') === 'approved' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst((string) ($activeSession['approval_status'] ?? 'draft'))) ?></span></div>
            <div class="card-body">
                <?php if (!$members): ?><div class="alert alert-warning mb-0">No active members are assigned to this class.</div>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_input() ?><input type="hidden" name="class_id" value="<?= $selectedClassId ?>"><input type="hidden" name="session_id" value="<?= $sessionId ?>">
                        <div class="row">
                            <?php foreach ($members as $member): $memberId = (int) $member['id']; $currentStatus = $attendance[$memberId] ?? 'absent'; ?>
                                <div class="col-lg-6"><div class="member-row"><div class="member-name"><?= htmlspecialchars(trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name'])) ?></div><div class="small text-muted mb-2"><?= htmlspecialchars($member['crn'] ?: 'No CRN') ?></div><select class="form-control" name="attendance[<?= $memberId ?>]">
                                    <?php foreach ($statusLabels as $value => $label): ?><option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                                </select></div></div>
                            <?php endforeach; ?>
                        </div>
                        <button class="btn btn-success" onclick="return confirm('Save attendance for this class and date?')"><i class="fas fa-save mr-1"></i>Save Attendance</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card class-attendance-card"><div class="card-header bg-white"><strong>Class sessions</strong></div><div class="table-responsive"><table class="table table-hover mb-0">
        <thead class="thead-light"><tr><th>Date</th><th>Session</th><th>Status</th><th>Records</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $session): ?><tr><td><?= htmlspecialchars((string) $session['service_date']) ?></td><td><?= htmlspecialchars($session['title']) ?></td><td><?= htmlspecialchars(ucfirst((string) ($session['approval_status'] ?? 'draft'))) ?></td><td><?= (int) $session['marked_count'] ?></td><td><a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(bible_class_attendance_url($selectedClassId, ['session_id' => (int) $session['id']])) ?>">Open</a></td></tr><?php endforeach; ?>
        <?php if (!$sessions): ?><tr><td colspan="5" class="text-center text-muted py-4">No attendance sessions have been generated for this class yet.</td></tr><?php endif; ?>
        </tbody></table></div></div>
</div></div>
<?php
$page_content = ob_get_clean();
$page_title = 'Bible Class Attendance - ' . $selectedClass['name'];
include __DIR__ . '/../includes/layout.php';
