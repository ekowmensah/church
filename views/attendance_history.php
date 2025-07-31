<?php
//if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$member_id = intval($_SESSION['member_id']);
// Fetch attendance records for this member
$filter = $_GET['filter'] ?? 'all';
$valid_filters = ['all', 'present', 'absent'];
if (!in_array($filter, $valid_filters)) $filter = 'all';
$sql = 'SELECT s.service_date, s.title, ar.status, ar.created_at FROM attendance_records ar LEFT JOIN attendance_sessions s ON ar.session_id = s.id WHERE ar.member_id = ?';
if ($filter === 'present') $sql .= " AND ar.status = 'Present'";
if ($filter === 'absent') $sql .= " AND ar.status = 'Absent'";
$sql .= ' ORDER BY COALESCE(ar.created_at, s.service_date) DESC';
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $member_id);
$stmt->execute();
$result = $stmt->get_result();

// For stats and chart, fetch all
$stmt2 = $conn->prepare('SELECT ar.status FROM attendance_records ar LEFT JOIN attendance_sessions s ON ar.session_id = s.id WHERE ar.member_id = ?');
$stmt2->bind_param('i', $member_id);
$stmt2->execute();
$all_result = $stmt2->get_result();
$present = $absent = 0;
while($s = $all_result->fetch_assoc()) {
    if (strtolower($s['status']) === 'present') $present++;
    elseif (strtolower($s['status']) === 'absent') $absent++;
}
$total = $present + $absent;
$percent = $total ? round($present * 100 / $total) : 0;
ob_start();
?>
<div class="card card-primary card-outline mt-4">
    <div class="card-header bg-primary text-white font-weight-bold">
        <i class="fas fa-calendar-check mr-2"></i>My Attendance History
    </div>
    <div class="card-body">
        <!-- Statistics -->
        <div class="row text-center mb-4">
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm border-left-success h-100 py-4 d-flex align-items-center justify-content-center">
            <div class="card-body p-2">
                <div class="display-4 font-weight-bold text-success mb-1"><?= $present ?></div>
                <div class="text-uppercase text-muted small" style="letter-spacing:1px;">Present</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm border-left-danger h-100 py-4 d-flex align-items-center justify-content-center">
            <div class="card-body p-2">
                <div class="display-4 font-weight-bold text-danger mb-1"><?= $absent ?></div>
                <div class="text-uppercase text-muted small" style="letter-spacing:1px;">Absent</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm border-left-primary h-100 py-4 d-flex align-items-center justify-content-center">
            <div class="card-body p-2">
                <div class="display-4 font-weight-bold text-primary mb-1"><?= $total ?></div>
                <div class="text-uppercase text-muted small" style="letter-spacing:1px;">Total</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm border-left-info h-100 py-4 d-flex align-items-center justify-content-center">
            <div class="card-body p-2">
                <div class="display-4 font-weight-bold text-info mb-1"><?= $percent ?>%</div>
                <div class="text-uppercase text-muted small" style="letter-spacing:1px;">Attendance %</div>
            </div>
        </div>
    </div>
</div>
        <!-- Filter -->
        <form method="get" class="form-inline mb-3">
            <label class="mr-2 font-weight-bold">Filter: </label>
            <select name="filter" class="form-control mr-2" onchange="this.form.submit()">
                <option value="all"<?= $filter==='all'?' selected':'' ?>>All</option>
                <option value="present"<?= $filter==='present'?' selected':'' ?>>Present</option>
                <option value="absent"<?= $filter==='absent'?' selected':'' ?>>Absent</option>
            </select>
        </form>
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info">No attendance records found.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Service/Title</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars(
    isset($row['created_at']) && $row['created_at'] ? date('Y-m-d', strtotime($row['created_at'])) :
    (isset($row['service_date']) && $row['service_date'] ? date('Y-m-d', strtotime($row['service_date'])) : '-')
) ?></td>
                        <td><?= htmlspecialchars(
    isset($row['created_at']) && $row['created_at'] ? date('H:i', strtotime($row['created_at'])) :
    (isset($row['service_date']) && $row['service_date'] ? date('H:i', strtotime($row['service_date'])) : '-')
) ?></td>
                        <td><?=htmlspecialchars($row['title'])?></td>
                        <td><span class="badge badge-<?= strtolower($row['status'])==='present'?'success':'danger' ?>" style="font-size:1em;min-width:70px;"><?=htmlspecialchars(ucfirst($row['status']))?></span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <!-- Bar Graph -->
        <div class="mt-4 mb-2">
            <canvas id="attBar" height="80"></canvas>
        </div>
        <a href="member_dashboard.php" class="btn btn-secondary mt-3"><i class="fas fa-arrow-left mr-1"></i>Back to Dashboard</a>
    </div>
</div>
<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
    var ctx = document.getElementById('attBar').getContext('2d');
    var attBar = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Present', 'Absent'],
            datasets: [{
                label: 'Attendance',
                data: [<?= $present ?>, <?= $absent ?>],
                backgroundColor: [
                    'rgba(40, 167, 69, 0.85)', // green
                    'rgba(220, 53, 69, 0.85)'  // red
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(220, 53, 69, 1)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                title: { display: false }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true, stepSize: 1 }
            }
        }
    });
</script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
