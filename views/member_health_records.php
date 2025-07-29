<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/member_auth.php';

$member_id = $_SESSION['member_id'];

// Fetch member info
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, crn FROM members WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $member_id);
$stmt->execute();
$stmt->bind_result($first_name, $middle_name, $last_name, $crn);
$stmt->fetch();
$stmt->close();
$member_name = trim("$last_name $first_name $middle_name");

// Fetch all health records for this member
$stmt2 = $conn->prepare('SELECT * FROM health_records WHERE member_id = ? ORDER BY recorded_at DESC');
$stmt2->bind_param('i', $member_id);
$stmt2->execute();
$result = $stmt2->get_result();

// Calculate health statistics
$total_records = $result->num_rows;
$latest_record = null;
$avg_bp_sys = 0;
$avg_bp_dia = 0;
$bp_count = 0;
$normal_bp_count = 0;

if ($total_records > 0) {
    $result->data_seek(0);
    $records = [];
    while ($rec = $result->fetch_assoc()) {
        $records[] = $rec;
        $v = json_decode($rec['vitals'], true) ?: [];
        
        if (!empty($v['bp']) && strpos($v['bp'], '/') !== false) {
            list($sys, $dia) = explode('/', $v['bp'], 2);
            $sys = (int)$sys;
            $dia = (int)$dia;
            $avg_bp_sys += $sys;
            $avg_bp_dia += $dia;
            $bp_count++;
            
            // Check if BP is normal (< 120/80)
            if ($sys < 120 && $dia < 80) {
                $normal_bp_count++;
            }
        }
    }
    
    $latest_record = $records[0];
    if ($bp_count > 0) {
        $avg_bp_sys = round($avg_bp_sys / $bp_count);
        $avg_bp_dia = round($avg_bp_dia / $bp_count);
    }
}

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

<!-- Page Header -->
<div class="page-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div>
            <h1 class="mb-0">
                <i class="fas fa-heartbeat mr-3"></i>
                My Health Records
            </h1>
            <p class="subtitle mb-0">Track your health journey with detailed records and insights</p>
        </div>
        <div class="text-right d-none d-md-block">
            <div class="text-white-50 small">Member</div>
            <div class="font-weight-bold"><?= htmlspecialchars($member_name) ?></div>
        </div>
    </div>
</div>

<!-- Health Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card health-card h-100">
            <div class="card-body text-center">
                <div class="stat-icon gradient-primary mx-auto">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-value"><?= $total_records ?></div>
                <div class="stat-label">Total Records</div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card health-card h-100">
            <div class="card-body text-center">
                <div class="stat-icon gradient-info mx-auto">
                    <i class="fas fa-heartbeat"></i>
                </div>
                <div class="stat-value">
                    <?= $bp_count > 0 ? $avg_bp_sys . '/' . $avg_bp_dia : 'N/A' ?>
                </div>
                <div class="stat-label">Average BP</div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card health-card h-100">
            <div class="card-body text-center">
                <div class="stat-icon gradient-success mx-auto">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-value">
                    <?= $bp_count > 0 ? round(($normal_bp_count / $bp_count) * 100) . '%' : 'N/A' ?>
                </div>
                <div class="stat-label">Normal BP Rate</div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="card health-card h-100">
            <div class="card-body text-center">
                <div class="stat-icon gradient-warning mx-auto">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="stat-value">
                    <?= $latest_record ? date('M d', strtotime($latest_record['recorded_at'])) : 'N/A' ?>
                </div>
                <div class="stat-label">Last Checkup</div>
            </div>
        </div>
    </div>
</div>

<?php 
// Reset result for graph
$result->data_seek(0);
$all_result = $result; 
include __DIR__.'/partials/health_bp_graph.php'; 
$result->data_seek(0); 
?>
<!-- Health Records Table -->
<div class="card health-card">
    <div class="card-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 1.5rem;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h5 class="mb-0 font-weight-bold">
                    <i class="fas fa-table mr-2"></i>
                    Detailed Health Records
                </h5>
                <small class="text-white-50 mt-1">Complete history of your health checkups</small>
            </div>
            <div class="text-right d-none d-md-block">
                <div class="text-white-50 small">Total Records</div>
                <div class="h4 mb-0 font-weight-bold"><?= $total_records ?></div>
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
                    $result->data_seek(0);
                    while($rec = $result->fetch_assoc()): 
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
        <?php else: ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-clipboard-list text-muted" style="font-size: 4rem;"></i>
                </div>
                <h5 class="text-muted mb-2">No Health Records Found</h5>
                <p class="text-muted mb-4">You don't have any health records yet. Visit the health center to get your first checkup recorded.</p>
                <a href="<?= BASE_URL ?>/views/member_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Dashboard
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
