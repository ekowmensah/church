<?php
require_once __DIR__.'/../includes/member_auth.php';
if (!empty($_SESSION['login_success']) && !empty($_SESSION['login_fullname'])): ?>
<!-- Login Success Modal -->
<div id="loginSuccessModal" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:36px 28px;box-shadow:0 8px 40px rgba(0,0,0,0.18);max-width:340px;width:90%;text-align:center;">
    <div style="font-size:1.5rem;font-weight:600;margin-bottom:8px;color:#2d7c36;">Welcome Back!</div>
    <div style="font-size:1.2rem;font-weight:500;margin-bottom:20px;">
      <?php echo htmlspecialchars($_SESSION['login_fullname']); ?>
    </div>
    <button id="loginSuccessOk" style="background:#2d7c36;color:#fff;font-weight:600;border:none;border-radius:6px;padding:10px 32px;font-size:1.1rem;cursor:pointer;">Continue</button>
  </div>
</div>
<script>
document.getElementById('loginSuccessOk').onclick = function() {
  document.getElementById('loginSuccessModal').style.display = 'none';
};
</script>
<?php unset($_SESSION['login_success'], $_SESSION['login_fullname']); endif;

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

ob_start();
$member_name = isset($_SESSION['member_name']) ? $_SESSION['member_name'] : 'Member';
$profile_img = BASE_URL . '/assets/img/undraw_profile.svg';
$member_id = isset($_SESSION['member_id']) ? intval($_SESSION['member_id']) : 0;
$first_name = $middle_name = $last_name = $crn = $email = $phone = '';

if ($member_id) {
    $stmt = $conn->prepare('SELECT photo, first_name, middle_name, last_name, crn, status, dob, email, phone FROM members WHERE id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $m = $stmt->get_result()->fetch_assoc();
    
    // If member not found or status is not active, destroy session and redirect to login
    if (!$m || (isset($m['status']) && strtolower($m['status']) != 'active')) {
        session_unset();
        session_destroy();
        header('Location: ' . BASE_URL . '/login.php?expired=1');
        exit;
    }
    
    if (!empty($m['photo']) && file_exists(__DIR__.'/../uploads/members/' . $m['photo'])) {
        $profile_img = BASE_URL . '/uploads/members/' . rawurlencode($m['photo']);
    }
    
    $first_name = $m['first_name'] ?? '';
    $middle_name = $m['middle_name'] ?? '';
    $last_name = $m['last_name'] ?? '';
    $crn = $m['crn'] ?? '';
    $email = $m['email'] ?? '';
    $phone = $m['phone'] ?? '';
    $dob_val = $m['dob'] ?? '';
}

$show_birthday_toast = false;
$birthday_str = '';
if (!empty($dob_val)) {
    $dob = date_create($dob_val);
    if ($dob && date('m-d') == $dob->format('m-d')) {
        $show_birthday_toast = true;
        $birthday_str = $dob->format('F j');
    }
}


// Enhanced statistics calculation
$attendance_percent = 0;
$att_total = $att_present = 0;
$total_payments = 0;
$payment_count = 0;
$avg_payment = 0;
$last_payment_date = null;
$harvest_total = 0;
$tithe_total = 0;

if ($member_id) {
    // Attendance statistics
    $sql = "SELECT status FROM attendance_records WHERE member_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $att_total++;
        if (strtolower($row['status']) === 'present') $att_present++;
    }
    $attendance_percent = $att_total ? round(($att_present/$att_total)*100) : 0;
    
    // Enhanced payment statistics
    $pay_sql = "SELECT SUM(amount) as total, COUNT(*) as count, AVG(amount) as avg_amount, 
                       MAX(payment_date) as last_payment,
                       SUM(CASE WHEN payment_type_id = 4 THEN amount ELSE 0 END) as harvest_total,
                       SUM(CASE WHEN payment_type_id = 1 THEN amount ELSE 0 END) as tithe_total
                FROM payments 
                WHERE member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))";
    $stmt = $conn->prepare($pay_sql);
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    $total_payments = $res['total'] ? (float)$res['total'] : 0;
    $payment_count = $res['count'] ? (int)$res['count'] : 0;
    $avg_payment = $res['avg_amount'] ? (float)$res['avg_amount'] : 0;
    $last_payment_date = $res['last_payment'];
    $harvest_total = $res['harvest_total'] ? (float)$res['harvest_total'] : 0;
    $tithe_total = $res['tithe_total'] ? (float)$res['tithe_total'] : 0;
}

