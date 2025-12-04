<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Log errors to a file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/role_based_filter.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_list')) {
    http_response_code(403);
    echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_payment');
$can_edit = $is_super_admin || has_permission('edit_payment');
$can_delete = $is_super_admin || has_permission('delete_payment');
$can_view_all = $is_super_admin || has_permission('view_all_payments');

// Enhanced filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$filter_gender = $_GET['gender'] ?? '';
$filter_church = $_GET['church_id'] ?? '';
$filter_payment_type = $_GET['payment_type_id'] ?? '';
$filter_mode = $_GET['mode'] ?? '';
$filter_user_id = $_GET['user_id'] ?? '';
$filter_period_from = $_GET['period_from'] ?? '';
$filter_period_to = $_GET['period_to'] ?? '';
$search_term = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$amount_min = $_GET['amount_min'] ?? '';
$amount_max = $_GET['amount_max'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'payment_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';

// Validate sort parameters
$allowed_sort = ['payment_date', 'amount', 'payment_type', 'member_name', 'mode', 'church_name'];
if (!in_array($sort_by, $allowed_sort)) $sort_by = 'payment_date';
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 50;
$show_all = ($records_per_page == 9999); // Special value for 'Show All'
if (!$show_all && ($records_per_page <= 0 || $records_per_page > 500)) $records_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Fetch filter options
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
$payment_types = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");
$genders = $conn->query("SELECT DISTINCT gender FROM members WHERE gender IS NOT NULL AND gender != '' ORDER BY gender");
$payment_modes = $conn->query("SELECT DISTINCT mode FROM payments WHERE mode IS NOT NULL AND mode != '' ORDER BY mode ASC");

// Fetch users for filter dropdown
$user_filter_options = null;
if ($is_super_admin || has_permission('view_all_payments')) {
    $user_filter_options = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
}

// Get role-based filters
$class_leader_class_ids = get_user_class_ids();
$org_leader_org_ids = get_user_organization_ids();

// Fetch classes and organizations based on selected church
$bible_classes = null;
$organizations = null;

if ($filter_church) {
    if ($class_leader_class_ids !== null) {
        $placeholders = implode(',', array_fill(0, count($class_leader_class_ids), '?'));
        $bible_classes = $conn->prepare("SELECT id, name FROM bible_classes WHERE church_id = ? AND id IN ($placeholders) ORDER BY name ASC");
        $bind_params = array_merge([$filter_church], $class_leader_class_ids);
        $bind_types = 'i' . str_repeat('i', count($class_leader_class_ids));
        $bible_classes->bind_param($bind_types, ...$bind_params);
        $bible_classes->execute();
        $bible_classes = $bible_classes->get_result();
    } else {
        $bible_classes = $conn->prepare("SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name ASC");
        $bible_classes->bind_param('i', $filter_church);
        $bible_classes->execute();
        $bible_classes = $bible_classes->get_result();
    }
    
    if ($org_leader_org_ids !== null) {
        $placeholders = implode(',', array_fill(0, count($org_leader_org_ids), '?'));
        $organizations = $conn->prepare("SELECT id, name FROM organizations WHERE church_id = ? AND id IN ($placeholders) ORDER BY name ASC");
        $bind_params = array_merge([$filter_church], $org_leader_org_ids);
        $bind_types = 'i' . str_repeat('i', count($org_leader_org_ids));
        $organizations->bind_param($bind_types, ...$bind_params);
        $organizations->execute();
        $organizations = $organizations->get_result();
    } else {
        $organizations = $conn->prepare("SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name ASC");
        $organizations->bind_param('i', $filter_church);
        $organizations->execute();
        $organizations = $organizations->get_result();
    }
}

// Build SQL with enhanced filters
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

// Map sort column
$sort_column = match($sort_by) {
    'payment_type' => 'pt.name',
    'member_name' => 'COALESCE(m.first_name, ss.first_name)',
    'church_name' => 'c.name',
    default => 'p.' . $sort_by
};

$sql = "SELECT p.*, 
    m.crn, m.first_name, m.last_name, m.middle_name, m.gender, 
    ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, 
    pt.name AS payment_type,
    c.name AS church_name,
    bc.name AS class_name,
    org.name AS organization_name,
    u.name AS recorded_by_username,
    p.reversal_requested_at,
    p.reversal_approved_at,
    p.reversal_undone_at
FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations org ON mo.organization_id = org.id
    LEFT JOIN users u ON p.recorded_by = u.id
WHERE ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL))";

