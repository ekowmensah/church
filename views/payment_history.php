<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__.'/../config/config.php';

// Check if admin or member is logged in
$is_admin = false;
$is_member = false;
$member_id = 0;

// Check for admin authentication
if (isset($_SESSION['user_id'])) {
    require_once __DIR__.'/../helpers/auth.php';
    require_once __DIR__.'/../helpers/permissions_v2.php';
    
    if (is_logged_in()) {
        $is_admin = true;
        // Admin can view any member's history via member_id parameter
        $member_id = intval($_GET['member_id'] ?? 0);
        
        if ($member_id <= 0) {
            echo '<div class="alert alert-danger m-4">Invalid member ID.</div>';
            exit;
        }
        
        // Check permission
        $is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                          (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);
        
        if (!$is_super_admin && !has_permission('view_payment_list')) {
            http_response_code(403);
            echo '<div class="alert alert-danger m-4"><h4>403 Forbidden</h4><p>You do not have permission to view payment history.</p></div>';
            exit;
        }
    }
}

// Check for member authentication if not admin
if (!$is_admin) {
    require_once __DIR__.'/../includes/member_auth.php';
    
    if (!isset($_SESSION['member_id'])) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    
    $is_member = true;
    $member_id = intval($_SESSION['member_id']);
}

// Get member info for display
$member_stmt = $conn->prepare("SELECT first_name, last_name, crn, phone FROM members WHERE id = ?");
$member_stmt->bind_param('i', $member_id);
$member_stmt->execute();
$member_info = $member_stmt->get_result()->fetch_assoc();

// Handle filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$payment_type_filter = $_GET['payment_type'] ?? '';
$payment_mode_filter = $_GET['payment_mode'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
$max_amount = $_GET['max_amount'] ?? '';
$search = $_GET['search'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'payment_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$group_by_month = isset($_GET['group_by_month']) ? true : false;

// Validate sort parameters
$allowed_sort = ['payment_date', 'amount', 'payment_type', 'mode', 'payment_period'];
if (!in_array($sort_by, $allowed_sort)) $sort_by = 'payment_date';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Build query conditions
$where = 'p.member_id = ?';
$params = [$member_id];
$types = 'i';

if ($start_date) {
    $where .= ' AND p.payment_date >= ?';
    $params[] = $start_date . ' 00:00:00';
    $types .= 's';
}
if ($end_date) {
    $where .= ' AND p.payment_date <= ?';
    $params[] = $end_date . ' 23:59:59';
    $types .= 's';
}
if ($payment_type_filter) {
    $where .= ' AND p.payment_type_id = ?';
    $params[] = $payment_type_filter;
    $types .= 'i';
}
if ($payment_mode_filter) {
    $where .= ' AND p.mode = ?';
    $params[] = $payment_mode_filter;
    $types .= 's';
}
if ($min_amount) {
    $where .= ' AND p.amount >= ?';
    $params[] = floatval($min_amount);
    $types .= 'd';
}
if ($max_amount) {
    $where .= ' AND p.amount <= ?';
    $params[] = floatval($max_amount);
    $types .= 'd';
}
if ($search) {
    $where .= ' AND (p.description LIKE ? OR pt.name LIKE ? OR p.id LIKE ?)';
    $search_term = '%' . $search . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'sss';
}

// Fetch payment history with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Map sort column
$sort_column = $sort_by;
if ($sort_by === 'payment_type') $sort_column = 'pt.name';

$sql = "SELECT p.*, pt.name AS payment_type, 
               p.payment_period, p.payment_period_description, p.mode,
               u.name as recorded_by_name
        FROM payments p 
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
        LEFT JOIN users u ON p.recorded_by = u.id
        WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL)) 
        ORDER BY $sort_column $sort_order, p.id DESC
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM payments p 
              LEFT JOIN payment_types pt ON p.payment_type_id = pt.id 
              WHERE ($where) AND ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL))";
$count_stmt = $conn->prepare($count_sql);
$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch summary with filters applied
$sum_where = 'member_id = ? AND ((reversal_approved_at IS NULL) OR (reversal_undone_at IS NOT NULL))';
$sum_params = [$member_id];
$sum_types = 'i';

