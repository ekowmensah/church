<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';
require_once __DIR__.'/../services/BibleClassAttendanceScheduleService.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$schedule_service = BibleClassAttendanceScheduleService::fromSession($conn);
$leader_classes = $schedule_service->getLeaderClasses();
if (!$leader_classes) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as a Bible class leader.</div>';
    exit;
}
$leader_class_map = [];
foreach ($leader_classes as $leader_class) {
    $leader_class_map[(int) $leader_class['class_id']] = $leader_class;
}
$class_id = (int) ($_GET['class_id'] ?? array_key_first($leader_class_map));
if (!isset($leader_class_map[$class_id]) || !$schedule_service->canAccessClass($class_id)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You cannot access that Bible class.</div>';
    exit;
}
$leader_info = $leader_class_map[$class_id];
$class_name = $leader_info['class_name'];
$class_code = $leader_info['code'];
$schedule_notice = '';
$today_session_id = 0;
if ($leader_info['meeting_day'] !== null && (int) $leader_info['meeting_day'] === (int) date('w')) {
    try {
        $today_session = $schedule_service->ensureForClassDate($class_id, date('Y-m-d'), 'dashboard');
        $today_session_id = (int) $today_session['session_id'];
    } catch (Throwable $exception) {
        $schedule_notice = $exception->getMessage();
    }
} elseif ($leader_info['meeting_day'] === null) {
    $schedule_notice = 'An administrator must configure the meeting day for this class group.';
}

// Get date range for filtering (default: current month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');

// Get class members
$members = get_bible_class_members($conn, $class_id);
$total_members = count($members);

// Get payment statistics
$payment_stats = get_bible_class_payment_stats($conn, $class_id, $start_date, $end_date);

// Get attendance statistics
$attendance_stats = get_bible_class_attendance_stats($conn, $class_id, $start_date, $end_date);

