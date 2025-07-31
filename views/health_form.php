<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

?>
<script>
    window.BASE_URL = "<?= htmlspecialchars(BASE_URL) ?>";
</script>
<?php
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
// Allow Super Admin (role_id==1 or role name 'Super Admin') to always access
$super_admin = false;
$role_id = $_SESSION['role_id'] ?? 0;
if ($role_id == 1) {
    $super_admin = true;
} else {
    $stmt = $conn->prepare("SELECT name FROM roles WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $role_id);
    $stmt->execute();
    $stmt->bind_result($role_name);
    $stmt->fetch();
    $stmt->close();
    if ($role_name === 'Super Admin') {
        $super_admin = true;
    }
}
if (!$super_admin && !has_permission('create_health_record')) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$editing = $id > 0;
$record = [
    'member_id' => '',
    'crn' => '',
    'vitals' => '',
    'notes' => '',
    'recorded_at' => date('Y-m-d\TH:i'),
];
// Prefill CRN/member_id if adding (not editing)
if (!$editing) {
    include __DIR__.'/health_form_prefill.php';
    if (!empty($prefill_crn)) {
        $record['crn'] = $prefill_crn;
    }
    if (!empty($prefill_member_id)) {
        $record['member_id'] = $prefill_member_id;
    }
}

$error = '';

$vitals = [];
// Fetch members for dropdown
$members = $conn->query("SELECT id, first_name, last_name FROM members ORDER BY first_name, last_name");
// On edit, fetch record
if ($editing) {
    $stmt = $conn->prepare("SELECT * FROM health_records WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $record = $row;
        $record['recorded_at'] = date('Y-m-d\TH:i', strtotime($row['recorded_at']));
        $vitals = json_decode($row['vitals'], true) ?: [];
    } else {
        $error = 'Health record not found.';
        $editing = false;
    }
    $stmt->close();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_id = intval($_POST['member_id'] ?? 0);
    $sundayschool_id = intval($_POST['sundayschool_id'] ?? 0);
    if (!$member_id) $member_id = null;
    if (!$sundayschool_id) $sundayschool_id = null;
    $vitals = $_POST['vitals'] ?? [];
    $notes = trim($_POST['notes'] ?? '');
    $recorded_at = trim($_POST['recorded_at'] ?? '');
    $recorded_by = $_SESSION['user_id'];
    // Validate
    if (!$member_id && !$sundayschool_id) {
        $error = 'Please select a member or Sunday School child.';
    } elseif ($member_id && $sundayschool_id) {
        $error = 'Cannot select both member and Sunday School child.';
    } elseif (empty($vitals['weight']) && empty($vitals['temperature']) && empty($vitals['bp']) && empty($vitals['sugar']) && empty($vitals['hepatitis_b']) && empty($vitals['malaria'])) {
        $error = 'At least one vital field is required.';
    } elseif (!$recorded_at) {
        $error = 'Date/time is required.';
    } else {
        // Prepare BP as systolic/diastolic string for storage
        if (isset($_POST['vitals']['bp_systolic']) && isset($_POST['vitals']['bp_diastolic'])) {
            $_POST['vitals']['bp'] = $_POST['vitals']['bp_systolic'] . '/' . $_POST['vitals']['bp_diastolic'];
            // Calculate status
            $sys = intval($_POST['vitals']['bp_systolic']);
            $dia = intval($_POST['vitals']['bp_diastolic']);
            if ($sys >= 140 || $dia >= 90) {
                $_POST['vitals']['bp_status'] = 'high';
            } elseif ($sys < 90 || $dia < 60) {
                $_POST['vitals']['bp_status'] = 'low';
            } else {
                $_POST['vitals']['bp_status'] = 'normal';
            }
        }
        // Prepare Sugar status
        if (isset($_POST['vitals']['sugar'])) {
            $sugar = floatval($_POST['vitals']['sugar']);
            if ($sugar >= 7.0) {
                $_POST['vitals']['sugar_status'] = 'high';
            } elseif ($sugar < 4.0) {
                $_POST['vitals']['sugar_status'] = 'low';
            } else {
                $_POST['vitals']['sugar_status'] = 'normal';
            }
        }
        // Prepare vitals as JSON
        $vitals_json = json_encode($_POST['vitals']);
        if ($editing) {
            $stmt = $conn->prepare("UPDATE health_records SET member_id=?, sundayschool_id=?, vitals=?, notes=?, recorded_at=?, recorded_by=? WHERE id=?");
            $stmt->bind_param('iiisssi', $member_id, $sundayschool_id, $vitals_json, $notes, $recorded_at, $recorded_by, $id);
            $ok = $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO health_records (member_id, sundayschool_id, vitals, notes, recorded_at, recorded_by) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iisssi', $member_id, $sundayschool_id, $vitals_json, $notes, $recorded_at, $recorded_by);
            $ok = $stmt->execute();
        }
        if ($ok) {
            header('Location: health_list.php?msg=saved');
            exit;
        } else {
            $error = 'Database error: ' . $stmt->error;
        }
    }
    // Repopulate form
    $record = [
        'member_id' => $member_id,
        'sundayschool_id' => $sundayschool_id,
        'vitals' => $vitals,
        'notes' => $notes,
        'recorded_at' => $recorded_at,
    ];
}
ob_start();
?>
<!-- Custom CSS for Health Form -->
<style>
.health-form-container {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
    padding: 2rem 0;
}

.health-card {
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.1);
    border: none;
    overflow: hidden;
}

