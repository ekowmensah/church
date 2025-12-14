<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_attendance_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

$can_add = $is_super_admin || has_permission('create_attendance');
$can_edit = $is_super_admin || has_permission('edit_attendance');
$can_delete = $is_super_admin || has_permission('delete_attendance');
$can_view = true;

// Filters
$filter_church = $_GET['church_id'] ?? '';
$filter_type = $_GET['session_type'] ?? ''; // all, recurring, one-time
$filter_status = $_GET['status'] ?? ''; // all, marked, unmarked
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_term = trim($_GET['search'] ?? '');
$view_date = $_GET['view_date'] ?? date('Y-m-d'); // Date to view sessions for

// Pagination
$records_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
if ($records_per_page <= 0 || $records_per_page > 500) $records_per_page = 25;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

$success_msg = '';
$error_msg = '';

// Auto-create recurring sessions for today if they don't exist
function auto_create_recurring_sessions($conn) {
    $today = date('Y-m-d');
    $day_of_week = date('w'); // 0 (Sunday) to 6 (Saturday)
    $month = date('n'); // 1 to 12
    
    // Get all recurring session templates
    $templates = $conn->query("SELECT * FROM attendance_sessions WHERE is_recurring = 1 GROUP BY title, church_id, recurrence_type, recurrence_day");
    
    $created_count = 0;
    while ($template = $templates->fetch_assoc()) {
        $should_create = false;
        
        if ($template['recurrence_type'] === 'weekly' && $template['recurrence_day'] == $day_of_week) {
            $should_create = true;
        } elseif ($template['recurrence_type'] === 'monthly' && $template['recurrence_day'] == $month) {
            $should_create = true;
        }
        
        if ($should_create) {
            // Check if session already exists for today
            $check = $conn->prepare("SELECT COUNT(*) as cnt FROM attendance_sessions WHERE title = ? AND church_id = ? AND service_date = ?");
            $check->bind_param('sis', $template['title'], $template['church_id'], $today);
            $check->execute();
            $result = $check->get_result()->fetch_assoc();
            
            if ($result['cnt'] == 0) {
                // Create session for today
                $insert = $conn->prepare("INSERT INTO attendance_sessions (title, church_id, is_recurring, recurrence_type, recurrence_day, service_date) VALUES (?, ?, 1, ?, ?, ?)");
                $insert->bind_param('sisis', $template['title'], $template['church_id'], $template['recurrence_type'], $template['recurrence_day'], $today);
                if ($insert->execute()) {
                    $created_count++;
                }
                $insert->close();
            }
            $check->close();
        }
    }
    
    return $created_count;
}

// Run auto-creation on page load
$auto_created = auto_create_recurring_sessions($conn);
if ($auto_created > 0) {
    $success_msg = "Auto-created $auto_created recurring session(s) for today.";
}