$params = [];
$types = '';

// Apply role-based filtering
$class_ids = get_user_class_ids();
if ($class_ids !== null) {
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $sql .= " AND ((p.member_id IS NOT NULL AND m.class_id IN ($placeholders)) OR (p.sundayschool_id IS NOT NULL AND ss.class_id IN ($placeholders)))";
    foreach ($class_ids as $class_id) {
        $params[] = $class_id;
        $types .= 'i';
    }
    foreach ($class_ids as $class_id) {
        $params[] = $class_id;
        $types .= 'i';
    }
}

$org_filter = apply_organizational_leader_filter('m');
if (!empty($org_filter['sql'])) {
    $sql .= " AND " . $org_filter['sql'];
    foreach ($org_filter['params'] as $param) {
        $params[] = $param;
    }
    $types .= $org_filter['types'];
}

$ss_filter = apply_sunday_school_filter('m');
if (!empty($ss_filter['sql'])) {
    $sql .= " AND " . $ss_filter['sql'];
    foreach ($ss_filter['params'] as $param) {
        $params[] = $param;
    }
    $types .= $ss_filter['types'];
}

$cashier_filter = apply_cashier_filter('p');
if (!empty($cashier_filter['sql'])) {
    $sql .= " AND " . $cashier_filter['sql'];
    foreach ($cashier_filter['params'] as $param) {
        $params[] = $param;
    }
    $types .= $cashier_filter['types'];
}

// Apply user filters
if (($is_super_admin || $can_view_all) && $filter_user_id) {
    $sql .= " AND p.recorded_by = ?";
    $params[] = $filter_user_id;
    $types .= 'i';
}

if ($filter_church) {
    $sql .= " AND m.church_id = ?";
    $params[] = $filter_church;
    $types .= 'i';
}
if ($filter_class) {
    $sql .= " AND m.class_id = ?";
    $params[] = $filter_class;
    $types .= 'i';
}
if ($filter_org) {
    $sql .= " AND mo.organization_id = ?";
    $params[] = $filter_org;
    $types .= 'i';
}
if ($filter_gender) {
    $sql .= " AND m.gender = ?";
    $params[] = $filter_gender;
    $types .= 's';
}
if ($filter_payment_type) {
    $sql .= " AND p.payment_type_id = ?";
    $params[] = $filter_payment_type;
    $types .= 'i';
}
if ($filter_mode) {
    $sql .= " AND p.mode = ?";
    $params[] = $filter_mode;
    $types .= 's';
}
if ($search_term) {
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ? OR ss.first_name LIKE ? OR ss.last_name LIKE ? OR ss.srn LIKE ? OR p.description LIKE ? OR p.id LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 9; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}
if ($date_from) {
    $sql .= " AND DATE(p.payment_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $sql .= " AND DATE(p.payment_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}
if (!empty($filter_period_from)) {
    $period_from_date = $filter_period_from;
    if (strlen($filter_period_from) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_from)) {
        $period_from_date = $filter_period_from . '-01';
    }
    $sql .= " AND p.payment_period >= ?";
    $params[] = $period_from_date;
    $types .= 's';
}
if (!empty($filter_period_to)) {
    $period_to_date = $filter_period_to;
    if (strlen($filter_period_to) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_to)) {
        try {
            $last_day = date('t', strtotime($filter_period_to . '-01'));
            $period_to_date = $filter_period_to . '-' . $last_day;
        } catch (Exception $e) {
            // If date calculation fails, just use the original value
        }
    }
    $sql .= " AND p.payment_period <= ?";
    $params[] = $period_to_date;
    $types .= 's';
}
if ($amount_min) {
    $sql .= " AND p.amount >= ?";
    $params[] = floatval($amount_min);
    $types .= 'd';
}
if ($amount_max) {
    $sql .= " AND p.amount <= ?";
    $params[] = floatval($amount_max);
    $types .= 'd';
}

$is_leader = ($class_ids !== null || get_user_organization_ids() !== null);
if (!$can_view_all && $user_id && !$is_leader) {
    $sql .= " AND p.recorded_by = ?";
    $params[] = $user_id;
    $types .= 'i';
}

// Get total count
$count_sql = "SELECT COUNT(DISTINCT p.id) as total FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations org ON mo.organization_id = org.id
    LEFT JOIN users u ON p.recorded_by = u.id
WHERE ((p.reversal_approved_at IS NULL) OR (p.reversal_undone_at IS NOT NULL))";

