<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!has_permission('mark_attendance')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

$session_id = intval($_GET['id'] ?? 0);
if (!$session_id) {
    header('Location: attendance_list.php');
    exit;
}

// Fetch session details
$stmt = $conn->prepare("SELECT s.*, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE s.id = ? LIMIT 1");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
if (!$session) {
    header('Location: attendance_list.php');
    exit;
}

// Get filter options
$bible_classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");
$organizations = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");

// Get filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build member query with filters
$sql = "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.crn, 
        bc.name AS class_name, m.gender
        FROM members m 
        LEFT JOIN bible_classes bc ON m.class_id = bc.id ";
if ($filter_org) {
    $sql .= "LEFT JOIN member_organizations mo ON mo.member_id = m.id ";
}
$sql .= "WHERE m.church_id = ? ";
$params = [$session['church_id']];
$types = 'i';

if ($filter_class) {
    $sql .= "AND m.class_id = ? ";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= "AND mo.organization_id = ? ";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= "AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ?) ";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}
$sql .= "ORDER BY m.last_name, m.first_name";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$members_result = $stmt->get_result();
$members = $members_result ? $members_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch previous attendance (including draft status)
$prev_attendance = [];
$draft_status = [];
$member_ids = array_column($members, 'id');
if (count($member_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $sql_att = "SELECT member_id, status, is_draft FROM attendance_records WHERE session_id = ? AND member_id IN ($placeholders)";
    $stmt = $conn->prepare($sql_att);
    $types_att = 'i' . str_repeat('i', count($member_ids));
    $bind_params = array_merge([$session_id], $member_ids);
    $stmt->bind_param($types_att, ...$bind_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $prev_attendance[$row['member_id']] = $row['status'];
        $draft_status[$row['member_id']] = $row['is_draft'] ?? 0;
    }
}

// Calculate statistics
$total_members = count($members);
$present_count = 0;
$absent_count = 0;
$draft_count = 0;
foreach ($members as $m) {
    if (isset($prev_attendance[$m['id']]) && strtolower($prev_attendance[$m['id']]) === 'present') {
        $present_count++;
    } else {
        $absent_count++;
    }
    if (isset($draft_status[$m['id']]) && $draft_status[$m['id']] == 1) {
        $draft_count++;
    }
}
$attendance_rate = $total_members > 0 ? round(($present_count / $total_members) * 100, 1) : 0;

// Handle AJAX draft save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    header('Content-Type: application/json');
    $member_id = intval($_POST['member_id'] ?? 0);
    $status = $_POST['status'] ?? 'absent';
    
    if ($member_id > 0) {
        // Save as draft (is_draft = 1)
        $stmt = $conn->prepare("REPLACE INTO attendance_records (session_id, member_id, status, marked_by, is_draft, created_at, updated_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
        $stmt->bind_param('iisi', $session_id, $member_id, $status, $_SESSION['user_id']);
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Draft saved']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to save draft']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid member ID']);
    }
    exit;
}