// Recent payments with more details
$recent_payments = [];
if ($member_id) {
    $pay_table = $conn->prepare("SELECT p.amount, p.payment_date, pt.name as payment_type, p.mode 
                                FROM payments p 
                                LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
                                WHERE p.member_id = ? 
                                ORDER BY p.payment_date DESC LIMIT 5");
    $pay_table->bind_param('i', $member_id);
    $pay_table->execute();
    $result = $pay_table->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_payments[] = $row;
    }
}
?>
<?php if (!empty($show_birthday_toast)): ?>
<!-- Birthday Banner -->
<div class="alert alert-warning d-flex align-items-center mb-3 shadow" style="font-size:1.15rem; border-left:6px solid #ff9800;">
  <i class="fas fa-birthday-cake fa-2x text-danger mr-3"></i>
  <div>
    <strong>Happy Birthday, <?php echo htmlspecialchars($first_name); ?>!</strong>
    <span class="ml-2">Wishing you a wonderful year ahead. Enjoy your special day!</span>
  </div>
</div>
<?php endif; ?>

<!-- Enhanced Welcome Header -->
<div class="row mb-4" id="welcomeHeader">
    <div class="col-12">
        <div class="card bg-gradient-primary text-white shadow-lg">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-2 text-center">
                        <div class="profile-image-container">
                            <img src="<?= $profile_img ?>" alt="Profile" class="rounded-circle shadow" style="width: 80px; height: 80px; object-fit: cover; border: 3px solid rgba(255,255,255,0.3);">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h3 class="mb-1 font-weight-bold">Welcome back, <?= htmlspecialchars($first_name) ?>!</h3>
                        <h5 class="mb-2 text-light"><?= htmlspecialchars($first_name . ' ' . $middle_name . ' ' . $last_name) ?></h5>
                        <div class="d-flex flex-wrap">
                            <span class="badge badge-light text-primary mr-2 mb-1">
                                <i class="fas fa-id-card mr-1"></i>CRN: <?= htmlspecialchars($crn) ?>
                            </span>
                            <?php if ($email): ?>
                                <span class="badge badge-light text-primary mr-2 mb-1">
                                    <i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($email) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($phone): ?>
                                <span class="badge badge-light text-primary mr-2 mb-1">
                                    <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($phone) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-right">
                        <div class="d-flex flex-column align-items-md-end">
                            <div class="mb-2">
                                <span class="text-light">Last Login: </span>
                                <strong><?= date('M d, Y - g:i A') ?></strong>
                            </div>
                            <div class="d-flex align-items-center">
                                <a href="<?= BASE_URL ?>/views/member_profile_edit.php" class="btn btn-light btn-sm mr-2">
                                    <i class="fas fa-edit mr-1"></i>Edit Profile
                                </a>
                                <button type="button" class="btn btn-outline-light btn-sm" onclick="hideWelcomeHeader()">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<!-- Enhanced Statistics Cards -->
