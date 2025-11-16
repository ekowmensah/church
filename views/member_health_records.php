<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

if (!isset($_SESSION['member_id'])) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$member_id = $_SESSION['member_id'];

// Fetch member info with available health profile data
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, crn, dob, gender, phone, email FROM members WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member_result = $stmt->get_result();
$member_info = $member_result->fetch_assoc();
$stmt->close();

$member_name = trim($member_info['last_name'] . ' ' . $member_info['first_name'] . ' ' . $member_info['middle_name']);
$age = null;
if ($member_info['dob']) {
    $age = date_diff(date_create($member_info['dob']), date_create('today'))->y;
}

// Handle filters and pagination
$filter = $_GET['filter'] ?? 'all';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query conditions
$where_conditions = ['member_id = ?'];
$params = [$member_id];
$types = 'i';

if ($date_from) {
    $where_conditions[] = 'recorded_at >= ?';
    $params[] = $date_from . ' 00:00:00';
    $types .= 's';
}

if ($date_to) {
    $where_conditions[] = 'recorded_at <= ?';
    $params[] = $date_to . ' 23:59:59';
    $types .= 's';
}

if ($search) {
    $where_conditions[] = 'notes LIKE ?';
    $params[] = '%' . $search . '%';
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch health records with pagination
$sql = "SELECT * FROM health_records WHERE $where_clause ORDER BY recorded_at DESC LIMIT ? OFFSET ?";
$stmt2 = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt2->bind_param($types, ...$params);
$stmt2->execute();
$result = $stmt2->get_result();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM health_records WHERE $where_clause";
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Calculate comprehensive health statistics
$stats_sql = "SELECT * FROM health_records WHERE member_id = ? ORDER BY recorded_at DESC";
$stats_stmt = $conn->prepare($stats_sql);
$stats_stmt->bind_param('i', $member_id);
$stats_stmt->execute();
$all_records = $stats_stmt->get_result();

$latest_record = null;
$avg_bp_sys = $avg_bp_dia = $avg_weight = $avg_temp = $avg_sugar = 0;
$bp_count = $weight_count = $temp_count = $sugar_count = 0;
$normal_bp_count = $normal_weight_count = $normal_temp_count = $normal_sugar_count = 0;
$high_bp_count = $high_sugar_count = 0;
$hepatitis_positive = $malaria_positive = 0;
$health_trends = [];
$bmi_data = [];

if ($all_records->num_rows > 0) {
    $records = [];
    while ($rec = $all_records->fetch_assoc()) {
        $records[] = $rec;
        $v = json_decode($rec['vitals'], true) ?: [];
        
        // Blood Pressure Analysis
        if (!empty($v['bp']) && strpos($v['bp'], '/') !== false) {
            list($sys, $dia) = explode('/', $v['bp'], 2);
            $sys = (int)$sys;
            $dia = (int)$dia;
            $avg_bp_sys += $sys;
            $avg_bp_dia += $dia;
            $bp_count++;
            
            if ($sys < 120 && $dia < 80) {
                $normal_bp_count++;
            } elseif (($sys >= 120 && $sys < 140) || ($dia >= 80 && $dia < 90)) {
                // Elevated/Prehypertension - count as normal for now
                $normal_bp_count++;
            } elseif ($sys >= 140 || $dia >= 90) {
                $high_bp_count++;
            } else {
                // Fallback - count as normal if doesn't fit other categories
                $normal_bp_count++;
            }
        }
        
        // Weight and BMI Analysis
        if (!empty($v['weight'])) {
            $weight = (float)$v['weight'];
            $avg_weight += $weight;
            $weight_count++;
            
            // Calculate BMI if height is available (assuming 1.7m average if not specified)
            $height = !empty($v['height']) ? (float)$v['height'] : 1.7;
            $bmi = $weight / ($height * $height);
            $bmi_data[] = ['date' => $rec['recorded_at'], 'bmi' => round($bmi, 1), 'weight' => $weight];
            
            if ($bmi >= 18.5 && $bmi < 25) {
                $normal_weight_count++;
            }
        }
        
        // Temperature Analysis
        if (!empty($v['temperature'])) {
            $temp = (float)$v['temperature'];
            $avg_temp += $temp;
            $temp_count++;
            
            if ($temp >= 36.1 && $temp <= 37.2) {
                $normal_temp_count++;
            }
        }
        
        // Sugar Level Analysis
        if (!empty($v['sugar'])) {
            $sugar = (float)$v['sugar'];
            $avg_sugar += $sugar;
            $sugar_count++;
            
            if ($sugar < 7.0) {
                $normal_sugar_count++;
            } elseif ($sugar >= 11.1) {
                $high_sugar_count++;
            }
        }
        
        // Disease Tracking
        if (!empty($v['hepatitis_b']) && strtolower($v['hepatitis_b']) === 'positive') {
            $hepatitis_positive++;
        }
        if (!empty($v['malaria']) && strtolower($v['malaria']) === 'positive') {
            $malaria_positive++;
        }
    }
    
    $latest_record = $records[0];
    
    // Calculate averages
    if ($bp_count > 0) {
        $avg_bp_sys = round($avg_bp_sys / $bp_count);
        $avg_bp_dia = round($avg_bp_dia / $bp_count);
    }
    if ($weight_count > 0) {
        $avg_weight = round($avg_weight / $weight_count, 1);
    }
    if ($temp_count > 0) {
        $avg_temp = round($avg_temp / $temp_count, 1);
    }
    if ($sugar_count > 0) {
        $avg_sugar = round($avg_sugar / $sugar_count, 1);
    }
}

// Health Risk Assessment
$health_score = 100;
$risk_factors = [];

if ($bp_count > 0) {
    $bp_normal_rate = ($normal_bp_count / $bp_count) * 100;
    if ($bp_normal_rate < 70) {
        $health_score -= 20;
        $risk_factors[] = 'Blood Pressure Concerns';
    }
}

if ($sugar_count > 0) {
    $sugar_normal_rate = ($normal_sugar_count / $sugar_count) * 100;
    if ($sugar_normal_rate < 70) {
        $health_score -= 15;
        $risk_factors[] = 'Blood Sugar Issues';
    }
}

if ($hepatitis_positive > 0) {
    $health_score -= 10;
    $risk_factors[] = 'Hepatitis B Positive';
}

if ($malaria_positive > 0) {
    $health_score -= 5;
    $risk_factors[] = 'Recent Malaria History';
}

$health_score = max(0, $health_score);

$page_title = 'My Health Records';
ob_start();
?>

<style>
.health-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    overflow: hidden;
}