// Handle POST (finalize attendance)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'finalize')) {
    $marked = $_POST['attendance'] ?? [];
    
    // First, get all members for this session's church to mark absent those not checked
    foreach ($members as $m) {
        $member_id = $m['id'];
        $status = isset($marked[$member_id]) && $marked[$member_id] === 'present' ? 'present' : 'absent';
        // Finalize: set is_draft = 0
        $stmt = $conn->prepare("REPLACE INTO attendance_records (session_id, member_id, status, marked_by, is_draft, created_at, updated_at) VALUES (?, ?, ?, ?, 0, NOW(), NOW())");
        $stmt->bind_param('iisi', $session_id, $member_id, $status, $_SESSION['user_id']);
        $stmt->execute();
    }
    header('Location: attendance_list.php?marked=1');
    exit;
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .attendance-mark-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .attendance-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .session-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .session-details h2 {
            margin: 0 0 10px 0;
            font-size: 1.8rem;
        }
        
        .session-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .session-meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
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
        .stat-box.rate { border-left-color: #17a2b8; }
        
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
        }
        
        .bulk-actions {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .bulk-actions-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        
        .member-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        
        .member-card.present {
            border-color: #28a745;
            background: #f8fff9;
        }
        
        .member-card.absent {
            border-color: #e0e0e0;
        }
        
        .member-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .member-info h6 {
            margin: 0 0 5px 0;
            font-size: 1.1rem;
            color: #2c3e50;
        }
        
        .member-crn {
            font-size: 0.85rem;
            color: #6c757d;
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
        
        .member-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .meta-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 12px;
            background: #e9ecef;
            color: #495057;
        }
        
        .save-buttons-fixed {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .save-buttons-fixed .btn {
            box-shadow: 0 5px 25px rgba(0,0,0,0.3);
            min-width: 200px;
        }
        
        .member-card.draft {
            border-left: 4px solid #ffc107;
        }
        
        .badge-sm {
            font-size: 0.65rem;
            padding: 2px 6px;
        }
        
        @media (max-width: 768px) {
            .members-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>

<div class="attendance-mark-container">
    <div class="attendance-header">
        <div class="session-info">
            <div class="session-details">
                <h2><i class="fas fa-calendar-check"></i> <?= htmlspecialchars($session['title']) ?></h2>
                <div class="session-meta">
                    <div class="session-meta-item">
                        <i class="fas fa-church"></i>
                        <span><?= htmlspecialchars($session['church_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="session-meta-item">
                        <i class="fas fa-calendar"></i>
                        <span><?= date('l, F j, Y', strtotime($session['service_date'])) ?></span>
                    </div>
                </div>
            </div>
            <div>
                <a href="attendance_list.php" class="btn btn-light btn-lg">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-box total">
            <div class="stat-label">Total Members</div>
            <div class="stat-value" id="total-count"><?= $total_members ?></div>
        </div>
        <div class="stat-box present">
            <div class="stat-label">Present</div>
            <div class="stat-value" id="present-count"><?= $present_count ?></div>
        </div>
        <div class="stat-box absent">
            <div class="stat-label">Absent</div>
            <div class="stat-value" id="absent-count"><?= $absent_count ?></div>
        </div>
        <div class="stat-box rate">
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value" id="attendance-rate"><?= $attendance_rate ?>%</div>
        </div>
        <div class="stat-box" style="border-left-color: #ffc107;">
            <div class="stat-label">Draft Marks</div>
            <div class="stat-value" id="draft-count"><?= $draft_count ?></div>
        </div>
    </div>

    <div class="filter-card">
        <h5><i class="fas fa-filter"></i> Filter Members</h5>
        <form method="get" id="filterForm">
            <input type="hidden" name="id" value="<?= $session_id ?>">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Bible Class</label>
                    <select class="form-select" name="class_id">
                        <option value="">All Classes</option>
                        <?php if ($bible_classes && $bible_classes->num_rows > 0): 
                            while($cl = $bible_classes->fetch_assoc()): ?>
                            <option value="<?= $cl['id'] ?>" <?= $filter_class == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Organization</label>
                    <select class="form-select" name="organization_id">
                        <option value="">All Organizations</option>
                        <?php if ($organizations && $organizations->num_rows > 0): 
                            while($org = $organizations->fetch_assoc()): ?>
                            <option value="<?= $org['id'] ?>" <?= $filter_org == $org['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($org['name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Search (Real-time)</label>
                    <input type="text" class="form-control" id="realtimeSearch" 
                           placeholder="Search by name or CRN..." 
                           value="<?= htmlspecialchars($search) ?>">
                    <small class="text-muted">Type to filter members instantly</small>
                </div>
                <div class="col-md-2 mb-3 d-flex align-items-end gap-2">
                    <a href="attendance_mark.php?id=<?= $session_id ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear Filters
                    </a>
                </div>
            </div>
        </form>
    </div>

    <form method="post" id="attendanceForm">
        <div class="bulk-actions">
            <h6 class="mb-0"><i class="fas fa-users"></i> Mark Attendance (<?= $total_members ?> members)</h6>
            <div class="bulk-actions-buttons">
                <button type="button" class="btn btn-success btn-sm" onclick="markAllPresent()">
                    <i class="fas fa-check-double"></i> Mark All Present
                </button>
                <button type="button" class="btn btn-danger btn-sm" onclick="markAllAbsent()">
                    <i class="fas fa-times-circle"></i> Mark All Absent
                </button>
                <button type="button" class="btn btn-info btn-sm" onclick="toggleAll()">
                    <i class="fas fa-exchange-alt"></i> Toggle All
                </button>
            </div>
        </div>

        <div class="members-grid" id="membersGrid">
            <?php foreach($members as $member): 
                $is_present = isset($prev_attendance[$member['id']]) && 
                             strtolower($prev_attendance[$member['id']]) === 'present';
                $is_draft = isset($draft_status[$member['id']]) && $draft_status[$member['id']] == 1;
            ?>
            <div class="member-card <?= $is_present ? 'present' : 'absent' ?> <?= $is_draft ? 'draft' : '' ?>" 
                 data-member-id="<?= $member['id'] ?>"
                 data-member-name="<?= htmlspecialchars(strtolower($member['last_name'] . ' ' . $member['first_name'] . ' ' . $member['middle_name'])) ?>"
                 data-member-crn="<?= htmlspecialchars(strtolower($member['crn'] ?? '')) ?>">
                <div class="member-header">
                    <div class="member-info">
                        <h6>
                            <?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name']) ?>
                            <?php if ($is_draft): ?>
                                <span class="badge badge-warning badge-sm ml-1">DRAFT</span>
                            <?php endif; ?>
                        </h6>
                        <div class="member-crn">
                            <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
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
                <div class="member-meta">
                    <?php if ($member['class_name']): ?>
                        <span class="meta-badge">
                            <i class="fas fa-book"></i> <?= htmlspecialchars($member['class_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($member['gender']): ?>
                        <span class="meta-badge">
                            <i class="fas fa-<?= strtolower($member['gender']) === 'male' ? 'mars' : 'venus' ?>"></i>
                            <?= htmlspecialchars($member['gender']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="save-buttons-fixed">
            <button type="submit" class="btn btn-success btn-lg mb-2" onclick="return confirmFinalize()">
                <i class="fas fa-check-circle"></i> Finalize Attendance
            </button>
            <div class="text-white text-center small">
                <i class="fas fa-info-circle"></i> Changes auto-save as draft
            </div>
        </div>
    </form>
</div>

<script>
let autoSaveTimeout = null;

function updateMemberCard(checkbox) {
    const card = checkbox.closest('.member-card');
    const memberId = card.dataset.memberId;
    const status = checkbox.checked ? 'present' : 'absent';
    
    if (checkbox.checked) {
        card.classList.add('present');
        card.classList.remove('absent');
    } else {
        card.classList.remove('present');
        card.classList.add('absent');
    }
    
    // Mark as draft
    card.classList.add('draft');
    const nameHeader = card.querySelector('.member-info h6');
    if (!nameHeader.querySelector('.badge-warning')) {
        const draftBadge = document.createElement('span');
        draftBadge.className = 'badge badge-warning badge-sm ml-1';
        draftBadge.textContent = 'DRAFT';
        nameHeader.appendChild(draftBadge);
    }
    
    updateStats();
    
    // Auto-save as draft after 1 second of inactivity
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        saveDraft(memberId, status);
    }, 1000);
}

function saveDraft(memberId, status) {
    const formData = new FormData();
    formData.append('action', 'save_draft');
    formData.append('member_id', memberId);
    formData.append('status', status);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Draft saved for member ' + memberId);
        }
    })
    .catch(error => console.error('Error saving draft:', error));
}

function updateStats() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="attendance"]');
    const cards = document.querySelectorAll('.member-card');
    const total = checkboxes.length;
    let present = 0;
    let drafts = 0;
    
    checkboxes.forEach(cb => {
        if (cb.checked) present++;
    });
    
    cards.forEach(card => {
        if (card.classList.contains('draft')) drafts++;
    });
    
    const absent = total - present;
    const rate = total > 0 ? ((present / total) * 100).toFixed(1) : 0;
    
    document.getElementById('total-count').textContent = total;
    document.getElementById('present-count').textContent = present;
    document.getElementById('absent-count').textContent = absent;
    document.getElementById('attendance-rate').textContent = rate + '%';
    document.getElementById('draft-count').textContent = drafts;
}

function confirmFinalize() {
    const draftCount = document.querySelectorAll('.member-card.draft').length;
    if (draftCount > 0) {
        return confirm(`You have ${draftCount} draft mark(s). Finalizing will save all attendance records permanently. Continue?`);
    }
    return confirm('Finalize attendance? This will save all records permanently.');
}

// Real-time search functionality
const searchInput = document.getElementById('realtimeSearch');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const memberCards = document.querySelectorAll('.member-card');
        
        memberCards.forEach(card => {
            const memberName = card.dataset.memberName || '';
            const memberCrn = card.dataset.memberCrn || '';
            
            if (memberName.includes(searchTerm) || memberCrn.includes(searchTerm)) {
                card.style.display = '';
            } else {
                card.style.display = 'none';
            }
        });
        
        // Update visible count
        const visibleCards = document.querySelectorAll('.member-card[style=""]').length;
        const totalCards = memberCards.length;
        console.log(`Showing ${visibleCards} of ${totalCards} members`);
    });
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

function toggleAll() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="attendance"]');
    checkboxes.forEach(cb => {
        cb.checked = !cb.checked;
        updateMemberCard(cb);
    });
}

// Confirm before leaving if unsaved changes
let formChanged = false;
document.getElementById('attendanceForm').addEventListener('change', function() {
    formChanged = true;
});

window.addEventListener('beforeunload', function(e) {
    const draftCount = document.querySelectorAll('.member-card.draft').length;
    if (formChanged && draftCount > 0) {
        e.preventDefault();
        e.returnValue = 'You have unsaved draft marks. Are you sure you want to leave?';
    }
});

document.getElementById('attendanceForm').addEventListener('submit', function() {
    formChanged = false;
});

// Initialize stats on page load
updateStats();
</script>

<?php 
$page_content = ob_get_clean(); 
$page_title = 'Mark Attendance - ' . $session['title'];
include '../includes/layout.php'; 
?>
