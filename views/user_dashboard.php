<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Ensure database connection is available
global $conn;
if (!isset($conn)) {
    $conn = $GLOBALS['conn'];
}

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_dashboard')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above

// --- COMPREHENSIVE DASHBOARD DATA QUERIES ---

// Member Statistics - New Formula Implementation
// Base counts for calculations
$total_baptized_confirmed = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE LOWER(confirmed) = 'yes' AND LOWER(baptized) = 'yes'")->fetch_assoc()['cnt'];
$total_baptized_or_confirmed = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE LOWER(confirmed) = 'yes' OR LOWER(baptized) = 'yes'")->fetch_assoc()['cnt'];
$adherent = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE membership_status = 'Adherent'")->fetch_assoc()['cnt'];
$no_status = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE NOT (LOWER(confirmed) = 'yes' OR LOWER(baptized) = 'yes') AND (membership_status IS NULL OR membership_status != 'Adherent')")->fetch_assoc()['cnt'];
$junior_members = $conn->query("SELECT COUNT(*) as cnt FROM sunday_school")->fetch_assoc()['cnt'];

// Apply new formulas:
// Full Members = Total (Baptized + Confirmed) - Total (Adherents + No Status)
$full_member = $total_baptized_confirmed - ($adherent + $no_status);
$full_member = max(0, $full_member); // Ensure non-negative

// Catechumens = Total (Baptized/Confirmed) - Total (Adherents + No Status) - Full Members
$catechumen = $total_baptized_or_confirmed - ($adherent + $no_status) - $full_member;
$catechumen = max(0, $catechumen); // Ensure non-negative

// Total Members = Full Members + Catechumens + Junior Members + Adherents + No Status
$total_members = $full_member + $catechumen + $junior_members + $adherent + $no_status;
$registered_members = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'active'")->fetch_assoc()['cnt'];
$pending_registration = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'pending'")->fetch_assoc()['cnt'];
$members_no_payments = $conn->query("SELECT COUNT(*) as cnt FROM members m LEFT JOIN payments p ON m.id = p.member_id WHERE p.id IS NULL AND m.status = 'active'")->fetch_assoc()['cnt'];

// Payment Statistics
$total_payments = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments")->fetch_assoc()['total'];
$payments_today = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE DATE(payment_date) = CURDATE()")->fetch_assoc()['total'];
$payments_this_week = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc()['total'];
$payments_this_month = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())")->fetch_assoc()['total'];
$payments_last_month = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEAR(payment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(payment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))")->fetch_assoc()['total'];
$avg_payment_per_member = $registered_members > 0 ? $total_payments / $registered_members : 0;

// Attendance Statistics
$total_attendance_sessions = $conn->query("SELECT COUNT(*) as cnt FROM attendance_sessions")->fetch_assoc()['cnt'];
$recent_attendance_rate = 0;
if ($total_attendance_sessions > 0) {
    $recent_session = $conn->query("SELECT id FROM attendance_sessions ORDER BY service_date DESC LIMIT 1")->fetch_assoc();
    if ($recent_session) {
        $present_count = $conn->query("SELECT COUNT(*) as cnt FROM attendance_records WHERE session_id = {$recent_session['id']} AND status = 'present'")->fetch_assoc()['cnt'];
        $total_records = $conn->query("SELECT COUNT(*) as cnt FROM attendance_records WHERE session_id = {$recent_session['id']}")->fetch_assoc()['cnt'];
        $recent_attendance_rate = $total_records > 0 ? ($present_count / $total_records) * 100 : 0;
    }
}

// Health Records Statistics
$health_records_count = $conn->query("SELECT COUNT(*) as cnt FROM health_records")->fetch_assoc()['cnt'];
$health_records_this_month = $conn->query("SELECT COUNT(*) as cnt FROM health_records WHERE YEAR(recorded_at) = YEAR(CURDATE()) AND MONTH(recorded_at) = MONTH(CURDATE())")->fetch_assoc()['cnt'];

// Event Statistics
$upcoming_events = $conn->query("SELECT COUNT(*) as cnt FROM events WHERE event_date >= CURDATE()")->fetch_assoc()['cnt'];
$events_this_month = $conn->query("SELECT COUNT(*) as cnt FROM events WHERE YEAR(event_date) = YEAR(CURDATE()) AND MONTH(event_date) = MONTH(CURDATE())")->fetch_assoc()['cnt'];

// ZKTeco Device Statistics
$active_devices = $conn->query("SELECT COUNT(*) as cnt FROM zkteco_devices WHERE is_active = 1")->fetch_assoc()['cnt'];
$enrolled_members = $conn->query("SELECT COUNT(DISTINCT member_id) as cnt FROM member_biometric_data WHERE is_active = 1")->fetch_assoc()['cnt'];

