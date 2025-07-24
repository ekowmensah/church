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

// DEBUG: Output session and permission info for troubleshooting
error_log('SESSION: ' . print_r($_SESSION, true));
error_log('has_permission(view_dashboard): ' . (has_permission('view_dashboard') ? 'YES' : 'NO'));

// --- DASHBOARD DATA QUERIES ---
$full_member = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE baptized = 'Yes' AND confirmed = 'Yes'")->fetch_assoc()['cnt'];
$catechumen = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE baptized = 'Yes' AND (confirmed IS NULL OR confirmed != 'Yes')")->fetch_assoc()['cnt'];
$adherent = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'de-activated' OR status = 'suspended'")->fetch_assoc()['cnt'];
$junior_members = $conn->query("SELECT COUNT(*) as cnt FROM sunday_school")->fetch_assoc()['cnt'];
$total_members = $conn->query("SELECT (SELECT COUNT(*) FROM members) + (SELECT COUNT(*) FROM sunday_school) AS cnt")->fetch_assoc()['cnt'];
$registered_members = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'active'")->fetch_assoc()['cnt'];
$pending_registration = $conn->query("SELECT COUNT(*) as cnt FROM members WHERE status = 'pending'")->fetch_assoc()['cnt'];
$total_payments = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments")->fetch_assoc()['total'];
$members_no_payments = $conn->query("SELECT COUNT(*) as cnt FROM members m LEFT JOIN payments p ON m.id = p.member_id WHERE p.id IS NULL AND m.status = 'active'")->fetch_assoc()['cnt'];

// Payment stats
$payments_today = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE DATE(payment_date) = CURDATE()")->fetch_assoc()['total'];
$payments_this_week = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEARWEEK(payment_date, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc()['total'];
$payments_this_month = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE()) AND MONTH(payment_date) = MONTH(CURDATE())")->fetch_assoc()['total'];
$payments_last_month = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEAR(payment_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(payment_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))")->fetch_assoc()['total'];

// Top 5 payment types by amount
$top_payment_types = $conn->query("SELECT pt.name, COALESCE(SUM(p.amount),0) as total FROM payment_types pt LEFT JOIN payments p ON p.payment_type_id = pt.id GROUP BY pt.id ORDER BY total DESC LIMIT 5");

// Recent members and payments
$recent_members = $conn->query("SELECT m.id, CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) AS name, bc.name AS class, m.status FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id ORDER BY m.created_at DESC, m.id DESC LIMIT 5");
$recent_payments = $conn->query("SELECT p.id, p.amount, p.payment_date, pt.name AS type, CONCAT(m.last_name, ' ', m.first_name) AS member FROM payments p LEFT JOIN payment_types pt ON p.payment_type_id=pt.id LEFT JOIN members m ON p.member_id=m.id ORDER BY p.payment_date DESC, p.id DESC LIMIT 5");

ob_start();
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '';
?>
<div class="content-header pb-2 mt-2 px-2">
    <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
            <h1 class="m-0 font-weight-bold">Dashboard</h1>
            <div class="text-muted small">Welcome back, <?= htmlspecialchars($user_name) ?>!</div>
        </div>
        <div class="col-sm-6 text-right">
            <a href="<?= BASE_URL ?>/views/member_list.php" class="btn btn-primary btn-sm"><i class="fa fa-users mr-1"></i> Members</a>
            <a href="<?= BASE_URL ?>/views/payment_list.php" class="btn btn-success btn-sm"><i class="fa fa-coins mr-1"></i> Payments</a>
            <a href="<?= BASE_URL ?>/views/reports.php" class="btn btn-info btn-sm"><i class="fa fa-chart-bar mr-1"></i> Reports</a>
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
    </div>
    <!-- Member Stats Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Total Members</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-users mr-1 text-primary"></i><?= number_format($total_members) ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-2">
            <div class="card bg-light shadow-sm">
                <div class="card-body text-center p-2">
                    <div class="text-secondary small">Registered Members</div>
                    <div class="h4 mb-0 font-weight-bold"><i class="fas fa-user-friends mr-1 text-success"></i><?= number_format($registered_members) ?></div>
                </div>
            </div>
        </div>
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
                    <form id="recent-payments-filter-form" class="form-inline mb-0">
                        <input type="date" name="start_date" id="recentPaymentsStart" class="form-control form-control-sm mr-1" value="<?= date('Y-01-01') ?>">
                        <input type="date" name="end_date" id="recentPaymentsEnd" class="form-control form-control-sm mr-1" value="<?= date('Y-m-d') ?>">
                        <button type="submit" class="btn btn-sm btn-light">Filter</button>
                    </form>
                </div>
                <div class="card-body p-2" id="recent-payments-list">
                    <ul class="list-group list-group-flush">
                        <?php while($p = $recent_payments->fetch_assoc()): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center p-2">
                                <span><?= htmlspecialchars($p['member'] ?? '-') ?> <span class="badge badge-info ml-2"><?= htmlspecialchars($p['type'] ?? '-') ?></span></span>
                                <span class="badge badge-primary">₵ <?= number_format($p['amount'],2) ?></span>
                                <span class="small text-muted ml-2"><?= date('d M Y', strtotime($p['payment_date'])) ?></span>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                </div>
