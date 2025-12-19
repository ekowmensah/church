<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is a Bible class leader
$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$leader_info = is_bible_class_leader($conn, $user_id, $member_id);

if (!$leader_info) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as a Bible class leader.</div>';
    exit;
}

$class_id = $leader_info['class_id'];
$class_name = $leader_info['class_name'];

// Get session_id from URL or show session selection
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

if (!$session_id) {
    // Show session selection
    $stmt = $conn->prepare("
        SELECT ats.*, c.name as church_name
        FROM attendance_sessions ats
        LEFT JOIN churches c ON ats.church_id = c.id
        WHERE ats.church_id = ?
        ORDER BY ats.service_date DESC
        LIMIT 20
    ");
    $stmt->bind_param('i', $leader_info['church_id']);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    ob_start();
    ?>
    <div class="container mt-4">
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4><i class="fas fa-clipboard-check"></i> Select Attendance Session</h4>
                <p class="mb-0">Bible Class: <?= htmlspecialchars($class_name) ?></p>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($sessions as $session): ?>
                    <a href="?session_id=<?= $session['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1"><?= htmlspecialchars($session['title']) ?></h5>
                                <p class="mb-1">
                                    <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                                    <span class="badge badge-info ml-2"><?= htmlspecialchars($session['church_name']) ?></span>
                                </p>
                            </div>
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    
                    <?php if (empty($sessions)): ?>
                    <div class="alert alert-info">No attendance sessions available.</div>
                    <?php endif; ?>
                </div>
                
                <div class="mt-3">
                    <a href="my_bible_class_leader.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php
    $page_content = ob_get_clean();
    $page_title = 'Select Attendance Session';
    include '../includes/layout.php';
    exit;
}

// Fetch session details
$stmt = $conn->prepare("SELECT s.*, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE s.id = ? LIMIT 1");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$session) {
    header('Location: my_bible_class_attendance.php');
    exit;
}

// Get class members
$members = get_bible_class_members($conn, $class_id);