<div class="row mb-4">
    <!-- Attendance Card -->
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card bg-gradient-success text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-calendar-check fa-3x"></i>
                </div>
                <div class="h2 font-weight-bold mb-1"><?= $attendance_percent ?>%</div>
                <div class="text-uppercase font-weight-bold">Attendance Rate</div>
                <small class="text-light"><?= $att_present ?> of <?= $att_total ?> sessions</small>
            </div>
        </div>
    </div>
    
    <!-- Total Payments Card -->
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card bg-gradient-primary text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-money-bill-wave fa-3x"></i>
                </div>
                <div class="h4 font-weight-bold mb-1">₵<?= number_format($total_payments, 2) ?></div>
                <div class="text-uppercase font-weight-bold">Total Contributions</div>
                <small class="text-light"><?= $payment_count ?> payments made</small>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/views/payment_history.php" class="btn btn-light btn-sm">
                        <i class="fas fa-eye mr-1"></i>View Details
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tithe Contributions -->
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card bg-gradient-warning text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-hand-holding-heart fa-3x"></i>
                </div>
                <div class="h4 font-weight-bold mb-1">₵<?= number_format($tithe_total, 2) ?></div>
                <div class="text-uppercase font-weight-bold">Tithe Contributions</div>
                <small class="text-light">Faithful giving</small>
            </div>
        </div>
    </div>
    
    <!-- Harvest Contributions -->
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card bg-gradient-info text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-seedling fa-3x"></i>
                </div>
                <div class="h4 font-weight-bold mb-1">₵<?= number_format($harvest_total, 2) ?></div>
                <div class="text-uppercase font-weight-bold">Harvest Contributions</div>
                <small class="text-light">Special offerings</small>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/views/member_harvest_records.php" class="btn btn-light btn-sm">
                        <i class="fas fa-eye mr-1"></i>View Records
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions Row -->
<div class="row mb-4">
    <!-- Bible Class Card -->
    <div class="col-lg-4 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-book fa-2x text-primary"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Bible Class</div>
                        <?php
                        $class_name = 'Not Assigned';
                        if ($member_id) {
                            try {
                                $stmt = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
                                $stmt->bind_param('i', $member_id);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                if ($row = $res->fetch_assoc()) {
                                    $class_id = trim($row['class_id']);
                                    if ($class_id !== '' && $class_id !== null && intval($class_id) > 0) {
                                        $class_id = intval($class_id);
                                        $stmt2 = $conn->prepare('SELECT name FROM bible_classes WHERE id = ?');
                                        $stmt2->bind_param('i', $class_id);
                                        $stmt2->execute();
                                        $res2 = $stmt2->get_result();
                                        if ($row2 = $res2->fetch_assoc()) {
                                            $class_name = $row2['name'];
                                        }
                                    }
                                }
                            } catch (mysqli_sql_exception $e) {
                                // Table or column missing, ignore and show Not Assigned
                            }
                        }
                        ?>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($class_name) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Organizations Card -->
    <div class="col-lg-4 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <div class="mr-3">
                        <i class="fas fa-users fa-2x text-success"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">My Organizations</div>
                    </div>
                </div>
                <?php
                // Fetch organizations the member belongs to
                $member_orgs = [];
                if ($member_id) {
                    $org_stmt = $conn->prepare('SELECT o.name FROM member_organizations mo INNER JOIN organizations o ON mo.organization_id = o.id WHERE mo.member_id = ? ORDER BY o.name');
                    $org_stmt->bind_param('i', $member_id);
                    $org_stmt->execute();
                    $org_res = $org_stmt->get_result();
                    while ($row = $org_res->fetch_assoc()) {
                        $member_orgs[] = $row['name'];
                    }
                    $org_stmt->close();
                }
                ?>
                <?php if (empty($member_orgs)): ?>
                    <div class="text-muted text-center py-2">
                        <small>No organizations joined yet</small>
                    </div>
                <?php else: ?>
                    <div class="mb-2">
                        <?php foreach (array_slice($member_orgs, 0, 3) as $org): ?>
                            <span class="badge badge-success mb-1 mr-1">
                                <i class="fas fa-check-circle mr-1"></i><?= htmlspecialchars($org) ?>
                            </span>
                        <?php endforeach; ?>
                        <?php if (count($member_orgs) > 3): ?>
                            <small class="text-muted">+<?= count($member_orgs) - 3 ?> more</small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="text-center">
                    <a href="<?= BASE_URL ?>/views/member_join_organization.php" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-plus mr-1"></i>Join Organizations
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions Card -->
    <div class="col-lg-4 col-md-12 mb-4">
        <div class="card border-left-info shadow h-100">
            <div class="card-body">
                <div class="text-center">
                    <div class="mb-3">
                        <i class="fas fa-bolt fa-2x text-info"></i>
                    </div>
                    <h5 class="font-weight-bold text-info mb-3">Quick Actions</h5>
                    <div class="d-flex flex-column">
                        <a href="<?= BASE_URL ?>/views/member_registered_events.php" class="btn btn-info btn-sm mb-2">
                            <i class="fas fa-calendar-check mr-1"></i>My Events
                        </a>
                        <a href="<?= BASE_URL ?>/views/payment_history.php" class="btn btn-outline-info btn-sm mb-2">
                            <i class="fas fa-history mr-1"></i>Payment History
                        </a>
                        <a href="<?= BASE_URL ?>/views/member_harvest_records.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-seedling mr-1"></i>Harvest Records
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Content Area -->
<div class="row">
    <!-- Left Column - Charts and Analytics -->
    <div class="col-xl-8 col-lg-7 mb-4">
        <!-- Attendance Chart -->
        <div class="card shadow mb-4">
            <div class="card-header bg-gradient-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Attendance Overview</h5>
                    <small>Last 7 days</small>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-area" style="height:280px;">
                    <canvas id="attendanceChart"></canvas>
                </div>
                <div class="mt-3 text-center">
                    <small class="text-muted">
                        <i class="fas fa-info-circle mr-1"></i>
                        Green = Present, Red = Absent, Gray = No Session
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card shadow">
            <div class="card-header bg-gradient-info text-white">
                <h5 class="mb-0"><i class="fas fa-clock mr-2"></i>Recent Activity</h5>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_payments)): ?>
                    <div class="timeline">
                        <?php foreach (array_slice($recent_payments, 0, 3) as $payment): ?>
                            <div class="timeline-item">
                                <div class="timeline-marker bg-success"></div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($payment['payment_type'] ?: 'Payment') ?></h6>
                                            <p class="text-muted mb-0">
                                                <i class="fas fa-calendar mr-1"></i>
                                                <?= date('M d, Y - g:i A', strtotime($payment['payment_date'])) ?>
                                            </p>
                                        </div>
                                        <div class="text-right">
                                            <span class="h6 text-success mb-0">₵<?= number_format($payment['amount'], 2) ?></span>
                                            <?php if ($payment['mode']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($payment['mode']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="<?= BASE_URL ?>/views/payment_history.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-eye mr-1"></i>View All Payments
                        </a>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No recent activity to display</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Right Column - Events and Quick Info -->
    <div class="col-xl-4 col-lg-5">
        <!-- Upcoming Events -->
        <?php include __DIR__.'/partials/upcoming_events_calendar.php'; ?>
        
        <!-- Member Stats Summary -->
        <div class="card shadow mb-4">
            <div class="card-header bg-gradient-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i>My Statistics</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6 border-right">
                        <div class="h4 text-primary mb-1"><?= $payment_count ?></div>
                        <small class="text-muted">Total Payments</small>
                    </div>
                    <div class="col-6">
                        <div class="h4 text-success mb-1">₵<?= number_format($avg_payment, 2) ?></div>
                        <small class="text-muted">Avg Payment</small>
                    </div>
                </div>
                <hr>
                <div class="row text-center">
                    <div class="col-6 border-right">
                        <div class="h4 text-info mb-1"><?= $att_present ?></div>
                        <small class="text-muted">Sessions Attended</small>
                    </div>
                    <div class="col-6">
                        <div class="h4 text-warning mb-1"><?= $att_total - $att_present ?></div>
                        <small class="text-muted">Sessions Missed</small>
                    </div>
                </div>
                <?php if ($last_payment_date): ?>
                    <hr>
                    <div class="text-center">
                        <small class="text-muted">
                            <i class="fas fa-calendar mr-1"></i>
                            Last payment: <?= date('M d, Y', strtotime($last_payment_date)) ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Styling -->
<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #6610f2 100%) !important;
}

.bg-gradient-success {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%) !important;
}