<script>
$('#recent-payments-filter-form').on('submit', function(e) {
    e.preventDefault();
    var start = $('#recentPaymentsStart').val();
    var end = $('#recentPaymentsEnd').val();
    $.get('ajax_recent_payments.php', {start_date: start, end_date: end}, function(html) {
        $('#recent-payments-list ul').html(html);
    });
});
</script>
            </div>
        </div>
    </div>
    <!-- Payments Over Time (Line) Full Width -->
    <div class="row g-2 mb-3">
        <div class="col-12 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-success text-white font-weight-bold py-2"><i class="fas fa-chart-line mr-2"></i>Payments Over Time (Line)</div>
                <div class="card-body p-2"><canvas id="lineChartPayments" height="120" style="max-height:200px;max-width:100%;"></canvas></div>
            </div>
        </div>
    </div>
    <!-- Member Graphs Row: Bar and Pie Side by Side -->
    <div class="row g-2 mb-3">
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-primary text-white font-weight-bold py-2"><i class="fas fa-chart-bar mr-2"></i>Members by Status (Bar)</div>
                <div class="card-body p-2"><canvas id="barChartMembers" height="120" style="max-height:120px;max-width:100%;"></canvas></div>
            </div>
        </div>
        <div class="col-lg-6 mb-2">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-gradient-info text-white font-weight-bold py-2"><i class="fas fa-chart-pie mr-2"></i>Member Distribution (Pie)</div>
                <div class="card-body p-2"><canvas id="pieChartMembers" height="120" style="max-height:120px;max-width:100%;"></canvas></div>
            </div>
        </div>
    </div>
    

    
    <!-- Widgets Row -->
    <!-- <div class="row g-2 mb-3">
        <div class="col-md-6 mb-2">
            <div class="card shadow-sm">
                <div class="card-header bg-gradient-success text-white font-weight-bold py-2"><i class="fas fa-tasks mr-2"></i>Progress Widget</div>
                <div class="card-body p-2">
                    <div class="mb-2">Annual Payment Target</div>
                    <div class="progress mb-1" style="height: 18px;">
                        <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" role="progressbar" style="width: 65%; font-size:0.95rem;">65%</div>
                    </div>
                    <div class="text-muted small">₵<?= number_format($total_payments,2) ?> of ₵<?= number_format($total_payments/0.65,2) ?> goal</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-2">
            <div class="card shadow-sm">
                <div class="card-header bg-gradient-warning text-white font-weight-bold py-2"><i class="fas fa-user mr-2"></i>Profile Widget</div>
                <div class="card-body d-flex align-items-center p-2">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($user_name) ?>&background=0D8ABC&color=fff&size=48" class="rounded-circle mr-3" alt="User Avatar" width="48" height="48">
                    <div>
                        <div class="font-weight-bold mb-1">Welcome, <?= htmlspecialchars($user_name) ?></div>
                        <div class="text-muted small">Role: Admin</div>
                        <div class="badge badge-success">Active</div>
                    </div>
                </div>
            </div>
        </div>
    </div> -->
    <!-- Event Calendar Row -->
    <div class="row g-2 mb-3">
        <div class="col-lg-12 mb-2">
            <div class="card shadow-sm">
                <div class="card-header bg-gradient-info text-white font-weight-bold py-2"><i class="fas fa-calendar-alt mr-2"></i>Event Calendar</div>
                <div class="card-body p-2">
                    <div id="eventCalendar" style="height:220px;display:flex;align-items:center;justify-content:center;">
                        <span class="text-muted">[Event Calendar will appear here]</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
.dashboard-main {
  min-width: 0;
  width: 100%;
}
.card, .info-box {
  padding: 0.25rem 0.5rem;
  font-size: 0.96rem;
}
.card-header, .info-box-content {
  padding: 0.3rem 0.5rem;
}
.card-body {
  padding: 0.5rem 0.5rem 0.3rem 0.5rem;
}
.row.g-2.mb-3 {
  margin-bottom: 0.75rem !important;
  row-gap: 0.5rem !important;
}
.info-box-icon {
  min-width: 38px;
  min-height: 38px;
  font-size: 1.4rem;
}
@media (max-width: 767.98px) {
  .dashboard-main .row > [class^='col-'] {
    margin-bottom: 0.5rem;
  }
  .info-box-number {
    font-size: 1.1rem;
  }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
<?php
// Get monthly payment data for current year
$year = date('Y');
$monthly_payments = [];
$labels = [];
for ($m = 1; $m <= 12; $m++) {
    $label = date('M', mktime(0,0,0,$m,1));
    $labels[] = $label;
    $res = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE YEAR(payment_date) = $year AND MONTH(payment_date) = $m");
    $monthly_payments[] = $res->fetch_assoc()['total'];
}
?>
window.DASHBOARD_STATS = {
    full_member: <?= (int)$full_member ?>,
    catechumen: <?= (int)$catechumen ?>,
    junior_members: <?= (int)$junior_members ?>,
    adherent: <?= (int)$adherent ?>,
    total_members: <?= (int)$total_members ?>,
    registered_members: <?= (int)$registered_members ?>,
    pending_registration: <?= (int)$pending_registration ?>,
    members_no_payments: <?= (int)$members_no_payments ?>,
    total_payments: <?= (float)$total_payments ?>,
    payments_labels: <?= json_encode($labels) ?>,
    payments_data: <?= json_encode($monthly_payments) ?>
};
</script>
<script src="<?= BASE_URL ?>/assets/js/dashboard_charts.js"></script>
<!-- FullCalendar placeholder: <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script> -->
<?php
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