// Apply same filters to count query
$count_params = [];
$count_types = '';

if ($class_ids !== null) {
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $count_sql .= " AND ((p.member_id IS NOT NULL AND m.class_id IN ($placeholders)) OR (p.sundayschool_id IS NOT NULL AND ss.class_id IN ($placeholders)))";
    foreach ($class_ids as $class_id) {
        $count_params[] = $class_id;
        $count_types .= 'i';
    }
    foreach ($class_ids as $class_id) {
        $count_params[] = $class_id;
        $count_types .= 'i';
    }
}

if (!empty($org_filter['sql'])) {
    $count_sql .= " AND " . $org_filter['sql'];
    foreach ($org_filter['params'] as $param) {
        $count_params[] = $param;
    }
    $count_types .= $org_filter['types'];
}

if (!empty($ss_filter['sql'])) {
    $count_sql .= " AND " . $ss_filter['sql'];
    foreach ($ss_filter['params'] as $param) {
        $count_params[] = $param;
    }
    $count_types .= $ss_filter['types'];
}

if (!empty($cashier_filter['sql'])) {
    $count_sql .= " AND " . $cashier_filter['sql'];
    foreach ($cashier_filter['params'] as $param) {
        $count_params[] = $param;
    }
    $count_types .= $cashier_filter['types'];
}

if (($is_super_admin || $can_view_all) && $filter_user_id) {
    $count_sql .= " AND p.recorded_by = ?";
    $count_params[] = $filter_user_id;
    $count_types .= 'i';
}

if ($filter_church) {
    $count_sql .= " AND m.church_id = ?";
    $count_params[] = $filter_church;
    $count_types .= 'i';
}
if ($filter_class) {
    $count_sql .= " AND m.class_id = ?";
    $count_params[] = $filter_class;
    $count_types .= 'i';
}
if ($filter_org) {
    $count_sql .= " AND mo.organization_id = ?";
    $count_params[] = $filter_org;
    $count_types .= 'i';
}
if ($filter_gender) {
    $count_sql .= " AND m.gender = ?";
    $count_params[] = $filter_gender;
    $count_types .= 's';
}
if ($filter_payment_type) {
    $count_sql .= " AND p.payment_type_id = ?";
    $count_params[] = $filter_payment_type;
    $count_types .= 'i';
}
if ($filter_mode) {
    $count_sql .= " AND p.mode = ?";
    $count_params[] = $filter_mode;
    $count_types .= 's';
}
if ($search_term) {
    $count_sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ? OR ss.first_name LIKE ? OR ss.last_name LIKE ? OR ss.srn LIKE ? OR p.description LIKE ? OR p.id LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 9; $i++) {
        $count_params[] = $search_like;
        $count_types .= 's';
    }
}
if ($date_from) {
    $count_sql .= " AND DATE(p.payment_date) >= ?";
    $count_params[] = $date_from;
    $count_types .= 's';
}
if ($date_to) {
    $count_sql .= " AND DATE(p.payment_date) <= ?";
    $count_params[] = $date_to;
    $count_types .= 's';
}
if (!empty($filter_period_from)) {
    $period_from_date = $filter_period_from;
    if (strlen($filter_period_from) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_from)) {
        $period_from_date = $filter_period_from . '-01';
    }
    $count_sql .= " AND p.payment_period >= ?";
    $count_params[] = $period_from_date;
    $count_types .= 's';
}
if (!empty($filter_period_to)) {
    $period_to_date = $filter_period_to;
    if (strlen($filter_period_to) === 7 && preg_match('/^\d{4}-\d{2}$/', $filter_period_to)) {
        try {
            $last_day = date('t', strtotime($filter_period_to . '-01'));
            $period_to_date = $filter_period_to . '-' . $last_day;
        } catch (Exception $e) {
        }
    }
    $count_sql .= " AND p.payment_period <= ?";
    $count_params[] = $period_to_date;
    $count_types .= 's';
}
if ($amount_min) {
    $count_sql .= " AND p.amount >= ?";
    $count_params[] = floatval($amount_min);
    $count_types .= 'd';
}
if ($amount_max) {
    $count_sql .= " AND p.amount <= ?";
    $count_params[] = floatval($amount_max);
    $count_types .= 'd';
}

if (!$can_view_all && $user_id && !$is_leader) {
    $count_sql .= " AND p.recorded_by = ?";
    $count_params[] = $user_id;
    $count_types .= 'i';
}