if ($start_date) {
    $sum_where .= ' AND payment_date >= ?';
    $sum_params[] = $start_date . ' 00:00:00';
    $sum_types .= 's';
}
if ($end_date) {
    $sum_where .= ' AND payment_date <= ?';
    $sum_params[] = $end_date . ' 23:59:59';
    $sum_types .= 's';
}
if ($payment_type_filter) {
    $sum_where .= ' AND payment_type_id = ?';
    $sum_params[] = $payment_type_filter;
    $sum_types .= 'i';
}
if ($payment_mode_filter) {
    $sum_where .= ' AND mode = ?';
    $sum_params[] = $payment_mode_filter;
    $sum_types .= 's';
}
if ($min_amount) {
    $sum_where .= ' AND amount >= ?';
    $sum_params[] = floatval($min_amount);
    $sum_types .= 'd';
}
if ($max_amount) {
    $sum_where .= ' AND amount <= ?';
    $sum_params[] = floatval($max_amount);
    $sum_types .= 'd';
}

$sum_sql = "SELECT SUM(amount) as total, COUNT(*) as num, MAX(payment_date) as last, 
                   AVG(amount) as avg_amount, MIN(payment_date) as first_payment,
                   MIN(amount) as min_amount, MAX(amount) as max_amount
            FROM payments WHERE $sum_where";
$sum_stmt = $conn->prepare($sum_sql);
$sum_stmt->bind_param($sum_types, ...$sum_params);
$sum_stmt->execute();
$summary = $sum_stmt->get_result()->fetch_assoc();

$total_paid = $summary['total'] ? (float)$summary['total'] : 0;
$num_payments = $summary['num'] ? (int)$summary['num'] : 0;
$last_payment = $summary['last'] ? $summary['last'] : null;
$avg_payment = $summary['avg_amount'] ? (float)$summary['avg_amount'] : 0;
$first_payment = $summary['first_payment'] ? $summary['first_payment'] : null;
$min_payment = $summary['min_amount'] ? (float)$summary['min_amount'] : 0;
$max_payment = $summary['max_amount'] ? (float)$summary['max_amount'] : 0;