// Handle create next recurring session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_next_recurring']) && isset($_POST['recurring_template_id'])) {
    $template_id = intval($_POST['recurring_template_id']);
    $tpl_q = $conn->prepare("SELECT * FROM attendance_sessions WHERE id = ? LIMIT 1");
    $tpl_q->bind_param('i', $template_id);
    $tpl_q->execute();
    $tpl_result = $tpl_q->get_result();
    if ($tpl_result && $tpl_result->num_rows > 0) {
        $row = $tpl_result->fetch_assoc();
        $rec_type = $row['recurrence_type'];
        $rec_day = $row['recurrence_day'];
        // Find the latest session for this template (by title, church, recurrence_type, recurrence_day)
        $latest = $conn->prepare("SELECT * FROM attendance_sessions WHERE is_recurring = 1 AND title = ? AND church_id = ? AND recurrence_type = ? AND recurrence_day = ? ORDER BY service_date DESC LIMIT 1");
        $latest->bind_param('sisi', $row['title'], $row['church_id'], $rec_type, $rec_day);
        $latest->execute();
        $latest_result = $latest->get_result();
        if ($latest_result && $latest_result->num_rows > 0) {
            $last = $latest_result->fetch_assoc();
            $next_date = '';
            if ($rec_type === 'weekly') {
                $last_date = $last['service_date'];
                $next_date = date('Y-m-d', strtotime($last_date . ' +7 days'));
            } elseif ($rec_type === 'monthly') {
                $last_date = $last['service_date'];
                $next_date = date('Y-m-d', strtotime($last_date . ' +1 month'));
            }
            // Check if session already exists for next_date
            $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance_sessions WHERE is_recurring = 1 AND title = ? AND church_id = ? AND recurrence_type = ? AND recurrence_day = ? AND service_date = ?");
            $stmt->bind_param('sisis', $row['title'], $row['church_id'], $rec_type, $rec_day, $next_date);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            if ($count == 0 && $next_date) {
                // Copy all fields except id, service_date
                $stmt = $conn->prepare("INSERT INTO attendance_sessions (title, church_id, is_recurring, recurrence_type, recurrence_day, service_date) VALUES (?, ?, 1, ?, ?, ?)");
                $stmt->bind_param('sisis', $row['title'], $row['church_id'], $rec_type, $rec_day, $next_date);
                if ($stmt->execute()) {
                    $success_msg = 'Next recurring session created for ' . htmlspecialchars($next_date);
                } else {
                    $error_msg = 'Failed to create next recurring session.';
                }
                $stmt->close();
            } else {
                $error_msg = 'Session for next occurrence already exists or invalid date.';
            }
        } else {
            $error_msg = 'No previous session found for this template.';
        }
        $latest->close();
    } else {
        $error_msg = 'Template not found.';
    }
    $tpl_q->close();
}
// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    $stmt = $conn->prepare("DELETE FROM attendance_sessions WHERE id = ?");
    $stmt->bind_param('i', $delete_id);
    $stmt->execute();
    // Optionally: delete related attendance_records
    $stmt2 = $conn->prepare("DELETE FROM attendance_records WHERE session_id = ?");
    $stmt2->bind_param('i', $delete_id);
    $stmt2->execute();
    header('Location: attendance_list.php?deleted=1');
    exit;
}

// Build SQL query with filters
$session_sql = "SELECT s.*, c.name AS church_name,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.id) as attendance_count,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.id AND ar.status = 'present') as present_count
FROM attendance_sessions s 
LEFT JOIN churches c ON s.church_id = c.id 
WHERE 1=1";

$session_params = [];
$session_types = '';

// Role-based filtering
if (is_class_leader() || is_organizational_leader()) {
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($user_id) {
        $user_church_sql = "SELECT m.church_id FROM users u JOIN members m ON u.member_id = m.id WHERE u.id = ?";
        $user_church_stmt = $conn->prepare($user_church_sql);
        $user_church_stmt->bind_param('i', $user_id);
        $user_church_stmt->execute();
        $user_church_result = $user_church_stmt->get_result();
        if ($user_church_row = $user_church_result->fetch_assoc()) {
            $session_sql .= " AND s.church_id = ?";
            $session_params[] = $user_church_row['church_id'];
            $session_types .= 'i';
        }
        $user_church_stmt->close();
    }
}

// Apply filters
if ($filter_church) {
    $session_sql .= " AND s.church_id = ?";
    $session_params[] = $filter_church;
    $session_types .= 'i';
}

if ($filter_type === 'recurring') {
    $session_sql .= " AND s.is_recurring = 1";
} elseif ($filter_type === 'one-time') {
    $session_sql .= " AND s.is_recurring = 0";
}

if ($date_from) {
    $session_sql .= " AND s.service_date >= ?";
    $session_params[] = $date_from;
    $session_types .= 's';
}

if ($date_to) {
    $session_sql .= " AND s.service_date <= ?";
    $session_params[] = $date_to;
    $session_types .= 's';
}

if ($search_term) {
    $session_sql .= " AND (s.title LIKE ? OR c.name LIKE ?)";
    $search_like = "%$search_term%";
    $session_params[] = $search_like;
    $session_params[] = $search_like;
    $session_types .= 'ss';
}