.bg-gradient-info {
    background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%) !important;
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%) !important;
}

.bg-gradient-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
}

.shadow-lg {
    box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175) !important;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #28a745;
}

.timeline-item:not(:last-child)::before {
    content: '';
    position: absolute;
    left: -30px;
    top: 17px;
    width: 2px;
    height: calc(100% + 5px);
    background: #e9ecef;
}

.timeline-content {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    border-left: 3px solid #28a745;
}

.profile-image-container {
    position: relative;
}

.profile-image-container::after {
    content: '';
    position: absolute;
    top: -5px;
    left: -5px;
    right: -5px;
    bottom: -5px;
    border-radius: 50%;
    background: linear-gradient(45deg, rgba(255,255,255,0.3), rgba(255,255,255,0.1));
    z-index: -1;
}

@media (max-width: 768px) {
    .timeline {
        padding-left: 20px;
    }
    
    .timeline-marker {
        left: -25px;
    }
    
    .timeline-item:not(:last-child)::before {
        left: -20px;
    }
}
</style>
<!-- SB Admin 2 Plugins: Chart.js -->
<script src="<?= BASE_URL ?>/assets/vendor/chart.js/Chart.min.js"></script>
<script>
// Attendance per day for past 7 days
<?php
$att_days = [];
$labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('D', strtotime($date));
    $att_days[$date] = [];
}
if ($member_id) {
    $att_day_sql = "SELECT s.service_date, ar.status FROM attendance_records ar JOIN attendance_sessions s ON ar.session_id = s.id WHERE ar.member_id = ? AND s.service_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)";
    $stmt = $conn->prepare($att_day_sql);
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $d = date('Y-m-d', strtotime($row['service_date']));
        if (isset($att_days[$d])) {
            $att_days[$d][] = strtolower($row['status']);
        }
    }
}
$att_graph = [];
foreach ($att_days as $d => $statuses) {
    if (empty($statuses)) {
        $att_graph[] = 'null'; // no session
    } elseif (in_array('present', $statuses)) {
        $att_graph[] = '1'; // present
    } elseif (in_array('absent', $statuses)) {
        $att_graph[] = '0'; // absent
    } else {
        $att_graph[] = 'null';
    }
}
$att_data = implode(',', array_map(function($v){return $v==='null'?'null':$v;}, $att_graph));
$att_labels = json_encode($labels);
$att_colors_bg = [];
$att_colors_border = [];
foreach ($att_graph as $v) {
    if ($v === '1') {
        $att_colors_bg[] = "'rgba(40,167,69,0.85)'";
        $att_colors_border[] = "'rgba(40,167,69,1)'";
    } elseif ($v === '0') {
        $att_colors_bg[] = "'rgba(220,53,69,0.85)'";
        $att_colors_border[] = "'rgba(220,53,69,1)'";
    } else {
        $att_colors_bg[] = "'rgba(108,117,125,0.4)'";
        $att_colors_border[] = "'rgba(108,117,125,0.7)'";
    }
}
$att_colors_bg_js = implode(',', $att_colors_bg);
$att_colors_border_js = implode(',', $att_colors_border);
?>
var ctx = document.getElementById('attendanceChart').getContext('2d');
var attendanceChart = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= $att_labels ?>,
        datasets: [{
            label: 'Present',
            data: [<?= $att_data ?>],
            backgroundColor: [<?= $att_colors_bg_js ?>],
            borderColor: [<?= $att_colors_border_js ?>],
            borderWidth: 2
        }]
    },
    options: {
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: { grid: { display: false } },
            y: { beginAtZero: true, min: 0, max: 1, ticks: { stepSize: 1, callback: function(v){return v===1?'Present':(v===0?'Absent':'');} } }
        },
        tooltips: {
            callbacks: {
                label: function(context) {
                    var v = context.raw;
                    if (v === 1) return 'Present';
                    if (v === 0) return 'Absent';
                    return 'No Data';
                }
            }
        }
    }
});