// Recent Activity Data
$recent_members = $conn->query("SELECT m.id, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS name, bc.name AS class, m.status, m.created_at FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id ORDER BY m.created_at DESC, m.id DESC LIMIT 8");
$recent_payments = $conn->query("SELECT p.id, p.amount, p.payment_date, pt.name as payment_type, CONCAT(m.last_name, ' ', m.first_name) as member_name FROM payments p LEFT JOIN payment_types pt ON p.payment_type_id = pt.id LEFT JOIN members m ON p.member_id = m.id ORDER BY p.payment_date DESC LIMIT 8");
$recent_events = $conn->query("SELECT id, name, event_date, location FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");

// Top Payment Types
$top_payment_types = $conn->query("SELECT pt.name, COALESCE(SUM(p.amount),0) as total, COUNT(p.id) as count FROM payment_types pt LEFT JOIN payments p ON p.payment_type_id = pt.id GROUP BY pt.id ORDER BY total DESC LIMIT 6");

// Gender Distribution
$gender_stats = $conn->query("SELECT gender, COUNT(*) as count FROM members WHERE gender IN ('Male', 'Female') GROUP BY gender");

// Age Group Distribution
$age_groups = $conn->query("SELECT 
    CASE 
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) < 18 THEN 'Under 18'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 18 AND 30 THEN '18-30'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 31 AND 50 THEN '31-50'
        WHEN TIMESTAMPDIFF(YEAR, dob, CURDATE()) BETWEEN 51 AND 65 THEN '51-65'
        ELSE 'Over 65'
    END as age_group,
    COUNT(*) as count
    FROM members 
    WHERE dob IS NOT NULL 
    GROUP BY age_group
    ORDER BY FIELD(age_group, 'Under 18', '18-30', '31-50', '51-65', 'Over 65')");

// Monthly Growth Data (Last 12 months)
$monthly_growth = [];
$monthly_payments_data = [];
$monthly_labels = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $label = date('M Y', strtotime("-$i months"));
    $monthly_labels[] = $label;
    
    // Member growth
    $member_count = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'")->fetch_assoc()['cnt'];
    $monthly_growth[] = (int)$member_count;
    
    // Payment data
    $payment_total = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = '$month'")->fetch_assoc()['total'];
    $monthly_payments_data[] = (float)$payment_total;
}

// Bible Classes Distribution
$bible_classes_data = $conn->query("SELECT bc.name, COUNT(m.id) as member_count FROM bible_classes bc LEFT JOIN members m ON bc.id = m.class_id GROUP BY bc.id ORDER BY member_count DESC LIMIT 8");

// Attendance Trends (Last 6 sessions)
$attendance_trends = $conn->query("SELECT 
    s.title, 
    s.service_date,
    COUNT(ar.id) as total_records,
    SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) as present_count,
    ROUND((SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) / COUNT(ar.id)) * 100, 1) as attendance_rate
    FROM attendance_sessions s 
    LEFT JOIN attendance_records ar ON s.id = ar.session_id 
    GROUP BY s.id 
    ORDER BY s.service_date DESC 
    LIMIT 6");

ob_start();
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'User';
$user_role = isset($_SESSION['role_name']) ? $_SESSION['role_name'] : 'Admin';
?>

<!-- Modern Dashboard Header -->
<div class="content-header pb-3 mt-2 px-3">
    <div class="row mb-3 align-items-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center">
                <div class="dashboard-icon-wrapper mr-3">
                    <i class="fas fa-tachometer-alt dashboard-main-icon"></i>
                </div>
                <div>
                    <h3 class="mb-1 font-weight-bold text-dark dashboard-title">Church Management Dashboard</h3>
                    <p class="text-muted mb-0">Welcome back, <strong><?= htmlspecialchars($user_name) ?></strong> | <?= htmlspecialchars($user_role) ?> | <?= date('l, F j, Y') ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4 text-right">
            <div class="btn-group" role="group">
                <a href="<?= BASE_URL ?>/views/member_form.php" class="btn btn-gradient-primary btn-sm"><i class="fa fa-plus mr-1"></i> Add Member</a>
                <a href="<?= BASE_URL ?>/views/payment_form.php" class="btn btn-gradient-success btn-sm"><i class="fa fa-money-bill mr-1"></i> Payment</a>
                <a href="<?= BASE_URL ?>/views/reports.php" class="btn btn-gradient-info btn-sm"><i class="fa fa-chart-bar mr-1"></i> Reports</a>
            </div>
        </div>
    </div>
