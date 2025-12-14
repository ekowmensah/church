<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/leader_helpers.php';

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Check if user is an organization leader
$user_id = $_SESSION['user_id'] ?? null;
$member_id = $_SESSION['member_id'] ?? null;
$org_leaderships = is_organization_leader($conn, $user_id, $member_id);

if (!$org_leaderships) {
    http_response_code(403);
    echo '<div class="alert alert-danger">You are not assigned as an organization leader.</div>';
    exit;
}

// If multiple organizations, require org_id parameter
if (count($org_leaderships) > 1) {
    $org_id = isset($_GET['org_id']) ? intval($_GET['org_id']) : 0;
    
    if (!$org_id) {
        // Redirect to organization selector
        header('Location: my_organizations_leader.php');
        exit;
    }
    
    // Verify the org_id is one they lead
    $leader_info = null;
    foreach ($org_leaderships as $org) {
        if ($org['organization_id'] == $org_id) {
            $leader_info = $org;
            break;
        }
    }
    
    if (!$leader_info) {
        http_response_code(403);
        echo '<div class="alert alert-danger">You are not the leader of this organization.</div>';
        exit;
    }
} else {
    // Only one organization, use it directly
    $leader_info = $org_leaderships[0];
    $org_id = $leader_info['organization_id'];
}

$org_name = $leader_info['org_name'];

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
                <p class="mb-0">Organization: <?= htmlspecialchars($org_name) ?></p>
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
                    <a href="my_organization_leader.php" class="btn btn-secondary">
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
    header('Location: my_organization_attendance.php');
    exit;
}

// Get organization members
$members = get_organization_members($conn, $org_id);

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
    
    foreach ($members as $m) {
        $member_id = $m['id'];
        $status = isset($marked[$member_id]) && $marked[$member_id] === 'present' ? 'present' : 'absent';
        
        $stmt = $conn->prepare("REPLACE INTO attendance_records (session_id, member_id, status, marked_by, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('iisi', $session_id, $member_id, $status, $marked_by);
        $stmt->execute();
        $stmt->close();
    }
    
    header('Location: my_organization_leader.php?attendance_marked=1');
    exit;
}

// Calculate statistics
$total_members = count($members);
$present_count = 0;
$absent_count = 0;
foreach ($members as $m) {
    if (isset($prev_attendance[$m['id']]) && strtolower($prev_attendance[$m['id']]) === 'present') {
        $present_count++;
    } else {
        $absent_count++;
    }
}
$attendance_rate = $total_members > 0 ? round(($present_count / $total_members) * 100, 1) : 0;

ob_start();
?>
<style>
.attendance-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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

.stat-box.total { border-left-color: #f093fb; }
.stat-box.present { border-left-color: #28a745; }
.stat-box.absent { border-left-color: #dc3545; }
.stat-box.rate { border-left-color: #17a2b8; }

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

.members-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.member-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.member-card.present {
    border-color: #28a745;
    background: #f8fff9;
}

.member-card.absent {
    border-color: #e0e0e0;
}

.attendance-toggle {
    position: relative;
    width: 60px;
    height: 30px;
}

.attendance-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 30px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .toggle-slider {
    background-color: #28a745;
}

input:checked + .toggle-slider:before {
    transform: translateX(30px);
}
</style>

<div class="attendance-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h2><i class="fas fa-clipboard-check"></i> Mark Attendance</h2>
            <h4><?= htmlspecialchars($session['title']) ?></h4>
            <p class="mb-0">
                <i class="fas fa-calendar"></i> <?= date('l, F j, Y', strtotime($session['service_date'])) ?>
                | <i class="fas fa-users-cog"></i> <?= htmlspecialchars($org_name) ?>
            </p>
        </div>
        <div>
            <a href="my_organization_attendance.php" class="btn btn-light btn-lg">
                <i class="fas fa-arrow-left"></i> Change Session
            </a>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-box total">
            <div class="stat-label">Total Members</div>
            <div class="stat-value" id="total-count"><?= $total_members ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box present">
            <div class="stat-label">Present</div>
            <div class="stat-value" id="present-count"><?= $present_count ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box absent">
            <div class="stat-label">Absent</div>
            <div class="stat-value" id="absent-count"><?= $absent_count ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-box rate">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value" id="attendance-rate"><?= $attendance_rate ?>%</div>
        </div>
    </div>
</div>

<form method="post" id="attendanceForm">
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-users"></i> Organization Members (<?= $total_members ?>)</h5>
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

    <div class="members-grid">
        <?php foreach($members as $member): 
            $is_present = isset($prev_attendance[$member['id']]) && 
                         strtolower($prev_attendance[$member['id']]) === 'present';
        ?>
        <div class="member-card <?= $is_present ? 'present' : 'absent' ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div class="d-flex align-items-center">
                    <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo'] ?? 'default.png') ?>" 
                         alt="Photo" 
                         style="width: 40px; height: 40px; border-radius: 50%; object-fit: cover; margin-right: 10px;">
                    <div>
                        <h6 class="mb-0"><?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name']) ?></h6>
                        <small class="text-muted">
                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                        </small>
                    </div>
                </div>
                <label class="attendance-toggle">
                    <input type="checkbox" 
                           name="attendance[<?= $member['id'] ?>]" 
                           value="present" 
                           <?= $is_present ? 'checked' : '' ?>
                           onchange="updateMemberCard(this)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mb-4">
        <button type="submit" class="btn btn-success btn-lg" onclick="return confirm('Save attendance for all members?')">
            <i class="fas fa-save"></i> Save Attendance
        </button>
        <a href="my_organization_leader.php" class="btn btn-secondary btn-lg">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>
</form>

<script>
function updateMemberCard(checkbox) {
    const card = checkbox.closest('.member-card');
    if (checkbox.checked) {
        card.classList.add('present');
        card.classList.remove('absent');
    } else {
        card.classList.remove('present');
        card.classList.add('absent');
    }
    updateStats();
}

function updateStats() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="attendance"]');
    const total = checkboxes.length;
    let present = 0;
    
    checkboxes.forEach(cb => {
        if (cb.checked) present++;
    });
    
    const absent = total - present;
    const rate = total > 0 ? ((present / total) * 100).toFixed(1) : 0;
    
    document.getElementById('total-count').textContent = total;
    document.getElementById('present-count').textContent = present;
    document.getElementById('absent-count').textContent = absent;
    document.getElementById('attendance-rate').textContent = rate + '%';
}

function markAllPresent() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="attendance"]');
    checkboxes.forEach(cb => {
        cb.checked = true;
        updateMemberCard(cb);
    });
}

function markAllAbsent() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="attendance"]');
    checkboxes.forEach(cb => {
        cb.checked = false;
        updateMemberCard(cb);
    });
}
</script>

<?php
$page_content = ob_get_clean();
$page_title = 'Mark Attendance - ' . $org_name;
include '../includes/layout.php';
?>