// Get recent payments
$stmt = $conn->prepare("
    SELECT p.*, pt.name as payment_type_name,
           CONCAT(m.first_name, ' ', m.last_name) as member_name
    FROM payments p
    INNER JOIN members m ON p.member_id = m.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    WHERE m.class_id = ? AND p.payment_date BETWEEN ? AND ?
    ORDER BY p.payment_date DESC
    LIMIT 10
");
$stmt->bind_param('iss', $class_id, $start_date, $end_date);
$stmt->execute();
$recent_payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$upcoming_sessions = array_values(array_filter(
    $schedule_service->getClassSessions($class_id, 100),
    static fn(array $session): bool => (string) $session['service_date'] >= date('Y-m-d')
));
usort($upcoming_sessions, static fn(array $a, array $b): int => strcmp($a['service_date'], $b['service_date']));
$upcoming_sessions = array_slice($upcoming_sessions, 0, 5);

ob_start();
?>
<style>
.leader-dashboard {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    transition: transform 0.2s;
    border-left: 4px solid;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-card.members { border-left-color: #667eea; }
.stat-card.payments { border-left-color: #28a745; }
.stat-card.attendance { border-left-color: #17a2b8; }
.stat-card.rate { border-left-color: #ffc107; }

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin: 10px 0;
}

.stat-label {
    font-size: 0.9rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.member-card {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    transition: all 0.3s;
}

.member-card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transform: translateX(5px);
}

.section-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
}

.filter-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
}
</style>

<div class="leader-dashboard">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="fas fa-chalkboard-teacher"></i> My Bible Class Leadership</h2>
            <h4><?= htmlspecialchars($class_name) ?> (<?= htmlspecialchars($class_code) ?>)</h4>
        </div>
        <div>
            <div class="btn-group">
                <a href="my_bible_class_attendance.php?class_id=<?= (int) $class_id ?>" class="btn btn-light btn-lg">
                    <i class="fas fa-clipboard-check"></i> Mark Attendance
                </a>
                <a href="bible_class_book.php?class_id=<?= (int) $class_id ?>&year=<?= date('Y') ?>&quarter=<?= ceil(date('n') / 3) ?>" class="btn btn-outline-light btn-lg">
                    <i class="fas fa-book-open"></i> Bible Class Book
                </a>
                <button type="button" class="btn btn-light btn-lg dropdown-toggle dropdown-toggle-split" data-toggle="dropdown">
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="leader_export_report.php?type=members&group_type=class&format=csv">
                        <i class="fas fa-download"></i> Export Members (CSV)
                    </a>
                    <a class="dropdown-item" href="leader_export_report.php?type=attendance&group_type=class&format=csv&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">
                        <i class="fas fa-download"></i> Export Attendance (CSV)
                    </a>
                    <a class="dropdown-item" href="leader_export_report.php?type=payments&group_type=class&format=csv&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>">
                        <i class="fas fa-download"></i> Export Payments (CSV)
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($today_session_id > 0): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span><strong>Attendance is ready:</strong> today is the meeting day for <?= htmlspecialchars($leader_info['group_name']) ?>.</span>
    <a class="btn btn-primary" href="my_bible_class_attendance.php?class_id=<?= (int) $class_id ?>&session_id=<?= $today_session_id ?>">Mark today</a>
</div>
<?php elseif ($schedule_notice !== ''): ?>
<div class="alert alert-warning"><?= htmlspecialchars($schedule_notice) ?></div>
<?php endif; ?>

<?php if (count($leader_class_map) > 1): ?>
<div class="filter-card"><form method="get" class="form-row align-items-end"><div class="col-md-8"><label for="class_id">Leadership class</label><select id="class_id" name="class_id" class="form-control" onchange="this.form.submit()">
    <?php foreach ($leader_class_map as $leader_class): ?><option value="<?= (int) $leader_class['class_id'] ?>" <?= $class_id === (int) $leader_class['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($leader_class['class_name'] . ' — ' . $leader_class['group_name']) ?></option><?php endforeach; ?>
</select></div></form></div>
<?php endif; ?>

<!-- Filter Section -->
<div class="filter-card">
    <form method="get" class="row align-items-end">
        <input type="hidden" name="class_id" value="<?= (int) $class_id ?>">
        <div class="col-md-4">
            <label class="form-label fw-bold">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label fw-bold">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="?class_id=<?= (int) $class_id ?>" class="btn btn-secondary">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </form>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card members">
            <div class="stat-label">Total Members</div>
            <div class="stat-value"><?= $total_members ?></div>
            <small class="text-muted">In your class</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card payments">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value">GH₵ <?= number_format($payment_stats['total_amount'] ?? 0, 2) ?></div>
            <small class="text-muted"><?= $payment_stats['total_payments'] ?? 0 ?> transactions</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card attendance">
            <div class="stat-label">Attendance</div>
            <div class="stat-value"><?= $attendance_stats['total_present'] ?? 0 ?></div>
            <small class="text-muted">Out of <?= $attendance_stats['total_records'] ?? 0 ?> records</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card rate">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"><?= $attendance_stats['attendance_rate'] ?? 0 ?>%</div>
            <small class="text-muted"><?= $attendance_stats['total_sessions'] ?? 0 ?> sessions</small>
        </div>
    </div>
</div>

<div class="row">
    <!-- Members List -->
    <div class="col-md-6">
        <div class="section-card">
            <h5 class="mb-4">
                <i class="fas fa-users text-primary"></i> Class Members (<?= $total_members ?>)
            </h5>
            <div style="max-height: 500px; overflow-y: auto;">
                <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="d-flex align-items-center">
                        <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>" 
                             alt="Photo" 
                             style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                        <div class="flex-grow-1">
                            <strong><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                                <?php if ($member['phone']): ?>
                                | <i class="fas fa-phone"></i> <?= htmlspecialchars($member['phone']) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div>
                            <a href="leader_member_profile.php?id=<?= $member['id'] ?>" 
                               class="btn btn-sm btn-outline-primary" 
                               title="View Profile">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="leader_member_payments.php?member_id=<?= $member['id'] ?>" 
                               class="btn btn-sm btn-outline-success" 
                               title="View Payments">
                                <i class="fas fa-money-bill-wave"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if (empty($members)): ?>
                <div class="alert alert-info">No members in this class yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-md-6">
        <!-- Recent Payments -->
        <div class="section-card mb-3">
            <h5 class="mb-4">
                <i class="fas fa-money-bill-wave text-success"></i> Recent Payments
            </h5>
            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Date</th>
                            <th>Member</th>
                            <th>Type</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?= date('M d', strtotime($payment['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($payment['member_name']) ?></td>
                            <td><small><?= htmlspecialchars($payment['payment_type_name'] ?? 'N/A') ?></small></td>
                            <td><strong>GH₵ <?= number_format($payment['amount'], 2) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($recent_payments)): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted">No payments in this period</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Upcoming Sessions -->
        <div class="section-card">
            <h5 class="mb-4">
                <i class="fas fa-calendar-alt text-info"></i> Upcoming Attendance Sessions
            </h5>
            <?php foreach ($upcoming_sessions as $session): ?>
            <div class="alert alert-light border-left border-primary">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong><?= htmlspecialchars($session['title']) ?></strong>
                        <br>
                        <small class="text-muted">
                            <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                        </small>
                    </div>
                    <a href="my_bible_class_attendance.php?class_id=<?= (int) $class_id ?>&session_id=<?= (int) $session['id'] ?>"
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-clipboard-check"></i> Mark
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($upcoming_sessions)): ?>
            <div class="alert alert-info">No upcoming sessions scheduled.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$page_content = ob_get_clean();
$page_title = 'My Bible Class Leadership - ' . $class_name;
include '../includes/layout.php';
?>
