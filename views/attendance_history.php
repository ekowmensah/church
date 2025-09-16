<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../includes/member_auth.php';
if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$member_id = intval($_SESSION['member_id']);

// Get member info for display
$member_stmt = $conn->prepare("SELECT first_name, last_name, crn FROM members WHERE id = ?");
$member_stmt->bind_param('i', $member_id);
$member_stmt->execute();
$member_info = $member_stmt->get_result()->fetch_assoc();

// Handle filters and pagination
$filter = $_GET['filter'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

$valid_filters = ['all', 'present', 'absent'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

// Build query conditions
$where_conditions = ['ar.member_id = ?'];
$params = [$member_id];
$types = 'i';

if ($filter === 'present') {
    $where_conditions[] = "ar.status = 'Present'";
} elseif ($filter === 'absent') {
    $where_conditions[] = "ar.status = 'Absent'";
}

if ($date_from) {
    $where_conditions[] = 'COALESCE(ar.created_at, s.service_date) >= ?';
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}

if ($date_to) {
    $where_conditions[] = 'COALESCE(ar.created_at, s.service_date) <= ?';
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

if ($search) {
    $where_conditions[] = 's.title LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch attendance records with pagination
$sql = "SELECT s.service_date, s.title, ar.status, ar.created_at, s.id as session_id
        FROM attendance_records ar 
        LEFT JOIN attendance_sessions s ON ar.session_id = s.id 
        WHERE $where_clause
        ORDER BY COALESCE(ar.created_at, s.service_date) DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total 
              FROM attendance_records ar 
              LEFT JOIN attendance_sessions s ON ar.session_id = s.id 
              WHERE $where_clause";
$count_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET
$count_types = substr($types, 0, -2);
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Enhanced statistics with date filtering
$stats_where = $where_clause;
$stats_params = $count_params;
$stats_types = $count_types;

$stats_sql = "SELECT ar.status, COUNT(*) as count,
                     MIN(COALESCE(ar.created_at, s.service_date)) as first_attendance,
                     MAX(COALESCE(ar.created_at, s.service_date)) as last_attendance
              FROM attendance_records ar 
              LEFT JOIN attendance_sessions s ON ar.session_id = s.id 
              WHERE $stats_where
              GROUP BY ar.status";

$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param($stats_types, ...$stats_params);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();

$present = $absent = 0;
$first_attendance = $last_attendance = null;

while($s = $stats_result->fetch_assoc()) {
    if (strtolower($s['status']) === 'present') {
        $present = $s['count'];
        if (!$first_attendance || $s['first_attendance'] < $first_attendance) {
            $first_attendance = $s['first_attendance'];
        }
        if (!$last_attendance || $s['last_attendance'] > $last_attendance) {
            $last_attendance = $s['last_attendance'];
        }
    } elseif (strtolower($s['status']) === 'absent') {
        $absent = $s['count'];
        if (!$first_attendance || $s['first_attendance'] < $first_attendance) {
            $first_attendance = $s['first_attendance'];
        }
        if (!$last_attendance || $s['last_attendance'] > $last_attendance) {
            $last_attendance = $s['last_attendance'];
        }
    }
}

$total = $present + $absent;
$percent = $total ? round($present * 100 / $total) : 0;

ob_start();
?>
<!-- Page Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-calendar-check mr-2"></i>My Attendance History</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="member_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Attendance History</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Member Info Header -->
<div class="row mb-3">
    <div class="col-12">
        <div class="card bg-gradient-primary text-white">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-user-circle fa-3x"></i>
                    </div>
                    <div>
                        <h4 class="mb-1"><?= htmlspecialchars($member_info['first_name'] . ' ' . $member_info['last_name']) ?></h4>
                        <p class="mb-0"><strong>CRN:</strong> <?= htmlspecialchars($member_info['crn']) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-success text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-check-circle fa-3x"></i>
                </div>
                <div class="h2 font-weight-bold mb-1"><?= $present ?></div>
                <div class="text-uppercase font-weight-bold">Sessions Present</div>
                <small class="text-light">Attended services</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-danger text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-times-circle fa-3x"></i>
                </div>
                <div class="h2 font-weight-bold mb-1"><?= $absent ?></div>
                <div class="text-uppercase font-weight-bold">Sessions Absent</div>
                <small class="text-light">Missed services</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-info text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-calendar-alt fa-3x"></i>
                </div>
                <div class="h2 font-weight-bold mb-1"><?= $total ?></div>
                <div class="text-uppercase font-weight-bold">Total Sessions</div>
                <small class="text-light">Overall attendance</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-warning text-white shadow-lg h-100">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-percentage fa-3x"></i>
                </div>
                <div class="h2 font-weight-bold mb-1"><?= $percent ?>%</div>
                <div class="text-uppercase font-weight-bold">Attendance Rate</div>
                <small class="text-light">Success percentage</small>
            </div>
        </div>
    </div>
</div>

<!-- Additional Stats Row -->
<?php if ($first_attendance || $last_attendance): ?>
<div class="row mb-4">
    <div class="col-md-6 mb-3">
        <div class="card border-left-primary shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-calendar-plus fa-2x text-primary"></i>
                    </div>
                    <div>
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">First Attendance</div>
                        <div class="h6 mb-0 font-weight-bold text-gray-800">
                            <?= $first_attendance ? date('M d, Y', strtotime($first_attendance)) : 'N/A' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6 mb-3">
        <div class="card border-left-success shadow h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="mr-3">
                        <i class="fas fa-calendar-check fa-2x text-success"></i>
                    </div>
                    <div>
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Latest Attendance</div>
                        <div class="h6 mb-0 font-weight-bold text-gray-800">
                            <?= $last_attendance ? date('M d, Y', strtotime($last_attendance)) : 'N/A' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<!-- Advanced Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter mr-2"></i>Advanced Filters</h5>
            </div>
            <div class="card-body">
                <form action="" method="get" id="filterForm">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="filter" class="form-label">Status Filter</label>
                            <select name="filter" class="form-control" id="filter">
                                <option value="all"<?= $filter==='all'?' selected':'' ?>>All Records</option>
                                <option value="present"<?= $filter==='present'?' selected':'' ?>>Present Only</option>
                                <option value="absent"<?= $filter==='absent'?' selected':'' ?>>Absent Only</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="search" class="form-label">Search Service</label>
                            <input type="text" class="form-control" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search service title...">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary mr-2">
                                <i class="fas fa-search mr-1"></i>Apply Filters
                            </button>
                            <?php if ($filter !== 'all' || $date_from || $date_to || $search): ?>
                                <a href="attendance_history.php" class="btn btn-outline-secondary mr-2">
                                    <i class="fas fa-times mr-1"></i>Clear All
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-success mr-2" onclick="exportCSV()">
                                <i class="fas fa-download mr-1"></i>Export CSV
                            </button>
                            <button type="button" class="btn btn-info" onclick="printTable()">
                                <i class="fas fa-print mr-1"></i>Print
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Records Table -->
<div class="card shadow">
    <div class="card-header bg-gradient-success text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list mr-2"></i>Attendance Records
                <?php if ($filter !== 'all' || $date_from || $date_to || $search): ?>
                    <small class="ml-2 badge badge-light text-dark">
                        Filtered Results (<?= $total_records ?> records)
                    </small>
                <?php endif; ?>
            </h5>
            <span class="badge badge-light text-dark">
                Showing <?= min($per_page, $total_records) ?> of <?= $total_records ?> records
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if ($result->num_rows === 0): ?>
            <div class="alert alert-info m-3">
                <i class="fas fa-info-circle mr-2"></i>
                <?php if ($filter !== 'all' || $date_from || $date_to || $search): ?>
                    No attendance records found matching your filter criteria.
                <?php else: ?>
                    No attendance records found.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="attendanceTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0"><i class="fas fa-calendar mr-1"></i>Date</th>
                            <th class="border-0"><i class="fas fa-clock mr-1"></i>Time</th>
                            <th class="border-0"><i class="fas fa-church mr-1"></i>Service/Title</th>
                            <th class="border-0"><i class="fas fa-user-check mr-1"></i>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr class="hover-highlight">
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="font-weight-bold text-dark">
                                        <?= htmlspecialchars(
                                            isset($row['created_at']) && $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) :
                                            (isset($row['service_date']) && $row['service_date'] ? date('M d, Y', strtotime($row['service_date'])) : '-')
                                        ) ?>
                                    </span>
                                    <small class="text-muted">
                                        <?= htmlspecialchars(
                                            isset($row['created_at']) && $row['created_at'] ? date('l', strtotime($row['created_at'])) :
                                            (isset($row['service_date']) && $row['service_date'] ? date('l', strtotime($row['service_date'])) : '')
                                        ) ?>
                                    </small>
                                </div>
                            </td>
                            <td>
                                <span class="text-dark">
                                    <?= htmlspecialchars(
                                        isset($row['created_at']) && $row['created_at'] ? date('g:i A', strtotime($row['created_at'])) :
                                        (isset($row['service_date']) && $row['service_date'] ? date('g:i A', strtotime($row['service_date'])) : '-')
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-dark font-weight-medium">
                                    <?= htmlspecialchars($row['title'] ?: 'Service') ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $status = strtolower($row['status']);
                                $badge_class = $status === 'present' ? 'success' : 'danger';
                                $icon = $status === 'present' ? 'fas fa-check-circle' : 'fas fa-times-circle';
                                ?>
                                <span class="badge badge-<?= $badge_class ?> px-3 py-2">
                                    <i class="<?= $icon ?> mr-1"></i><?= htmlspecialchars(ucfirst($row['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= $total_records ?> entries
                            </small>
                        </div>
                        <nav aria-label="Attendance records pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                            <i class="fas fa-chevron-left"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                            <i class="fas fa-chevron-right"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Enhanced Chart Section -->
<div class="row mt-4">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-gradient-info text-white">
                <h5 class="mb-0"><i class="fas fa-chart-bar mr-2"></i>Attendance Overview</h5>
            </div>
            <div class="card-body">
                <canvas id="attBar" height="100"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow">
            <div class="card-header bg-gradient-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle mr-2"></i>Quick Stats</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between">
                        <span>Attendance Rate:</span>
                        <strong class="text-<?= $percent >= 80 ? 'success' : ($percent >= 60 ? 'warning' : 'danger') ?>"><?= $percent ?>%</strong>
                    </div>
                    <div class="progress mt-1">
                        <div class="progress-bar bg-<?= $percent >= 80 ? 'success' : ($percent >= 60 ? 'warning' : 'danger') ?>" 
                             role="progressbar" style="width: <?= $percent ?>%" aria-valuenow="<?= $percent ?>" 
                             aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between">
                        <span>Total Sessions:</span>
                        <strong><?= $total ?></strong>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between">
                        <span>Present:</span>
                        <strong class="text-success"><?= $present ?></strong>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between">
                        <span>Absent:</span>
                        <strong class="text-danger"><?= $absent ?></strong>
                    </div>
                </div>
                <?php if ($first_attendance): ?>
                <div class="mb-2">
                    <small class="text-muted">First Attendance:</small><br>
                    <small class="font-weight-bold"><?= date('M d, Y', strtotime($first_attendance)) ?></small>
                </div>
                <?php endif; ?>
                <?php if ($last_attendance): ?>
                <div class="mb-2">
                    <small class="text-muted">Latest Attendance:</small><br>
                    <small class="font-weight-bold"><?= date('M d, Y', strtotime($last_attendance)) ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Back to Dashboard -->
<div class="row mt-4">
    <div class="col-12 text-center">
        <a href="member_dashboard.php" class="btn btn-secondary btn-lg">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<!-- Custom CSS -->
<style>
.hover-highlight:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

.card {
    border: none;
    border-radius: 10px;
}

.badge {
    font-size: 0.9em;
    border-radius: 20px;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
}

.bg-gradient-success {
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
}

.bg-gradient-info {
    background: linear-gradient(135deg, #17a2b8 0%, #117a8b 100%);
}

.bg-gradient-warning {
    background: linear-gradient(135deg, #ffc107 0%, #d39e00 100%);
}

.bg-gradient-danger {
    background: linear-gradient(135deg, #dc3545 0%, #bd2130 100%);
}

.bg-gradient-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #545b62 100%);
}

.form-label {
    font-weight: 600;
    color: #495057;
}

.pagination .page-link {
    border-radius: 20px;
    margin: 0 2px;
    border: none;
    color: #007bff;
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    border: none;
}

@media print {
    .no-print {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
}
</style>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<!-- JavaScript Functions -->
<script>
// Enhanced Bar Graph
var ctx = document.getElementById('attBar').getContext('2d');
var attBar = new Chart(ctx, {
    type: 'bar',
    data: {
        labels: ['Present', 'Absent'],
        datasets: [{
            label: 'Attendance Count',
            data: [<?= $present ?>, <?= $absent ?>],
            backgroundColor: [
                'rgba(40, 167, 69, 0.8)',
                'rgba(220, 53, 69, 0.8)'
            ],
            borderColor: [
                'rgba(40, 167, 69, 1)',
                'rgba(220, 53, 69, 1)'
            ],
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const total = <?= $total ?>;
                        const value = context.parsed.y;
                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                        return context.label + ': ' + value + ' sessions (' + percentage + '%)';
                    }
                }
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    font: {
                        size: 14,
                        weight: 'bold'
                    }
                }
            },
            y: {
                beginAtZero: true,
                stepSize: 1,
                grid: {
                    color: 'rgba(0,0,0,0.1)'
                },
                ticks: {
                    font: {
                        size: 12
                    }
                }
            }
        },
        animation: {
            duration: 1000,
            easing: 'easeOutBounce'
        }
    }
});

// Export CSV Function
function exportCSV() {
    const table = document.getElementById('attendanceTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            let cellText = cols[j].innerText.replace(/"/g, '""');
            row.push('"' + cellText + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    
    if (link.download !== undefined) {
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', 'attendance_history_<?= date('Y-m-d') ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Print Function
function printTable() {
    const printContent = document.querySelector('.table-responsive').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <div style="padding: 20px;">
            <h2 style="text-align: center; margin-bottom: 20px;">
                <?= htmlspecialchars($member_info['first_name'] . ' ' . $member_info['last_name']) ?> - Attendance History
            </h2>
            <p style="text-align: center; margin-bottom: 30px;">
                <strong>CRN:</strong> <?= htmlspecialchars($member_info['crn']) ?> | 
                <strong>Generated:</strong> <?= date('M d, Y g:i A') ?>
            </p>
            ${printContent}
        </div>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