.health-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 2rem;
    color: white;
    position: relative;
}

.health-header::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="%23ffffff" opacity="0.1"/><circle cx="80" cy="80" r="2" fill="%23ffffff" opacity="0.1"/><circle cx="40" cy="60" r="1" fill="%23ffffff" opacity="0.1"/></svg>');
}

.health-title {
    font-size: 1.8rem;
    font-weight: 600;
    margin: 0;
    position: relative;
    z-index: 1;
}

.health-subtitle {
    opacity: 0.9;
    margin-top: 0.5rem;
    position: relative;
    z-index: 1;
}

.section-card {
    background: #fff;
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
    border: 1px solid #e3f2fd;
    transition: all 0.3s ease;
}

.section-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.12);
}

.section-title {
    color: #667eea;
    font-weight: 600;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    font-size: 1.1rem;
}

.section-icon {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.75rem;
    font-size: 0.9rem;
}

.form-control {
    border-radius: 10px;
    border: 2px solid #e3f2fd;
    padding: 0.75rem 1rem;
    transition: all 0.3s ease;
}

.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn-health {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    border-radius: 25px;
    padding: 0.75rem 2rem;
    color: white;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-health:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.3);
    color: white;
}

.btn-outline-health {
    border: 2px solid #667eea;
    color: #667eea;
    border-radius: 25px;
    padding: 0.5rem 1.5rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-outline-health:hover {
    background: #667eea;
    color: white;
    transform: translateY(-1px);
}

.member-photo {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    border: 4px solid #667eea;
    object-fit: cover;
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
}

.status-badge {
    border-radius: 20px;
    padding: 0.5rem 1rem;
    font-weight: 600;
    font-size: 0.9rem;
}

.alert-modern {
    border-radius: 15px;
    border: none;
    padding: 1rem 1.5rem;
}

.input-group .form-control {
    border-radius: 10px 0 0 10px;
}

.input-group-append .btn {
    border-radius: 0 10px 10px 0;
}

@media (max-width: 768px) {
    .health-form-container {
        padding: 1rem;
    }
    
    .health-header {
        padding: 1.5rem;
    }
    
    .section-card {
        padding: 1rem;
    }
}
</style>

<div class="health-form-container">
<div class="container">
<div class="row justify-content-center">
<div class="col-lg-10">
<div class="card health-card">
    <div class="health-header">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <h1 class="health-title">
                    <i class="fas fa-heartbeat mr-3"></i>
                    <?= $editing ? 'Edit Health Record' : 'New Health Record' ?>
                </h1>
                <p class="health-subtitle mb-0">
                    <?= $editing ? 'Update member health information' : 'Record comprehensive health data for church members' ?>
                </p>
            </div>
            <a href="health_list.php" class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left mr-2"></i>Back to Records
            </a>
        </div>
    </div>
    <div class="card-body p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger alert-modern mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="post" autocomplete="off">
            <!-- Member Search Section -->
            <div class="section-card">
                <h5 class="section-title">
                    <span class="section-icon"><i class="fas fa-search"></i></span>
                    Member Identification
                </h5>
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <label for="crn" class="form-label font-weight-bold">
                            <i class="fas fa-id-card mr-2 text-primary"></i>
                            Church Registration Number (CRN)
                            <span class="text-danger">*</span>
                        </label>
                        <div class="input-group input-group-lg">
                            <input type="text" class="form-control" id="crn" name="crn" 
                                   placeholder="Enter CRN or SRN" 
                                   value="<?= htmlspecialchars($record['crn'] ?? '') ?>" 
                                   autocomplete="off" required>
                            <div class="input-group-append">
                                <button class="btn btn-outline-health" type="button" id="search_crn_btn">
                                    <i class="fas fa-search mr-2"></i>Find Member
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Enter the member's CRN or Sunday School child's SRN
                        </small>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="recorded_at" class="form-label font-weight-bold">
                                <i class="fas fa-calendar-alt mr-2 text-primary"></i>
                                Date & Time <span class="text-danger">*</span>
                            </label>
                            <input type="datetime-local" class="form-control form-control-lg" 
                                   id="recorded_at" name="recorded_at" 
                                   value="<?= htmlspecialchars($record['recorded_at']) ?>" required>
                        </div>
                    </div>
                </div>
                
                <input type="hidden" id="member_id" name="member_id" value="<?= htmlspecialchars($record['member_id']) ?>">
                <input type="hidden" id="sundayschool_id" name="sundayschool_id" value="<?= htmlspecialchars($record['sundayschool_id'] ?? '') ?>">
                
                <div class="row mt-3" id="crn_error_box" style="display:none;">
                    <div class="col-12">
                        <div class="alert alert-warning alert-modern" id="crn_error_msg"></div>
                    </div>
                </div>
                <div class="row member-info-summary mt-4" id="member_info_summary" style="display:none;">
                    <div class="col-12">
                        <div class="bg-light p-4 rounded-lg border">
                            <h6 class="text-success mb-3">
                                <i class="fas fa-check-circle mr-2"></i>Member Found
                            </h6>
                            <div class="row">
                                <div class="col-md-3 text-center">
                                    <img id="member_photo"
     src="<?= htmlspecialchars(rtrim($base_url ?? BASE_URL, '/')) ?>/assets/img/undraw_profile.svg"
     alt="Member Photo"
     class="member-photo mb-3"
     style="cursor: pointer;"
     data-toggle="modal"
     data-target="#photoModal"
     title="Click to view full size">
                                    <div class="text-center">
                                        <small class="text-muted font-weight-bold">Member Photo</small>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label font-weight-bold text-primary">
                                                <i class="fas fa-user mr-2"></i>Full Name
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="member_name" readonly>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label font-weight-bold text-primary">
                                                <i class="fas fa-graduation-cap mr-2"></i>Bible Class
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="member_class" readonly>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label font-weight-bold text-primary">
                                                <i class="fas fa-phone mr-2"></i>Phone Number
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="member_phone" readonly>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label font-weight-bold text-primary">
                                                <i class="fas fa-birthday-cake mr-2"></i>Age
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="member_age" readonly>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label font-weight-bold text-primary">
                                                <i class="fas fa-venus-mars mr-2"></i>Gender
                                            </label>
                                            <input type="text" class="form-control form-control-lg" id="member_gender" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- Sunday School Child Extra Fields -->
                            <div class="row mt-3" id="child_parent_fields" style="display:none;">
                                <div class="col-md-6">
                                    <label class="form-label font-weight-bold text-muted">Parents Information</label>
                                    <div class="row">
                                        <div class="col-6">
                                            <input type="text" class="form-control mb-2" id="father_name" placeholder="Father's Name" readonly>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" class="form-control mb-2" id="father_contact" placeholder="Father's Contact" readonly>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" class="form-control" id="mother_name" placeholder="Mother's Name" readonly>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" class="form-control" id="mother_contact" placeholder="Mother's Contact" readonly>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6" id="child_school_field">
                                    <label class="form-label font-weight-bold text-muted">School Information</label>
                                    <input type="text" class="form-control" id="school_attend" placeholder="School Attended" readonly>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Health Data Sections -->
            <div class="member-sections" style="display:none;">
                <!-- Vitals Section -->
                <div class="section-card">
                    <h5 class="section-title">
                        <span class="section-icon"><i class="fas fa-stethoscope"></i></span>
                        Vital Signs
                    </h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="weight" class="form-label font-weight-bold">
                                    <i class="fas fa-weight mr-2 text-info"></i>Weight (Kg)
                                </label>
                                <input type="number" step="0.1" class="form-control form-control-lg" 
                                       id="weight" name="vitals[weight]" 
                                       value="<?= htmlspecialchars($vitals['weight'] ?? '') ?>"
                                       placeholder="e.g. 70.5">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="temperature" class="form-label font-weight-bold">
                                    <i class="fas fa-thermometer-half mr-2 text-warning"></i>Temperature (°C)
                                </label>
                                <input type="number" step="0.1" class="form-control form-control-lg" 
                                       id="temperature" name="vitals[temperature]" 
                                       value="<?= htmlspecialchars($vitals['temperature'] ?? '') ?>"
                                       placeholder="e.g. 36.5">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label font-weight-bold">
                                    <i class="fas fa-heartbeat mr-2 text-danger"></i>Blood Pressure (mmHg)
                                </label>
                                <div class="input-group input-group-lg">
                                    <input type="number" min="0" class="form-control" 
                                           id="bp_systolic" name="vitals[bp_systolic]" 
                                           value="<?= htmlspecialchars($vitals['bp_systolic'] ?? (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? explode('/', $vitals['bp'])[0] : '')) ?>" 
                                           placeholder="120">
                                    <div class="input-group-append input-group-prepend">
                                        <span class="input-group-text bg-primary text-white">/</span>
                                    </div>
                                    <input type="number" min="0" class="form-control" 
                                           id="bp_diastolic" name="vitals[bp_diastolic]" 
                                           value="<?= htmlspecialchars($vitals['bp_diastolic'] ?? (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? explode('/', $vitals['bp'])[1] : '')) ?>" 
                                           placeholder="80">
                                </div>
                                <small class="form-text text-muted mt-1">
                                    <i class="fas fa-info-circle mr-1"></i>Systolic / Diastolic
                                </small>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="form-label font-weight-bold">
                                    <i class="fas fa-chart-line mr-2 text-success"></i>BP Status
                                </label>
                                <div id="bp_status_display" class="mt-2">
                                    <?php
                                        $sys = isset($vitals['bp_systolic']) ? intval($vitals['bp_systolic']) : (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? intval(explode('/', $vitals['bp'])[0]) : null);
                                        $dia = isset($vitals['bp_diastolic']) ? intval($vitals['bp_diastolic']) : (isset($vitals['bp']) && strpos($vitals['bp'], '/')!==false ? intval(explode('/', $vitals['bp'])[1]) : null);
                                        $bp_status = '';
                                        $bp_color = '';
                                        if (isset($vitals['bp_status']) && $vitals['bp_status']) {
                                            $bp_status = ucfirst($vitals['bp_status']);
                                            if ($bp_status === 'High') $bp_color = '#dc3545';
                                            elseif ($bp_status === 'Low') $bp_color = '#ffc107';
                                            elseif ($bp_status === 'Normal') $bp_color = '#28a745';
                                        } elseif ($sys && $dia) {
                                            if ($sys >= 140 || $dia >= 90) { $bp_status = 'High'; $bp_color = '#dc3545'; }
                                            elseif ($sys < 90 || $dia < 60) { $bp_status = 'Low'; $bp_color = '#ffc107'; }
                                            else { $bp_status = 'Normal'; $bp_color = '#28a745'; }
                                        }
                                    ?>
                                    <span id="bp_status_text" class="status-badge d-inline-block" 
                                          style="background:<?= $bp_color ?: '#6c757d' ?>;color:#fff;">
                                        <?= $bp_status ? $bp_status : 'Enter BP values' ?>
                                    </span>
                                    <input type="hidden" id="bp_status" name="vitals[bp_status]" value="<?= strtolower($bp_status) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="sugar" class="form-label font-weight-bold">
                                    <i class="fas fa-tint mr-2 text-primary"></i>Blood Sugar (mmol/L)
                                </label>
                                <input type="number" step="0.1" class="form-control form-control-lg" 
                                       id="sugar" name="vitals[sugar]" 
                                       value="<?= htmlspecialchars($vitals['sugar'] ?? '') ?>"
                                       placeholder="e.g. 5.5">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="form-label font-weight-bold">
                                    <i class="fas fa-chart-bar mr-2 text-info"></i>Sugar Status
                                </label>
                                <div id="sugar_status_display" class="mt-2">
                                    <?php
                                        $sugar_val = isset($vitals['sugar']) ? floatval($vitals['sugar']) : null;
                                        $sugar_status = '';
                                        $sugar_color = '';
                                        if (isset($vitals['sugar_status']) && $vitals['sugar_status']) {
                                            $sugar_status = ucfirst($vitals['sugar_status']);
                                            if ($sugar_status === 'High') $sugar_color = '#dc3545';
                                            elseif ($sugar_status === 'Low') $sugar_color = '#ffc107';
                                            elseif ($sugar_status === 'Normal') $sugar_color = '#28a745';
                                        } elseif ($sugar_val) {
                                            if ($sugar_val > 7.0) { $sugar_status = 'High'; $sugar_color = '#dc3545'; }
                                            elseif ($sugar_val < 4.0) { $sugar_status = 'Low'; $sugar_color = '#ffc107'; }
                                            else { $sugar_status = 'Normal'; $sugar_color = '#28a745'; }
                                        }
                                    ?>
                                    <span id="sugar_status_text" class="status-badge d-inline-block" 
                                          style="background:<?= $sugar_color ?: '#6c757d' ?>;color:#fff;">
                                        <?= $sugar_status ? $sugar_status : 'Enter sugar level' ?>
                                    </span>
                                    <input type="hidden" id="sugar_status" name="vitals[sugar_status]" value="<?= strtolower($sugar_status) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tests Section -->
                <div class="section-card">
                    <h5 class="section-title">
                        <span class="section-icon"><i class="fas fa-vial"></i></span>
                        Tests & Screenings
                    </h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="hepatitis_b" class="form-label font-weight-bold">
                                    <i class="fas fa-shield-virus mr-2 text-warning"></i>Hepatitis B Status
                                </label>
                                <select class="form-control form-control-lg" id="hepatitis_b" name="vitals[hepatitis_b]">
                                    <option value="">-- Select Status --</option>
                                    <option value="positive" <?= (isset($vitals['hepatitis_b']) && $vitals['hepatitis_b'] === 'positive') ? 'selected' : '' ?>>Positive</option>
                                    <option value="negative" <?= (isset($vitals['hepatitis_b']) && $vitals['hepatitis_b'] === 'negative') ? 'selected' : '' ?>>Negative</option>
                                    <option value="not_tested" <?= (isset($vitals['hepatitis_b']) && $vitals['hepatitis_b'] === 'not_tested') ? 'selected' : '' ?>>Not Tested</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="malaria" class="form-label font-weight-bold">
                                    <i class="fas fa-bug mr-2 text-danger"></i>Malaria Status
                                </label>
                                <select class="form-control form-control-lg" id="malaria" name="vitals[malaria]">
                                    <option value="">-- Select Status --</option>
                                    <option value="positive" <?= (isset($vitals['malaria']) && $vitals['malaria'] === 'positive') ? 'selected' : '' ?>>Positive</option>
                                    <option value="negative" <?= (isset($vitals['malaria']) && $vitals['malaria'] === 'negative') ? 'selected' : '' ?>>Negative</option>
                                    <option value="not_tested" <?= (isset($vitals['malaria']) && $vitals['malaria'] === 'not_tested') ? 'selected' : '' ?>>Not Tested</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Notes Section -->
                <div class="section-card">
                    <h5 class="section-title">
                        <span class="section-icon"><i class="fas fa-sticky-note"></i></span>
                        Additional Notes
                    </h5>
                    <div class="form-group">
                        <label for="notes" class="form-label font-weight-bold">
                            <i class="fas fa-edit mr-2 text-secondary"></i>Health Notes & Observations
                        </label>
                        <textarea class="form-control form-control-lg" id="notes" name="notes" rows="4" 
                                  placeholder="Record any additional health observations, symptoms, medications, or relevant medical history..."><?= htmlspecialchars($record['notes'] ?? '') ?></textarea>
                        <small class="form-text text-muted mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Include any relevant medical history, current medications, or special health considerations
                        </small>
                    </div>
                </div>
                <!-- Submit Section -->
                <div class="section-card bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1 font-weight-bold text-dark">
                                <i class="fas fa-check-circle mr-2 text-success"></i>
                                Ready to <?= $editing ? 'Update' : 'Save' ?> Record?
                            </h6>
                            <small class="text-muted">
                                Please review all information before submitting
                            </small>
                        </div>
                        <div>
                            <a href="health_list.php" class="btn btn-outline-secondary btn-lg mr-3">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-health btn-lg px-4">
                                <i class="fas fa-save mr-2"></i><?= $editing ? 'Update Record' : 'Save Record' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>
</div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function updateBPStatus() {
    var sys = parseInt(document.getElementById('bp_systolic').value) || 0;
    var dia = parseInt(document.getElementById('bp_diastolic').value) || 0;
    var status = '';
    var color = '';
    if (sys && dia) {
        if (sys >= 140 || dia >= 90) { status = 'High'; color = 'red'; }
        else if (sys < 90 || dia < 60) { status = 'Low'; color = 'pink'; }
        else { status = 'Normal'; color = 'green'; }
    } else {
        status = 'Enter BP values'; color = '#333';
    }
    var bpText = document.getElementById('bp_status_text');
    bpText.innerText = status;
    bpText.style.background = color;
    bpText.style.color = (status === 'Low' || status === 'High' || status === 'Normal') ? '#fff' : '#333';
    document.getElementById('bp_status').value = status.toLowerCase();
}
function updateSugarStatus() {
    var sugar = parseFloat(document.getElementById('sugar').value);
    var status = '';
    var color = '';
    if (!isNaN(sugar)) {
        if (sugar >= 7.0) { status = 'High'; color = 'red'; }
        else if (sugar < 4.0) { status = 'Low'; color = 'pink'; }
        else { status = 'Normal'; color = 'green'; }
    } else {
        status = 'Enter Sugar value'; color = '#333';
    }
    var sugarText = document.getElementById('sugar_status_text');
    sugarText.innerText = status;
    sugarText.style.background = color;
    sugarText.style.color = (status === 'Low' || status === 'High' || status === 'Normal') ? '#fff' : '#333';
    document.getElementById('sugar_status').value = status.toLowerCase();
}
function showFormSections(show) {
    // Hide or show all member-dependent sections
    var memberSections = document.querySelectorAll('.member-sections');
    for (var i = 0; i < memberSections.length; i++) {
        memberSections[i].style.display = show ? '' : 'none';
    }
}
function populateMemberFields(member) {
    document.getElementById('member_id').value = member.id;
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_name').value = member.first_name + ' ' + member.last_name;
    document.getElementById('member_class').value = member.class_name || '';
    document.getElementById('member_phone').value = member.phone || '';
    document.getElementById('member_age').value = member.age !== null ? member.age : '';
    document.getElementById('member_gender').value = member.gender;
    document.getElementById('member_id').value = member.id;
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_info_summary').style.display = '';
    // Set photo
    var photoUrl;
    if (member.photo && member.photo.trim()) {
        // Member has a photo - construct proper path
        photoUrl = window.BASE_URL.replace(/\/$/, '') + '/uploads/members/' + member.photo;
    } else {
        // No photo - use default
        photoUrl = window.BASE_URL.replace(/\/$/, '') + '/assets/img/undraw_profile.svg';
    }
    document.getElementById('member_photo').src = photoUrl;
    // Update modal photo and member name
    document.getElementById('modalPhoto').src = photoUrl;
    document.getElementById('memberNameInModal').textContent = member.name || 'Member Photo';
    // Show/hide fields for member
    document.getElementById('member_class').parentElement.style.display = '';
    document.getElementById('member_phone').parentElement.style.display = '';
    document.getElementById('member_gender').parentElement.style.display = '';
    // Hide child-only fields if any
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) parentFields.style.display = 'none';
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) schoolField.style.display = 'none';
}