if ($count_types) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($count_types, ...$count_params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result()->fetch_assoc();
    $total_records = $count_result ? $count_result['total'] : 0;
} else {
    $count_result = $conn->query($count_sql);
    if ($count_result) {
        $count_row = $count_result->fetch_assoc();
        $total_records = $count_row ? $count_row['total'] : 0;
    } else {
        $total_records = 0;
    }
}

// Calculate pagination values
$total_pages = ceil($total_records / $records_per_page);
$current_page = min($current_page, max(1, $total_pages));

// Add ORDER BY and LIMIT to main query
$sql .= " GROUP BY p.id ORDER BY $sort_column $sort_order, p.id DESC";
if (!$show_all) {
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $records_per_page;
    $params[] = $offset;
    $types .= 'ii';
}

// Execute the main query
if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $payments = $stmt->get_result();
} else {
    $payments = $conn->query($sql);
}

// Calculate totals and statistics
$total_amount = 0;
$payment_count = 0;
$payments_array = [];
$payment_modes_count = [];
$payment_types_count = [];

while ($row = $payments->fetch_assoc()) {
    $payments_array[] = $row;
    $total_amount += $row['amount'];
    $payment_count++;
    
    $mode = $row['mode'] ?? 'cash';
    $payment_modes_count[$mode] = ($payment_modes_count[$mode] ?? 0) + 1;
    
    $type = $row['payment_type'] ?? 'Unknown';
    $payment_types_count[$type] = ($payment_types_count[$type] ?? 0) + 1;
}