</div>
<div class="dashboard-main px-2 px-lg-4 pt-2">
    <!-- Stat Cards Row -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 mb-2">
            <div class="info-box bg-primary shadow-sm">
                <span class="info-box-icon bg-gradient-primary elevation-1"><i class="fas fa-user-check"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Full Members</span>
                    <span class="info-box-number h4 mb-0"><?= number_format($full_member) ?></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="info-box bg-success shadow-sm">
                <span class="info-box-icon bg-gradient-success elevation-1"><i class="fas fa-user-graduate"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Catechumens</span>
                    <span class="info-box-number h4 mb-0"><?= number_format($catechumen) ?></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="info-box bg-warning shadow-sm">
                <span class="info-box-icon bg-gradient-warning elevation-1"><i class="fas fa-child"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Junior Members</span>
                    <span class="info-box-number h4 mb-0"><?= number_format($junior_members) ?></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="info-box bg-danger shadow-sm">
                <span class="info-box-icon bg-gradient-danger elevation-1"><i class="fas fa-user-slash"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Adherents</span>
                    <span class="info-box-number h4 mb-0"><?= number_format($adherent) ?></span>
                </div>
            </div>
        </div>
         <!-- <div class="col-6 col-md-3 mb-2">
            <div class="info-box bg-secondary shadow-sm">
                <span class="info-box-icon bg-gradient-secondary elevation-1"><i class="fas fa-user-times"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">No Status</span>
                    <span class="info-box-number h4 mb-0"><?= number_format($no_status) ?></span>
                </div>
            </div>
        </div> --> 
    </div>
    <!-- Member Stats Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Christian Community</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-users mr-1 text-primary"></i><?= number_format($total_members) ?></div>
                </div>
            </div>
        </div>
     <!--   <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Registered Members</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-user-friends mr-1 text-success"></i><?= number_format($registered_members) ?></div>
                </div>
            </div>
        </div> -->
        <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Pending Registration</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-user-clock mr-1 text-warning"></i><?= number_format($pending_registration) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Members with No Payments</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-user-times mr-1 text-danger"></i><?= number_format($members_no_payments) ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Payment Stats Cards (Row 1: Total, Today, Average) -->
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Total Payments</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-coins mr-1 text-success"></i>₵<?= number_format($total_payments,2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Payments Today</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-calendar-day mr-1 text-primary"></i>₵<?= number_format($payments_today,2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Average Payment</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-chart-line mr-1 text-primary"></i>₵<?= number_format($total_payments / max($registered_members,1),2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <!-- Payment Stats Cards (Row 2: Week, Month, Last Month) -->
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Payments This Week</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-calendar-week mr-1 text-info"></i>₵<?= number_format($payments_this_week,2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Payments This Month</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-calendar-alt mr-1 text-info"></i>₵<?= number_format($payments_this_month,2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Payments Last Month</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-calendar-minus mr-1 text-secondary"></i>₵<?= number_format($payments_last_month,2) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Links and Recent Members Side by Side -->
    <div class="row g-2 mb-3">
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-primary text-white font-weight-bold py-2"><i class="fa fa-link mr-2"></i>Quick Links</div>
                <div class="card-body p-2">
                    <a href="<?= BASE_URL ?>/views/member_list.php" class="btn btn-outline-primary btn-block btn-sm mb-2"><i class="fa fa-users mr-1"></i> Member List</a>
                    <a href="<?= BASE_URL ?>/views/bibleclass_list.php" class="btn btn-outline-info btn-block btn-sm mb-2"><i class="fa fa-book mr-1"></i> Bible Classes</a>
                    <a href="<?= BASE_URL ?>/views/payment_list.php" class="btn btn-outline-success btn-block btn-sm mb-2"><i class="fa fa-coins mr-1"></i> Payments</a>
                    <a href="<?= BASE_URL ?>/views/reports.php" class="btn btn-outline-secondary btn-block btn-sm mb-2"><i class="fa fa-chart-bar mr-1"></i> Reports</a>
                    <a href="<?= BASE_URL ?>/views/sms_bulk.php" class="btn btn-outline-warning btn-block btn-sm mb-2"><i class="fa fa-paper-plane mr-1"></i> Bulk SMS</a>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-info text-white font-weight-bold py-2"><i class="fa fa-user-plus mr-2"></i>Recent Members</div>
                <div class="card-body p-2">
                    <ul class="list-group list-group-flush">
                        <?php while($m = $recent_members->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                                <span><?= htmlspecialchars($m['name']) ?> <span class="badge badge-secondary ml-2"><?= htmlspecialchars($m['class'] ?? '-') ?></span></span>
                                <span class="badge badge-success">Active</span>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <!-- Top 5 Payment Types and Recent Payments Side by Side -->
    <div class="row g-2 mb-3">
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-info text-white font-weight-bold py-2 d-flex align-items-center justify-content-between">
                    <span><i class="fas fa-star mr-2"></i>Top 5 Payment Types</span>
                    <form id="payment-type-filter-form" class="form-inline mb-0">
                        <input type="date" name="start_date" id="topTypeStart" class="form-control form-control-sm mr-1" value="<?= date('Y-01-01') ?>">
                        <input type="date" name="end_date" id="topTypeEnd" class="form-control form-control-sm mr-1" value="<?= date('Y-m-d') ?>">
                        <button type="submit" class="btn btn-sm btn-light">Filter</button>
                    </form>
                </div>
                <div class="card-body p-2" id="top-payment-types-list">
                    <ul class="list-group list-group-flush">
                        <?php while($type = $top_payment_types->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                                <span><?= htmlspecialchars($type['name']) ?></span>
                                <span class="badge badge-primary">₵ <?= number_format($type['total'],2) ?></span>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
<script>
$('#payment-type-filter-form').on('submit', function(e) {
    e.preventDefault();
    var start = $('#topTypeStart').val();
    var end = $('#topTypeEnd').val();
    $.get('ajax_top_payment_types.php', {start_date: start, end_date: end}, function(html) {
        $('#top-payment-types-list ul').html(html);
    });
});
</script>
            </div>
        </div>
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-success text-white font-weight-bold py-2 d-flex align-items-center justify-content-between">
                    <span><i class="fa fa-coins mr-2"></i>Recent Payments</span>
                </div>
                <div class="card-body p-2">
                    <div class="recent-payments-list" style="max-height: 300px; overflow-y: auto;">
                        <?php while($payment = $recent_payments->fetch_assoc()): ?>
                            <div class="payment-item d-flex justify-content-between align-items-center p-2 border-bottom">
                                <div>
                                    <div class="font-weight-bold text-dark"><?= htmlspecialchars($payment['member_name']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($payment['payment_type']) ?> • <?= date('M j, Y', strtotime($payment['payment_date'])) ?></small>
                                </div>
                                <span class="badge badge-success font-weight-bold">₵<?= number_format($payment['amount'],2) ?></span>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>

    <!-- Advanced Analytics Section -->
    <div class="row g-3 mb-4">
        <!-- Member Growth Chart -->
        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-line mr-2"></i>Member Growth & Payment Trends</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-light btn-sm active" data-chart="growth">Growth</button>
                        <button type="button" class="btn btn-light btn-sm" data-chart="payments">Payments</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="growthChart" height="120"></canvas>
                </div>
            </div>
        </div>
        
        <!-- System Overview Cards -->
        <div class="col-lg-4 mb-3">
            <div class="row g-2">
                <div class="col-12 mb-2">
                    <div class="card bg-gradient-info text-white shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">Attendance Rate</h6>
                                    <h3 class="mb-0"><?= number_format($recent_attendance_rate, 1) ?>%</h3>
                                    <small>Last Service</small>
                                </div>
                                <i class="fas fa-users fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 mb-2">
                    <div class="card bg-gradient-success text-white shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">Health Records</h6>
                                    <h3 class="mb-0"><?= number_format($health_records_count) ?></h3>
                                    <small><?= $health_records_this_month ?> this month</small>
                                </div>
                                <i class="fas fa-heartbeat fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 mb-2">
                    <div class="card bg-gradient-warning text-white shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">Upcoming Events</h6>
                                    <h3 class="mb-0"><?= number_format($upcoming_events) ?></h3>
                                    <small><?= $events_this_month ?> this month</small>
                                </div>
                                <i class="fas fa-calendar-check fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 mb-2">
                    <div class="card bg-gradient-dark text-white shadow-sm">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">ZKTeco Devices</h6>
                                    <h3 class="mb-0"><?= number_format($active_devices) ?></h3>
                                    <small><?= $enrolled_members ?> enrolled</small>
                                </div>
                                <i class="fas fa-fingerprint fa-2x opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Demographics & Distribution Charts -->
    <div class="row g-3 mb-4">
        <!-- Gender & Age Distribution -->
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-info text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i>Member Demographics</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <h6 class="text-center mb-3">Gender Distribution</h6>
                            <canvas id="genderChart" height="150"></canvas>
                        </div>
                        <div class="col-6">
                            <h6 class="text-center mb-3">Age Groups</h6>
                            <canvas id="ageChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bible Classes & Payment Types -->
        <div class="col-lg-6 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-success text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Classes & Payment Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <h6 class="text-center mb-3">Bible Classes</h6>
                            <canvas id="classesChart" height="150"></canvas>
                        </div>
                        <div class="col-6">
                            <h6 class="text-center mb-3">Payment Types</h6>
                            <canvas id="paymentTypesChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Trends & Recent Activity -->
    <div class="row g-3 mb-4">
        <!-- Attendance Trends -->
        <div class="col-lg-8 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-warning text-white">
                    <h5 class="mb-0"><i class="fas fa-chart-area mr-2"></i>Attendance Trends (Last 6 Sessions)</h5>
                </div>
                <div class="card-body">
                    <canvas id="attendanceChart" height="120"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Recent Events -->
        <div class="col-lg-4 mb-3">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-purple text-white">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt mr-2"></i>Upcoming Events</h5>
                </div>
                <div class="card-body p-2">
                    <div class="events-list" style="max-height: 300px; overflow-y: auto;">
                        <?php while($event = $recent_events->fetch_assoc()): ?>
                            <div class="event-item p-2 border-bottom">
                                <div class="font-weight-bold text-dark"><?= htmlspecialchars($event['name']) ?></div>
                                <small class="text-muted">
                                    <i class="fas fa-calendar mr-1"></i><?= date('M j, Y', strtotime($event['event_date'])) ?>
                                    <?php if($event['location']): ?>
                                        <br><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($event['location']) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Performance Metrics Dashboard -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-gradient-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-tachometer-alt mr-2"></i>Key Performance Indicators</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-primary"><?= number_format(($full_member / max($total_members, 1)) * 100, 1) ?>%</div>
                                <div class="kpi-label">Full Members</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-success">₵<?= number_format($avg_payment_per_member, 0) ?></div>
                                <div class="kpi-label">Avg Payment</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-info"><?= number_format($recent_attendance_rate, 1) ?>%</div>
                                <div class="kpi-label">Attendance</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-warning"><?= number_format($total_attendance_sessions) ?></div>
                                <div class="kpi-label">Total Sessions</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-danger"><?= number_format(($enrolled_members / max($registered_members, 1)) * 100, 1) ?>%</div>
                                <div class="kpi-label">Biometric Enrolled</div>
                            </div>
                        </div>
                        <div class="col-md-2 col-6 mb-3">
                            <div class="kpi-item">
                                <div class="kpi-value text-dark"><?= number_format($health_records_count) ?></div>
                                <div class="kpi-label">Health Records</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Modern Dashboard Styling */
.dashboard-main {
    min-width: 0;
    width: 100%;
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
    padding-bottom: 2rem;
}

/* Header Styling */
.dashboard-icon-wrapper {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
}

.dashboard-main-icon {
    font-size: 28px;
    color: white;
}

.dashboard-title {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* Button Gradients */
.btn-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
}

.btn-gradient-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
    color: white;
}

.btn-gradient-success {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    border: none;
    color: white;
    transition: all 0.3s ease;
}

.btn-gradient-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(79, 172, 254, 0.3);
    color: white;
}

.btn-gradient-info {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
    border: none;
    color: #333;
    transition: all 0.3s ease;
}

.btn-gradient-info:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(168, 237, 234, 0.3);
    color: #333;
}

/* Card Enhancements */
.card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    overflow: hidden;
}

.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
}

