<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/leader_helpers.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/BibleClassAttendanceScheduleService.php';
require_once __DIR__ . '/../services/BibleClassBookOperationsService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$memberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : null;
$canReview = has_permission('review_bible_class_member_removal');
$canRequest = has_permission('request_bible_class_member_removal')
    || (bool) is_bible_class_leader($conn, $userId, $memberId);
$isSuper = has_permission('*') || has_role('Super Admin');
$scheduleService = BibleClassAttendanceScheduleService::fromSession($conn);
$operationsService = BibleClassBookOperationsService::fromSession($conn);

$classMap = [];
if ($canReview) {
    $churchId = $isSuper ? 0 : (int) (get_user_church_id($conn) ?: 0);
    if ($isSuper) {
        $classResult = $conn->query('SELECT id, name, code, church_id FROM bible_classes ORDER BY name');
        $classes = $classResult ? $classResult->fetch_all(MYSQLI_ASSOC) : [];
    } else {
        $stmt = $conn->prepare('SELECT id, name, code, church_id FROM bible_classes WHERE church_id = ? ORDER BY name');
        $stmt->bind_param('i', $churchId);
        $stmt->execute();
        $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    foreach ($classes as $class) $classMap[(int) $class['id']] = $class;
}
if ($canRequest) {
    foreach ($scheduleService->getLeaderClasses() as $class) {
        $classMap[(int) $class['class_id']] = [
            'id' => (int) $class['class_id'], 'name' => $class['class_name'],
            'code' => $class['code'], 'church_id' => (int) $class['church_id'],
        ];
    }
}
if (!$classMap) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}
uasort($classMap, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

$selectedClassId = isset($_GET['class_id'])
    ? (int) $_GET['class_id']
    : ($canReview ? 0 : (int) array_key_first($classMap));
if (($selectedClassId === 0 && !$canReview)
    || ($selectedClassId > 0 && !isset($classMap[$selectedClassId]))) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}