// Get total count - build separate count query
$count_sql = "SELECT COUNT(DISTINCT s.id) as total 
FROM attendance_sessions s 
LEFT JOIN churches c ON s.church_id = c.id 
WHERE 1=1";

// Apply same filters to count query
$count_params = [];
$count_types = '';

// Role-based filtering
if (is_class_leader() || is_organizational_leader()) {
    $user_id = $_SESSION['user_id'] ?? 0;
    if ($user_id) {
        $user_church_sql = "SELECT m.church_id FROM users u JOIN members m ON u.member_id = m.id WHERE u.id = ?";
        $user_church_stmt = $conn->prepare($user_church_sql);
        $user_church_stmt->bind_param('i', $user_id);
        $user_church_stmt->execute();
        $user_church_result = $user_church_stmt->get_result();
        if ($user_church_row = $user_church_result->fetch_assoc()) {
            $count_sql .= " AND s.church_id = ?";
            $count_params[] = $user_church_row['church_id'];
            $count_types .= 'i';
        }
        $user_church_stmt->close();
    }
}

if ($filter_church) {
    $count_sql .= " AND s.church_id = ?";
    $count_params[] = $filter_church;
    $count_types .= 'i';
}

if ($filter_type === 'recurring') {
    $count_sql .= " AND s.is_recurring = 1";
} elseif ($filter_type === 'one-time') {
    $count_sql .= " AND s.is_recurring = 0";
}

if ($date_from) {
    $count_sql .= " AND s.service_date >= ?";
    $count_params[] = $date_from;
    $count_types .= 's';
}

if ($date_to) {
    $count_sql .= " AND s.service_date <= ?";
    $count_params[] = $date_to;
    $count_types .= 's';
}

if ($search_term) {
    $count_sql .= " AND (s.title LIKE ? OR c.name LIKE ?)";
    $search_like = "%$search_term%";
    $count_params[] = $search_like;
    $count_params[] = $search_like;
    $count_types .= 'ss';
}

if ($count_types) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
    $total_records = $count_result ? $count_result['total'] : 0;
} else {
    $count_result = $conn->query($count_sql);
    $count_row = $count_result ? $count_result->fetch_assoc() : null;
    $total_records = $count_row ? $count_row['total'] : 0;
}

$total_pages = ceil($total_records / $records_per_page);
$current_page = min($current_page, max(1, $total_pages));

// Add ORDER BY and LIMIT
$session_sql .= " ORDER BY s.service_date DESC, s.id DESC LIMIT ? OFFSET ?";
$session_params[] = $records_per_page;
$session_params[] = $offset;
$session_types .= 'ii';

if ($session_types) {
    $result = $conn->prepare($session_sql);
    $result->bind_param($session_types, ...$session_params);
    $result->execute();
    $result = $result->get_result();
} else {
    $result = $conn->query($session_sql);
}

// Get statistics
$stats_total = $conn->query("SELECT COUNT(*) as cnt FROM attendance_sessions")->fetch_assoc()['cnt'];
$stats_recurring = $conn->query("SELECT COUNT(*) as cnt FROM attendance_sessions WHERE is_recurring = 1")->fetch_assoc()['cnt'];
$stats_onetime = $conn->query("SELECT COUNT(*) as cnt FROM attendance_sessions WHERE is_recurring = 0")->fetch_assoc()['cnt'];
$stats_marked = $conn->query("SELECT COUNT(DISTINCT session_id) as cnt FROM attendance_records")->fetch_assoc()['cnt'];

// Get sessions for selected date
$today_sessions_sql = "SELECT s.*, c.name AS church_name,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.id) as attendance_count,
    (SELECT COUNT(*) FROM attendance_records ar WHERE ar.session_id = s.id AND ar.status = 'present') as present_count
