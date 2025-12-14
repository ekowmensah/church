<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_reversal_log')){
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to view the reversal log.</p></div>';
    exit;
}

// Filters
$filter_action = $_GET['action'] ?? '';
$filter_actor = $_GET['actor_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$search_term = trim($_GET['search'] ?? '');

// Pagination
$records_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
if ($records_per_page <= 0 || $records_per_page > 500) $records_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Build SQL query
$sql = "SELECT l.*, u.name AS actor_name, 
    p.amount, p.payment_date, p.id AS payment_id,
    p.reversal_requested_at, p.reversal_approved_at, p.reversal_undone_at,
    pt.name AS payment_type,
    CONCAT(m.first_name, ' ', m.last_name) AS member_name,
    m.crn
FROM payment_reversal_log l
LEFT JOIN users u ON l.actor_id = u.id
LEFT JOIN payments p ON l.payment_id = p.id
LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
LEFT JOIN members m ON p.member_id = m.id
WHERE 1=1";

$params = [];
$types = '';

if ($filter_action) {
    $sql .= " AND l.action = ?";
    $params[] = $filter_action;
    $types .= 's';
}

if ($filter_actor) {
    $sql .= " AND l.actor_id = ?";
    $params[] = $filter_actor;
    $types .= 'i';
}

if ($date_from) {
    $sql .= " AND DATE(l.action_at) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to) {
    $sql .= " AND DATE(l.action_at) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

if ($search_term) {
    $sql .= " AND (l.reason LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.crn LIKE ? OR p.id LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 5; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM payment_reversal_log l
LEFT JOIN users u ON l.actor_id = u.id
LEFT JOIN payments p ON l.payment_id = p.id
LEFT JOIN members m ON p.member_id = m.id
WHERE 1=1";

$count_params = [];
$count_types = '';

if ($filter_action) {
    $count_sql .= " AND l.action = ?";
    $count_params[] = $filter_action;
    $count_types .= 's';
}

if ($filter_actor) {
    $count_sql .= " AND l.actor_id = ?";
    $count_params[] = $filter_actor;
    $count_types .= 'i';
}

if ($date_from) {
    $count_sql .= " AND DATE(l.action_at) >= ?";
    $count_params[] = $date_from;
    $count_types .= 's';
}

if ($date_to) {
    $count_sql .= " AND DATE(l.action_at) <= ?";
    $count_params[] = $date_to;
    $count_types .= 's';
}

if ($search_term) {
    $count_sql .= " AND (l.reason LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.crn LIKE ? OR p.id LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 5; $i++) {
        $count_params[] = $search_like;
        $count_types .= 's';
    }
}

if ($count_types) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $total_records = $count_stmt->get_result()->fetch_assoc()['total'];
} else {
    $total_records = $conn->query($count_sql)->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $records_per_page);
$current_page = min($current_page, max(1, $total_pages));

// Add ORDER BY and LIMIT
$sql .= " ORDER BY l.action_at DESC, l.id DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

// Execute query
if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
} else {
    $res = $conn->query($sql);
}

// Get statistics
$stats_request = $conn->query("SELECT COUNT(*) as cnt FROM payment_reversal_log WHERE action = 'request'")->fetch_assoc()['cnt'];
$stats_approve = $conn->query("SELECT COUNT(*) as cnt FROM payment_reversal_log WHERE action = 'approve'")->fetch_assoc()['cnt'];
$stats_undo = $conn->query("SELECT COUNT(*) as cnt FROM payment_reversal_log WHERE action = 'undo'")->fetch_assoc()['cnt'];
$stats_total = $stats_request + $stats_approve + $stats_undo;