function populateChildFields(child) {
    document.getElementById('sundayschool_id').value = child.id;
    document.getElementById('member_id').value = '';
    document.getElementById('member_name').value = child.first_name + ' ' + (child.middle_name ? child.middle_name + ' ' : '') + child.last_name;
    document.getElementById('member_class').value = child.srn;
    document.getElementById('member_phone').value = child.contact || '';
    document.getElementById('member_age').value = child.age !== null ? child.age : '';
    document.getElementById('member_gender').value = child.gender;
    document.getElementById('sundayschool_id').value = child.id;
    document.getElementById('member_id').value = '';
    document.getElementById('member_info_summary').style.display = '';
    // Set photo
    var photoUrl;
    if (child.photo && child.photo.trim()) {
        // Child has a photo - construct proper path
        photoUrl = window.BASE_URL.replace(/\/$/, '') + '/uploads/members/' + child.photo;
    } else {
        // No photo - use default
        photoUrl = window.BASE_URL.replace(/\/$/, '') + '/assets/img/undraw_profile.svg';
    }
    document.getElementById('member_photo').src = photoUrl;
    // Update modal photo and member name for child
    document.getElementById('modalPhoto').src = photoUrl;
    document.getElementById('memberNameInModal').textContent = child.name || 'Child Photo';
    // Show child-only fields
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) {
        parentFields.style.display = '';
        document.getElementById('father_name').value = child.father_name || '';
        document.getElementById('father_contact').value = child.father_contact || '';
        document.getElementById('mother_name').value = child.mother_name || '';
        document.getElementById('mother_contact').value = child.mother_contact || '';
    }
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) {
        schoolField.style.display = '';
        document.getElementById('school_attend').value = child.school_attend || '';
    }
}
function clearMemberFields() {
    document.getElementById('member_id').value = '';
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_name').value = '';
    document.getElementById('member_class').value = '';
    document.getElementById('member_phone').value = '';
    document.getElementById('member_age').value = '';
    document.getElementById('member_gender').value = '';
    document.getElementById('member_id').value = '';
    document.getElementById('sundayschool_id').value = '';
    document.getElementById('member_info_summary').style.display = 'none';
    var parentFields = document.getElementById('child_parent_fields');
    if (parentFields) parentFields.style.display = 'none';
    var schoolField = document.getElementById('child_school_field');
    if (schoolField) schoolField.style.display = 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    // Hide form sections until member is found
    var editing = <?= json_encode($editing) ?>;
    if (!editing) {
        showFormSections(false);
    } else {
        showFormSections(true);
        // Prefill member info if editing
        <?php if ($editing && isset($record['member_id']) && $record['member_id']): ?>
        fetch('ajax_get_member_by_crn.php?crn=<?= urlencode($vitals['crn'] ?? $record['crn'] ?? '') ?>')
            .then(r=>r.json()).then(function(data){
                if(data.success && data.member) populateMemberFields(data.member);
            });
        <?php endif; ?>
    }
    document.getElementById('search_crn_btn').addEventListener('click', function() {
        var id = document.getElementById('crn').value.trim();
        var errorBox = document.getElementById('crn_error_box');
        var errorMsg = document.getElementById('crn_error_msg');
        if (!id) {
            errorBox.style.display = '';
            errorMsg.innerText = 'Please enter a CRN or SRN.';
            clearMemberFields();
            showFormSections(false);
            return;
        }
        fetch('ajax_get_person_by_id.php?id=' + encodeURIComponent(id))
            .then(response => response.json())
            .then(function(data) {
                if (data.success && data.type === 'member') {
                    errorBox.style.display = 'none';
                    populateMemberFields(data.data);
                    showFormSections(true);
                } else if (data.success && data.type === 'sundayschool') {
                    errorBox.style.display = 'none';
                    populateChildFields(data.data);
                    showFormSections(true);
                } else {
                    errorBox.style.display = '';
                    errorMsg.innerText = data.msg || 'ID not found.';
                    clearMemberFields();
                    showFormSections(false);
                }
            })
            .catch(function() {
                errorBox.style.display = '';
                errorMsg.innerText = 'Error searching for ID.';
                clearMemberFields();
                showFormSections(false);
            });
    });
    // Allow Enter key in CRN field to trigger search
    document.getElementById('crn').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('search_crn_btn').click();
        }
    });
    // BP/Sugar listeners
    var sys = document.getElementById('bp_systolic');
    var dia = document.getElementById('bp_diastolic');
    if (sys && dia) {
        sys.addEventListener('input', updateBPStatus);
        dia.addEventListener('input', updateBPStatus);
    }
    var sugar = document.getElementById('sugar');
    if (sugar) {
        sugar.addEventListener('input', updateSugarStatus);
    }
});
</script>
<style>
    .vitals-section, .tests-section, .notes-section, .d-flex.justify-content-end { transition: opacity 0.2s; }
</style>
<?php
$page_content = ob_get_clean();
require_once __DIR__.'/../includes/layout.php';
?>
<!-- Member Photo Modal -->
<div class="modal fade" id="photoModal" tabindex="-1" role="dialog" aria-labelledby="photoModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="photoModalLabel">Member Photo</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body text-center">
        <img src="" id="modalPhoto" alt="Member Photo" style="max-width:100%;max-height:70vh;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,0.08);">
      </div>
      <div class="modal-footer">
        <div id="memberNameInModal" class="text-muted mr-auto"></div>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">
          <i class="fas fa-times mr-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    var modal = $('#photoModal');
    var img = $('#modalPhoto');
    
    // Handle photo click
    $(document).on('click', '#member_photo', function(e) {
      e.preventDefault();
      var src = $(this).attr('src');
      var memberName = $('#member_name').val() || 'Member Photo';
      img.attr('src', src);
      $('#memberNameInModal').text(memberName);
      modal.modal('show');
    });
    
    // Clear image when modal is hidden
    modal.on('hidden.bs.modal', function(){
      img.attr('src','');
      $('#memberNameInModal').text('');
    });
  });
</script>