FROM attendance_sessions s 
LEFT JOIN churches c ON s.church_id = c.id 
WHERE s.service_date = ? 
ORDER BY s.title";
$today_stmt = $conn->prepare($today_sessions_sql);
$today_stmt->bind_param('s', $view_date);
$today_stmt->execute();
$today_sessions = $today_stmt->get_result();

// Get churches for filter
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name");

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .attendance-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .attendance-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .attendance-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: -30px 20px 30px 20px;
            position: relative;
            z-index: 10;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card.purple { border-left-color: #667eea; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.info { border-left-color: #17a2b8; }
        
        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-card.purple .icon { color: #667eea; }
        .stat-card.success .icon { color: #28a745; }
        .stat-card.warning .icon { color: #ffc107; }
        .stat-card.info .icon { color: #17a2b8; }
        
        .stat-card .label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .stat-card .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }
        
        .table-wrapper {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .table-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .badge-recurring { background: #667eea; color: #fff; }
        .badge-onetime { background: #17a2b8; color: #fff; }
        .badge-marked { background: #28a745; color: #fff; }
        .badge-unmarked { background: #ffc107; color: #000; }
        
        .pagination-banking {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 20px;
            background: #f8f9fa;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
    </style>
</head>
<body>

<div class="attendance-container">
    <div class="attendance-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1>
                <i class="fas fa-calendar-check"></i>
                Attendance Sessions
            </h1>
            <div>
                <?php if ($can_add): ?>
                    <a href="attendance_form.php" class="btn btn-light btn-lg">
                        <i class="fas fa-plus"></i> Add Session
                    </a>
                <?php endif; ?>
                <?php
                $recurring_check = $conn->query("SELECT * FROM attendance_sessions WHERE is_recurring = 1 ORDER BY service_date DESC LIMIT 1");
                if ($recurring_check && $recurring_check->num_rows > 0): ?>
                    <button type="button" class="btn btn-success btn-lg ml-2" data-toggle="modal" data-target="#nextRecurringModal">
                        <i class="fas fa-sync-alt"></i> Create Next Recurring
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
            <i class="fas fa-check-circle mr-2"></i><?= $success_msg ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php elseif ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show mx-3 mt-3" role="alert">
            <i class="fas fa-exclamation-circle mr-2"></i><?= $error_msg ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show mx-3 mt-3" role="alert">
            <i class="fas fa-check-circle mr-2"></i>Session deleted successfully!
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <!-- Today's Sessions Section -->
    <div class="mx-3 mt-3">
        <div class="card shadow-sm">
            <div class="card-header bg-gradient-primary text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-calendar-day"></i> 
                        Sessions for <?= date('l, F j, Y', strtotime($view_date)) ?>
                    </h5>
                    <form method="get" class="form-inline">
                        <input type="date" name="view_date" class="form-control form-control-sm" 
                               value="<?= $view_date ?>" 
                               onchange="this.form.submit()">
                        <button type="button" class="btn btn-light btn-sm ml-2" 
                                onclick="window.location.href='?view_date=<?= date('Y-m-d') ?>'">
                            Today
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php if ($today_sessions && $today_sessions->num_rows > 0): ?>
                    <div class="row">
                        <?php while($session = $today_sessions->fetch_assoc()): 
                            $is_marked = $session['attendance_count'] > 0;
                        ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card h-100 border-left-primary">
                                <div class="card-body">
                                    <h6 class="text-primary font-weight-bold">
                                        <?= htmlspecialchars($session['title']) ?>
                                    </h6>
                                    <p class="text-muted mb-2">
                                        <i class="fas fa-church"></i> 
                                        <?= htmlspecialchars($session['church_name'] ?? 'N/A') ?>
                                    </p>
                                    <?php if ($session['is_recurring']): ?>
                                        <span class="badge badge-recurring mb-2">
                                            <i class="fas fa-sync-alt"></i> Recurring
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($is_marked): ?>
                                        <div class="mt-2">
                                            <small class="text-success">
                                                <i class="fas fa-check-circle"></i> 
                                                <?= $session['present_count'] ?>/<?= $session['attendance_count'] ?> present
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2">
                                            <small class="text-warning">
                                                <i class="fas fa-exclamation-circle"></i> Not marked
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    <div class="mt-3">
                                        <?php if ($is_marked): ?>
                                            <a href="attendance_mark.php?id=<?= $session['id'] ?>" 
                                               class="btn btn-warning btn-sm btn-block">
                                                <i class="fas fa-redo"></i> Re-Mark
                                            </a>
                                        <?php else: ?>
                                            <a href="attendance_mark.php?id=<?= $session['id'] ?>" 
                                               class="btn btn-success btn-sm btn-block">
                                                <i class="fas fa-check"></i> Mark Attendance
                                            </a>
                                        <?php endif; ?>
                                        <a href="attendance_view.php?id=<?= $session['id'] ?>" 
                                           class="btn btn-info btn-sm btn-block mt-1">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No sessions scheduled for this date</h5>
                        <p class="text-muted">Try selecting a different date or create a new session.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="stats-dashboard">
        <div class="stat-card purple">
            <div class="icon"><i class="fas fa-calendar-alt"></i></div>
            <div class="label">Total Sessions</div>
            <div class="value"><?= number_format($stats_total) ?></div>
        </div>
        <div class="stat-card success">
            <div class="icon"><i class="fas fa-sync-alt"></i></div>
            <div class="label">Recurring</div>
            <div class="value"><?= number_format($stats_recurring) ?></div>
        </div>
        <div class="stat-card info">
            <div class="icon"><i class="fas fa-calendar-day"></i></div>
            <div class="label">One-Time</div>
            <div class="value"><?= number_format($stats_onetime) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <div class="label">Marked Sessions</div>
            <div class="value"><?= number_format($stats_marked) ?></div>
        </div>
    </div>

    <div class="filter-section">
        <h5><i class="fas fa-filter"></i> Filters & Search</h5>
        <form action="" method="get" id="filterForm">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Church</label>
                    <select class="form-select" name="church_id">
                        <option value="">All Churches</option>
                        <?php while ($church = $churches->fetch_assoc()): ?>
                            <option value="<?= $church['id'] ?>" <?= $filter_church == $church['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($church['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Session Type</label>
                    <select class="form-select" name="session_type">
                        <option value="">All Types</option>
                        <option value="recurring" <?= $filter_type == 'recurring' ? 'selected' : '' ?>>Recurring</option>
                        <option value="one-time" <?= $filter_type == 'one-time' ? 'selected' : '' ?>>One-Time</option>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Per Page</label>
                    <select class="form-select" name="per_page">
                        <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by session title or church name..." 
                           value="<?= htmlspecialchars($search_term) ?>">
                </div>
                <div class="col-md-6 mb-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="attendance_list.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
    </div>
    <div class="table-wrapper">
        <div class="table-header">
            <h5 class="mb-0">
                <i class="fas fa-list"></i> 
                Sessions (<?= number_format($total_records) ?> records)
            </h5>
        </div>
        
        <?php if ($result && $result->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Session Details</th>
                        <th>Church</th>
                        <th>Type</th>
                        <th>Service Date</th>
                        <th>Attendance</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): 
                        $is_marked = $row['attendance_count'] > 0;
                        $attendance_rate = $row['attendance_count'] > 0 ? round(($row['present_count'] / $row['attendance_count']) * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <div>
                                <strong class="text-primary"><?= htmlspecialchars($row['title']) ?></strong>
                                <?php if ($row['is_recurring']): ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-sync-alt"></i>
                                        <?= ucfirst($row['recurrence_type']) ?> - 
                                        <?php
                                        if ($row['recurrence_type'] === 'weekly') {
                                            $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                            echo $days[$row['recurrence_day']] ?? '—';
                                        } elseif ($row['recurrence_type'] === 'monthly') {
                                            $months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
                                            echo $months[$row['recurrence_day']] ?? '—';
                                        }
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= $row['church_name'] ? htmlspecialchars($row['church_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <span class="badge <?= $row['is_recurring'] ? 'badge-recurring' : 'badge-onetime' ?>">
                                <?= $row['is_recurring'] ? 'Recurring' : 'One-Time' ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $service_date = $row['service_date'];
                            $timestamp = strtotime($service_date);
                            if ($timestamp !== false && $timestamp > 0): ?>
                                <strong><?= date('M j, Y', $timestamp) ?></strong>
                                <br>
                                <small class="text-muted"><?= date('l', $timestamp) ?></small>
                            <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($service_date) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_marked): ?>
                                <div>
                                    <strong class="text-success"><?= $row['present_count'] ?></strong> / <?= $row['attendance_count'] ?>
                                    <br>
                                    <small class="text-muted"><?= $attendance_rate ?>% present</small>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">Not marked</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm" role="group">
                                <?php if ($is_marked): ?>
                                    <a href="attendance_mark.php?id=<?= $row['id'] ?>" 
                                       class="btn btn-warning" 
                                       title="Re-Mark Attendance">
                                        <i class="fas fa-redo"></i>
                                    </a>
                                <?php else: ?>
                                    <a href="attendance_mark.php?id=<?= $row['id'] ?>" 
                                       class="btn btn-success" 
                                       title="Mark Attendance">
                                        <i class="fas fa-check"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <a href="attendance_view.php?id=<?= $row['id'] ?>" 
                                   class="btn btn-info" 
                                   title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                
                                <?php if ($can_edit && !$is_marked): ?>
                                    <a href="attendance_form.php?id=<?= $row['id'] ?>" 
                                       class="btn btn-primary" 
                                       title="Edit Session">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($can_delete && !$is_marked): ?>
                                    <form method="post" action="attendance_list.php" style="display:inline;" 
                                          onsubmit="return confirm('Delete this session? This will remove all attendance records.');">                                        <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn btn-danger" title="Delete Session">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <div class="pagination-banking">
            <?php if ($current_page > 1): ?>
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <?php 
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
                <span class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                        <?= $i ?>
                    </a>
                </span>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
            
            <div class="text-muted ms-3">
                <small>
                    Showing <?= (($current_page - 1) * $records_per_page) + 1 ?> to 
                    <?= min($current_page * $records_per_page, $total_records) ?> of 
                    <?= number_format($total_records) ?> records
                </small>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h4>No Sessions Found</h4>
            <p class="text-muted">No attendance sessions match your current filters.</p>
            <?php if ($can_add): ?>
                <a href="attendance_form.php" class="btn btn-primary mt-3">
                    <i class="fas fa-plus"></i> Create First Session
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php 
$page_content = ob_get_clean(); 
$page_title = 'Attendance Sessions';

// Define modal outside output buffer
$modal_html = '';
$recurring_check = $conn->query("SELECT * FROM attendance_sessions WHERE is_recurring = 1 ORDER BY service_date DESC LIMIT 1");
if ($recurring_check && $recurring_check->num_rows > 0) {
    ob_start();
    ?>
    <!-- Recurring Session Modal -->
    <div class="modal fade" id="nextRecurringModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="post" action="attendance_list.php">
                    <div class="modal-header">
                        <h5 class="modal-title">Create Next Recurring Session</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="recurring_template_id">Select Session Template</label>
                            <select class="form-control" id="recurring_template_id" name="recurring_template_id" required>
                                <option value="">Choose a template...</option>
                                <?php
                                $templates = $conn->query("SELECT s.id, s.title, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE s.is_recurring = 1 GROUP BY s.title, s.church_id ORDER BY s.title, c.name");
                                while ($tpl = $templates->fetch_assoc()): ?>
                                    <option value="<?= $tpl['id'] ?>">
                                        <?= htmlspecialchars($tpl['title']) ?> (<?= htmlspecialchars($tpl['church_name']) ?>)
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_next_recurring" value="1" class="btn btn-success">
                            <i class="fas fa-plus"></i> Create Session
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    $modal_html = ob_get_clean();
}

include '../includes/layout.php';
echo $modal_html;
?>
