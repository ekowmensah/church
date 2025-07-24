<?php
require_once __DIR__.'/../includes/member_auth.php';
if (!empty($_SESSION['login_success']) && !empty($_SESSION['login_fullname'])): ?>
<!-- Login Success Modal -->
<div id="loginSuccessModal" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;padding:36px 28px;box-shadow:0 8px 40px rgba(0,0,0,0.18);max-width:340px;width:90%;text-align:center;">
    <div style="font-size:1.5rem;font-weight:600;margin-bottom:8px;color:#2d7c36;">You are Welcome</div>
    <div style="font-size:1.2rem;font-weight:500;margin-bottom:20px;">
      <?php echo htmlspecialchars($_SESSION['login_fullname']); ?>
    </div>
    <button id="loginSuccessOk" style="background:#2d7c36;color:#fff;font-weight:600;border:none;border-radius:6px;padding:10px 32px;font-size:1.1rem;cursor:pointer;">OK</button>
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
$first_name = $middle_name = $last_name = $crn = '';
if ($member_id) {
    $stmt = $conn->prepare('SELECT photo, first_name, middle_name, last_name, crn, status, dob FROM members WHERE id = ?');
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


// Attendance percent based on all attendance records (like attendance_history)
$attendance_percent = 0;
$att_total = $att_present = 0;
if ($member_id) {
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
}
// Payments
$total_payments = 0;
if ($member_id) {
    $pay_sql = "SELECT SUM(amount) as total FROM payments WHERE member_id = $member_id AND (
        (reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL)
    )";
    $res = $conn->query($pay_sql)->fetch_assoc();
    $total_payments = $res['total'] ? $res['total'] : 0;
}
$recent_payments = [];
if ($member_id) {
    $pay_table = $conn->query("SELECT amount, payment_date FROM payments WHERE member_id = $member_id ORDER BY payment_date DESC LIMIT 5");
    while ($row = $pay_table->fetch_assoc()) $recent_payments[] = $row;
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
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <strong>Welcome <?php echo htmlspecialchars($first_name . ' ' . $middle_name . ' ' . $last_name); ?>! (<?php echo htmlspecialchars($crn); ?>)</strong>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="row">
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Attendance (30d)</div>
<div class="my-3">
    <div class="h3 font-weight-bold text-info mb-1" style="line-height:1.1;">
        <?= $attendance_percent ?>%
    </div>
    <div class="text-uppercase text-muted small" style="letter-spacing:1px;">Attendance %</div>
</div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-calendar-check fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Payments</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">&#8373;<?php echo number_format((float)$total_payments, 2); ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-money-bill-wave fa-2x text-gray-300"></i>
                            </div>
                        </div>
                        <!-- Check Payments Button -->
                        <div class="mt-2 text-right">
                            <a href="<?= BASE_URL ?>/views/payment_history.php" class="btn btn-sm btn-outline-info">Check Payments</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-md-12 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Bible Class</div>
<?php
$class_name = 'Not Assigned';
if ($member_id) {
    try {
        $stmt = $conn->prepare('SELECT class_id FROM members WHERE id = ?');
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $debug_class_id = 'N/A';
        $debug_found = 'no';
        if ($row = $res->fetch_assoc()) {
            $debug_found = 'yes';
            $class_id = trim($row['class_id']);
            $debug_class_id = $class_id;
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
        echo "<!-- DEBUG: found_row=$debug_found, class_id=$debug_class_id, final_class_name=".htmlspecialchars($class_name)." -->\n";
    } catch (mysqli_sql_exception $e) {
        // Table or column missing, ignore and show Not Assigned
        echo "<!-- DEBUG SQL ERROR: ".$e->getMessage()." -->\n";
    }
}
?>
<div class="h5 mb-0 font-weight-bold text-gray-800"><?= htmlspecialchars($class_name) ?></div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-book fa-2x text-gray-300"></i>
                            </div>
                        </div>
                        <!-- Edit Profile Button -->
                        <div class="mt-2 text-right">
                            <a href="<?= BASE_URL ?>/views/member_profile_edit.php" class="btn btn-sm btn-outline-primary">Edit Profile</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-8 col-lg-7">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">Attendance Overview</h6>
                    </div>
                    <div class="card-body">
                        <div class="chart-area" style="height:250px;">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4 col-lg-5">
                <?php include __DIR__.'/partials/upcoming_events_calendar.php'; ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">Recent Payments</h6>
                    </div>
                    <div class="card-body p-2">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr><th>Date</th><th>Amount</th></tr>
                                </thead>
                                <tbody>
                                <?php if (count($recent_payments)): foreach($recent_payments as $row): ?>
                                    <tr>
                                        <td><?=htmlspecialchars($row['payment_date'])?></td>
                                        <td>&#8373;<?=number_format((float)$row['amount'], 2)?></td>
                                    </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="2" class="text-center text-muted">No recent payments</td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
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
</script>
<div class="fixed-top d-flex justify-content-end p-3" style="z-index:1050;top:250px;right:10px;">
  <a href="<?= BASE_URL ?>/views/member_registered_events.php" class="btn btn-primary btn-sm shadow"><i class="fas fa-calendar-check mr-1"></i> My Registered Events</a>
</div>
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