$avg_amount = $payment_count > 0 ? $total_amount / $payment_count : 0;

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .banking-container {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
        }
        
        .statement-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 30px;
            border-radius: 15px 15px 0 0;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .statement-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .statement-header .subtitle {
            margin-top: 10px;
            opacity: 0.9;
            font-size: 0.95rem;
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
        
        .stat-card.primary { border-left-color: #1e3c72; }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.warning { border-left-color: #ffc107; }
        
        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-card.primary .icon { color: #1e3c72; }
        .stat-card.success .icon { color: #28a745; }
        .stat-card.info .icon { color: #17a2b8; }
        .stat-card.warning .icon { color: #ffc107; }
        
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
        
        .filter-section h5 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-section h5 i {
            color: #1e3c72;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #1e3c72;
            box-shadow: 0 0 0 0.2rem rgba(30, 60, 114, 0.25);
        }
        
        .btn-banking {
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-banking-primary {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
        }
        
        .btn-banking-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(30, 60, 114, 0.4);
            color: white;
        }
        
        .btn-banking-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-banking-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn-banking-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }
        
        .transactions-table-wrapper {
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }
        
        .table-header-banking {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header-banking h5 {
            margin: 0;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .table-banking {
            width: 100%;
            margin: 0;
        }
        
        .table-banking thead {
            background: #f8f9fa;
        }
        
        .table-banking thead th {
            border: none;
            padding: 15px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .table-banking thead th:hover {
            background: #e9ecef;
        }
        
        .table-banking thead th.sortable.active {
            background: #e3f2fd;
            color: #1e3c72;
        }
        
        .sort-indicator {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 0.7rem;
            color: #1e3c72;
        }
        
        .table-banking tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.2s ease;
        }
        
        .table-banking tbody tr:hover {
            background: #f8f9fa;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .table-banking tbody td {
            padding: 18px 15px;
            vertical-align: middle;
        }
        
        .transaction-id {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            color: #6c757d;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 5px;
            display: inline-block;
            font-weight: 600;
        }
        
        .badge-banking {
            padding: 6px 12px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .pagination-banking {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            padding: 30px;
            background: white;
            border-radius: 0 0 15px 15px;
        }
        
        .pagination-banking .page-link {
            border-radius: 8px;
            border: 2px solid #e9ecef;
            padding: 8px 15px;
            color: #1e3c72;
            font-weight: 600;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .pagination-banking .page-link:hover {
            background: #1e3c72;
            color: white;
            border-color: #1e3c72;
            transform: translateY(-2px);
        }
        
        .pagination-banking .page-item.active .page-link {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-color: #1e3c72;
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
        
        @media print {
            .filter-section, .pagination-banking, .no-print {
                display: none !important;
            }
            
            .statement-header {
                border-radius: 0;
            }
            
            .table-banking tbody tr:hover {
                box-shadow: none;
            }
        }
        
        @media (max-width: 768px) {
            .stats-dashboard {
                grid-template-columns: 1fr;
                margin: -20px 10px 20px 10px;
            }
            
            .statement-header h1 {
                font-size: 1.5rem;
            }
            
            .table-responsive {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>

<div class="banking-container">
    <!-- Statement Header -->
    <div class="statement-header">
        <h1>
            <i class="fas fa-university"></i>
            Payment Transaction Register
        </h1>
        <div class="subtitle">
            Comprehensive payment tracking and management system
            <?php if ($date_from || $date_to): ?>
                | Period: <?= $date_from ? date('M j, Y', strtotime($date_from)) : 'Beginning' ?> - 
                <?= $date_to ? date('M j, Y', strtotime($date_to)) : 'Present' ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Dashboard -->
    <div class="stats-dashboard">
        <div class="stat-card primary">
            <div class="icon"><i class="fas fa-coins"></i></div>
            <div class="label">Total Amount</div>
            <div class="value">₵<?= number_format($total_amount, 2) ?></div>
        </div>
        <div class="stat-card success">
            <div class="icon"><i class="fas fa-receipt"></i></div>
            <div class="label">Transactions</div>
            <div class="value"><?= number_format($payment_count) ?></div>
        </div>
        <div class="stat-card info">
            <div class="icon"><i class="fas fa-chart-line"></i></div>
            <div class="label">Average Amount</div>
            <div class="value">₵<?= number_format($avg_amount, 2) ?></div>
        </div>
        <div class="stat-card warning">
            <div class="icon"><i class="fas fa-database"></i></div>
            <div class="label">Total Records</div>
            <div class="value"><?= number_format($total_records) ?></div>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (isset($_GET['added'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i>Payment added successfully!
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php elseif (isset($_GET['updated'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i>Payment updated successfully!
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php elseif (isset($_GET['deleted'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i>Payment deleted successfully!
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <!-- Advanced Filters -->
    <div class="filter-section no-print">
        <h5><i class="fas fa-filter"></i>Advanced Filters & Search</h5>
        <form action="" method="get" id="filterForm">
            <div class="row mb-3">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Date From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Date To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Payment Type</label>
                    <select class="form-select" name="payment_type_id">
                        <option value="">All Types</option>
                        <?php while ($pt = $payment_types->fetch_assoc()): ?>
                            <option value="<?= $pt['id'] ?>" <?= $filter_payment_type == $pt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name'] ?? '') ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Payment Mode</label>
                    <select class="form-select" name="mode">
                        <option value="">All Modes</option>
                        <?php while ($mode = $payment_modes->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($mode['mode'] ?? '') ?>" 
                                    <?= $filter_mode == $mode['mode'] ? 'selected' : '' ?>>
                                <?= ucwords(str_replace('_', ' ', $mode['mode'] ?? 'Unknown')) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Church</label>
                    <select class="form-select" name="church_id" id="churchFilter">
                        <option value="">All Churches</option>
                        <?php 
                        $churches->data_seek(0);
                        while ($church = $churches->fetch_assoc()): 
                        ?>
                            <option value="<?= $church['id'] ?>" <?= $filter_church == $church['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($church['name'] ?? '') ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Min Amount (₵)</label>
                    <input type="number" step="0.01" class="form-control" name="amount_min" 
                           placeholder="0.00" value="<?= $amount_min ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Max Amount (₵)</label>
                    <input type="number" step="0.01" class="form-control" name="amount_max" 
                           placeholder="0.00" value="<?= $amount_max ?>">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Search</label>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Name, CRN, ID, description..." value="<?= htmlspecialchars($search_term ?? '') ?>">
                </div>
            </div>
            
            <div class="row mb-3">
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Records Per Page</label>
                    <select class="form-select" name="per_page" onchange="this.form.submit()">
                        <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200</option>
                        <option value="500" <?= $records_per_page == 500 ? 'selected' : '' ?>>500</option>
                        <option value="9999" <?= $show_all ? 'selected' : '' ?>>Show All</option>
                    </select>
                </div>
                <?php if ($user_filter_options): ?>
                <div class="col-md-3 mb-3">
                    <label class="form-label fw-bold">Recorded By</label>
                    <select class="form-select" name="user_id">
                        <option value="">All Users</option>
                        <?php while ($user = $user_filter_options->fetch_assoc()): ?>
                            <option value="<?= $user['id'] ?>" <?= $filter_user_id == $user['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['name'] ?? '') ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="row">
                <div class="col-md-12 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-banking btn-banking-primary">
                        <i class="fas fa-search mr-2"></i>Apply Filters
                    </button>
                    <a href="payment_list.php" class="btn btn-banking btn-banking-secondary">
                        <i class="fas fa-times mr-2"></i>Clear All
                    </a>
                    <button type="button" class="btn btn-banking btn-banking-success" onclick="exportToExcel()">
                        <i class="fas fa-file-excel mr-2"></i>Export Excel
                    </button>
                    <button type="button" class="btn btn-banking btn-banking-info" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                    <?php if ($can_add): ?>
                        <a href="payment_form.php" class="btn btn-banking btn-banking-primary">
                            <i class="fas fa-plus mr-2"></i>New Payment
                        </a>
                    <?php endif; ?>
                    <a href="payment_reversal_log.php" class="btn btn-banking btn-banking-secondary">
                        <i class="fas fa-history mr-2"></i>Reversal Log
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Reversal Status Legend -->
    <div class="alert alert-info no-print" style="border-radius: 10px; border-left: 4px solid #17a2b8;">
        <div class="d-flex align-items-center">
            <i class="fas fa-info-circle mr-3" style="font-size: 1.5rem;"></i>
            <div>
                <strong>Payment Status Legend:</strong>
                <span class="ml-3" style="background-color: #fff3cd; padding: 4px 8px; border-radius: 4px;">
                    <i class="fas fa-clock text-warning"></i> Yellow = Reversal Pending
                </span>
                <span class="ml-2" style="background-color: #ffe6e6; padding: 4px 8px; border-radius: 4px;">
                    <i class="fas fa-ban text-danger"></i> Red = Reversed
                </span>
            </div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="transactions-table-wrapper">
        <div class="table-header-banking">
            <h5><i class="fas fa-list-alt"></i>Transaction Records</h5>
            <span class="badge badge-light"><?= number_format($total_records) ?> total</span>
        </div>
        
        <?php if (empty($payments_array)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h4>No Transactions Found</h4>
                <p class="text-muted">Try adjusting your filters or add a new payment.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-banking">
                <thead>
                    <tr>
                        <th class="sortable <?= $sort_by == 'payment_date' ? 'active' : '' ?>" onclick="sortTable('payment_date')">
                            Date & Time
                            <?php if ($sort_by == 'payment_date'): ?>
                                <span class="sort-indicator">
                                    <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                </span>
                            <?php endif; ?>
                        </th>
                        <th class="sortable <?= $sort_by == 'member_name' ? 'active' : '' ?>" onclick="sortTable('member_name')">
                            Member/Student
                            <?php if ($sort_by == 'member_name'): ?>
                                <span class="sort-indicator">
                                    <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                </span>
                            <?php endif; ?>
                        </th>
                        <th class="sortable <?= $sort_by == 'payment_type' ? 'active' : '' ?>" onclick="sortTable('payment_type')">
                            Type
                            <?php if ($sort_by == 'payment_type'): ?>
                                <span class="sort-indicator">
                                    <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                </span>
                            <?php endif; ?>
                        </th>
                        <th>Description</th>
                        <th class="sortable <?= $sort_by == 'mode' ? 'active' : '' ?>" onclick="sortTable('mode')">
                            Mode
                            <?php if ($sort_by == 'mode'): ?>
                                <span class="sort-indicator">
                                    <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                </span>
                            <?php endif; ?>
                        </th>
                        <th class="sortable text-end <?= $sort_by == 'amount' ? 'active' : '' ?>" onclick="sortTable('amount')">
                            Amount
                            <?php if ($sort_by == 'amount'): ?>
                                <span class="sort-indicator">
                                    <i class="fas fa-sort-<?= $sort_order == 'ASC' ? 'up' : 'down' ?>"></i>
                                </span>
                            <?php endif; ?>
                        </th>
                        <th class="text-center no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments_array as $row): 
                        $is_reversed = !empty($row['reversal_approved_at']) && empty($row['reversal_undone_at']);
                        $is_pending_reversal = !empty($row['reversal_requested_at']) && empty($row['reversal_approved_at']);
                    ?>
                        <tr <?= $is_reversed ? 'style="opacity: 0.6; background-color: #ffe6e6;"' : ($is_pending_reversal ? 'style="background-color: #fff3cd;"' : '') ?>>
                            <td>
                                <div>
                                    <strong><?= date('M j, Y', strtotime($row['payment_date'])) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= date('g:i A', strtotime($row['payment_date'])) ?></small>
                                </div>
                            </td>
                            <td>
                                <?php if ($row['member_id']): ?>
                                    <div>
                                        <strong><?= htmlspecialchars(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?></strong>
                                        <br>
                                        <small class="text-muted">CRN: <?= htmlspecialchars($row['crn'] ?? '') ?></small>
                                    </div>
                                <?php elseif ($row['sundayschool_id']): ?>
                                    <div>
                                        <strong><?= htmlspecialchars(($row['ss_first_name'] ?? '') . ' ' . ($row['ss_last_name'] ?? '')) ?></strong>
                                        <br>
                                        <small class="text-muted">SRN: <?= htmlspecialchars($row['srn'] ?? '') ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-primary badge-banking">
                                    <?= htmlspecialchars($row['payment_type'] ?? 'Unknown') ?>
                                </span>
                                <?php if ($row['payment_period'] && $row['payment_period'] != date('Y-m-d', strtotime($row['payment_date']))): ?>
                                    <br>
                                    <small class="badge badge-info badge-banking mt-1">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?= $row['payment_period_description'] ?: date('M Y', strtotime($row['payment_period'])) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-truncate d-inline-block" style="max-width: 200px;" 
                                      title="<?= htmlspecialchars($row['description'] ?? '') ?>">
                                    <?= htmlspecialchars($row['description'] ?? '') ?>
                                </span>
                                <?php if ($row['recorded_by_username']): ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($row['recorded_by_username'] ?? '') ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                // Get the mode from the current row and normalize it
                                $mode = strtolower(trim($row['mode'] ?? 'cash'));
                                
                                $mode_badges = [
                                    'cash' => ['class' => 'success', 'icon' => 'money-bill-wave', 'label' => 'Cash', 'bg' => '#28a745', 'color' => '#fff'],
                                    'mobile_money' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'Mobile Money', 'bg' => '#ffc107', 'color' => '#000'],
                                    'mobile money' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'Mobile Money', 'bg' => '#ffc107', 'color' => '#000'],
                                    'momo' => ['class' => 'warning', 'icon' => 'mobile-alt', 'label' => 'Mobile Money', 'bg' => '#ffc107', 'color' => '#000'],
                                    'ussd' => ['class' => 'info', 'icon' => 'phone-square-alt', 'label' => 'USSD', 'bg' => '#17a2b8', 'color' => '#fff'],
                                    'online' => ['class' => 'primary', 'icon' => 'globe', 'label' => 'Online', 'bg' => '#6f42c1', 'color' => '#fff'],
                                    'bulk_upload' => ['class' => 'info', 'icon' => 'upload', 'label' => 'Bulk Upload', 'bg' => '#20c997', 'color' => '#fff'],
                                    'bulk upload' => ['class' => 'info', 'icon' => 'upload', 'label' => 'Bulk Upload', 'bg' => '#20c997', 'color' => '#fff'],
                                    'bank_transfer' => ['class' => 'primary', 'icon' => 'university', 'label' => 'Bank Transfer', 'bg' => '#007bff', 'color' => '#fff'],
                                    'bank transfer' => ['class' => 'primary', 'icon' => 'university', 'label' => 'Bank Transfer', 'bg' => '#007bff', 'color' => '#fff'],
                                    'transfer' => ['class' => 'primary', 'icon' => 'university', 'label' => 'Bank Transfer', 'bg' => '#007bff', 'color' => '#fff'],
                                    'cheque' => ['class' => 'secondary', 'icon' => 'money-check', 'label' => 'Cheque', 'bg' => '#6c757d', 'color' => '#fff'],
                                    'check' => ['class' => 'secondary', 'icon' => 'money-check', 'label' => 'Cheque', 'bg' => '#6c757d', 'color' => '#fff'],
                                    'card' => ['class' => 'danger', 'icon' => 'credit-card', 'label' => 'Card', 'bg' => '#dc3545', 'color' => '#fff'],
                                    'credit card' => ['class' => 'danger', 'icon' => 'credit-card', 'label' => 'Card', 'bg' => '#dc3545', 'color' => '#fff']
                                ];
                                
                                // If mode not in predefined list, create a generic badge with debug info
                                if (isset($mode_badges[$mode])) {
                                    $badge_info = $mode_badges[$mode];
                                } else {
                                    $badge_info = [
                                        'class' => 'secondary', 
                                        'icon' => 'question-circle', 
                                        'label' => ucwords(str_replace('_', ' ', $mode ?: 'Unknown')) . ' [' . $mode . ']',
                                        'bg' => '#6c757d',
                                        'color' => '#fff'
                                    ];
                                }
                                ?>
                                <span class="badge badge-<?= $badge_info['class'] ?> badge-banking" 
                                      style="background-color: <?= $badge_info['bg'] ?>; color: <?= $badge_info['color'] ?>; font-weight: 600;">
                                    <i class="fas fa-<?= $badge_info['icon'] ?>"></i>
                                    <?= $badge_info['label'] ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <strong class="text-success" style="font-size: 1.1rem;">
                                    ₵<?= number_format((float)$row['amount'], 2) ?>
                                </strong>
                            </td>
                            <td class="text-center no-print">
                                <div class="btn-group btn-group-sm">
                                    <?php if ($can_edit): ?>
                                        <a href="payment_form.php?id=<?= $row['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="payment_view.php?id=<?= $row['id'] ?>" 
                                       class="btn btn-sm btn-outline-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($can_delete): ?>
                                        <button onclick="deletePayment(<?= $row['id'] ?>)" 
                                                class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Reversal Status & Actions -->
                                <?php
                                    $is_pending = !empty($row['reversal_requested_at']) && empty($row['reversal_approved_at']);
                                    $is_reversed = !empty($row['reversal_approved_at']) && empty($row['reversal_undone_at']);
                                    $can_approve = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
                                    $can_undo = $can_approve;
                                ?>
                                <div class="mt-2">
                                    <?php if ($is_pending): ?>
                                        <span class="badge badge-warning badge-banking">
                                            <i class="fas fa-clock"></i> Reversal Pending
                                        </span>
                                        <?php if ($can_approve): ?>
                                            <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=approve" 
                                               class="btn btn-xs btn-success mt-1" 
                                               onclick="return confirm('Approve this payment reversal?');" 
                                               title="Approve Reversal">
                                                <i class="fas fa-check"></i> Approve
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif ($is_reversed): ?>
                                        <span class="badge badge-danger badge-banking">
                                            <i class="fas fa-ban"></i> Reversed
                                        </span>
                                        <?php if ($can_undo): ?>
                                            <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=undo" 
                                               class="btn btn-xs btn-info mt-1" 
                                               onclick="return confirm('Undo this payment reversal?');" 
                                               title="Undo Reversal">
                                                <i class="fas fa-undo"></i> Undo
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif (empty($row['reversal_requested_at'])): ?>
                                        <a href="payment_reverse.php?id=<?= $row['id'] ?>" 
                                           class="btn btn-xs btn-secondary mt-1" 
                                           onclick="return confirm('Request reversal for this payment?');" 
                                           title="Request Reversal">
                                            <i class="fas fa-undo"></i> Reverse
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-active">
                        <td colspan="5" class="text-end"><strong>Page Total:</strong></td>
                        <td class="text-end">
                            <strong class="text-success" style="font-size: 1.2rem;">
                                ₵<?= number_format($total_amount, 2) ?>
                            </strong>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Pagination -->
        <?php if (!$show_all && $total_pages > 1): ?>
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
        <?php elseif ($show_all): ?>
            <div class="pagination-banking">
                <div class="text-muted">
                    <small>
                        <i class="fas fa-info-circle mr-2"></i>
                        Showing all <?= number_format($total_records) ?> records
                    </small>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function sortTable(column) {
    const form = document.getElementById('filterForm');
    const currentSort = '<?= $sort_by ?>';
    const currentOrder = '<?= $sort_order ?>';
    
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
    
    if (currentSort === column) {
        sortOrderInput.value = currentOrder === 'ASC' ? 'DESC' : 'ASC';
    } else {
        sortOrderInput.value = 'DESC';
    }
    
    form.submit();
}

function exportToExcel() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = 'export_payments.php?' + params.toString();
}

function deletePayment(id) {
    if (confirm('Are you sure you want to delete this payment? This action cannot be undone.')) {
        window.location.href = 'payment_delete.php?id=' + id;
    }
}

// Auto-submit on date change
document.addEventListener('DOMContentLoaded', function() {
    const autoSubmitFields = ['date_from', 'date_to', 'churchFilter'];
    autoSubmitFields.forEach(id => {
        const field = document.getElementById(id) || document.querySelector(`[name="${id}"]`);
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
$page_title = 'Payment Transaction Register';
include '../includes/layout.php';
?>