// Auto-hide welcome header after 10 seconds
setTimeout(function() {
    hideWelcomeHeader();
}, 10000);

// Function to hide welcome header with animation
function hideWelcomeHeader() {
    const welcomeHeader = document.getElementById('welcomeHeader');
    if (welcomeHeader) {
        welcomeHeader.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out';
        welcomeHeader.style.opacity = '0';
        welcomeHeader.style.transform = 'translateY(-20px)';
        
        setTimeout(function() {
            welcomeHeader.style.display = 'none';
        }, 500);
    }
}
</script>
<?php if ($show_birthday_toast): ?>

<!--<div class="alert alert-warning d-flex align-items-center mb-3 shadow" style="font-size:1.15rem; border-left:6px solid #ff9800;">
  <i class="fas fa-birthday-cake fa-2x text-danger mr-3"></i>
  <div>
    <strong>Happy Birthday, <?php echo htmlspecialchars($first_name); ?>!</strong>
    <span class="ml-2">Wishing you a wonderful year ahead. Enjoy your special day!</span>
  </div>
</div> -->
<!-- Birthday Toast -->
<div aria-live="polite" aria-atomic="true" style="position: fixed; top: 80px; right: 20px; min-width: 320px; z-index: 1080;">
  <div class="toast bg-warning shadow border-0" id="birthdayToast" data-delay="12000" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header bg-warning text-dark">
      <i class="fas fa-birthday-cake mr-2 text-danger"></i>
      <strong class="mr-auto">Happy Birthday!</strong>
      <small class="text-muted">Today</small>
      <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">
        <span aria-hidden="true">&times;</span>
      </button>
    </div>
    <div class="toast-body text-dark">
      <span class="h5 font-weight-bold">Dear <?php echo htmlspecialchars($first_name); ?>,</span><br>
      Wishing you a joyful and blessed birthday on <b><?php echo htmlspecialchars($birthday_str); ?></b>!<br>
      <span class="small text-muted">— From all of us at MyFreeman Methodist Church</span>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/js/birthday-toast.js"></script>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
?>
