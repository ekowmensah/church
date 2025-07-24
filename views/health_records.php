<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_member_health_records')) {
    // Show 403 error with fallback
    http_response_code(403);
    if (file_exists(__DIR__ . '/../errors/403.php')) {
        include __DIR__ . '/../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Validate health record ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$id) {
    header('Location: ' . BASE_URL . '/views/health_list.php');
    exit;
}

// Store print/export flags for later processing
$is_print_request = isset($_GET['print']) && $_GET['print'] == 1;
$is_export_request = isset($_GET['export']) && $_GET['export'] === 'single';

// Fetch the current health record with comprehensive data
$stmt = $conn->prepare("
    SELECT hr.*, 
           m.first_name AS m_first_name, m.last_name AS m_last_name, m.crn, m.phone AS m_phone,
           ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.srn,
           u.name AS recorded_by,
           c.name AS church_name,
           bc.name AS class_name
    FROM health_records hr 
    LEFT JOIN members m ON hr.member_id = m.id 
    LEFT JOIN sunday_school ss ON hr.sundayschool_id = ss.id 
    LEFT JOIN users u ON hr.recorded_by = u.id
    LEFT JOIN churches c ON (m.church_id = c.id OR ss.church_id = c.id)
    LEFT JOIN bible_classes bc ON (m.class_id = bc.id OR ss.class_id = bc.id)
    WHERE hr.id = ? LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

if (!($row = $result->fetch_assoc())) {
    header('Location: ' . BASE_URL . '/views/health_list.php?error=record_not_found');
    exit;
}

// Extract person information
$member_id = $row['member_id'];
$sundayschool_id = $row['sundayschool_id'];
$person_type = $member_id ? 'Member' : 'Child';
$person_id = $member_id ? $row['crn'] : $row['srn'];
$person_name = trim(($member_id ? $row['m_last_name'] : $row['ss_last_name']) . ' ' . ($member_id ? $row['m_first_name'] : $row['ss_first_name']));
$person_phone = $row['m_phone'] ?? '';
$church_name = $row['church_name'] ?? 'N/A';
$class_name = $row['class_name'] ?? 'N/A';

// Parse health data
$vitals = json_decode($row['vitals'], true) ?: [];
$notes = $row['notes'];
$recorded_at = $row['recorded_at'];
$recorded_by = $row['recorded_by'];

// Fetch all health records for this person (for trends and history)
if ($member_id) {
    $stmt2 = $conn->prepare("SELECT * FROM health_records WHERE member_id = ? ORDER BY recorded_at DESC");
    $stmt2->bind_param('i', $member_id);
} else {
    $stmt2 = $conn->prepare("SELECT * FROM health_records WHERE sundayschool_id = ? ORDER BY recorded_at DESC");
    $stmt2->bind_param('i', $sundayschool_id);
}
$stmt2->execute();
$all_result = $stmt2->get_result();

// Calculate health statistics and trends
$health_stats = calculateHealthStats($all_result);
$all_result->data_seek(0); // Reset for later use

// Permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_health_record');
$can_edit = $is_super_admin || has_permission('edit_health_record');
$can_export = $is_super_admin || has_permission('export_health_records');

/**
 * Calculate health statistics and trends from all records
 */
function calculateHealthStats($result) {
    $stats = [
        'total_records' => 0,
        'latest_weight' => null,
        'weight_trend' => 'stable',
        'bp_status' => 'normal',
        'last_checkup' => null,
        'avg_temperature' => null,
        'risk_factors' => []
    ];
    
    $weights = [];
    $temperatures = [];
    $bp_readings = [];
    
    while ($record = $result->fetch_assoc()) {
        $stats['total_records']++;
        $vitals = json_decode($record['vitals'], true) ?: [];
        
        if (!empty($vitals['weight'])) {
            $weights[] = floatval($vitals['weight']);
        }
        
        if (!empty($vitals['temperature'])) {
            $temperatures[] = floatval($vitals['temperature']);
        }
        
        if (!empty($vitals['bp'])) {
            $bp_readings[] = $vitals['bp'];
        }
        
        if (!$stats['last_checkup']) {
            $stats['last_checkup'] = $record['recorded_at'];
        }
    }
    
    // Calculate trends
    if (count($weights) >= 2) {
        $stats['latest_weight'] = end($weights);
        $weight_change = end($weights) - $weights[count($weights) - 2];
        $stats['weight_trend'] = $weight_change > 2 ? 'increasing' : ($weight_change < -2 ? 'decreasing' : 'stable');
    }
    
    if (!empty($temperatures)) {
        $stats['avg_temperature'] = array_sum($temperatures) / count($temperatures);
    }
    
    // Determine BP status from latest reading
    if (!empty($bp_readings)) {
        $latest_bp = end($bp_readings);
        if (strpos($latest_bp, '/') !== false) {
            list($sys, $dia) = explode('/', $latest_bp);
            if ($sys >= 140 || $dia >= 90) {
                $stats['bp_status'] = 'high';
                $stats['risk_factors'][] = 'High Blood Pressure';
            } elseif ($sys < 90 || $dia < 60) {
                $stats['bp_status'] = 'low';
                $stats['risk_factors'][] = 'Low Blood Pressure';
            }
        }
    }
    
    return $stats;
}

// Handle print request after data is available
if ($is_print_request) {
    include __DIR__ . '/partials/health_print.php';
    exit;
}

// Handle CSV export after data is available
if ($is_export_request) {
    include __DIR__ . '/partials/health_export.php';
    exit;
}

ob_start();
?>

<!-- Custom CSS for Health Records -->
<style>
.health-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    color: white;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.health-stat-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
    border: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.health-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.vital-sign-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    border-left: 4px solid #007bff;
    transition: all 0.2s ease;
}

