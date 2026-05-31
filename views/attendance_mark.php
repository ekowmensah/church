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

function attendance_scope_columns_available($conn) {
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $sql = "SELECT COUNT(*) AS cnt
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'attendance_sessions'
              AND COLUMN_NAME IN ('attendance_scope', 'scope_id')";
    $res = $conn->query($sql);
    $row = $res ? $res->fetch_assoc() : null;
    $available = ($row && intval($row['cnt']) === 2);
    return $available;
}

function safe_display_date($rawDate) {
    if (!$rawDate || $rawDate === '0000-00-00') {
        return 'No date set';
    }
    $ts = strtotime($rawDate);
    if ($ts === false) {
        return 'Invalid date';
    }
    return date('l, F j, Y', $ts);
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
$scope_columns_available = attendance_scope_columns_available($conn);
$session_scope = $scope_columns_available ? strtolower(trim((string)($session['attendance_scope'] ?? ''))) : '';
$session_scope_id = $scope_columns_available ? intval($session['scope_id'] ?? 0) : 0;
$session_display_date = safe_display_date($session['service_date'] ?? '');

// Get filter options
$bible_classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");
$organizations = $conn->query("SELECT id, name FROM organizations ORDER BY name ASC");

// Get filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build member query with filters
$sql = "SELECT m.id, m.first_name, m.last_name, m.middle_name, m.crn, 
        m.class_id, bc.name AS class_name, m.gender
        FROM members m 
        LEFT JOIN bible_classes bc ON m.class_id = bc.id ";
if ($filter_org || ($session_scope === 'organization' && $session_scope_id > 0)) {
    $sql .= "LEFT JOIN member_organizations mo ON mo.member_id = m.id ";
}
$sql .= "WHERE m.church_id = ? AND m.status = 'active' ";
$params = [$session['church_id']];
$types = 'i';

if ($session_scope === 'bible_class' && $session_scope_id > 0) {
    $sql .= "AND m.class_id = ? ";
    $params[] = $session_scope_id;
    $types .= 'i';
} elseif ($filter_class) {
    $sql .= "AND m.class_id = ? ";
    $params[] = $filter_class;
    $types .= 'i';
}

if ($session_scope === 'organization' && $session_scope_id > 0) {
    $sql .= "AND mo.organization_id = ? ";
    $params[] = $session_scope_id;
    $types .= 'i';
} elseif ($filter_org) {
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

// Fetch member organizations for filtering
$member_orgs = [];
if (count($members) > 0) {
    $member_ids = array_column($members, 'id');
    $placeholders = implode(',', array_fill(0, count($member_ids), '?'));
    $sql_orgs = "SELECT member_id, GROUP_CONCAT(organization_id) as org_ids 
                 FROM member_organizations 
                 WHERE member_id IN ($placeholders) 
                 GROUP BY member_id";
    $stmt_orgs = $conn->prepare($sql_orgs);
    $types_orgs = str_repeat('i', count($member_ids));
    $stmt_orgs->bind_param($types_orgs, ...$member_ids);
    $stmt_orgs->execute();
    $result_orgs = $stmt_orgs->get_result();
    while ($row = $result_orgs->fetch_assoc()) {
        $member_orgs[$row['member_id']] = $row['org_ids'];
    }
}

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
$sick_count = 0;
$permission_count = 0;
$distance_count = 0;
$invalid_count = 0;
$draft_count = 0;
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
    
    // Validate status
    $valid_statuses = ['present', 'absent', 'sick', 'permission', 'distance', 'invalid'];
    if (!in_array($status, $valid_statuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit;
    }
    
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
    $valid_statuses = ['present', 'absent', 'sick', 'permission', 'distance', 'invalid'];

    if ($scope_columns_available && ($session_scope === '' || ($session_scope === 'church' && $session_scope_id === 0))) {
        $newScope = null;
        $newScopeId = 0;
        if (!empty($filter_class) && ctype_digit((string)$filter_class)) {
            $newScope = 'bible_class';
            $newScopeId = intval($filter_class);
        } elseif (!empty($filter_org) && ctype_digit((string)$filter_org)) {
            $newScope = 'organization';
            $newScopeId = intval($filter_org);
        }

        if ($newScope !== null && $newScopeId > 0) {
            $scopeStmt = $conn->prepare("UPDATE attendance_sessions SET attendance_scope = ?, scope_id = ? WHERE id = ?");
            $scopeStmt->bind_param('sii', $newScope, $newScopeId, $session_id);
            $scopeStmt->execute();
            $scopeStmt->close();
        }
    }
    
    // Process all members for this session's church
    foreach ($members as $m) {
        $member_id = $m['id'];
        // Get status from POST data, default to 'absent' if not set or invalid
        $status = isset($marked[$member_id]) && in_array($marked[$member_id], $valid_statuses) 
                  ? $marked[$member_id] 
                  : 'absent';
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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />
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
        .stat-box.sick { border-left-color: #ffc107; }
        .stat-box.permission { border-left-color: #17a2b8; }
        .stat-box.distance { border-left-color: #fd7e14; }
        .stat-box.invalid { border-left-color: #6c757d; }
        .stat-box.rate { border-left-color: #667eea; }
        
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
        
        .members-table tbody tr.draft {
            background-color: #fffbf0;
            border-left: 4px solid #ffc107;
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
        
        .member-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .status-radio-group {
            display: flex;
            gap: 4px;
            flex-wrap: nowrap;
            align-items: center;
            justify-content: flex-start;
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
        
        .badge-sm {
            font-size: 0.65rem;
            padding: 2px 6px;
        }
        
        .row-number {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.9rem;
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
            .stats-row {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .members-table thead th:nth-child(4),
            .members-table tbody td:nth-child(4) {
                display: none;
            }
            
            .status-radio label {
                padding: 3px 5px;
                font-size: 0.65rem;
            }
        }
        
        @media (max-width: 768px) {
            .stats-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
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
            
            .members-table thead th:nth-child(1) {
                width: 30px;
            }
            
            .members-table thead th:nth-child(5),
            .members-table tbody td:nth-child(5) {
                display: none;
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
            
            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
            
            .bulk-actions-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .bulk-actions-buttons .btn {
                flex: 1;
                font-size: 0.8rem;
                padding: 6px 8px;
            }
        }
        
        @media (max-width: 576px) {
            .attendance-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .session-info {
                flex-direction: column;
                gap: 10px;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .filter-card .row {
                margin: 0;
            }
            
            .filter-card .col-md-4 {
                padding: 0 0 10px 0;
            }
            
            .members-table thead th:nth-child(3),
            .members-table tbody td:nth-child(3) {
                display: none;
            }
            
            .save-buttons-fixed {
                left: 10px;
                right: 10px;
                bottom: 10px;
            }
            
            .save-buttons-fixed .btn {
                min-width: 100%;
                font-size: 0.9rem;
            }
        }
        
        /* Select2 custom styling */
        .select2-container--bootstrap4 .select2-selection {
            border-radius: 8px;
            border: 1px solid #ced4da;
            min-height: 38px;
        }
        
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
        }
        
        .select2-container--bootstrap4 .select2-dropdown {
            border-radius: 8px;
            border: 1px solid #ced4da;
        }
        
        .select2-container--bootstrap4 .select2-results__option--highlighted {
            background-color: #667eea;
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
                        <span><?= htmlspecialchars($session_display_date) ?></span>
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
            <div class="stat-label"><i class="fas fa-check-circle"></i> Present</div>
            <div class="stat-value" id="present-count"><?= $present_count ?></div>
        </div>
        <div class="stat-box absent">
            <div class="stat-label"><i class="fas fa-times-circle"></i> Absent</div>
            <div class="stat-value" id="absent-count"><?= $absent_count ?></div>
        </div>
        <div class="stat-box sick">
            <div class="stat-label"><i class="fas fa-thermometer"></i> Sick</div>
            <div class="stat-value" id="sick-count"><?= $sick_count ?></div>
        </div>
        <div class="stat-box permission">
            <div class="stat-label"><i class="fas fa-user-check"></i> Permission</div>
            <div class="stat-value" id="permission-count"><?= $permission_count ?></div>
        </div>
        <div class="stat-box distance">
            <div class="stat-label"><i class="fas fa-road"></i> Distance</div>
            <div class="stat-value" id="distance-count"><?= $distance_count ?></div>
        </div>
        <div class="stat-box invalid">
            <div class="stat-label"><i class="fas fa-exclamation-triangle"></i> Invalid</div>
            <div class="stat-value" id="invalid-count"><?= $invalid_count ?></div>
        </div>
        <div class="stat-box rate">
            <div class="stat-label"><i class="fas fa-chart-line"></i> Attendance Rate</div>
            <div class="stat-value" id="attendance-rate"><?= $attendance_rate ?>%</div>
        </div>
    </div>

    <div class="filter-card">
        <h5><i class="fas fa-filter"></i> Filter Members (Real-time)</h5>
        <form method="get" id="filterForm">
            <input type="hidden" name="id" value="<?= $session_id ?>">
            <div class="row">
                <div class="col-md-4 mb-3">
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
                 <!--   <small class="text-muted">Select to filter instantly</small> -->
                </div>
                <div class="col-md-4 mb-3">
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
                <!--    <small class="text-muted">Select to filter instantly</small> -->
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" id="realtimeSearch" 
                           placeholder="Search by name or CRN..." 
                           value="<?= htmlspecialchars($search) ?>">
                    <small class="text-muted">Type to filter instantly</small>
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

        <div class="members-list-container">
            <table class="members-table" id="membersTable">
                <thead>
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th style="width: 250px;">Member</th>
                        <th style="width: 120px;">CRN</th>
                        <th style="width: 150px;">Class</th>
                        <th style="width: 80px;">Gender</th>
                        <th>Attendance Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($members as $index => $member): 
                        $current_status = strtolower($prev_attendance[$member['id']] ?? 'absent');
                        $is_draft = isset($draft_status[$member['id']]) && $draft_status[$member['id']] == 1;
                    ?>
                    <tr class="<?= $is_draft ? 'draft' : '' ?>" 
                        data-member-id="<?= $member['id'] ?>"
                        data-member-name="<?= htmlspecialchars(strtolower($member['last_name'] . ' ' . $member['first_name'] . ' ' . $member['middle_name'])) ?>"
                        data-member-crn="<?= htmlspecialchars(strtolower($member['crn'] ?? '')) ?>"
                        data-member-class="<?= $member['class_id'] ?? '' ?>"
                        data-member-org="<?= isset($member_orgs[$member['id']]) ? $member_orgs[$member['id']] : '' ?>">
                        <td class="row-number"><?= $index + 1 ?></td>
                        <td>
                            <div class="member-name">
                                <?= htmlspecialchars($member['last_name'] . ', ' . $member['first_name']) ?>
                                <?php if ($is_draft): ?>
                                    <span class="badge badge-warning badge-sm ml-1">DRAFT</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="member-crn">
                                <i class="fas fa-id-card"></i> <?= htmlspecialchars($member['crn'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($member['class_name']): ?>
                                <span class="meta-badge">
                                    <i class="fas fa-book"></i> <?= htmlspecialchars($member['class_name']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($member['gender']): ?>
                                <i class="fas fa-<?= strtolower($member['gender']) === 'male' ? 'mars' : 'venus' ?>"></i>
                                <?= htmlspecialchars($member['gender']) ?>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
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
    
    // Mark as draft
    row.classList.add('draft');
    const nameCell = row.querySelector('.member-name');
    if (!nameCell.querySelector('.badge-warning')) {
        const draftBadge = document.createElement('span');
        draftBadge.className = 'badge badge-warning badge-sm ml-1';
        draftBadge.textContent = 'DRAFT';
        nameCell.appendChild(draftBadge);
    }
    
    updateStats();
    
    // Auto-save as draft after 1 second of inactivity
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        saveDraft(memberId, status);
    }, 1000);
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
    
    // Mark as draft
    row.classList.add('draft');
    const nameCell = row.querySelector('.member-name');
    if (!nameCell.querySelector('.badge-warning')) {
        const draftBadge = document.createElement('span');
        draftBadge.className = 'badge badge-warning badge-sm ml-1';
        draftBadge.textContent = 'DRAFT';
        nameCell.appendChild(draftBadge);
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
    const rows = document.querySelectorAll('#membersTable tbody tr');
    const total = rows.length;
    let present = 0;
    let absent = 0;
    let sick = 0;
    let permission = 0;
    let distance = 0;
    let invalid = 0;
    let drafts = 0;
    
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
        if (row.classList.contains('draft')) drafts++;
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

function confirmFinalize() {
    const draftCount = document.querySelectorAll('#membersTable tbody tr.draft').length;
    if (draftCount > 0) {
        return confirm(`You have ${draftCount} draft mark(s). Finalizing will save all attendance records permanently. Continue?`);
    }
    return confirm('Finalize attendance? This will save all records permanently.');
}

// Real-time search functionality
const searchInput = document.getElementById('realtimeSearch');
const classFilter = document.querySelector('select[name="class_id"]');
const orgFilter = document.querySelector('select[name="organization_id"]');

function applyFilters() {
    const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
    const selectedClass = classFilter ? classFilter.value : '';
    const selectedOrg = orgFilter ? orgFilter.value : '';
    
    const rows = document.querySelectorAll('#membersTable tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const memberName = row.dataset.memberName || '';
        const memberCrn = row.dataset.memberCrn || '';
        const memberClass = row.dataset.memberClass || '';
        const memberOrg = row.dataset.memberOrg || '';
        
        // Check search term
        const matchesSearch = !searchTerm || memberName.includes(searchTerm) || memberCrn.includes(searchTerm);
        
        // Check class filter
        const matchesClass = !selectedClass || memberClass === selectedClass;
        
        // Check organization filter
        const matchesOrg = !selectedOrg || memberOrg.includes(selectedOrg);
        
        if (matchesSearch && matchesClass && matchesOrg) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    // Update visible count in bulk actions
    const bulkActionsTitle = document.querySelector('.bulk-actions h6');
    if (bulkActionsTitle) {
        bulkActionsTitle.innerHTML = `<i class="fas fa-users"></i> Mark Attendance (${visibleCount} of ${rows.length} members shown)`;
    }
    
    console.log(`Showing ${visibleCount} of ${rows.length} members`);
}

// Real-time search
if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
}

// Real-time class filter
if (classFilter) {
    classFilter.addEventListener('change', applyFilters);
}

// Real-time organization filter
if (orgFilter) {
    orgFilter.addEventListener('change', applyFilters);
}

function markAllPresent() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const presentRadio = row.querySelector('input[value="present"]');
            const mobileSelect = row.querySelector('.status-select-mobile');
            
            if (presentRadio) {
                presentRadio.checked = true;
                updateMemberRadio(presentRadio);
            } else if (mobileSelect) {
                mobileSelect.value = 'present';
                updateMemberSelect(mobileSelect);
            }
        }
    });
}

function markAllAbsent() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const absentRadio = row.querySelector('input[value="absent"]');
            const mobileSelect = row.querySelector('.status-select-mobile');
            
            if (absentRadio) {
                absentRadio.checked = true;
                updateMemberRadio(absentRadio);
            } else if (mobileSelect) {
                mobileSelect.value = 'absent';
                updateMemberSelect(mobileSelect);
            }
        }
    });
}

function toggleAll() {
    const rows = document.querySelectorAll('#membersTable tbody tr');
    rows.forEach(row => {
        if (row.style.display !== 'none') {
            const checkedRadio = row.querySelector('input[type="radio"]:checked');
            const mobileSelect = row.querySelector('.status-select-mobile');
            
            if (checkedRadio) {
                const newStatus = checkedRadio.value === 'present' ? 'absent' : 'present';
                const newRadio = row.querySelector(`input[value="${newStatus}"]`);
                if (newRadio) {
                    newRadio.checked = true;
                    updateMemberRadio(newRadio);
                }
            } else if (mobileSelect) {
                const currentStatus = mobileSelect.value;
                const newStatus = currentStatus === 'present' ? 'absent' : 'present';
                mobileSelect.value = newStatus;
                updateMemberSelect(mobileSelect);
            }
        }
    });
}

// Confirm before leaving if unsaved changes
let formChanged = false;
document.getElementById('attendanceForm').addEventListener('change', function() {
    formChanged = true;
});

window.addEventListener('beforeunload', function(e) {
    const draftCount = document.querySelectorAll('#membersTable tbody tr.draft').length;
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

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
// Initialize Select2 for searchable dropdowns
$(document).ready(function() {
    // Initialize Bible Class dropdown with search
    $('select[name="class_id"]').select2({
        theme: 'bootstrap4',
        placeholder: 'All Classes',
        allowClear: true,
        width: '100%'
    });
    
    // Initialize Organization dropdown with search
    $('select[name="organization_id"]').select2({
        theme: 'bootstrap4',
        placeholder: 'All Organizations',
        allowClear: true,
        width: '100%'
    });
    
    // Re-attach change event listeners after Select2 initialization
    $('select[name="class_id"]').on('change', function() {
        applyFilters();
    });
    
    $('select[name="organization_id"]').on('change', function() {
        applyFilters();
    });
});
</script>

<?php 
$page_content = ob_get_clean(); 
$page_title = 'Mark Attendance - ' . $session['title'];
include '../includes/layout.php'; 
?>