.card-header {
    border: none;
    border-radius: 15px 15px 0 0 !important;
    padding: 1rem 1.5rem;
    font-weight: 600;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

.bg-gradient-success {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%) !important;
}

.bg-gradient-info {
    background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%) !important;
    color: #333 !important;
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    color: #333 !important;
}

.bg-gradient-danger {
    background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%) !important;
    color: #333 !important;
}

.bg-gradient-dark {
    background: linear-gradient(135deg, #434343 0%, #000000 100%) !important;
}

.bg-gradient-purple {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
}

.bg-gradient-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%) !important;
    color: white !important;
}

/* Info Box Styling */
.info-box {
    border-radius: 15px;
    border: none;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    overflow: hidden;
}

.info-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
}

.info-box-icon {
    border-radius: 12px;
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

/* KPI Styling */
.kpi-item {
    padding: 1rem;
    border-radius: 10px;
    background: white;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    transition: all 0.3s ease;
}

.kpi-item:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
}

.kpi-value {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.kpi-label {
    font-size: 0.875rem;
    color: #6c757d;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Payment and Event Items */
.payment-item, .event-item {
    border-radius: 8px;
    transition: all 0.3s ease;
    margin-bottom: 0.5rem;
}

.payment-item:hover, .event-item:hover {
    background-color: #f8f9fa;
    transform: translateX(5px);
}

/* Responsive Design */
@media (max-width: 768px) {
    .dashboard-main {
        padding: 1rem;
    }
    
    .dashboard-icon-wrapper {
        width: 50px;
        height: 50px;
    }
    
    .dashboard-main-icon {
        font-size: 24px;
    }
    
    .kpi-value {
        font-size: 1.5rem;
    }
    
    .btn-group .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }
}

/* Animation Classes */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-in-up {
    animation: fadeInUp 0.6s ease forwards;
}

/* Chart Container Styling */
canvas {
    border-radius: 8px;
    touch-action: manipulation;
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
}

/* Chart Container Fixes for Scrolling Issues */
.card-body canvas {
    position: relative;
    z-index: 1;
    pointer-events: auto;
}

/* Prevent scrolling on chart containers */
.card-body:has(canvas) {
    overflow: hidden;
    touch-action: pan-y;
}

/* Alternative for browsers that don't support :has() */
.chart-container {
    overflow: hidden;
    touch-action: pan-y;
    position: relative;
}

.chart-container canvas {
    display: block;
    max-width: 100%;
    height: auto !important;
    max-height: 400px;
}

/* Fixed height containers for charts */
.chart-wrapper {
    position: relative;
    height: 300px;
    width: 100%;
}

.chart-wrapper canvas {
    position: absolute;
    top: 0;
    left: 0;
    width: 100% !important;
    height: 100% !important;
}

/* Specific chart height controls */
#growthChart {
    max-height: 300px !important;
}

