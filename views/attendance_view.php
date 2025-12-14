<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

if (!has_permission('view_attendance_list')) {
    http_response_code(403);
    include '../views/errors/403.php';
    exit;
}

$session_id = intval($_GET['id'] ?? 0);
if (!$session_id) {
    header('Location: attendance_list.php');
    exit;
}

// Fetch session details with church info
$stmt = $conn->prepare("SELECT s.*, c.name AS church_name FROM attendance_sessions s LEFT JOIN churches c ON s.church_id = c.id WHERE s.id = ? LIMIT 1");
$stmt->bind_param('i', $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();
if (!$session) {
    header('Location: attendance_list.php');
    exit;
}

// Get filters
$status_filter = $_GET['status'] ?? '';
$class_filter = $_GET['class_id'] ?? '';
$gender_filter = $_GET['gender'] ?? '';
$search = trim($_GET['search'] ?? '');

// Fetch attendance records with filters
$sql = "SELECT m.id, m.crn, m.last_name, m.first_name, m.middle_name, m.gender,
        bc.name AS class_name, ar.status, ar.created_at, ar.is_draft,
        u.name AS marked_by
        FROM attendance_records ar
        JOIN members m ON ar.member_id = m.id
        LEFT JOIN bible_classes bc ON m.class_id = bc.id
        LEFT JOIN users u ON ar.marked_by = u.id
        WHERE ar.session_id = ?";
$params = [$session_id];
$types = 'i';

if ($status_filter === 'present' || $status_filter === 'absent') {
    $sql .= " AND ar.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($class_filter) {
    $sql .= " AND m.class_id = ?";
    $params[] = $class_filter;
    $types .= 'i';
}

if ($gender_filter) {
    $sql .= " AND m.gender = ?";
    $params[] = $gender_filter;
    $types .= 's';
}

if ($search) {
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql .= " ORDER BY m.last_name, m.first_name";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$records = $stmt->get_result();
$all_records = [];
while ($row = $records->fetch_assoc()) {
    $all_records[] = $row;
}

// Calculate statistics
$total_records = count($all_records);
$present_count = 0;
$absent_count = 0;
$draft_count = 0;
$male_present = 0;
$female_present = 0;

foreach ($all_records as $record) {
    if (strtolower($record['status']) === 'present') {
        $present_count++;
        if (strtolower($record['gender']) === 'male') $male_present++;
        if (strtolower($record['gender']) === 'female') $female_present++;
    } else {
        $absent_count++;
    }
    if ($record['is_draft'] == 1) {
        $draft_count++;
    }
}

$attendance_rate = $total_records > 0 ? round(($present_count / $total_records) * 100, 1) : 0;

// Get filter options
$bible_classes = $conn->query("SELECT id, name FROM bible_classes ORDER BY name ASC");

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .attendance-view-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .view-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .session-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item i {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-card.total { border-left-color: #667eea; }
        .stat-card.present { border-left-color: #28a745; }
        .stat-card.absent { border-left-color: #dc3545; }
        .stat-card.rate { border-left-color: #17a2b8; }
        .stat-card.male { border-left-color: #007bff; }
        .stat-card.female { border-left-color: #e83e8c; }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        .stat-card.total .stat-icon { color: #667eea; }
        .stat-card.present .stat-icon { color: #28a745; }
        .stat-card.absent .stat-icon { color: #dc3545; }
        .stat-card.rate .stat-icon { color: #17a2b8; }
        .stat-card.male .stat-icon { color: #007bff; }
        .stat-card.female .stat-icon { color: #e83e8c; }
        
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 1.8rem;
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
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .records-card {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0,0,0,0.08);
        }
        
        .records-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .member-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .member-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            border-left: 4px solid;
            transition: all 0.3s;
        }
        
        .member-item:hover {
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
            transform: translateX(3px);
        }
        
        .member-item.present {
            border-left-color: #28a745;
            background: #f8fff9;
        }
        
        .member-item.absent {
            border-left-color: #dc3545;
            background: #fff8f8;
        }
        
        .member-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }
        
        .status-badge.present {
            background: #28a745;
            color: white;
        }
        
        .status-badge.absent {
            background: #dc3545;
            color: white;
        }
        
        .member-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
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
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .attendance-view-container {
                background: white;
                padding: 0;
            }
            
            .view-header {
                background: white;
                color: black;
                border: 2px solid #000;
            }
        }
        
        @media (max-width: 768px) {
            .stats-dashboard {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .member-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="attendance-view-container">
    <div class="view-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap">
            <div>
                <h2><i class="fas fa-clipboard-check"></i> <?= htmlspecialchars($session['title']) ?></h2>
                <div class="session-info-grid">
                    <div class="info-item">
                        <i class="fas fa-church"></i>
                        <span><?= htmlspecialchars($session['church_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar"></i>
                        <span><?= date('l, F j, Y', strtotime($session['service_date'])) ?></span>
                    </div>
                    <?php if ($session['is_recurring']): ?>
                    <div class="info-item">
                        <i class="fas fa-sync-alt"></i>
                        <span>Recurring (<?= ucfirst($session['recurrence_type']) ?>)</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="action-buttons no-print">
                <a href="attendance_list.php" class="btn btn-light btn-lg">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <a href="attendance_mark.php?id=<?= $session_id ?>" class="btn btn-warning btn-lg">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button onclick="window.print()" class="btn btn-info btn-lg">
                    <i class="fas fa-print"></i> Print
                </button>
                <button onclick="exportToCSV()" class="btn btn-success btn-lg">
                    <i class="fas fa-file-csv"></i> Export
                </button>
            </div>
        </div>
    </div>

    <div class="stats-dashboard">
        <div class="stat-card total">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-label">Total Records</div>
            <div class="stat-value"><?= $total_records ?></div>
        </div>
        <div class="stat-card present">
            <div class="stat-icon"><i class="fas fa-user-check"></i></div>
            <div class="stat-label">Present</div>
            <div class="stat-value"><?= $present_count ?></div>
        </div>
        <div class="stat-card absent">
            <div class="stat-icon"><i class="fas fa-user-times"></i></div>
            <div class="stat-label">Absent</div>
            <div class="stat-value"><?= $absent_count ?></div>
        </div>
        <div class="stat-card rate">
            <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
            <div class="stat-label">Attendance Rate</div>
            <div class="stat-value"><?= $attendance_rate ?>%</div>
        </div>
        <div class="stat-card male">
            <div class="stat-icon"><i class="fas fa-mars"></i></div>
            <div class="stat-label">Male Present</div>
            <div class="stat-value"><?= $male_present ?></div>
        </div>
        <div class="stat-card female">
            <div class="stat-icon"><i class="fas fa-venus"></i></div>
            <div class="stat-label">Female Present</div>
            <div class="stat-value"><?= $female_present ?></div>
        </div>
    </div>

    <div class="filter-card no-print">
        <h5><i class="fas fa-filter"></i> Filter Records</h5>
        <form method="get" id="filterForm">
            <input type="hidden" name="id" value="<?= $session_id ?>">
            <div class="row">
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Status</label>
                    <select class="form-select" name="status" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="present" <?= $status_filter === 'present' ? 'selected' : '' ?>>Present</option>
                        <option value="absent" <?= $status_filter === 'absent' ? 'selected' : '' ?>>Absent</option>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Bible Class</label>
                    <select class="form-select" name="class_id" onchange="this.form.submit()">
                        <option value="">All Classes</option>
                        <?php if ($bible_classes && $bible_classes->num_rows > 0): 
                            while($cl = $bible_classes->fetch_assoc()): ?>
                            <option value="<?= $cl['id'] ?>" <?= $class_filter == $cl['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['name']) ?>
                            </option>
                        <?php endwhile; endif; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Gender</label>
                    <select class="form-select" name="gender" onchange="this.form.submit()">
                        <option value="">All</option>
                        <option value="Male" <?= $gender_filter === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $gender_filter === 'Female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Search by name or CRN..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-1 mb-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
        </form>
        <?php if ($status_filter || $class_filter || $gender_filter || $search): ?>
        <div class="mt-2">
            <a href="attendance_view.php?id=<?= $session_id ?>" class="btn btn-secondary btn-sm">
                <i class="fas fa-times"></i> Clear All Filters
            </a>
        </div>
        <?php endif; ?>
    </div>

    <div class="records-card">
        <div class="records-header">
            <h5 class="mb-0">
                <i class="fas fa-list"></i> 
                Attendance Records (<?= count($all_records) ?> members)
            </h5>
            <div class="no-print">
                <button class="btn btn-light btn-sm" onclick="toggleView()">
                    <i class="fas fa-th"></i> Toggle View
                </button>
            </div>
        </div>
        
        <?php if (count($all_records) > 0): ?>
        <!-- Grid View -->
        <div class="member-grid" id="gridView">
            <?php foreach($all_records as $record): ?>
            <div class="member-item <?= strtolower($record['status']) ?>" 
                 data-name="<?= htmlspecialchars($record['last_name'] . ' ' . $record['first_name']) ?>"
                 data-crn="<?= htmlspecialchars($record['crn'] ?? '') ?>"
                 data-status="<?= htmlspecialchars($record['status']) ?>"
                 data-class="<?= htmlspecialchars($record['class_name'] ?? '') ?>"
                 data-marked-by="<?= htmlspecialchars($record['marked_by'] ?? '') ?>"
                 data-marked-at="<?= htmlspecialchars($record['created_at']) ?>">
                <div class="member-name">
                    <span><?= htmlspecialchars($record['last_name'] . ', ' . $record['first_name']) ?></span>
                    <span class="status-badge <?= strtolower($record['status']) ?>">
                        <?= strtoupper($record['status']) ?>
                    </span>
                </div>
                <div class="member-meta">
                    <div class="meta-item">
                        <i class="fas fa-id-card"></i>
                        <span><?= htmlspecialchars($record['crn'] ?? 'N/A') ?></span>
                    </div>
                    <?php if ($record['class_name']): ?>
                    <div class="meta-item">
                        <i class="fas fa-book"></i>
                        <span><?= htmlspecialchars($record['class_name']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($record['gender']): ?>
                    <div class="meta-item">
                        <i class="fas fa-<?= strtolower($record['gender']) === 'male' ? 'mars' : 'venus' ?>"></i>
                        <span><?= htmlspecialchars($record['gender']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($record['is_draft'] == 1): ?>
                <div class="mt-2">
                    <span class="badge badge-warning badge-sm">DRAFT</span>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Table View (hidden by default) -->
        <div class="table-responsive" id="tableView" style="display: none;">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>CRN</th>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Gender</th>
                        <th>Status</th>
                        <th>Marked By</th>
                        <th>Marked At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($all_records as $record): ?>
                    <tr>
                        <td><?= htmlspecialchars($record['crn'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($record['last_name'] . ', ' . $record['first_name']) ?></td>
                        <td><?= htmlspecialchars($record['class_name'] ?? '-') ?></td>
                        <td>
                            <i class="fas fa-<?= strtolower($record['gender']) === 'male' ? 'mars' : 'venus' ?>"></i>
                            <?= htmlspecialchars($record['gender'] ?? '-') ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= strtolower($record['status']) === 'present' ? 'success' : 'danger' ?>">
                                <?= strtoupper($record['status']) ?>
                            </span>
                            <?php if ($record['is_draft'] == 1): ?>
                                <span class="badge badge-warning ml-1">DRAFT</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($record['marked_by'] ?? 'N/A') ?></td>
                        <td><?= date('M j, Y g:i A', strtotime($record['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h4>No Records Found</h4>
            <p class="text-muted">No attendance records match your current filters.</p>
            <a href="attendance_mark.php?id=<?= $session_id ?>" class="btn btn-primary mt-3">
                <i class="fas fa-plus"></i> Mark Attendance
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleView() {
    const gridView = document.getElementById('gridView');
    const tableView = document.getElementById('tableView');
    
    if (gridView.style.display === 'none') {
        gridView.style.display = 'grid';
        tableView.style.display = 'none';
    } else {
        gridView.style.display = 'none';
        tableView.style.display = 'block';
    }
}

function exportToCSV() {
    const records = <?= json_encode($all_records) ?>;
    const sessionTitle = <?= json_encode($session['title']) ?>;
    const sessionDate = <?= json_encode($session['service_date']) ?>;
    
    let csv = 'CRN,Last Name,First Name,Class,Gender,Status,Marked By,Marked At\n';
    
    records.forEach(record => {
        csv += `"${record.crn || 'N/A'}",`;
        csv += `"${record.last_name}",`;
        csv += `"${record.first_name}",`;
        csv += `"${record.class_name || '-'}",`;
        csv += `"${record.gender || '-'}",`;
        csv += `"${record.status}",`;
        csv += `"${record.marked_by || 'N/A'}",`;
        csv += `"${record.created_at}"\n`;
    });
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `attendance_${sessionTitle}_${sessionDate}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>

<?php 
$page_content = ob_get_clean(); 
$page_title = 'View Attendance - ' . $session['title'];
include '../includes/layout.php'; 
?>