// Get actors for filter
$actors = $conn->query("SELECT DISTINCT u.id, u.name FROM payment_reversal_log l JOIN users u ON l.actor_id = u.id ORDER BY u.name");

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .reversal-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .reversal-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .reversal-header h1 {
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
        
        .stat-card.danger { border-left-color: #dc3545; }
        .stat-card.warning { border-left-color: #ffc107; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.info { border-left-color: #17a2b8; }
        
        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-card.danger .icon { color: #dc3545; }
        .stat-card.warning .icon { color: #ffc107; }
        .stat-card.success .icon { color: #28a745; }
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
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            padding: 20px 30px;
        }
        
        .badge-action {
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .badge-request { background: #ffc107; color: #000; }
        .badge-approve { background: #dc3545; color: #fff; }
        .badge-undo { background: #28a745; color: #fff; }
    </style>
</head>
<body>

<div class="reversal-container">
    <div class="reversal-header">
        <h1>
            <i class="fas fa-undo-alt"></i>
            Payment Reversal Activity Log
        </h1>
        <div class="subtitle">
            Complete audit trail of all payment reversal actions
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_GET['reversal_denied'])): ?>
        <div class="alert alert-success alert-dismissible fade show mx-3" role="alert">
            <i class="fas fa-check-circle mr-2"></i>Reversal request denied successfully!
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <div class="stats-dashboard">
        <div class="stat-card danger">
            <div class="icon"><i class="fas fa-list"></i></div>
            <div class="label">Total Actions</div>
            <div class="value"><?= number_format($stats_total) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
            <div class="label">Requests</div>
            <div class="value"><?= number_format($stats_request) ?></div>
        </div>
        <div class="stat-card danger">
            <div class="icon"><i class="fas fa-ban"></i></div>
            <div class="label">Approvals</div>
            <div class="value"><?= number_format($stats_approve) ?></div>
        </div>
        <div class="stat-card success">
            <div class="icon"><i class="fas fa-undo"></i></div>
            <div class="label">Undos</div>
            <div class="value"><?= number_format($stats_undo) ?></div>
        </div>
    </div>

    <div class="filter-section">
        <h5><i class="fas fa-filter"></i> Filters & Search</h5>
        <form action="" method="get" id="filterForm">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Action</label>
                    <select class="form-select" name="action">
                        <option value="">All Actions</option>
                        <option value="request" <?= $filter_action == 'request' ? 'selected' : '' ?>>Requested</option>
                        <option value="approve" <?= $filter_action == 'approve' ? 'selected' : '' ?>>Approved</option>
                        <option value="undo" <?= $filter_action == 'undo' ? 'selected' : '' ?>>Undo</option>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label fw-bold">Actor</label>
                    <select class="form-select" name="actor_id">
                        <option value="">All Users</option>
                        <?php while ($actor = $actors->fetch_assoc()): ?>
                            <option value="<?= $actor['id'] ?>" <?= $filter_actor == $actor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($actor['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
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
                           placeholder="Search by reason, member name, CRN, or payment ID..." 
                           value="<?= htmlspecialchars($search_term) ?>">
                </div>
                <div class="col-md-6 mb-3 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <a href="payment_reversal_log.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrapper">
        <div class="table-header">
            <h5 class="mb-0">
                <i class="fas fa-history"></i> 
                Reversal Log (<?= number_format($total_records) ?> records)
            </h5>
        </div>
        
        <?php if ($res && $res->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date/Time</th>
                        <th>Action</th>
                        <th>Payment ID</th>
                        <th>Member</th>
                        <th>Amount</th>
                        <th>Payment Type</th>
                        <th>Actor</th>
                        <th>Reason</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $res->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div>
                                <strong><?= date('M j, Y', strtotime($row['action_at'])) ?></strong>
                                <br>
                                <small class="text-muted"><?= date('g:i A', strtotime($row['action_at'])) ?></small>
                            </div>
                        </td>
                        <td>
                            <?php 
                            $action = strtolower($row['action']);
                            $badge_class = match($action) {
                                'request' => 'badge-request',
                                'approve' => 'badge-approve',
                                'undo' => 'badge-undo',
                                default => 'badge-secondary'
                            };
                            $icon = match($action) {
                                'request' => 'exclamation-circle',
                                'approve' => 'ban',
                                'undo' => 'undo',
                                default => 'question'
                            };
                            ?>
                            <span class="badge badge-action <?= $badge_class ?>">
                                <i class="fas fa-<?= $icon ?>"></i>
                                <?= ucfirst($action) ?>
                            </span>
                        </td>
                        <td>
                            <a href="payment_view.php?id=<?= $row['payment_id'] ?>" 
                               class="text-primary fw-bold">
                                #<?= $row['payment_id'] ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($row['member_name']): ?>
                                <div>
                                    <strong><?= htmlspecialchars($row['member_name']) ?></strong>
                                    <br>
                                    <small class="text-muted">CRN: <?= htmlspecialchars($row['crn']) ?></small>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">N/A</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-danger">₵<?= number_format($row['amount'], 2) ?></strong>
                        </td>
                        <td>
                            <span class="badge badge-info">
                                <?= htmlspecialchars($row['payment_type'] ?? 'Unknown') ?>
                            </span>
                        </td>
                        <td>
                            <i class="fas fa-user"></i>
                            <?= htmlspecialchars($row['actor_name'] ?? 'Unknown') ?>
                        </td>
                        <td>
                            <span class="text-truncate d-inline-block" style="max-width: 250px;" 
                                  title="<?= htmlspecialchars($row['reason']) ?>">
                                <?= htmlspecialchars($row['reason']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?php
                            // Determine current payment status
                            $is_pending = !empty($row['reversal_requested_at']) && empty($row['reversal_approved_at']);
                            $is_approved = !empty($row['reversal_approved_at']) && empty($row['reversal_undone_at']);
                            $can_manage = $is_super_admin || has_permission('approve_payment_reversal');
                            $current_action = strtolower($row['action']);
                            
                            // Show action buttons based on status and action type
                            if ($current_action == 'request' && $is_pending && $can_manage): ?>
                                <div class="btn-group btn-group-sm">
                                    <a href="payment_reverse.php?id=<?= $row['payment_id'] ?>&action=approve" 
                                       class="btn btn-success btn-sm" 
                                       onclick="return confirm('Approve this payment reversal?');" 
                                       title="Approve Reversal">
                                        <i class="fas fa-check"></i> Approve
                                    </a>
                                    <button class="btn btn-danger btn-sm" 
                                            onclick="denyReversal(<?= $row['payment_id'] ?>)" 
                                            title="Deny Reversal">
                                        <i class="fas fa-times"></i> Deny
                                    </button>
                                </div>
                            <?php elseif ($current_action == 'approve' && $is_approved && $can_manage): ?>
                                <a href="payment_reverse.php?id=<?= $row['payment_id'] ?>&action=undo" 
                                   class="btn btn-info btn-sm" 
                                   onclick="return confirm('Undo this payment reversal? This will restore the payment.');" 
                                   title="Undo Reversal">
                                    <i class="fas fa-undo"></i> Undo
                                </a>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
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
            <i class="fas fa-inbox"></i>
            <h4>No Reversal Records Found</h4>
            <p class="text-muted">No payment reversal actions match your current filters.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function denyReversal(paymentId) {
    if (confirm('Are you sure you want to deny this reversal request? This action cannot be undone.')) {
        window.location.href = 'payment_reverse.php?id=' + paymentId + '&action=deny';
    }
}
</script>

<?php 
$page_content = ob_get_clean(); 
$page_title = 'Payment Reversal Log';
include '../includes/layout.php'; 
?>