#genderChart, #ageChart {
    max-height: 200px !important;
}

#classesChart, #paymentTypesChart {
    max-height: 200px !important;
}

#attendanceChart {
    max-height: 250px !important;
}

/* Prevent chart container from growing */
.card-body:has(canvas) {
    overflow: hidden;
    touch-action: pan-y;
    height: auto;
    min-height: 200px;
    max-height: 400px;
}

/* Scrollbar Styling */
.recent-payments-list::-webkit-scrollbar,
.events-list::-webkit-scrollbar {
    width: 6px;
}

.recent-payments-list::-webkit-scrollbar-track,
.events-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.recent-payments-list::-webkit-scrollbar-thumb,
.events-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.recent-payments-list::-webkit-scrollbar-thumb:hover,
.events-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Comprehensive Dashboard Data
<?php
// Prepare all data for JavaScript
$gender_data = [];
$gender_labels = [];
while($gender = $gender_stats->fetch_assoc()) {
    $gender_labels[] = $gender['gender'];
    $gender_data[] = (int)$gender['count'];
}

$age_data = [];
$age_labels = [];
while($age = $age_groups->fetch_assoc()) {
    $age_labels[] = $age['age_group'];
    $age_data[] = (int)$age['count'];
}

$classes_data = [];
$classes_labels = [];
while($class = $bible_classes_data->fetch_assoc()) {
    $classes_labels[] = $class['name'];
    $classes_data[] = (int)$class['member_count'];
}