.health-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.health-card .card-body {
    padding: 1.5rem;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
    margin-bottom: 1rem;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.stat-label {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.health-status {
    padding: 0.375rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-normal {
    background: linear-gradient(135deg, #d4edda, #c3e6cb);
    color: #155724;
}

.status-elevated {
    background: linear-gradient(135deg, #fff3cd, #ffeaa7);
    color: #856404;
}

.status-high {
    background: linear-gradient(135deg, #f8d7da, #f5c6cb);
    color: #721c24;
}

.gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.gradient-success {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
}

.gradient-info {
    background: linear-gradient(135deg, #3498db 0%, #74b9ff 100%);
}

.gradient-warning {
    background: linear-gradient(135deg, #f39c12 0%, #fdcb6e 100%);
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}

.page-header h1 {
    margin: 0;
    font-weight: 700;
}

.page-header .subtitle {
    opacity: 0.9;
    margin-top: 0.5rem;
}

.records-table {
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.records-table th {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: none;
    font-weight: 600;
    color: #495057;
    padding: 1rem 0.75rem;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.records-table td {
    padding: 1rem 0.75rem;
    border-color: #f1f3f4;
    vertical-align: middle;
}

.records-table tbody tr {
    transition: background-color 0.2s ease;
}

.records-table tbody tr:hover {
    background-color: #f8f9ff;
}

.chart-container {
    background: white;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    padding: 1.5rem;
    margin-bottom: 2rem;
}

@media (max-width: 768px) {
    .stat-value {
        font-size: 1.5rem;
    }
    
    .stat-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .page-header {
        padding: 1.5rem;
        text-align: center;
    }
    
    .records-table th,
    .records-table td {
        padding: 0.75rem 0.5rem;
        font-size: 0.8rem;
    }
}
</style>

<!-- Enhanced Page Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0"><i class="fas fa-heartbeat mr-2"></i>My Health Records</h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="member_dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Health Records</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- Member Health Profile Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card bg-gradient-info text-white shadow-lg">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3 text-center">
                        <div class="profile-health-icon">
                            <i class="fas fa-user-md fa-4x mb-2"></i>
                            <div class="h5 mb-0">Health Profile</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h3 class="mb-2"><?= htmlspecialchars($member_name) ?></h3>
                        <div class="row">
                            <div class="col-6">
                                <small class="text-light">CRN:</small>
                                <div class="font-weight-bold"><?= htmlspecialchars($member_info['crn']) ?></div>
                            </div>
                            <div class="col-6">
                                <small class="text-light">Age:</small>
                                <div class="font-weight-bold"><?= $age ? $age . ' years' : 'N/A' ?></div>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-6">
                                <small class="text-light">Gender:</small>
                                <div class="font-weight-bold"><?= htmlspecialchars($member_info['gender'] ?: 'N/A') ?></div>
                            </div>
                            <div class="col-6">
                                <small class="text-light">Phone:</small>
                                <div class="font-weight-bold"><?= htmlspecialchars($member_info['phone'] ?: 'N/A') ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="health-score-circle">
                            <div class="h2 mb-0 font-weight-bold"><?= $health_score ?>%</div>
                            <small class="text-light">Health Score</small>
                            <div class="mt-2">
                                <?php if ($health_score >= 80): ?>
                                    <span class="badge badge-success"><i class="fas fa-thumbs-up mr-1"></i>Excellent</span>
                                <?php elseif ($health_score >= 60): ?>
                                    <span class="badge badge-warning"><i class="fas fa-exclamation-triangle mr-1"></i>Good</span>
                                <?php else: ?>
                                    <span class="badge badge-danger"><i class="fas fa-exclamation-circle mr-1"></i>Needs Attention</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Health Risk Factors Alert -->
<?php if (!empty($risk_factors)): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-warning shadow">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle fa-2x mr-3"></i>
                <div>
                    <h5 class="alert-heading mb-2">Health Risk Factors Identified</h5>
                    <p class="mb-0">The following areas require attention based on your health records:</p>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($risk_factors as $factor): ?>
                            <li><?= htmlspecialchars($factor) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Enhanced Health Statistics Dashboard -->
<div class="row mb-4">
    <!-- Total Records -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-primary text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-clipboard-list fa-2x"></i>
                </div>
                <div class="h3 font-weight-bold mb-1"><?= $all_records->num_rows ?></div>
                <div class="small text-uppercase">Total Records</div>
            </div>
        </div>
    </div>
    
    <!-- Blood Pressure -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-danger text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-heartbeat fa-2x"></i>
                </div>
                <div class="h5 font-weight-bold mb-1">
                    <?= $bp_count > 0 ? $avg_bp_sys . '/' . $avg_bp_dia : 'N/A' ?>
                </div>
                <div class="small text-uppercase">Avg Blood Pressure</div>
                <?php if ($bp_count > 0): ?>
                    <div class="mt-1">
                        <small><?= round(($normal_bp_count / $bp_count) * 100) ?>% Normal</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Weight/BMI -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-success text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-weight fa-2x"></i>
                </div>
                <div class="h5 font-weight-bold mb-1">
                    <?= $weight_count > 0 ? $avg_weight . ' kg' : 'N/A' ?>
                </div>
                <div class="small text-uppercase">Average Weight</div>
                <?php if (!empty($bmi_data)): ?>
                    <?php $latest_bmi = end($bmi_data)['bmi']; ?>
                    <div class="mt-1">
                        <small>BMI: <?= $latest_bmi ?></small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Temperature -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-warning text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-thermometer-half fa-2x"></i>
                </div>
                <div class="h5 font-weight-bold mb-1">
                    <?= $temp_count > 0 ? $avg_temp . '°C' : 'N/A' ?>
                </div>
                <div class="small text-uppercase">Avg Temperature</div>
                <?php if ($temp_count > 0): ?>
                    <div class="mt-1">
                        <small><?= round(($normal_temp_count / $temp_count) * 100) ?>% Normal</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Blood Sugar -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-info text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-tint fa-2x"></i>
                </div>
                <div class="h5 font-weight-bold mb-1">
                    <?= $sugar_count > 0 ? $avg_sugar . ' mmol/L' : 'N/A' ?>
                </div>
                <div class="small text-uppercase">Avg Blood Sugar</div>
                <?php if ($sugar_count > 0): ?>
                    <div class="mt-1">
                        <small><?= round(($normal_sugar_count / $sugar_count) * 100) ?>% Normal</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Last Checkup -->
    <div class="col-xl-2 col-lg-3 col-md-6 mb-3">
        <div class="card bg-gradient-secondary text-white shadow h-100">
            <div class="card-body text-center">
                <div class="mb-2">
                    <i class="fas fa-calendar-check fa-2x"></i>
                </div>
                <div class="h6 font-weight-bold mb-1">
                    <?= $latest_record ? date('M d, Y', strtotime($latest_record['recorded_at'])) : 'N/A' ?>
                </div>
                <div class="small text-uppercase">Last Checkup</div>
                <?php if ($latest_record): ?>
                    <div class="mt-1">
                        <small><?= date('g:i A', strtotime($latest_record['recorded_at'])) ?></small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Health Trends and Disease Tracking -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0"><i class="fas fa-chart-line mr-2"></i>Health Trends Overview</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="border-left-primary p-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-heartbeat fa-2x text-primary mr-3"></i>
                                <div>
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Blood Pressure Status</div>
                                    <div class="h6 mb-0 font-weight-bold text-gray-800">
                                        <?php if ($bp_count > 0): ?>
                                            <?= $normal_bp_count ?> Normal, <?= $high_bp_count ?> High
                                        <?php else: ?>
                                            No data available
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="border-left-success p-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-tint fa-2x text-success mr-3"></i>
                                <div>
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Blood Sugar Status</div>
                                    <div class="h6 mb-0 font-weight-bold text-gray-800">
                                        <?php if ($sugar_count > 0): ?>
                                            <?= $normal_sugar_count ?> Normal, <?= $high_sugar_count ?> High
                                        <?php else: ?>
                                            No data available
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow">
            <div class="card-header bg-gradient-warning text-white">
                <h5 class="mb-0"><i class="fas fa-virus mr-2"></i>Disease Tracking</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Hepatitis B:</span>
                        <?php if ($hepatitis_positive > 0): ?>
                            <span class="badge badge-danger">Positive (<?= $hepatitis_positive ?>)</span>
                        <?php else: ?>
                            <span class="badge badge-success">Negative</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Malaria History:</span>
                        <?php if ($malaria_positive > 0): ?>
                            <span class="badge badge-warning"><?= $malaria_positive ?> Cases</span>
                        <?php else: ?>
                            <span class="badge badge-success">No Cases</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <small class="text-muted">Based on <?= $all_records->num_rows ?> health records</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Advanced Filters -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow">
            <div class="card-header bg-gradient-primary text-white">
                <h5 class="mb-0"><i class="fas fa-filter mr-2"></i>Advanced Filters & Export</h5>
            </div>
            <div class="card-body">
                <form action="" method="get" id="filterForm">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="date_from" class="form-label">From Date</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="date_to" class="form-label">To Date</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="search" class="form-label">Search Notes</label>
                            <input type="text" class="form-control" id="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search in notes...">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex">
                                <button type="submit" class="btn btn-primary mr-2">
                                    <i class="fas fa-search"></i>
                                </button>
                                <?php if ($date_from || $date_to || $search): ?>
                                    <a href="member_health_records.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-12">
                            <button type="button" class="btn btn-success mr-2" onclick="exportHealthData()">
                                <i class="fas fa-download mr-1"></i>Export CSV
                            </button>
                            <button type="button" class="btn btn-info mr-2" onclick="printHealthReport()">
                                <i class="fas fa-print mr-1"></i>Print Report
                            </button>
                            <button type="button" class="btn btn-warning" onclick="generateHealthSummary()">
                                <i class="fas fa-file-medical mr-1"></i>Health Summary
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php 
// Reset result for graph
$all_records->data_seek(0);
$all_result = $all_records; 
include __DIR__.'/partials/health_bp_graph.php'; 
$all_records->data_seek(0); 
?>
<!-- Enhanced Health Records Table -->
<div class="card shadow">
    <div class="card-header bg-gradient-success text-white">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0 font-weight-bold">
                    <i class="fas fa-table mr-2"></i>
                    Detailed Health Records
                </h5>
                <small class="text-light mt-1">Complete history of your health checkups</small>
                <?php if ($date_from || $date_to || $search): ?>
                    <div class="mt-1">
                        <span class="badge badge-light text-dark">
                            Filtered Results (<?= $total_records ?> records)
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <div class="text-light small">Showing</div>
                <div class="h5 mb-0 font-weight-bold"><?= min($per_page, $total_records) ?> of <?= $total_records ?></div>
            </div>
        </div>
    </div>
    
    <div class="card-body p-0">
        <?php if ($total_records > 0): ?>
            <div class="table-responsive">
                <table class="table records-table mb-0">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar mr-1"></i>Date/Time</th>
                            <th><i class="fas fa-weight mr-1"></i>Weight</th>
                            <th><i class="fas fa-thermometer-half mr-1"></i>Temp</th>
                            <th><i class="fas fa-heartbeat mr-1"></i>Blood Pressure</th>
                            <th><i class="fas fa-tint mr-1"></i>Sugar Level</th>
                            <th><i class="fas fa-virus mr-1"></i>Hep B</th>
                            <th><i class="fas fa-bug mr-1"></i>Malaria</th>
                            <th><i class="fas fa-notes-medical mr-1"></i>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php 
                    $all_records->data_seek(0);
                    while($rec = $all_records->fetch_assoc()): 
                        $v = json_decode($rec['vitals'], true) ?: [];
                        
                        // Determine BP status color
                        $bp_status_class = 'status-normal';
                        $bp_status_text = $v['bp_status'] ?? '';
                        if (stripos($bp_status_text, 'high') !== false || stripos($bp_status_text, 'hypertension') !== false) {
                            $bp_status_class = 'status-high';
                        } elseif (stripos($bp_status_text, 'elevated') !== false || stripos($bp_status_text, 'prehypertension') !== false) {
                            $bp_status_class = 'status-elevated';
                        }
                        
                        // Determine sugar status color
                        $sugar_status_class = 'status-normal';
                        $sugar_status_text = $v['sugar_status'] ?? '';
                        if (stripos($sugar_status_text, 'high') !== false || stripos($sugar_status_text, 'diabetic') !== false) {
                            $sugar_status_class = 'status-high';
                        } elseif (stripos($sugar_status_text, 'elevated') !== false || stripos($sugar_status_text, 'prediabetic') !== false) {
                            $sugar_status_class = 'status-elevated';
                        }
                    ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold text-primary">
                                    <?= date('M d, Y', strtotime($rec['recorded_at'])) ?>
                                </div>
                                <small class="text-muted">
                                    <?= date('g:i A', strtotime($rec['recorded_at'])) ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($v['weight'])): ?>
                                    <span class="font-weight-bold"><?= htmlspecialchars($v['weight']) ?></span>
                                    <small class="text-muted">kg</small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['temperature'])): ?>
                                    <span class="font-weight-bold"><?= htmlspecialchars($v['temperature']) ?></span>
                                    <small class="text-muted">°C</small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['bp'])): ?>
                                    <div>
                                        <span class="font-weight-bold"><?= htmlspecialchars($v['bp']) ?></span>
                                        <small class="text-muted">mmHg</small>
                                    </div>
                                    <?php if (!empty($bp_status_text)): ?>
                                        <span class="health-status <?= $bp_status_class ?> mt-1">
                                            <?= htmlspecialchars($bp_status_text) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['sugar'])): ?>
                                    <div>
                                        <span class="font-weight-bold"><?= htmlspecialchars($v['sugar']) ?></span>
                                        <small class="text-muted">mmol/L</small>
                                    </div>
                                    <?php if (!empty($sugar_status_text)): ?>
                                        <span class="health-status <?= $sugar_status_class ?> mt-1">
                                            <?= htmlspecialchars($sugar_status_text) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['hepatitis_b'])): ?>
                                    <?php if (strtolower($v['hepatitis_b']) === 'positive'): ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            Positive
                                        </span>
                                    <?php elseif (strtolower($v['hepatitis_b']) === 'negative'): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check mr-1"></i>
                                            Negative
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <?= htmlspecialchars($v['hepatitis_b']) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['malaria'])): ?>
                                    <?php if (strtolower($v['malaria']) === 'positive'): ?>
                                        <span class="badge badge-danger">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            Positive
                                        </span>
                                    <?php elseif (strtolower($v['malaria']) === 'negative'): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check mr-1"></i>
                                            Negative
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">
                                            <?= htmlspecialchars($v['malaria']) ?>
                                        </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($rec['notes'])): ?>
                                    <div class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($rec['notes']) ?>">
                                        <?= htmlspecialchars($rec['notes']) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">No notes</span>
                                <?php endif; ?>
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
                        <nav aria-label="Health records pagination">
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
        <?php else: ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-clipboard-list text-muted" style="font-size: 4rem;"></i>
                </div>
                <h5 class="text-muted mb-2">No Health Records Found</h5>
                <p class="text-muted mb-4">
                    <?php if ($date_from || $date_to || $search): ?>
                        No health records found matching your filter criteria.
                    <?php else: ?>
                        You don't have any health records yet. Visit the health center to get your first checkup recorded.
                    <?php endif; ?>
                </p>
                <a href="member_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Enhanced JavaScript Functions -->
<script>
// Export health data to CSV
function exportHealthData() {
    const table = document.querySelector('.records-table');
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
        link.setAttribute('download', 'health_records_<?= date('Y-m-d') ?>.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
}

// Print health report
function printHealthReport() {
    window.print();
}

// Generate health summary
function generateHealthSummary() {
    alert('Health summary feature coming soon!');
}
</script>

<!-- Custom CSS for Health Records -->
<style>
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

.border-left-primary {
    border-left: 4px solid #007bff !important;
}

.border-left-success {
    border-left: 4px solid #28a745 !important;
}

.health-score-circle {
    background: rgba(255,255,255,0.1);
    border-radius: 50%;
    padding: 20px;
    display: inline-block;
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

<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