.vital-sign-card:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    transform: translateX(2px);
}

.vital-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 0.25rem;
}

.vital-label {
    font-size: 0.9rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    font-weight: 500;
}

.status-badge {
    font-size: 0.75rem;
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-normal { background: #d4edda; color: #155724; }
.status-warning { background: #fff3cd; color: #856404; }
.status-danger { background: #f8d7da; color: #721c24; }

.trend-icon {
    font-size: 1.2rem;
    margin-left: 0.5rem;
}

.trend-up { color: #28a745; }
.trend-down { color: #dc3545; }
.trend-stable { color: #6c757d; }

.health-timeline {
    position: relative;
    padding-left: 2rem;
}

.health-timeline::before {
    content: '';
    position: absolute;
    left: 1rem;
    top: 0;
    bottom: 0;
    width: 2px;
    background: linear-gradient(to bottom, #007bff, #6f42c1);
}

.timeline-item {
    position: relative;
    margin-bottom: 2rem;
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -2.25rem;
    top: 1.5rem;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
    border: 3px solid white;
    box-shadow: 0 0 0 3px #007bff;
}

.action-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-modern {
    border-radius: 8px;
    padding: 0.6rem 1.2rem;
    font-weight: 500;
    border: none;
    transition: all 0.2s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.btn-modern:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    text-decoration: none;
}

/* Enhanced Timeline Styles */
.timeline-item:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.timeline-item:hover::before {
    background: #28a745;
    box-shadow: 0 0 0 3px #28a745;
}

/* Health Statistics Cards */
.health-stat-card {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-radius: 12px;
    padding: 1.5rem;
    border: 1px solid #dee2e6;
    transition: all 0.3s ease;
    height: 100%;
    text-align: center;
}

.health-stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
    border-color: #007bff;
}

/* Gradient Headers */
.bg-gradient-primary {
    background: linear-gradient(135deg, #007bff 0%, #6f42c1 100%) !important;
}

/* Empty State */
.empty-state {
    padding: 3rem 1rem;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    opacity: 0.5;
    margin-bottom: 1rem;
}

/* Badge Enhancements */
.badge-sm {
    font-size: 0.7rem;
    padding: 0.25rem 0.6rem;
}

/* Animation Classes */
.fade-in {
    animation: fadeIn 0.6s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .health-timeline {
        padding-left: 1rem;
    }
    
    .health-timeline::before {
        left: 0.5rem;
    }
    
    .timeline-item::before {
        left: -1.75rem;
    }
    
    .vital-sign-card {
        margin-bottom: 0.75rem;
    }
    
    .health-stat-card {
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .vital-value {
        font-size: 1.5rem;
    }
    
    .action-buttons {
        justify-content: center;
    }
}

@media (max-width: 576px) {
    .timeline-item {
        padding: 1rem;
    }
    
    .vital-sign-card {
        padding: 1rem;
    }
    
    .btn-modern {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }
}

/* Timeline Collapse/Expand Styles */
.timeline-toggle {
    transition: all 0.3s ease;
    outline: none !important;
    box-shadow: none !important;
}

.timeline-toggle:hover {
    transform: scale(1.1);
    opacity: 0.8;
}

.timeline-toggle:focus {
    outline: none !important;
    box-shadow: none !important;
}

.timeline-chevron {
    transition: transform 0.3s ease;
    font-size: 1rem;
}

.timeline-chevron.fa-spin {
    animation: spin 0.3s linear;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Enhanced collapse animation */
#healthTimeline {
    transition: all 0.4s ease-in-out;
}

#healthTimeline.collapsing {
    opacity: 0.7;
    transform: translateY(-10px);
}

#healthTimeline.show {
    opacity: 1;
    transform: translateY(0);
}

/* Timeline header enhancements */
.card-header .btn-link {
    color: inherit !important;
    text-decoration: none !important;
}

.card-header .btn-link:hover {
    color: inherit !important;
    text-decoration: none !important;
}

/* Print Styles */
@media print {
    .btn, .action-buttons, .timeline-toggle {
        display: none !important;
    }
    
    .timeline-item {
        border: 1px solid #000;
        margin-bottom: 1rem;
        page-break-inside: avoid;
    }
    
    .health-stat-card {
        border: 1px solid #000;
    }
    
    .card {
        box-shadow: none;
        border: 1px solid #000;
    }
    
    /* Ensure timeline is always visible when printing */
    #healthTimeline {
        display: block !important;
        opacity: 1 !important;
    }
}

@media (max-width: 768px) {
    .health-header {
        padding: 1.5rem;
        text-align: center;
    }
    
    .action-buttons {
        justify-content: center;
    }
    
    .health-timeline {
        padding-left: 1rem;
    }
    
    .timeline-item::before {
        left: -1.25rem;
    }
}
</style>

<!-- Health Records Header -->
<div class="health-header">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h1 class="h3 mb-2">
                <i class="fas fa-user-md mr-3"></i>
                <?= htmlspecialchars($person_name) ?>
            </h1>
            <div class="d-flex flex-wrap align-items-center mb-2">
                <span class="badge badge-light mr-2 px-3 py-2">
                    <i class="fas fa-id-card mr-1"></i>
                    <?= htmlspecialchars($person_id) ?>
                </span>
                <span class="badge badge-<?= $person_type === 'Member' ? 'primary' : 'success' ?> mr-2 px-3 py-2">
                    <i class="fas fa-<?= $person_type === 'Member' ? 'user' : 'child' ?> mr-1"></i>
                    <?= $person_type ?>
                </span>
                <?php if ($person_phone): ?>
                <span class="badge badge-info mr-2 px-3 py-2">
                    <i class="fas fa-phone mr-1"></i>
                    <?= htmlspecialchars($person_phone) ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="text-light opacity-75">
                <i class="fas fa-church mr-2"></i><?= htmlspecialchars($church_name) ?>
                <i class="fas fa-users ml-3 mr-2"></i><?= htmlspecialchars($class_name) ?>
            </div>
        </div>
        <div class="col-md-4 text-md-right mt-3 mt-md-0">
            <div class="action-buttons">
                <a href="<?= BASE_URL ?>/views/health_list.php" class="btn btn-light btn-modern">
                    <i class="fas fa-arrow-left mr-1"></i> Back to List
                </a>
                <?php if ($can_add): ?>
                <a href="<?= BASE_URL ?>/views/health_form.php?<?= $person_type === 'Member' ? 'member_id=' . $member_id : 'sundayschool_id=' . $sundayschool_id ?>" 
                   class="btn btn-warning btn-modern">
                    <i class="fas fa-plus mr-1"></i> Add Record
                </a>
                <?php endif; ?>
            </div>
            <div class="action-buttons mt-2">
                <button onclick="window.print()" class="btn btn-info btn-modern">
                    <i class="fas fa-print mr-1"></i> Print
                </button>
                <?php if ($can_export): ?>
                <a href="?id=<?= $id ?>&export=single" class="btn btn-success btn-modern">
                    <i class="fas fa-download mr-1"></i> Export
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Health Statistics Dashboard -->
<div class="row mb-4">
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="health-stat-card text-center">
            <div class="h2 text-primary mb-1"><?= $health_stats['total_records'] ?></div>
            <div class="text-muted small">Total Records</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="health-stat-card text-center">
            <div class="h2 text-success mb-1">
                <?= $health_stats['latest_weight'] ? number_format($health_stats['latest_weight'], 1) . ' kg' : 'N/A' ?>
            </div>
            <div class="text-muted small">
                Latest Weight
                <?php if ($health_stats['weight_trend'] !== 'stable'): ?>
                <i class="fas fa-arrow-<?= $health_stats['weight_trend'] === 'increasing' ? 'up trend-up' : 'down trend-down' ?> trend-icon"></i>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="health-stat-card text-center">
            <div class="h2 mb-1">
                <span class="status-badge status-<?= $health_stats['bp_status'] === 'normal' ? 'normal' : ($health_stats['bp_status'] === 'high' ? 'danger' : 'warning') ?>">
                    <?= ucfirst($health_stats['bp_status']) ?>
                </span>
            </div>
            <div class="text-muted small">Blood Pressure</div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 mb-3">
        <div class="health-stat-card text-center">
            <div class="h2 text-info mb-1">
                <?= $health_stats['last_checkup'] ? date('M j', strtotime($health_stats['last_checkup'])) : 'Never' ?>
            </div>
            <div class="text-muted small">Last Checkup</div>
        </div>
    </div>
</div>

<!-- Current Health Status -->
<div class="row mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-heartbeat mr-2"></i>
                    Current Vital Signs
                    <small class="float-right"><?= date('F j, Y \a\t g:i A', strtotime($recorded_at)) ?></small>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <div class="vital-sign-card">
                            <div class="vital-value"><?= htmlspecialchars($vitals['weight'] ?? 'N/A') ?></div>
                            <div class="vital-label">Weight (kg)</div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="vital-sign-card">
                            <div class="vital-value"><?= htmlspecialchars($vitals['temperature'] ?? 'N/A') ?></div>
                            <div class="vital-label">Temperature (°C)</div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="vital-sign-card">
                            <div class="vital-value"><?= htmlspecialchars($vitals['bp'] ?? 'N/A') ?></div>
                            <div class="vital-label">Blood Pressure (mmHg)</div>
                            <?php if (!empty($vitals['bp_status'])): ?>
                            <span class="status-badge status-<?= strpos(strtolower($vitals['bp_status']), 'normal') !== false ? 'normal' : (strpos(strtolower($vitals['bp_status']), 'high') !== false ? 'danger' : 'warning') ?>">
                                <?= htmlspecialchars($vitals['bp_status']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <div class="vital-sign-card">
                            <div class="vital-value"><?= htmlspecialchars($vitals['sugar'] ?? 'N/A') ?></div>
                            <div class="vital-label">Blood Sugar (mmol/L)</div>
                            <?php if (!empty($vitals['sugar_status'])): ?>
                            <span class="status-badge status-<?= strpos(strtolower($vitals['sugar_status']), 'normal') !== false ? 'normal' : 'warning' ?>">
                                <?= htmlspecialchars($vitals['sugar_status']) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Test Results -->
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <span class="font-weight-medium">Hepatitis B Test:</span>
                            <span class="badge badge-<?= !empty($vitals['hepatitis_b']) && strtolower($vitals['hepatitis_b']) === 'positive' ? 'danger' : 'success' ?> px-3 py-2">
                                <?= htmlspecialchars($vitals['hepatitis_b'] ?? 'Not Tested') ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                            <span class="font-weight-medium">Malaria Test:</span>
                            <span class="badge badge-<?= !empty($vitals['malaria']) && strtolower($vitals['malaria']) === 'positive' ? 'danger' : 'success' ?> px-3 py-2">
                                <?= htmlspecialchars($vitals['malaria'] ?? 'Not Tested') ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Notes Section -->
                <?php if ($notes): ?>
                <div class="mt-4">
                    <h6 class="text-muted mb-2">Medical Notes:</h6>
                    <div class="p-3 bg-light rounded">
                        <p class="mb-0"><?= nl2br(htmlspecialchars($notes)) ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="mt-3 text-muted small">
                    <i class="fas fa-user-md mr-1"></i>
                    Recorded by: <strong><?= htmlspecialchars($recorded_by) ?></strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Risk Factors & Alerts -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Health Alerts
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($health_stats['risk_factors'])): ?>
                    <?php foreach ($health_stats['risk_factors'] as $risk): ?>
                    <div class="alert alert-warning py-2 px-3 mb-2">
                        <i class="fas fa-warning mr-2"></i>
                        <?= htmlspecialchars($risk) ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-success py-2 px-3 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>
                        No risk factors identified
                    </div>
                <?php endif; ?>
                
                <!-- Quick Stats -->
                <div class="mt-4">
                    <h6 class="text-muted mb-3">Quick Stats</h6>
                    <?php if ($health_stats['avg_temperature']): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Avg Temperature:</span>
                        <strong><?= number_format($health_stats['avg_temperature'], 1) ?>°C</strong>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Total Checkups:</span>
                        <strong><?= $health_stats['total_records'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Weight Trend:</span>
                        <strong class="text-<?= $health_stats['weight_trend'] === 'increasing' ? 'success' : ($health_stats['weight_trend'] === 'decreasing' ? 'danger' : 'muted') ?>">
                            <?= ucfirst($health_stats['weight_trend']) ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Blood Pressure Chart -->
<?php include __DIR__.'/partials/health_bp_graph.php'; $all_result->data_seek(0); ?>

<!-- Health Records Timeline -->
<div class="card shadow mb-4">
    <div class="card-header bg-gradient-primary text-white">
        <h5 class="mb-0">
            <button class="btn btn-link text-white p-0 mr-2 timeline-toggle" type="button" data-toggle="collapse" data-target="#healthTimeline" aria-expanded="true" aria-controls="healthTimeline" style="text-decoration: none; border: none; background: none;">
                <i class="fas fa-chevron-down timeline-chevron"></i>
            </button>
            <i class="fas fa-history mr-2"></i>
            Health Records Timeline
            <small class="float-right"><?= $health_stats['total_records'] ?> Records</small>
        </h5>
    </div>
    <div class="collapse show" id="healthTimeline">
        <div class="card-body p-4">
        <?php if ($health_stats['total_records'] > 0): ?>
        <div class="health-timeline">
            <?php while($rec = $all_result->fetch_assoc()): 
                $v = json_decode($rec['vitals'], true) ?: [];
                $record_date = date('F j, Y', strtotime($rec['recorded_at']));
                $record_time = date('g:i A', strtotime($rec['recorded_at']));
                
                // Get recorder name
                $stmt3 = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
                $stmt3->bind_param('i', $rec['recorded_by']);
                $stmt3->execute();
                $stmt3->bind_result($recorder_name);
                $stmt3->fetch();
                $stmt3->close();
            ?>
            <div class="timeline-item">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h6 class="text-primary mb-1"><?= $record_date ?></h6>
                        <small class="text-muted"><?= $record_time ?></small>
                    </div>
                    <div class="text-right">
                        <?php if ($can_edit): ?>
                        <a href="<?= BASE_URL ?>/views/health_form.php?id=<?= $rec['id'] ?>" 
                           class="btn btn-sm btn-outline-primary mr-1" title="Edit Record">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php endif; ?>
                        <a href="?id=<?= $rec['id'] ?>&print=1" 
                           class="btn btn-sm btn-outline-secondary" title="Print Record" target="_blank">
                            <i class="fas fa-print"></i>
                        </a>
                    </div>
                </div>
                
                <!-- Vital Signs Grid -->
                <div class="row mb-3">
                    <?php if (!empty($v['weight'])): ?>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="d-flex align-items-center p-2 bg-light rounded">
                            <i class="fas fa-weight text-primary mr-2"></i>
                            <div>
                                <div class="font-weight-bold"><?= htmlspecialchars($v['weight']) ?> kg</div>
                                <small class="text-muted">Weight</small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($v['temperature'])): ?>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="d-flex align-items-center p-2 bg-light rounded">
                            <i class="fas fa-thermometer-half text-danger mr-2"></i>
                            <div>
                                <div class="font-weight-bold"><?= htmlspecialchars($v['temperature']) ?>°C</div>
                                <small class="text-muted">Temperature</small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($v['bp'])): ?>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="d-flex align-items-center p-2 bg-light rounded">
                            <i class="fas fa-heartbeat text-info mr-2"></i>
                            <div>
                                <div class="font-weight-bold"><?= htmlspecialchars($v['bp']) ?> mmHg</div>
                                <small class="text-muted">Blood Pressure</small>
                                <?php if (!empty($v['bp_status'])): ?>
                                <div class="mt-1">
                                    <span class="badge badge-<?= strpos(strtolower($v['bp_status']), 'normal') !== false ? 'success' : (strpos(strtolower($v['bp_status']), 'high') !== false ? 'danger' : 'warning') ?> badge-sm">
                                        <?= htmlspecialchars($v['bp_status']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($v['sugar'])): ?>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <div class="d-flex align-items-center p-2 bg-light rounded">
                            <i class="fas fa-tint text-warning mr-2"></i>
                            <div>
                                <div class="font-weight-bold"><?= htmlspecialchars($v['sugar']) ?> mmol/L</div>
                                <small class="text-muted">Blood Sugar</small>
                                <?php if (!empty($v['sugar_status'])): ?>
                                <div class="mt-1">
                                    <span class="badge badge-<?= strpos(strtolower($v['sugar_status']), 'normal') !== false ? 'success' : 'warning' ?> badge-sm">
                                        <?= htmlspecialchars($v['sugar_status']) ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Test Results -->
                <?php if (!empty($v['hepatitis_b']) || !empty($v['malaria'])): ?>
                <div class="row mb-3">
                    <?php if (!empty($v['hepatitis_b'])): ?>
                    <div class="col-md-6 mb-2">
                        <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                            <span><i class="fas fa-vial mr-2 text-secondary"></i>Hepatitis B:</span>
                            <span class="badge badge-<?= strtolower($v['hepatitis_b']) === 'positive' ? 'danger' : 'success' ?>">
                                <?= htmlspecialchars($v['hepatitis_b']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($v['malaria'])): ?>
                    <div class="col-md-6 mb-2">
                        <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                            <span><i class="fas fa-bug mr-2 text-secondary"></i>Malaria:</span>
                            <span class="badge badge-<?= strtolower($v['malaria']) === 'positive' ? 'danger' : 'success' ?>">
                                <?= htmlspecialchars($v['malaria']) ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Notes -->
                <?php if (!empty($rec['notes'])): ?>
                <div class="mb-3">
                    <h6 class="text-muted mb-2"><i class="fas fa-sticky-note mr-1"></i>Notes:</h6>
                    <div class="p-3 bg-light rounded border-left border-primary">
                        <?= nl2br(htmlspecialchars($rec['notes'])) ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Footer Info -->
                <div class="d-flex justify-content-between align-items-center text-muted small">
                    <span>
                        <i class="fas fa-user-md mr-1"></i>
                        Recorded by: <strong><?= htmlspecialchars($recorder_name ?? 'Unknown') ?></strong>
                    </span>
                    <span>
                        <i class="fas fa-clock mr-1"></i>
                        <?= date('M j, Y \a\t g:i A', strtotime($rec['recorded_at'])) ?>
                    </span>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-5">
            <i class="fas fa-notes-medical fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">No Health Records Found</h5>
            <p class="text-muted mb-4">This person doesn't have any health records yet.</p>
            <?php if ($can_add): ?>
            <a href="<?= BASE_URL ?>/views/health_form.php?<?= $person_type === 'Member' ? 'member_id=' . $member_id : 'sundayschool_id=' . $sundayschool_id ?>" 
               class="btn btn-primary btn-modern">
                <i class="fas fa-plus mr-2"></i>Add First Health Record
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- JavaScript for Enhanced Functionality -->
<script>
// Print functionality
function printHealthRecord(recordId) {
    window.open('?id=' + recordId + '&print=1', '_blank');
}

// Export functionality
function exportHealthRecords() {
    const data = [];
    const headers = ['Date', 'Weight (kg)', 'Temperature (°C)', 'BP (mmHg)', 'BP Status', 'Sugar (mmol/L)', 'Sugar Status', 'Hepatitis B', 'Malaria', 'Notes', 'Recorded By'];
    
    // Add headers
    data.push(headers.join(','));
    
    // Add data rows from timeline
    document.querySelectorAll('.timeline-item').forEach(item => {
        const date = item.querySelector('h6').textContent;
        const time = item.querySelector('small').textContent;
        const dateTime = date + ' ' + time;
        
        // Extract vital signs and other data
        const weight = item.querySelector('[title="Weight"]')?.textContent || '';
        const temp = item.querySelector('[title="Temperature"]')?.textContent || '';
        const bp = item.querySelector('[title="Blood Pressure"]')?.textContent || '';
        const notes = item.querySelector('.bg-light.rounded.border-left')?.textContent.trim() || '';
        const recorder = item.querySelector('strong')?.textContent || '';
        
        const row = [dateTime, weight, temp, bp, '', '', '', '', '', notes, recorder];
        data.push(row.map(field => '"' + field.replace(/"/g, '""') + '"').join(','));
    });
    
    // Create and download CSV
    const csvContent = data.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'health_records_<?= htmlspecialchars($person_name) ?>_<?= date('Y-m-d') ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Timeline collapse/expand functionality
document.addEventListener('DOMContentLoaded', function() {
    const timelineToggle = document.querySelector('.timeline-toggle');
    const timelineChevron = document.querySelector('.timeline-chevron');
    const healthTimeline = document.getElementById('healthTimeline');
    
    if (timelineToggle && timelineChevron && healthTimeline) {
        // Handle collapse/expand events
        $('#healthTimeline').on('show.bs.collapse', function() {
            timelineChevron.classList.remove('fa-chevron-right');
            timelineChevron.classList.add('fa-chevron-down');
            timelineToggle.setAttribute('aria-expanded', 'true');
        });
        
        $('#healthTimeline').on('hide.bs.collapse', function() {
            timelineChevron.classList.remove('fa-chevron-down');
            timelineChevron.classList.add('fa-chevron-right');
            timelineToggle.setAttribute('aria-expanded', 'false');
        });
        
        // Add smooth animation
        timelineToggle.addEventListener('click', function() {
            const isExpanded = timelineToggle.getAttribute('aria-expanded') === 'true';
            
            // Add loading state
            timelineChevron.classList.add('fa-spin');
            setTimeout(() => {
                timelineChevron.classList.remove('fa-spin');
            }, 300);
        });
    }
    
    // Add export button to header if user has permission
    <?php if ($can_export): ?>
    const exportBtn = document.createElement('button');
    exportBtn.className = 'btn btn-success btn-modern ml-2';
    exportBtn.innerHTML = '<i class="fas fa-download mr-1"></i> Export All';
    exportBtn.onclick = exportHealthRecords;
    
    const actionButtons = document.querySelector('.action-buttons');
    if (actionButtons) {
        actionButtons.appendChild(exportBtn);
    }
    <?php endif; ?>
});
</script>
<?php
// Export single record as CSV if requested
if (isset($_GET['print']) && $_GET['print'] == 1) {
    // Enhanced print-friendly single record view
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Health Record Print</title>';
    echo '<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">';
    echo '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">';
    echo '<style>';
    echo 'body { background: #f8f9fa; }';
    echo '.print-header { text-align:center; margin-bottom:32px; }';
    echo '.print-header .logo { font-size:40px; color:#007bff; margin-bottom:8px; }';
    echo '.print-header .church-name { font-size:1.7rem; font-weight:700; letter-spacing:2px; color:#333; }';
    echo '.print-header .subtitle { font-size:1.1rem; color:#888; margin-bottom:0; }';
    echo '.print-card { max-width:600px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 4px 24px rgba(0,0,0,0.07); padding:40px 32px 32px 32px; }';
    echo '.print-card h2 { font-size:2rem; font-weight:700; color:#007bff; margin-bottom:10px; }';
    echo '.print-card .record-date { font-size:1.1rem; color:#555; font-weight:500; margin-bottom:24px; }';
    echo '.print-section-title { font-size:1.15rem; font-weight:600; color:#444; margin:30px 0 15px 0; letter-spacing:1px; }';
    echo '.print-table { width:100%; margin-bottom:0; }';
    echo '.print-table th { width:40%; background:#f2f5fa; font-weight:600; color:#333; border-top:1px solid #e3e6f0; border-bottom:1px solid #e3e6f0; }';
    echo '.print-table td { background:#fff; color:#222; border-top:1px solid #e3e6f0; border-bottom:1px solid #e3e6f0; }';
    echo '.noprint { display:block; margin-top:24px; }';
    echo '@media print { body { background:#fff; } .noprint { display:none !important; } .print-card { box-shadow:none; border:0; } }';
    echo '</style>';
    echo '</head><body onload="window.print()">';
    echo '<div class="print-header">';
    echo '<div class="logo"><i class="fas fa-clinic-medical"></i></div>';
    echo '<div class="church-name">CHURCH NAME HERE</div>';
    echo '<div class="subtitle">Health Records Management</div>';
    echo '</div>';
    echo '<div class="print-card">';
    echo '<h2>'.htmlspecialchars($member_name).'</h2>';
    echo '<div class="record-date">'.date('l, F j, Y \a\t g:ia', strtotime($recorded_at)).'</div>';
    echo '<div class="print-section-title">Health Record Summary</div>';
    echo '<table class="print-table">';
    echo '<tr><th>Weight (Kg)</th><td>'.htmlspecialchars($vitals['weight'] ?? '').'</td></tr>';
    echo '<tr><th>Temperature (°C)</th><td>'.htmlspecialchars($vitals['temperature'] ?? '').'</td></tr>';
    echo '<tr><th>Blood Pressure (MMHG)</th><td>'.htmlspecialchars($vitals['bp'] ?? '').'</td></tr>';
    echo '<tr><th>BP Status</th><td>'.htmlspecialchars($vitals['bp_status'] ?? '').'</td></tr>';
    echo '<tr><th>Blood Sugar (mmol/L)</th><td>'.htmlspecialchars($vitals['sugar'] ?? '').'</td></tr>';
    echo '<tr><th>Sugar Status</th><td>'.htmlspecialchars($vitals['sugar_status'] ?? '').'</td></tr>';
    echo '<tr><th>Hepatitis B Test</th><td>'.htmlspecialchars($vitals['hepatitis_b'] ?? '').'</td></tr>';
    echo '<tr><th>Malaria Test</th><td>'.htmlspecialchars($vitals['malaria'] ?? '').'</td></tr>';
    echo '<tr><th>Notes</th><td>'.htmlspecialchars($notes).'</td></tr>';
    echo '<tr><th>Recorded By</th><td>'.htmlspecialchars($recorded_by).'</td></tr>';
    echo '</table>';
    echo '<div class="text-center noprint"><a href="javascript:window.close();" class="btn btn-secondary mt-3">Close</a></div>';
    echo '</div>';
    echo '</body></html>';
    exit;
}
if (isset($_GET['export']) && $_GET['export'] === 'single') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=health_record_'.intval($id).'.csv');
    $fields = ['Date/Time','Weight (Kg)','Temperature (°C)','BP (MMHG)','BP Status','Sugar (mmol/L)','Sugar Status','Hep B','Malaria','Notes','Recorded By'];
    $out = fopen('php://output', 'w');
    fputcsv($out, $fields);
    // Output the single record row
    $row = [$recorded_at, $vitals['weight'] ?? '', $vitals['temperature'] ?? '', $vitals['bp'] ?? '', $vitals['bp_status'] ?? '', $vitals['sugar'] ?? '', $vitals['sugar_status'] ?? '', $vitals['hepatitis_b'] ?? '', $vitals['malaria'] ?? '', $notes, $recorded_by];
    fputcsv($out, $row);
    fclose($out);
    exit;
}
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