$payment_types_data = [];
$payment_types_labels = [];
$top_payment_types->data_seek(0); // Reset pointer
while($type = $top_payment_types->fetch_assoc()) {
    $payment_types_labels[] = $type['name'];
    $payment_types_data[] = (float)$type['total'];
}

$attendance_data = [];
$attendance_labels = [];
while($attendance = $attendance_trends->fetch_assoc()) {
    $attendance_labels[] = date('M j', strtotime($attendance['service_date']));
    $attendance_data[] = (float)$attendance['attendance_rate'];
}
?>

window.DASHBOARD_STATS = {
    // Member Statistics
    full_member: <?= (int)$full_member ?>,
    catechumen: <?= (int)$catechumen ?>,
    junior_members: <?= (int)$junior_members ?>,
    adherent: <?= (int)$adherent ?>,
    total_members: <?= (int)$total_members ?>,
    registered_members: <?= (int)$registered_members ?>,
    pending_registration: <?= (int)$pending_registration ?>,
    members_no_payments: <?= (int)$members_no_payments ?>,
    
    // Payment Statistics
    total_payments: <?= (float)$total_payments ?>,
    monthly_labels: <?= json_encode($monthly_labels) ?>,
    monthly_growth: <?= json_encode($monthly_growth) ?>,
    monthly_payments: <?= json_encode($monthly_payments_data) ?>,
    
    // Demographics
    gender_labels: <?= json_encode($gender_labels) ?>,
    gender_data: <?= json_encode($gender_data) ?>,
    age_labels: <?= json_encode($age_labels) ?>,
    age_data: <?= json_encode($age_data) ?>,
    
    // Classes and Payment Types
    classes_labels: <?= json_encode($classes_labels) ?>,
    classes_data: <?= json_encode($classes_data) ?>,
    payment_types_labels: <?= json_encode($payment_types_labels) ?>,
    payment_types_data: <?= json_encode($payment_types_data) ?>,
    
    // Attendance
    attendance_labels: <?= json_encode($attendance_labels) ?>,
    attendance_data: <?= json_encode($attendance_data) ?>
};

// Initialize Dashboard Charts
document.addEventListener('DOMContentLoaded', function() {
    initializeDashboardCharts();
    addAnimations();
    preventChartScrolling();
});

// Prevent scrolling issues with charts
function preventChartScrolling() {
    // Get all canvas elements
    const canvases = document.querySelectorAll('canvas');
    
    canvases.forEach(canvas => {
        // Prevent default touch behaviors that cause scrolling
        canvas.addEventListener('touchstart', function(e) {
            e.preventDefault();
        }, { passive: false });
        
        canvas.addEventListener('touchmove', function(e) {
            e.preventDefault();
        }, { passive: false });
        
        canvas.addEventListener('touchend', function(e) {
            e.preventDefault();
        }, { passive: false });
        
        // Prevent mouse wheel scrolling on charts
        canvas.addEventListener('wheel', function(e) {
            e.preventDefault();
        }, { passive: false });
        
        // Prevent context menu on right click
        canvas.addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });
    });
    
    // Add chart-container class to card bodies with canvas
    const cardBodies = document.querySelectorAll('.card-body');
    cardBodies.forEach(cardBody => {
        if (cardBody.querySelector('canvas')) {
            cardBody.classList.add('chart-container');
        }
    });
    
    // Fix chart height issues
    fixChartHeights();
}