// Get payment types for filter dropdown
$pt_stmt = $conn->prepare("SELECT DISTINCT pt.id, pt.name FROM payment_types pt 
                          JOIN payments p ON pt.id = p.payment_type_id 
                          WHERE p.member_id = ? ORDER BY pt.name");
$pt_stmt->bind_param('i', $member_id);
$pt_stmt->execute();
$payment_types = $pt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get payment modes for filter dropdown
$mode_stmt = $conn->prepare("SELECT DISTINCT mode FROM payments WHERE member_id = ? AND mode IS NOT NULL ORDER BY mode");
$mode_stmt->bind_param('i', $member_id);
$mode_stmt->execute();
$payment_modes = $mode_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get monthly breakdown if grouping enabled
$monthly_data = [];
if ($group_by_month && $result->num_rows > 0) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $month_key = date('Y-m', strtotime($row['payment_date']));
        if (!isset($monthly_data[$month_key])) {
            $monthly_data[$month_key] = [
                'month_name' => date('F Y', strtotime($row['payment_date'])),
                'total' => 0,
                'count' => 0,
                'payments' => []
            ];
        }
        $monthly_data[$month_key]['total'] += (float)$row['amount'];
        $monthly_data[$month_key]['count']++;
        $monthly_data[$month_key]['payments'][] = $row;
    }
    krsort($monthly_data);
}

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .statement-container {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .statement-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .statement-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 15s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .statement-header h2 {
            margin: 0;
            font-size: 2.5rem;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .statement-header .member-info {
            margin-top: 15px;
            font-size: 1.1rem;
            opacity: 0.95;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .stat-card i {
            font-size: 2.5rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-card .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .filter-panel {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .filter-panel h5 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-panel h5 i {
            color: #667eea;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .btn-modern {
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-primary-modern {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary-modern {
            background: #6c757d;
            color: white;
        }
        
        .btn-success-modern {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        
        .btn-info-modern {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
        }
        
        .statement-table {
            margin: 0 30px 30px 30px;
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
        
        .table-header h5 {
            margin: 0;
            font-weight: 700;
        }
        
        .sortable-header {
            cursor: pointer;
            user-select: none;
            transition: all 0.2s ease;
            position: relative;
            padding-right: 20px;
        }
        
        .sortable-header:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .sortable-header.active {
            background: rgba(102, 126, 234, 0.15);
            font-weight: 700;
        }
        
        .sort-icon {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.8rem;
            color: #667eea;
        }
        
        .table-modern {
            width: 100%;
            margin: 0;
        }
        
        .table-modern thead {
            background: #f8f9fa;
        }
        
        .table-modern thead th {
            border: none;
            padding: 15px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }
        
        .table-modern tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }
        
        .table-modern tbody tr:hover {
            background: #f8f9fa;
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .table-modern tbody td {
            padding: 20px 15px;
            vertical-align: middle;
        }
        
        .transaction-ref {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: #6c757d;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            display: inline-block;
        }
        
        .balance-cell {
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .balance-positive {
            color: #28a745;
        }
        
        .balance-negative {
            color: #dc3545;
        }
        
        .badge-modern {
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .month-group {
            margin-bottom: 40px;
        }
        
        .month-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .month-header h4 {
            margin: 0;
            font-weight: 700;
        }
        
        .month-summary {
            display: flex;
            gap: 30px;
            font-size: 0.95rem;
        }
        
        .pagination-modern {
            display: flex;
            justify-content: center;
            gap: 5px;
            padding: 30px;
        }
        
        .pagination-modern .page-link {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            color: #667eea;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .pagination-modern .page-link:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
            transform: translateY(-2px);
        }
        
        .pagination-modern .page-item.active .page-link {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-color: #667eea;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 30px;
        }
        
        .empty-state i {
            font-size: 5rem;
            color: #e9ecef;
            margin-bottom: 20px;
        }
        
        .empty-state h4 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #adb5bd;
        }
        
        @media print {
            .filter-panel, .pagination-modern, .no-print {
                display: none !important;
            }
            
            .statement-container {
                box-shadow: none;
            }
            
            .table-modern tbody tr:hover {
                transform: none;
                box-shadow: none;
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .statement-header h2 {
                font-size: 1.8rem;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
            
            .month-summary {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>

<div class="statement-container">
    <!-- Statement Header -->
    <div class="statement-header">
        <h2><i class="fas fa-file-invoice-dollar mr-3"></i>Payment Statement</h2>
        <div class="member-info">
            <strong><?= htmlspecialchars($member_info['first_name'] . ' ' . $member_info['last_name']) ?></strong>
            <br>
            CRN: <?= htmlspecialchars($member_info['crn']) ?> | 
            Phone: <?= htmlspecialchars($member_info['phone'] ?? 'N/A') ?>
            <?php if ($start_date || $end_date): ?>
                <br>
                <small>
                    Statement Period: 
                    <?= $start_date ? date('M j, Y', strtotime($start_date)) : 'Beginning' ?> - 
                    <?= $end_date ? date('M j, Y', strtotime($end_date)) : 'Present' ?>
                </small>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <i class="fas fa-wallet"></i>
            <div class="stat-label">Total Paid</div>
            <div class="stat-value">₵<?= number_format($total_paid, 2) ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-receipt"></i>
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?= $num_payments ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-chart-line"></i>
            <div class="stat-label">Average Payment</div>
            <div class="stat-value">₵<?= number_format($avg_payment, 2) ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-arrow-down"></i>
            <div class="stat-label">Minimum</div>
            <div class="stat-value">₵<?= number_format($min_payment, 2) ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-arrow-up"></i>
            <div class="stat-label">Maximum</div>
            <div class="stat-value">₵<?= number_format($max_payment, 2) ?></div>
        </div>
        <div class="stat-card">
            <i class="fas fa-calendar-check"></i>
            <div class="stat-label">Last Payment</div>
            <div class="stat-value" style="font-size: 1rem;">
                <?= $last_payment ? date('M j, Y', strtotime($last_payment)) : 'N/A' ?>
            </div>
        </div>
    </div>

    <!-- Advanced Filters -->
    <div class="filter-panel no-print">
        <h5><i class="fas fa-sliders-h"></i>Advanced Filters & Sorting</h5>
        <form action="" method="get" id="filterForm">
            <div class="row mb-3">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Payment Type</label>
                    <select class="form-select" name="payment_type">
                        <option value="">All Types</option>
                        <?php foreach ($payment_types as $pt): ?>
                            <option value="<?= $pt['id'] ?>" <?= $payment_type_filter == $pt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Payment Mode</label>
                    <select class="form-select" name="payment_mode">
                        <option value="">All Modes</option>
                        <?php foreach ($payment_modes as $mode): ?>
                            <option value="<?= htmlspecialchars($mode['mode']) ?>" 
                                    <?= $payment_mode_filter == $mode['mode'] ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_', ' ', $mode['mode'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Min Amount (₵)</label>
                    <input type="number" step="0.01" class="form-control" name="min_amount" 
                           placeholder="0.00" value="<?= $min_amount ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Max Amount (₵)</label>
                    <input type="number" step="0.01" class="form-control" name="max_amount" 
                           placeholder="0.00" value="<?= $max_amount ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Ref, description, type..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Sort By</label>
                    <select class="form-select" name="sort_by">
                        <option value="payment_date" <?= $sort_by == 'payment_date' ? 'selected' : '' ?>>Payment Date</option>
                        <option value="amount" <?= $sort_by == 'amount' ? 'selected' : '' ?>>Amount</option>
                        <option value="payment_type" <?= $sort_by == 'payment_type' ? 'selected' : '' ?>>Payment Type</option>
                        <option value="mode" <?= $sort_by == 'mode' ? 'selected' : '' ?>>Payment Mode</option>
                        <option value="payment_period" <?= $sort_by == 'payment_period' ? 'selected' : '' ?>>Payment Period</option>
                    </select>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary-modern btn-modern">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="payment_history_v2.php" class="btn btn-secondary-modern btn-modern">
                        <i class="fas fa-times mr-2"></i>Clear All
                    </a>
                    <button type="submit" name="sort_order" value="<?= $sort_order == 'ASC' ? 'DESC' : 'ASC' ?>" 
                            class="btn btn-info-modern btn-modern">
                        <i class="fas fa-sort mr-2"></i><?= $sort_order == 'ASC' ? 'Descending' : 'Ascending' ?>
                    </button>
                    <button type="submit" name="group_by_month" value="1" class="btn btn-success-modern btn-modern">
                        <i class="fas fa-calendar-alt mr-2"></i>Group by Month
                    </button>
                    <button type="button" class="btn btn-success-modern btn-modern" onclick="exportToCSV()">
                        <i class="fas fa-file-csv mr-2"></i>Export CSV
                    </button>
                    <button type="button" class="btn btn-info-modern btn-modern" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Statement
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Payment Table -->
    <div class="statement-table">
        <?php if ($result->num_rows === 0): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No Transactions Found</h4>
                <p>Try adjusting your filters or make your first payment.</p>
            </div>
        <?php elseif ($group_by_month && !empty($monthly_data)): ?>
            <!-- Grouped by Month View -->
            <?php foreach ($monthly_data as $month_key => $month_info): ?>
                <div class="month-group">
                    <div class="month-header">
                        <h4><i class="fas fa-calendar mr-2"></i><?= $month_info['month_name'] ?></h4>
                        <div class="month-summary">
                            <span><strong><?= $month_info['count'] ?></strong> transactions</span>
                            <span><strong>₵<?= number_format($month_info['total'], 2) ?></strong> total</span>
                        </div>
                    </div>
                    
                    <div class="table-wrapper">
                        <div class="table-responsive">
                            <table class="table table-modern">
                                <thead>
                                    <tr>
                                        <th>Ref</th>
                                        <th>Date & Time</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Mode</th>
                                        <th class="text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($month_info['payments'] as $row): ?>
                                        <tr>
                                            <td>
                                                <span class="transaction-ref">PAY-<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></span>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?= date('M j, Y', strtotime($row['payment_date'])) ?></strong>
                                                    <br>
                                                    <small class="text-muted"><?= date('g:i A', strtotime($row['payment_date'])) ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary badge-modern">
                                                    <?= htmlspecialchars($row['payment_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($row['description']) ?>
                                                <?php if ($row['payment_period'] && $row['payment_period'] != date('Y-m-d', strtotime($row['payment_date']))): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-calendar-alt"></i> 
                                                        Period: <?= $row['payment_period_description'] ?: date('M Y', strtotime($row['payment_period'])) ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $mode = $row['mode'] ?? 'unknown';
                                                $mode_badges = [
                                                    'cash' => ['class' => 'success', 'icon' => 'money-bill-wave', 'label' => 'Cash'],
                                                    'mobile_money' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'Mobile Money'],
                                                    'ussd' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'USSD'],
                                                    'bank_transfer' => ['class' => 'info', 'icon' => 'university', 'label' => 'Bank Transfer'],
                                                    'cheque' => ['class' => 'secondary', 'icon' => 'money-check', 'label' => 'Cheque'],
                                                    'card' => ['class' => 'primary', 'icon' => 'credit-card', 'label' => 'Card']
                                                ];
                                                
                                                // If mode not in predefined list, create a generic badge
                                                if (isset($mode_badges[$mode])) {
                                                    $badge_info = $mode_badges[$mode];
                                                } else {
                                                    $badge_info = [
                                                        'class' => 'secondary', 
                                                        'icon' => 'question-circle', 
                                                        'label' => ucwords(str_replace('_', ' ', $mode ?? 'Unknown'))
                                                    ];
                                                }
                                                ?>
                                                <span class="badge badge-<?= $badge_info['class'] ?> badge-modern">
                                                    <i class="fas fa-<?= $badge_info['icon'] ?>"></i>
                                                    <?= $badge_info['label'] ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <strong class="text-success">₵<?= number_format((float)$row['amount'], 2) ?></strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-active">
                                        <td colspan="5" class="text-end"><strong>Month Total:</strong></td>
                                        <td class="text-end"><strong class="text-success">₵<?= number_format($month_info['total'], 2) ?></strong></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Standard Table View -->
            <div class="table-wrapper">
                <div class="table-header">
                    <h5><i class="fas fa-list mr-2"></i>Transaction History</h5>
                    <span class="badge badge-light"><?= $total_records ?> records</span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-modern">
                        <thead>
                            <tr>
                                <th class="sortable-header <?= $sort_by == 'id' ? 'active' : '' ?>" 
                                    onclick="sortTable('id')">
                                    Ref #
                                    <?php if ($sort_by == 'id'): ?>
                                        <span class="sort-icon">
                                            <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <th class="sortable-header <?= $sort_by == 'payment_date' ? 'active' : '' ?>" 
                                    onclick="sortTable('payment_date')">
                                    Date & Time
                                    <?php if ($sort_by == 'payment_date'): ?>
                                        <span class="sort-icon">
                                            <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <th class="sortable-header <?= $sort_by == 'payment_type' ? 'active' : '' ?>" 
                                    onclick="sortTable('payment_type')">
                                    Type
                                    <?php if ($sort_by == 'payment_type'): ?>
                                        <span class="sort-icon">
                                            <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <th>Description</th>
                                <th class="sortable-header <?= $sort_by == 'mode' ? 'active' : '' ?>" 
                                    onclick="sortTable('mode')">
                                    Mode
                                    <?php if ($sort_by == 'mode'): ?>
                                        <span class="sort-icon">
                                            <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <th class="sortable-header text-end <?= $sort_by == 'amount' ? 'active' : '' ?>" 
                                    onclick="sortTable('amount')">
                                    Amount
                                    <?php if ($sort_by == 'amount'): ?>
                                        <span class="sort-icon">
                                            <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                        </span>
                                    <?php endif; ?>
                                </th>
                                <th class="text-end">Running Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $running_total = 0;
                            $result->data_seek(0);
                            while ($row = $result->fetch_assoc()): 
                                $running_total += (float)$row['amount'];
                            ?>
                                <tr>
                                    <td>
                                        <span class="transaction-ref">PAY-<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></span>
                                    </td>
                                    <td>
                                        <div>
                                            <strong><?= date('M j, Y', strtotime($row['payment_date'])) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= date('g:i A', strtotime($row['payment_date'])) ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary badge-modern">
                                            <?= htmlspecialchars($row['payment_type']) ?>
                                        </span>
                                        <?php if ($row['payment_period'] && $row['payment_period'] != date('Y-m-d', strtotime($row['payment_date']))): ?>
                                            <br>
                                            <small class="badge badge-info badge-modern mt-1">
                                                <?= $row['payment_period_description'] ?: date('M Y', strtotime($row['payment_period'])) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['description']) ?>
                                        <?php if ($row['recorded_by_name']): ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-user"></i> <?= htmlspecialchars($row['recorded_by_name']) ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $mode = $row['mode'] ?? 'unknown';
                                        $mode_badges = [
                                            'cash' => ['class' => 'success', 'icon' => 'money-bill-wave', 'label' => 'Cash'],
                                            'mobile_money' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'Mobile Money'],
                                            'ussd' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'USSD'],
                                            'bank_transfer' => ['class' => 'info', 'icon' => 'university', 'label' => 'Bank Transfer'],
                                            'cheque' => ['class' => 'secondary', 'icon' => 'money-check', 'label' => 'Cheque'],
                                            'card' => ['class' => 'primary', 'icon' => 'credit-card', 'label' => 'Card']
                                        ];
                                        
                                        // If mode not in predefined list, create a generic badge
                                        if (isset($mode_badges[$mode])) {
                                            $badge_info = $mode_badges[$mode];
                                        } else {
                                            $badge_info = [
                                                'class' => 'secondary', 
                                                'icon' => 'question-circle', 
                                                'label' => ucwords(str_replace('_', ' ', $mode ?? 'Unknown'))
                                            ];
                                        }
                                        ?>
                                        <span class="badge badge-<?= $badge_info['class'] ?> badge-modern">
                                            <i class="fas fa-<?= $badge_info['icon'] ?>"></i>
                                            <?= $badge_info['label'] ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <strong class="text-success">₵<?= number_format((float)$row['amount'], 2) ?></strong>
                                    </td>
                                    <td class="text-end balance-cell balance-positive">
                                        ₵<?= number_format($running_total, 2) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-active">
                                <td colspan="5" class="text-end"><strong>Grand Total:</strong></td>
                                <td class="text-end"><strong class="text-success">₵<?= number_format($total_paid, 2) ?></strong></td>
                                <td class="text-end"><strong class="balance-cell balance-positive">₵<?= number_format($running_total, 2) ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1 && !$group_by_month): ?>
        <div class="pagination-modern no-print">
            <ul class="pagination mb-0">
                <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php 
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++): 
                ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            <i class="fas fa-angle-right"></i>
                        </a>
                    </li>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            
            <div class="text-center mt-3 text-muted">
                <small>
                    Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= $total_records ?> records
                </small>
            </div>
        </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="text-center py-4 no-print">
        <?php if ($is_admin): ?>
            <a href="payment_list.php" class="btn btn-secondary-modern btn-modern">
                <i class="fas fa-arrow-left mr-2"></i>Back to Payment List
            </a>
        <?php else: ?>
            <a href="member_dashboard.php" class="btn btn-secondary-modern btn-modern">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        <?php endif; ?>
    </div>
</div>

<script>
function sortTable(column) {
    const form = document.getElementById('filterForm');
    const currentSort = '<?= $sort_by ?>';
    const currentOrder = '<?= $sort_order ?>';
    
    // Create hidden inputs for sort parameters
    let sortByInput = form.querySelector('input[name="sort_by"]');
    if (!sortByInput) {
        sortByInput = document.createElement('input');
        sortByInput.type = 'hidden';
        sortByInput.name = 'sort_by';
        form.appendChild(sortByInput);
    }
    sortByInput.value = column;
    
    let sortOrderInput = form.querySelector('input[name="sort_order"]');
    if (!sortOrderInput) {
        sortOrderInput = document.createElement('input');
        sortOrderInput.type = 'hidden';
        sortOrderInput.name = 'sort_order';
        form.appendChild(sortOrderInput);
    }
    
    // Toggle order if same column, otherwise default to DESC
    if (currentSort === column) {
        sortOrderInput.value = currentOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        sortOrderInput.value = 'DESC';
    }
    
    form.submit();
}

function exportToCSV() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = 'export_payment_history.php?' + params.toString();
}

// Auto-submit on filter change
document.addEventListener('DOMContentLoaded', function() {
    const autoSubmitFields = ['start_date', 'end_date'];
    autoSubmitFields.forEach(name => {
        const field = document.querySelector(`[name="${name}"]`);
        if (field) {
            field.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        }
    });
});
</script>

<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