// Fetch previous attendance
$prev_attendance = [];
$member_ids = array_column($members, 'id');
if (count($member_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $sql_att = "SELECT member_id, status FROM attendance_records WHERE session_id = ? AND member_id IN ($placeholders)";
    $stmt = $conn->prepare($sql_att);
    $types_att = 'i' . str_repeat('i', count($member_ids));
    $bind_params = array_merge([$session_id], $member_ids);
    $stmt->bind_param($types_att, ...$bind_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $prev_attendance[$row['member_id']] = $row['status'];
    }
    $stmt->close();
}

// Handle POST (save attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $marked = $_POST['attendance'] ?? [];
    $marked_by = $_SESSION['user_id'] ?? $_SESSION['member_id'] ?? null;
    $valid_statuses = ['present', 'absent', 'sick', 'permission', 'distance', 'invalid'];
    
    foreach ($members as $m) {
        $member_id = $m['id'];
        // Get status from POST data, default to 'absent' if not set or invalid
        $status = isset($marked[$member_id]) && in_array($marked[$member_id], $valid_statuses) 
                  ? $marked[$member_id] 
                  : 'absent';
        
        $stmt = $conn->prepare("REPLACE INTO attendance_records (session_id, member_id, status, marked_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('iisi', $session_id, $member_id, $status, $marked_by);
        $stmt->execute();
        $stmt->close();
    }
    
    header('Location: my_bible_class_leader.php?attendance_marked=1');
    exit;
}

// Calculate statistics
$total_members = count($members);
$present_count = 0;
$absent_count = 0;
$sick_count = 0;
$permission_count = 0;
$distance_count = 0;
$invalid_count = 0;
foreach ($members as $m) {
    $status = strtolower($prev_attendance[$m['id']] ?? 'absent');
    switch($status) {
        case 'present':
            $present_count++;
            break;
        case 'sick':
            $sick_count++;
            break;
        case 'permission':
            $permission_count++;
            break;
        case 'distance':
            $distance_count++;
            break;
        case 'invalid':
            $invalid_count++;
            break;
        default:
            $absent_count++;
    }
}
$attendance_rate = $total_members > 0 ? round(($present_count / $total_members) * 100, 1) : 0;

ob_start();
?>
<style>
.attendance-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.stat-box {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    border-left: 4px solid;
    transition: transform 0.2s;
}

.stat-box:hover {
    transform: translateY(-3px);
}

.stat-box.total { border-left-color: #667eea; }
.stat-box.present { border-left-color: #28a745; }
.stat-box.absent { border-left-color: #dc3545; }
.stat-box.sick { border-left-color: #ffc107; }
.stat-box.permission { border-left-color: #17a2b8; }
.stat-box.distance { border-left-color: #fd7e14; }
.stat-box.invalid { border-left-color: #6c757d; }
.stat-box.rate { border-left-color: #667eea; }

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #2c3e50;
}

.stat-label {
    font-size: 0.85rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 8px;
}

.members-list-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 3px 15px rgba(0,0,0,0.08);
    overflow: hidden;
    margin-bottom: 25px;
}

.members-table {
    width: 100%;
    border-collapse: collapse;
}

.members-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.members-table thead th {
    padding: 12px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.members-table tbody tr {
    border-bottom: 1px solid #e9ecef;
    transition: background-color 0.2s ease;
}

.members-table tbody tr:hover {
    background-color: #f8f9fa;
}

.members-table tbody td {
    padding: 10px 8px;
    vertical-align: middle;
    font-size: 0.9rem;
}

.member-name {
    font-weight: 600;
    color: #2c3e50;
    font-size: 0.95rem;
}

.member-crn {
    color: #6c757d;
    font-size: 0.85rem;
}

.row-number {
    font-weight: 600;
    color: #6c757d;
    font-size: 0.9rem;
}

.status-radio-group {
    display: flex;
    gap: 4px;
    flex-wrap: nowrap;
    align-items: center;
    justify-content: flex-start;
}

.status-radio {
    position: relative;
}

.status-radio input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}

.status-radio label {
    display: inline-block;
    padding: 4px 8px;
    border: 2px solid #e0e0e0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.75rem;
    font-weight: 500;
    transition: all 0.2s ease;
    background: white;
    margin: 0;
    white-space: nowrap;
    line-height: 1.2;
}

.status-radio label:hover {
    border-color: #667eea;
    transform: translateY(-1px);
}

.status-radio input[type="radio"]:checked + label {
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}

.status-radio.present input[type="radio"]:checked + label {
    background: #28a745;
    border-color: #28a745;
    color: white;
}

.status-radio.absent input[type="radio"]:checked + label {
    background: #dc3545;
    border-color: #dc3545;
    color: white;
}

.status-radio.sick input[type="radio"]:checked + label {
    background: #ffc107;
    border-color: #ffc107;
    color: #000;
}

.status-radio.permission input[type="radio"]:checked + label {
    background: #17a2b8;
    border-color: #17a2b8;
    color: white;
}

.status-radio.distance input[type="radio"]:checked + label {
    background: #fd7e14;
    border-color: #fd7e14;
    color: white;
}

.status-radio.invalid input[type="radio"]:checked + label {
    background: #6c757d;
    border-color: #6c757d;
    color: white;
}

.status-select-mobile {
    display: none;
    width: 100%;
    padding: 8px;
    border: 2px solid #e0e0e0;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    background: white;
    transition: all 0.2s ease;
}

.status-select-mobile:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.status-select-mobile.status-present {
    border-color: #28a745;
    background: #f8fff9;
    color: #28a745;
}

.status-select-mobile.status-absent {
    border-color: #dc3545;
    background: #fff5f5;
    color: #dc3545;
}

.status-select-mobile.status-sick {
    border-color: #ffc107;
    background: #fffbf0;
    color: #d39e00;
}

.status-select-mobile.status-permission {
    border-color: #17a2b8;
    background: #f0f9fb;
    color: #117a8b;
}

.status-select-mobile.status-distance {
    border-color: #fd7e14;
    background: #fff8f0;
    color: #e8590c;
}

.status-select-mobile.status-invalid {
    border-color: #6c757d;
    background: #f8f9fa;
    color: #495057;
}

@media (max-width: 1200px) {
    .status-radio label {
        padding: 3px 6px;
        font-size: 0.7rem;
    }
    .status-radio-group {
        gap: 3px;
    }
}

@media (max-width: 992px) {
    .status-radio label {
        padding: 3px 5px;
        font-size: 0.65rem;
    }
}

@media (max-width: 768px) {
    .members-table {
        font-size: 0.8rem;
        display: block;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .members-table thead th,
    .members-table tbody td {
        padding: 8px 4px;
    }
    .status-radio-group {
        display: none !important;
    }
    .status-select-mobile {
        display: block;
    }
    .member-name {
        font-size: 0.85rem;
    }
    .member-crn {
        font-size: 0.75rem;
    }
}

@media (max-width: 576px) {
    .stat-value {
        font-size: 1.5rem;
    }
}
</style>

<div class="attendance-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="fas fa-clipboard-check"></i> Mark Attendance</h2>
            <h4><?= htmlspecialchars($session['title']) ?></h4>
            <p class="mb-0">
                <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                | <i class="fas fa-chalkboard-teacher"></i> <?= htmlspecialchars($class_name) ?>
            </p>
        </div>
        <div>
            <a href="my_bible_class_attendance.php" class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left"></i> Change Session
            </a>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box total">
            <div class="stat-label">Total</div>
            <div class="stat-value" id="total-count"><?= $total_members ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box present">
            <div class="stat-label"><i class="fas fa-check-circle"></i> Present</div>
            <div class="stat-value" id="present-count"><?= $present_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box absent">
            <div class="stat-label"><i class="fas fa-times-circle"></i> Absent</div>
            <div class="stat-value" id="absent-count"><?= $absent_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box sick">
            <div class="stat-label"><i class="fas fa-thermometer"></i> Sick</div>
            <div class="stat-value" id="sick-count"><?= $sick_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box permission">
            <div class="stat-label"><i class="fas fa-user-check"></i> Permission</div>
            <div class="stat-value" id="permission-count"><?= $permission_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box distance">
            <div class="stat-label"><i class="fas fa-road"></i> Distance</div>
            <div class="stat-value" id="distance-count"><?= $distance_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box invalid">
            <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Invalid</div>
            <div class="stat-value" id="invalid-count"><?= $invalid_count ?></div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="stat-box rate">
            <div class="stat-label"><i class="fas fa-chart-line"></i> Rate</div>
            <div class="stat-value" id="attendance-rate"><?= $attendance_rate ?>%</div>
        </div>
    </div>
</div>

<form method="post" id="attendanceForm">
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users"></i> Class Members (<?= $total_members ?>)</h5>
                <div>
                    <button type="button" class="btn btn-success btn-sm" onclick="markAllPresent()">
                        <i class="fas fa-check-double"></i> Mark All Present
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="markAllAbsent()">
                        <i class="fas fa-times-circle"></i> Mark All Absent
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="members-list-container">
        <table class="members-table" id="membersTable">
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th style="width: 60px;">Photo</th>
                    <th style="width: 250px;">Member</th>
                    <th style="width: 120px;">CRN</th>
                    <th>Attendance Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($members as $index => $member): 
                    $current_status = strtolower($prev_attendance[$member['id']] ?? 'absent');
                ?>
                <tr data-member-id="<?= $member['id'] ?>">
                    <td class="row-number"><?= $index + 1 ?></td>
                    <td>
                        <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>" 
                             alt="Photo" 
                             style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover;">
                    </td>
                    <td>
                        <div class="member-name">
                            <?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="member-crn">
                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                        </span>
                    </td>
                    <td>
                        <!-- Radio buttons for desktop -->
                        <div class="status-radio-group">
                            <div class="status-radio present">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_present" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="present" 
                                       <?= $current_status === 'present' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_present">✓ Present</label>
                            </div>
                            <div class="status-radio absent">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_absent" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="absent" 
                                       <?= $current_status === 'absent' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_absent">✗ Absent</label>
                            </div>
                            <div class="status-radio sick">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_sick" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="sick" 
                                       <?= $current_status === 'sick' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_sick">🤒 Sick</label>
                            </div>
                            <div class="status-radio permission">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_permission" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="permission" 
                                       <?= $current_status === 'permission' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_permission">📋 Permission</label>
                            </div>
                            <div class="status-radio distance">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_distance" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="distance" 
                                       <?= $current_status === 'distance' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_distance">🛣️ Distance</label>
                            </div>
                            <div class="status-radio invalid">
                                <input type="radio" 
                                       id="status_<?= $member['id'] ?>_invalid" 
                                       name="attendance[<?= $member['id'] ?>]" 
                                       value="invalid" 
                                       <?= $current_status === 'invalid' ? 'checked' : '' ?>
                                       onchange="updateMemberRadio(this)">
                                <label for="status_<?= $member['id'] ?>_invalid">⚠️ Invalid</label>
                            </div>
                        </div>
                        <!-- Select dropdown for mobile -->
                        <select class="status-select-mobile status-<?= $current_status ?>" 
                                data-member-id="<?= $member['id'] ?>"
                                onchange="updateMemberSelect(this)">
                            <option value="present" <?= $current_status === 'present' ? 'selected' : '' ?>>✓ Present</option>
                            <option value="absent" <?= $current_status === 'absent' ? 'selected' : '' ?>>✗ Absent</option>
                            <option value="sick" <?= $current_status === 'sick' ? 'selected' : '' ?>>🤒 Sick</option>
                            <option value="permission" <?= $current_status === 'permission' ? 'selected' : '' ?>>📋 Permission</option>
                            <option value="distance" <?= $current_status === 'distance' ? 'selected' : '' ?>>🛣️ Distance</option>
                            <option value="invalid" <?= $current_status === 'invalid' ? 'selected' : '' ?>>⚠️ Invalid</option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="text-center mb-4">
        <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Save attendance for all members?')">
            <i class="fas fa-save"></i> Save Attendance
        </button>
        <a href="my_bible_class_leader.php" class="btn btn-secondary btn-lg">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>
</form>

<script>
function updateMemberRadio(radioElement) {
    const row = radioElement.closest('tr');
    const memberId = row.dataset.memberId;
    const status = radioElement.value;
    
    // Sync with mobile select if it exists
    const mobileSelect = row.querySelector('.status-select-mobile');
    if (mobileSelect) {
        mobileSelect.value = status;
        mobileSelect.className = 'status-select-mobile status-' + status;
    }
    
    updateStats();
}

function updateMemberSelect(selectElement) {
    const row = selectElement.closest('tr');
    const memberId = selectElement.dataset.memberId;
    const status = selectElement.value;
    
    // Update select styling
    selectElement.className = 'status-select-mobile status-' + status;
    
    // Sync with radio buttons
    const radioButton = row.querySelector(`input[name="attendance[${memberId}]"][value="${status}"]`);
    if (radioButton) {
        radioButton.checked = true;
    }
    
    updateStats();
}

function updateStats() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    const total = rows.length;
    let present = 0;
    let absent = 0;
    let sick = 0;
    let permission = 0;
    let distance = 0;
    let invalid = 0;
    
    rows.forEach(row => {
        // Try to get status from radio button first, then from select
        let status = null;
        const checkedRadio = row.querySelector('input[type="radio"]:checked');
        if (checkedRadio) {
            status = checkedRadio.value;
        } else {
            const mobileSelect = row.querySelector('.status-select-mobile');
            if (mobileSelect) {
                status = mobileSelect.value;
            }
        }
        
        if (status) {
            switch(status) {
                case 'present': present++; break;
                case 'absent': absent++; break;
                case 'sick': sick++; break;
                case 'permission': permission++; break;
                case 'distance': distance++; break;
                case 'invalid': invalid++; break;
            }
        }
    });
    
    const rate = total > 0 ? ((present / total) * 100).toFixed(1) : 0;
    
    document.getElementById('total-count').textContent = total;
    document.getElementById('present-count').textContent = present;
    document.getElementById('absent-count').textContent = absent;
    document.getElementById('sick-count').textContent = sick;
    document.getElementById('permission-count').textContent = permission;
    document.getElementById('distance-count').textContent = distance;
    document.getElementById('invalid-count').textContent = invalid;
    document.getElementById('attendance-rate').textContent = rate + '%';
}

function markAllPresent() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    rows.forEach(row => {
        const presentRadio = row.querySelector('input[value="present"]');
        const mobileSelect = row.querySelector('.status-select-mobile');
        
        if (presentRadio) {
            presentRadio.checked = true;
            updateMemberRadio(presentRadio);
        } else if (mobileSelect) {
            mobileSelect.value = 'present';
            updateMemberSelect(mobileSelect);
        }
    });
}

function markAllAbsent() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    rows.forEach(row => {
        const absentRadio = row.querySelector('input[value="absent"]');
        const mobileSelect = row.querySelector('.status-select-mobile');
        
        if (absentRadio) {
            absentRadio.checked = true;
            updateMemberRadio(absentRadio);
        } else if (mobileSelect) {
            mobileSelect.value = 'absent';
            updateMemberSelect(mobileSelect);
        }
    });
}

// Initialize stats on page load
updateStats();
</script>

<?php
$page_content = ob_get_clean();
$page_title = 'Mark Attendance - ' . $class_name;
include '../includes/layout.php';
?>