// Function to fix chart height issues
function fixChartHeights() {
    const canvases = document.querySelectorAll('canvas');
    
    canvases.forEach(canvas => {
        // Remove height attribute to prevent conflicts
        canvas.removeAttribute('height');
        
        // Set fixed dimensions via CSS
        const canvasId = canvas.id;
        switch(canvasId) {
            case 'growthChart':
                canvas.style.height = '300px';
                break;
            case 'genderChart':
            case 'ageChart':
            case 'classesChart':
            case 'paymentTypesChart':
                canvas.style.height = '200px';
                break;
            case 'attendanceChart':
                canvas.style.height = '250px';
                break;
            default:
                canvas.style.height = '250px';
        }
        
        // Ensure width is responsive
        canvas.style.width = '100%';
        
        // Prevent further resizing
        canvas.style.maxHeight = canvas.style.height;
    });
    
    // Disable window resize handling for charts
    window.removeEventListener('resize', handleChartResize);
    
    // Add controlled resize handling
    let resizeTimeout;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(() => {
            // Only resize charts if window width changed significantly
            const currentWidth = window.innerWidth;
            if (Math.abs(currentWidth - (window.lastWidth || 0)) > 100) {
                Object.values(Chart.instances).forEach(chart => {
                    if (chart && chart.resize) {
                        chart.resize();
                    }
                });
                window.lastWidth = currentWidth;
            }
        }, 250);
    });
}

// Prevent default chart resize handler
function handleChartResize() {
    // Do nothing - we handle resizing manually
}