$selectedClass = $selectedClassId > 0 ? $classMap[$selectedClassId] : null;
$error = '';
$success = trim((string) ($_GET['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        try {
            $action = trim((string) ($_POST['action'] ?? ''));
            $actionClassId = (int) ($_POST['class_id'] ?? 0);
            $returnClassId = (int) ($_POST['return_class_id'] ?? $selectedClassId);
            if (!isset($classMap[$actionClassId])) {
                throw new RuntimeException('The selected Bible class is outside your approval scope.');
            }
            if ($action === 'request') {
                if (!$canRequest) throw new RuntimeException('You cannot submit class-list removal requests.');
                $operationsService->requestRemoval(
                    $actionClassId,
                    (int) ($_POST['member_id'] ?? 0),
                    (string) ($_POST['requested_reason'] ?? '')
                );
                header('Location: bible_class_member_removals.php?' . http_build_query([
                    'class_id' => $returnClassId, 'message' => 'Removal request submitted for administrator review.',
                ]));
                exit;
            }
            if ($action === 'review') {
                $operationsService->reviewRemoval(
                    (int) ($_POST['request_id'] ?? 0),
                    $actionClassId,
                    (string) ($_POST['decision'] ?? ''),
                    (string) ($_POST['review_notes'] ?? ''),
                    $canReview
                );
                header('Location: bible_class_member_removals.php?' . http_build_query([
                    'class_id' => $returnClassId, 'message' => 'Removal request reviewed.',
                ]));
                exit;
            }
            throw new RuntimeException('Unsupported action.');
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$members = [];
if ($selectedClassId > 0 && $canRequest && $scheduleService->canAccessClass($selectedClassId)) {
    $members = $scheduleService->getActiveMembers($selectedClassId);
}
$preselectedMemberId = (int) ($_GET['member_id'] ?? 0);
$requests = $selectedClassId > 0
    ? $operationsService->listRequests($selectedClassId, $canReview)
    : $operationsService->listRequestsForClasses(array_keys($classMap));
$pendingCount = count(array_filter($requests, static fn(array $request): bool => $request['status'] === 'pending'));

ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div><h2 class="mb-1">Bible Class Member Removal Requests</h2><p class="text-muted mb-0">Approval removes the class assignment only; it never deletes the member record.</p></div>
        <a class="btn btn-secondary" href="bible_class_book.php<?= $selectedClassId > 0 ? '?class_id=' . (int) $selectedClassId : '' ?>"><i class="fas fa-arrow-left mr-1"></i>Class Book</a>
    </div>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="card shadow-sm mb-4"><div class="card-body"><form method="get" class="form-row align-items-end"><div class="form-group col-md-8 mb-md-0"><label for="class_id">Approval queue</label><select class="form-control" id="class_id" name="class_id" onchange="this.form.submit()">
        <?php if ($canReview): ?><option value="0" <?= $selectedClassId === 0 ? 'selected' : '' ?>>All Bible classes in my scope</option><?php endif; ?>
        <?php foreach ($classMap as $class): ?><option value="<?= (int) $class['id'] ?>" <?= $selectedClassId === (int) $class['id'] ? 'selected' : '' ?>><?= htmlspecialchars($class['name'] . ($class['code'] ? ' (' . $class['code'] . ')' : '')) ?></option><?php endforeach; ?>
    </select></div><div class="form-group col-md-4 mb-0"><div class="alert <?= $pendingCount > 0 ? 'alert-warning' : 'alert-success' ?> mb-0 py-2"><strong><?= number_format($pendingCount) ?></strong> pending request<?= $pendingCount === 1 ? '' : 's' ?></div></div></form></div></div>

    <?php if ($canRequest && $members): ?>
    <div class="card shadow-sm mb-4"><div class="card-header bg-white"><strong>Request removal from <?= htmlspecialchars($selectedClass['name']) ?></strong></div><div class="card-body"><form method="post">
        <?= csrf_input() ?><input type="hidden" name="action" value="request"><input type="hidden" name="class_id" value="<?= $selectedClassId ?>"><input type="hidden" name="return_class_id" value="<?= $selectedClassId ?>">
        <div class="form-row"><div class="form-group col-md-5"><label for="member_id">Member</label><select class="form-control" id="member_id" name="member_id" required><option value="">Select member</option><?php foreach ($members as $member): ?><option value="<?= (int) $member['id'] ?>" <?= $preselectedMemberId === (int) $member['id'] ? 'selected' : '' ?>><?= htmlspecialchars(($member['crn'] ?: 'No CRN') . ' - ' . trim($member['first_name'] . ' ' . $member['middle_name'] . ' ' . $member['last_name'])) ?></option><?php endforeach; ?></select></div><div class="form-group col-md-7"><label for="requested_reason">Reason</label><textarea class="form-control" id="requested_reason" name="requested_reason" rows="2" maxlength="500" required></textarea></div></div>
        <button class="btn btn-warning" onclick="return confirm('Submit this request for administrator review?')"><i class="fas fa-paper-plane mr-1"></i>Submit Request</button>
    </form></div></div>
    <?php endif; ?>

    <div class="card shadow-sm"><div class="card-header bg-white"><strong><?= $selectedClassId === 0 ? 'Central approval queue' : 'Request history' ?></strong></div><div class="table-responsive"><table class="table table-hover mb-0"><thead class="thead-light"><tr><th>Class</th><th>Member</th><th>Reason</th><th>Requested</th><th>Status</th><th>Review</th></tr></thead><tbody>
        <?php foreach ($requests as $request): ?><tr><td><?= htmlspecialchars($request['class_name']) ?></td><td><strong><?= htmlspecialchars($request['member_name']) ?></strong><br><small><?= htmlspecialchars($request['crn'] ?: 'No CRN') ?></small></td><td><?= nl2br(htmlspecialchars($request['requested_reason'])) ?></td><td><?= htmlspecialchars((string) $request['created_at']) ?><br><small><?= htmlspecialchars($request['requested_by_name'] ?: 'System actor') ?></small></td><td><span class="badge badge-<?= $request['status'] === 'approved' ? 'success' : ($request['status'] === 'rejected' ? 'danger' : 'warning') ?>"><?= htmlspecialchars(ucfirst($request['status'])) ?></span></td><td>
            <?php if ($canReview && $request['status'] === 'pending'): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="review"><input type="hidden" name="class_id" value="<?= (int) $request['class_id'] ?>"><input type="hidden" name="return_class_id" value="<?= (int) $selectedClassId ?>"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><textarea class="form-control form-control-sm mb-2" name="review_notes" maxlength="500" placeholder="Review note (required for rejection)"></textarea><button class="btn btn-sm btn-success mr-1" name="decision" value="approved" onclick="return confirm('Approve and remove this member from the class list?')">Approve</button><button class="btn btn-sm btn-danger" name="decision" value="rejected">Reject</button></form><?php else: ?><?= nl2br(htmlspecialchars($request['review_notes'] ?: '-')) ?><?php endif; ?>
        </td></tr><?php endforeach; ?>
        <?php if (!$requests): ?><tr><td colspan="6" class="text-center text-muted py-4">No removal requests in this approval scope.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<?php
$page_content = ob_get_clean();
$page_title = 'Bible Class Member Removals';
include __DIR__ . '/../includes/layout.php';