function initializeDashboardCharts() {
    // Chart.js default configuration
    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = '#6c757d';
    Chart.defaults.plugins.legend.display = true;
    Chart.defaults.plugins.legend.position = 'bottom';
    Chart.defaults.plugins.legend.labels.padding = 20;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    
    // Global interaction settings to prevent scrolling issues
    Chart.defaults.interaction = {
        intersect: false,
        mode: 'index'
    };
    
    // Prevent default behaviors that cause scrolling
    Chart.defaults.onHover = function(event, activeElements) {
        if (event.native && event.native.target) {
            event.native.target.style.cursor = activeElements.length > 0 ? 'pointer' : 'default';
        }
    };
    
    // Global settings to prevent height growth issues
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.resizeDelay = 0;
    
    // Disable Chart.js resize observer to prevent height conflicts
    Chart.defaults.plugins.resize = {
        delay: 0
    };
    
    // Color palettes
    const primaryColors = ['#667eea', '#764ba2', '#4facfe', '#00f2fe', '#a8edea', '#fed6e3'];
    const backgroundColors = primaryColors.map(color => color + '20');
    const borderColors = primaryColors;
    
    // 1. Growth Chart (Line Chart)
    const growthCtx = document.getElementById('growthChart');
    if (growthCtx) {
        window.growthChart = new Chart(growthCtx, {
            type: 'line',
            data: {
                labels: window.DASHBOARD_STATS.monthly_labels,
                datasets: [{
                    label: 'New Members',
                    data: window.DASHBOARD_STATS.monthly_growth,
                    borderColor: '#667eea',
                    backgroundColor: '#667eea20',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#667eea',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e9ecef'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                },
                elements: {
                    point: {
                        hoverRadius: 8
                    }
                }
            }
        });
    }
    
    // 2. Gender Distribution (Doughnut Chart)
    const genderCtx = document.getElementById('genderChart');
    if (genderCtx && window.DASHBOARD_STATS.gender_data.length > 0) {
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: window.DASHBOARD_STATS.gender_labels,
                datasets: [{
                    data: window.DASHBOARD_STATS.gender_data,
                    backgroundColor: ['#667eea', '#764ba2'],
                    borderWidth: 0,
                    cutout: '60%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            font: {
                                size: 12
                            }
                        }
                    }
                }
            }
        });
    }
    
    // 3. Age Groups (Polar Area Chart)
    const ageCtx = document.getElementById('ageChart');
    if (ageCtx && window.DASHBOARD_STATS.age_data.length > 0) {
        new Chart(ageCtx, {
            type: 'polarArea',
            data: {
                labels: window.DASHBOARD_STATS.age_labels,
                datasets: [{
                    data: window.DASHBOARD_STATS.age_data,
                    backgroundColor: backgroundColors.slice(0, window.DASHBOARD_STATS.age_data.length),
                    borderColor: borderColors.slice(0, window.DASHBOARD_STATS.age_data.length),
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 10,
                            font: {
                                size: 10
                            }
                        }
                    }
                },
                scales: {
                    r: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
    
    // 4. Bible Classes (Bar Chart)
    const classesCtx = document.getElementById('classesChart');
    if (classesCtx && window.DASHBOARD_STATS.classes_data.length > 0) {
        new Chart(classesCtx, {
            type: 'bar',
            data: {
                labels: window.DASHBOARD_STATS.classes_labels,
                datasets: [{
                    data: window.DASHBOARD_STATS.classes_data,
                    backgroundColor: '#4facfe40',
                    borderColor: '#4facfe',
                    borderWidth: 2,
                    borderRadius: 8,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e9ecef'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45
                        }
                    }
                }
            }
        });
    }
    
    // 5. Payment Types (Doughnut Chart)
    const paymentTypesCtx = document.getElementById('paymentTypesChart');
    if (paymentTypesCtx && window.DASHBOARD_STATS.payment_types_data.length > 0) {
        new Chart(paymentTypesCtx, {
            type: 'doughnut',
            data: {
                labels: window.DASHBOARD_STATS.payment_types_labels,
                datasets: [{
                    data: window.DASHBOARD_STATS.payment_types_data,
                    backgroundColor: backgroundColors.slice(0, window.DASHBOARD_STATS.payment_types_data.length),
                    borderColor: borderColors.slice(0, window.DASHBOARD_STATS.payment_types_data.length),
                    borderWidth: 2,
                    cutout: '50%'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 10,
                            font: {
                                size: 10
                            }
                        }
                    }
                }
            }
        });
    }
    
    // 6. Attendance Trends (Area Chart)
    const attendanceCtx = document.getElementById('attendanceChart');
    if (attendanceCtx && window.DASHBOARD_STATS.attendance_data.length > 0) {
        new Chart(attendanceCtx, {
            type: 'line',
            data: {
                labels: window.DASHBOARD_STATS.attendance_labels,
                datasets: [{
                    label: 'Attendance Rate (%)',
                    data: window.DASHBOARD_STATS.attendance_data,
                    borderColor: '#ffecd2',
                    backgroundColor: '#ffecd220',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#fcb69f',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            color: '#e9ecef'
                        },
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    }
    
    // Chart Toggle Functionality
    const chartButtons = document.querySelectorAll('[data-chart]');
    chartButtons.forEach(button => {
        button.addEventListener('click', function() {
            const chartType = this.getAttribute('data-chart');
            
            // Update button states
            chartButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');
            
            // Update chart data
            if (window.growthChart) {
                if (chartType === 'growth') {
                    window.growthChart.data.datasets[0].label = 'New Members';
                    window.growthChart.data.datasets[0].data = window.DASHBOARD_STATS.monthly_growth;
                    window.growthChart.data.datasets[0].borderColor = '#667eea';
                    window.growthChart.data.datasets[0].backgroundColor = '#667eea20';
                } else if (chartType === 'payments') {
                    window.growthChart.data.datasets[0].label = 'Monthly Payments (₵)';
                    window.growthChart.data.datasets[0].data = window.DASHBOARD_STATS.monthly_payments;
                    window.growthChart.data.datasets[0].borderColor = '#4facfe';
                    window.growthChart.data.datasets[0].backgroundColor = '#4facfe20';
                }
                window.growthChart.update('active');
            }
        });
    });
}

function addAnimations() {
    // Add fade-in animations to cards
    const cards = document.querySelectorAll('.card, .info-box');
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('fade-in-up');
        }, index * 100);
    });
    
    // Add counter animations to KPI values
    const kpiValues = document.querySelectorAll('.kpi-value');
    kpiValues.forEach(value => {
        const finalValue = value.textContent;
        const numericValue = parseFloat(finalValue.replace(/[^0-9.]/g, ''));
        
        if (!isNaN(numericValue)) {
            let currentValue = 0;
            const increment = numericValue / 50;
            const timer = setInterval(() => {
                currentValue += increment;
                if (currentValue >= numericValue) {
                    currentValue = numericValue;
                    clearInterval(timer);
                }
                
                if (finalValue.includes('%')) {
                    value.textContent = Math.round(currentValue) + '%';
                } else if (finalValue.includes('₵')) {
                    value.textContent = '₵' + Math.round(currentValue).toLocaleString();
                } else {
                    value.textContent = Math.round(currentValue).toLocaleString();
                }
            }, 50);
        }
    });
}

// Utility function for number formatting
function formatNumber(num) {
    if (num >= 1000000) {
        return (num / 1000000).toFixed(1) + 'M';
    } else if (num >= 1000) {
        return (num / 1000).toFixed(1) + 'K';
    }
    return num.toString();
}

// Real-time updates (optional - can be implemented with WebSockets or periodic AJAX calls)
function updateDashboardData() {
    // This function can be called periodically to update dashboard data
    // Implementation depends on your real-time requirements
}
</script>

<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
