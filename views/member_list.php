<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_member')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Check permissions
$can_add = $is_super_admin || has_permission('add_member');
$can_edit = $is_super_admin || has_permission('edit_member');
$can_delete = $is_super_admin || has_permission('delete_member');
$can_export = $is_super_admin || has_permission('export_member_list');
$can_view = true; // Already validated above

// Sorting parameters
$allowed_sort_fields = ['crn', 'last_name', 'first_name', 'phone', 'church_name', 'class_name', 'day_born', 'gender', 'status'];
$sort_field = isset($_GET['sort']) && in_array($_GET['sort'], $allowed_sort_fields) ? $_GET['sort'] : 'last_name';
$sort_direction = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Build WHERE clause with filters - EXCLUDE adherents (moved before export)
$where_conditions = ["m.status = 'active'", "m.membership_status != 'Adherent'"];
$params = [];
$param_types = "";

// Search filter
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(CONCAT(m.last_name, ' ', m.first_name, ' ', m.middle_name) LIKE ? OR m.crn LIKE ? OR m.phone LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $param_types .= "sss";
}

// Church filter
if (!empty($_GET['church_id'])) {
    $where_conditions[] = "m.church_id = ?";
    $params[] = $_GET['church_id'];
    $param_types .= "i";
}

// Bible class filter
if (!empty($_GET['class_id'])) {
    $where_conditions[] = "m.class_id = ?";
    $params[] = $_GET['class_id'];
    $param_types .= "i";
}

// Gender filter
if (!empty($_GET['gender'])) {
    $where_conditions[] = "m.gender = ?";
    $params[] = $_GET['gender'];
    $param_types .= "s";
}

// Day born filter
if (!empty($_GET['day_born'])) {
    $where_conditions[] = "m.day_born = ?";
    $params[] = $_GET['day_born'];
    $param_types .= "s";
}

// Status filter
if (!empty($_GET['status_filter'])) {
    switch ($_GET['status_filter']) {
        case 'full_member':
            $where_conditions[] = "LOWER(m.confirmed) = 'yes' AND LOWER(m.baptized) = 'yes'";
            break;
        case 'catechumen':
            $where_conditions[] = "(LOWER(m.confirmed) = 'yes' OR LOWER(m.baptized) = 'yes') AND NOT (LOWER(m.confirmed) = 'yes' AND LOWER(m.baptized) = 'yes')";
            break;
        case 'no_status':
            $where_conditions[] = "(m.confirmed IS NULL OR m.confirmed = '' OR LOWER(m.confirmed) != 'yes') AND (m.baptized IS NULL OR m.baptized = '' OR LOWER(m.baptized) != 'yes')";
            break;
        case 'juvenile':
            // This will be handled in the UNION part for Sunday school members
            break;
    }
}

// Build the main query
$where_clause = implode(' AND ', $where_conditions);

// Build Sunday School WHERE clause with similar filters
$ss_where_conditions = [];
$ss_params = [];
$ss_param_types = "";

// Search filter for Sunday School
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $ss_where_conditions[] = "(CONCAT(s.last_name, ' ', s.first_name, ' ', s.middle_name) LIKE ? OR s.srn LIKE ? OR s.contact LIKE ?)";
    $ss_params[] = $search;
    $ss_params[] = $search;
    $ss_params[] = $search;
    $ss_param_types .= "sss";
}

// Church filter for Sunday School
if (!empty($_GET['church_id'])) {
    $ss_where_conditions[] = "s.church_id = ?";
    $ss_params[] = $_GET['church_id'];
    $ss_param_types .= "i";
}

// Bible class filter for Sunday School
if (!empty($_GET['class_id'])) {
    $ss_where_conditions[] = "s.class_id = ?";
    $ss_params[] = $_GET['class_id'];
    $ss_param_types .= "i";
}

// Gender filter for Sunday School
if (!empty($_GET['gender'])) {
    $ss_where_conditions[] = "s.gender = ?";
    $ss_params[] = $_GET['gender'];
    $ss_param_types .= "s";
}

// Day born filter for Sunday School
if (!empty($_GET['day_born'])) {
    $ss_where_conditions[] = "s.dayborn = ?";
    $ss_params[] = $_GET['day_born'];
    $ss_param_types .= "s";
}

// Status filter - only include Sunday School if juvenile is selected or no status filter
$include_sunday_school = true;
if (!empty($_GET['status_filter']) && $_GET['status_filter'] !== 'juvenile') {
    $include_sunday_school = false;
}

$ss_where_clause = !empty($ss_where_conditions) ? implode(' AND ', $ss_where_conditions) : '1=1';

// Combine all parameters for prepared statements
$all_params = array_merge($params, $ss_params);
$all_param_types = $param_types . $ss_param_types;

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $can_export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="member_list_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'CRN', 'Last Name', 'First Name', 'Middle Name', 'Phone', 'Gender', 
        'Day Born', 'Church', 'Bible Class', 'Status', 'Total Paid'
    ]);
    
    // Re-run the query for export (without pagination) - include Sunday school members
    $export_sql = "SELECT 
        m.id, m.crn, m.last_name, m.first_name, m.middle_name, m.phone, m.gender, 
        m.day_born, m.photo, m.membership_status, m.status, m.confirmed, m.baptized,
        c.name as church_name, cl.name as class_name, 'member' as member_type
    FROM members m
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes cl ON m.class_id = cl.id
    WHERE $where_clause
    
    UNION ALL
    
    SELECT 
        s.id, s.srn as crn, s.last_name, s.first_name, s.middle_name, s.contact as phone, s.gender,
        s.dayborn as day_born, s.photo, NULL as membership_status, 'active' as status, 
        'no' as confirmed, 'no' as baptized, c.name as church_name, cl.name as class_name, 'sunday_school' as member_type
    FROM sunday_school s
    LEFT JOIN churches c ON s.church_id = c.id
    LEFT JOIN bible_classes cl ON s.class_id = cl.id
    WHERE " . ($include_sunday_school ? $ss_where_clause : "1=0") . "
    
    ORDER BY last_name ASC, first_name ASC";
    
    $export_stmt = $conn->prepare($export_sql);
    if (!empty($all_params)) {
        $export_stmt->bind_param($all_param_types, ...$all_params);
    }
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();
    
    while ($row = $export_result->fetch_assoc()) {
        // Determine status based on member type
        if ($row['member_type'] === 'sunday_school') {
            $status = 'Juvenile';
        } else {
            // Regular members - determine status based on confirmed and baptized
            $is_confirmed = (strtolower($row['confirmed']) === 'yes');
            $is_baptized = (strtolower($row['baptized']) === 'yes');
            
            if ($is_confirmed && $is_baptized) {
                $status = 'Full Member';
            } elseif ($is_confirmed || $is_baptized) {
                $status = 'Catechumen';
            } else {
                $status = 'Catechumen';
            }
        }
        
        // Get total payments for this member
        $payment_sql = "SELECT SUM(amount) as total FROM payments 
                       WHERE member_id = ? AND reversal_approved_at IS NULL AND amount IS NOT NULL";
        $payment_stmt = $conn->prepare($payment_sql);
        $payment_stmt->bind_param("i", $row['id']);
        $payment_stmt->execute();
        $payment_result = $payment_stmt->get_result();
        $payment_row = $payment_result->fetch_assoc();
        $total_paid = $payment_row['total'] ? floatval($payment_row['total']) : 0;
        
        fputcsv($output, [
            $row['crn'],
            $row['last_name'],
            $row['first_name'],
            $row['middle_name'],
            $row['phone'],
            $row['gender'],
            $row['day_born'],
            $row['church_name'],
            $row['class_name'],
            $status,
            number_format($total_paid, 2)
        ]);
    }
    
    fclose($output);
    exit;
}

// Modal accumulator for all modals in this file
$modal_html = '';

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$allowed_sizes = [20,40,60,80,100,'all'];
$page_size = isset($_GET['page_size']) && (in_array($_GET['page_size'], $allowed_sizes)) ? $_GET['page_size'] : 20;

// Where clause already built above before export

// Count total members for pagination (including Sunday school members)
$count_sql = "SELECT COUNT(*) as total FROM (
    SELECT m.id FROM members m WHERE $where_clause
    UNION ALL
    SELECT s.id FROM sunday_school s 
    LEFT JOIN churches c ON s.church_id = c.id 
    LEFT JOIN bible_classes cl ON s.class_id = cl.id 
    WHERE " . ($include_sunday_school ? $ss_where_clause : "1=0") . "
) as combined_members";
$count_stmt = $conn->prepare($count_sql);
if (!empty($all_params)) {
    $count_stmt->bind_param($all_param_types, ...$all_params);
}
$count_stmt->execute();
$total_members = $count_stmt->get_result()->fetch_assoc()['total'];

// Calculate pagination
$total_pages = ($page_size === 'all') ? 1 : ceil($total_members / $page_size);
$offset = ($page_size === 'all') ? 0 : ($page - 1) * $page_size;
$limit_clause = ($page_size === 'all') ? '' : "LIMIT $page_size OFFSET $offset";

// Main query to get members and Sunday school members combined with proper sorting
$sql = "
    SELECT * FROM (
        SELECT 
            m.id, m.crn, m.last_name, m.first_name, m.middle_name, m.phone, m.gender, 
            m.day_born, m.photo, m.membership_status, m.status, m.confirmed, m.baptized,
            c.name as church_name, cl.name as class_name, 'member' as member_type,
            CASE 
                WHEN LOWER(m.confirmed) = 'yes' AND LOWER(m.baptized) = 'yes' THEN 'Full Member'
                WHEN LOWER(m.confirmed) = 'yes' OR LOWER(m.baptized) = 'yes' THEN 'Catechumen'
                ELSE 'No Status'
            END as computed_status
        FROM members m
        LEFT JOIN churches c ON m.church_id = c.id
        LEFT JOIN bible_classes cl ON m.class_id = cl.id
        WHERE $where_clause
        
        UNION ALL
        
        SELECT 
            s.id, s.srn as crn, s.last_name, s.first_name, s.middle_name, s.contact as phone, s.gender,
            s.dayborn as day_born, s.photo, NULL as membership_status, 'active' as status, 
            'no' as confirmed, 'no' as baptized, c.name as church_name, cl.name as class_name, 'sunday_school' as member_type,
            'Juvenile' as computed_status
        FROM sunday_school s
        LEFT JOIN churches c ON s.church_id = c.id
        LEFT JOIN bible_classes cl ON s.class_id = cl.id
        WHERE " . ($include_sunday_school ? $ss_where_clause : "1=0") . "
    ) as combined_results
    ORDER BY " . ($sort_field === 'status' ? 'computed_status' : $sort_field) . " $sort_direction, last_name ASC, first_name ASC
    $limit_clause
";

$stmt = $conn->prepare($sql);
if (!empty($all_params)) {
    $stmt->bind_param($all_param_types, ...$all_params);
}
$stmt->execute();
$members = $stmt->get_result();

ob_start();
?>

<style>
.member-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}

.member-title {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
}

.member-subtitle {
    font-size: 1.1rem;
    opacity: 0.9;
}

.sortable-header {
    cursor: pointer;
    user-select: none;
    position: relative;
    transition: background-color 0.2s ease;
}

.sortable-header:hover {
    background-color: rgba(255, 255, 255, 0.1);
    transform: translateY(-1px);
}

.sort-icon {
    margin-left: 5px;
    opacity: 0.6;
    transition: opacity 0.3s ease;
}

.sortable-header:hover .sort-icon {
    opacity: 1;
}

.sortable-header.active {
    background-color: rgba(255, 255, 255, 0.15);
    font-weight: 600;
}

.sortable-header.active .sort-icon {
    opacity: 1;
    color: #ffc107;
}

/* Enhanced table styling */
.table-responsive {
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    overflow-x: auto;
}

/* Mobile responsive table styles */
@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th, .table td {
        padding: 0.5rem 0.25rem;
        white-space: nowrap;
    }
    
    .table th {
        font-size: 0.8rem;
    }
    
    /* Hide less important columns on mobile */
    .table th:nth-child(5), /* Church */
    .table td:nth-child(5),
    .table th:nth-child(6), /* Bible Class */
    .table td:nth-child(6),
    .table th:nth-child(7), /* Day Born */
    .table td:nth-child(7) {
        display: none;
    }
    
    /* Make photo smaller on mobile */
    .member-photo {
        width: 30px !important;
        height: 30px !important;
    }
    
    /* Adjust button sizes for mobile */
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    /* Stack action buttons vertically on very small screens */
    @media (max-width: 480px) {
        .table th:nth-child(4), /* Phone */
        .table td:nth-child(4) {
            display: none;
        }
        
        .action-buttons .btn {
            display: block;
            width: 100%;
            margin-bottom: 0.25rem;
        }
    }
}

/* Tablet responsive adjustments */
@media (max-width: 992px) and (min-width: 769px) {
    .table th, .table td {
        padding: 0.6rem 0.4rem;
    }
    
    .table th:nth-child(7), /* Day Born */
    .table td:nth-child(7) {
        display: none;
    }
}

.table tbody tr {
    transition: all 0.3s ease;
}

.table tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
    transform: scale(1.01);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* Enhanced button animations */
.btn {
    transition: all 0.3s ease;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Loading state for filters */
.filter-loading {
    opacity: 0.6;
    pointer-events: none;
}

.filter-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid #007bff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}

.filter-card {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
    border: 1px solid rgba(102, 126, 234, 0.2);
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    backdrop-filter: blur(5px);
}

.filter-toggle-btn {
    background: none;
    border: none;
    color: #495057;
    font-weight: 600;
    font-size: 1.1rem;
    text-decoration: none;
    transition: color 0.3s ease;
}

.filter-toggle-btn:hover {
    color: #667eea;
    text-decoration: none;
}

.filter-form {
    background: white;
    border-radius: 10px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.form-group-modern {
    margin-bottom: 1.5rem;
}

.form-label-modern {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.5rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.form-control-modern {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.form-control-modern:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

.btn-search {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border: none;
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    color: white;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    color: white;
}

.btn-clear {
    background: linear-gradient(135deg, #6c757d, #5a6268);
    border: none;
    border-radius: 8px;
    padding: 0.75rem 1.5rem;
    color: white;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-clear:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
    color: white;
}

.member-card {
    border: none;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stats-card {
    background: rgba(255,255,255,0.2);
    border-radius: 15px;
    padding: 1.5rem;
    backdrop-filter: blur(10px);
    transition: transform 0.3s ease;
}

.stats-card:hover {
    transform: translateY(-5px);
}

.member-table {
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 15px rgba(0,0,0,0.08);
}

.member-table thead {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.member-table thead th {
    border: none;
    font-weight: 600;
    padding: 1rem 0.75rem;
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.member-table tbody tr:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.member-table tbody td {
    padding: 1rem 0.75rem;
    vertical-align: middle;
    border: none;
}

.member-photo {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    border: 3px solid #667eea;
    object-fit: cover;
    box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
    transition: transform 0.3s ease;
}

.member-photo:hover {
    transform: scale(1.1);
}

.gender-badge {
    border-radius: 15px;
    padding: 0.3rem 0.6rem;
    font-size: 0.75rem;
    font-weight: 600;
}

.gender-male {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
}

.gender-female {
    background: linear-gradient(135deg, #e83e8c, #d91a72);
    color: white;
}

.action-btn {
    border: none;
    border-radius: 8px;
    padding: 0.4rem 0.8rem;
    margin: 0.1rem;
    font-size: 0.8rem;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: inline-block;
}

.btn-view {
    background: linear-gradient(135deg, #28a745, #20c997);
    color: white;
}

.btn-edit {
    background: linear-gradient(135deg, #ffc107, #fd7e14);
    color: white;
}

.btn-delete {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: white;
}

.btn-deactivate {
    background: linear-gradient(135deg, #6c757d, #5a6268);
    color: white;
}

.btn-adherent {
    background: linear-gradient(135deg, #8e44ad, #9b59b6);
    color: white;
}

.btn-history {
    background: linear-gradient(135deg, #17a2b8, #138496);
    color: white;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    color: white;
    text-decoration: none;
}

.status-active {
    color: #28a745;
    font-weight: 600;
}

.crn-cell {
    font-size: 0.85rem;
    font-weight: 600;
    white-space: nowrap;
}

.total-payments {
    font-size: 0.8rem;
    color: #6c757d;
    font-weight: 500;
}

.filter-section {
    background: rgba(102, 126, 234, 0.05);
    border-radius: 15px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #e3f2fd;
}

.alert-modern {
    border-radius: 15px;
    border: none;
    padding: 1rem 1.5rem;
    margin-bottom: 1.5rem;
}

@media (max-width: 768px) {
    .member-header {
        padding: 1.5rem;
    }
    
    .member-title {
        font-size: 1.8rem;
    }
    
    .member-subtitle {
        font-size: 1rem;
    }
    
    .stats-card {
        margin-top: 1rem;
        margin-left: 0 !important;
    }
    
    .header-actions {
        flex-direction: column;
        align-items: stretch;
        gap: 0.5rem;
    }
    
    .header-actions .btn {
        margin: 0.2rem 0;
    }
    
    .filter-form .row {
        margin: 0;
    }
    
    .filter-form .col-md-3,
    .filter-form .col-md-2,
    .filter-form .col-md-1 {
        padding: 0.25rem;
    }
    
    .member-table {
        font-size: 0.85rem;
    }
    
    .action-btn {
        padding: 0.3rem 0.6rem;
        font-size: 0.7rem;
        margin: 0.05rem;
    }
}

@media (max-width: 576px) {
    .member-header {
        padding: 1rem;
        text-align: center;
    }
    
    .member-title {
        font-size: 1.5rem;
    }
    
    .header-actions {
        margin-top: 1rem;
    }
    
    .filter-form .col-md-3,
    .filter-form .col-md-2,
    .filter-form .col-md-1 {
        flex: 0 0 100%;
        max-width: 100%;
        margin-bottom: 0.5rem;
    }
}
</style>

<div class="card member-card shadow mb-4">
    <div class="member-header">
        <div class="row align-items-center">
            <div class="col-lg-6 col-md-12 mb-3 mb-lg-0">
                <h1 class="member-title mb-2">
                    <i class="fas fa-users mr-3"></i>
                    Church Members
                </h1>
                <p class="member-subtitle mb-0">
                    Manage and view all church member information
                </p>
            </div>
            <div class="col-lg-6 col-md-12">
                <div class="header-actions d-flex flex-wrap align-items-center justify-content-lg-end">
                    <?php if ($can_add): ?>
                        <a href="member_form.php" class="btn btn-light btn-lg mr-2 mb-2">
                            <i class="fas fa-user-plus mr-2"></i>Add Member
                        </a>
                        <div class="btn-group mr-2 mb-2" role="group">
                            <button type="button" class="btn btn-outline-light dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-tools mr-2"></i>Tools
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="member_upload.php">
                                    <i class="fas fa-file-upload mr-2"></i>Bulk Upload
                                </a>
                                <a class="dropdown-item" href="member_upload_enhanced.php">
                                    <i class="fas fa-file-upload mr-2"></i>Enhanced Bulk Upload
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="adherent_list.php">
                                    <i class="fas fa-user-tag mr-2"></i>Adherents
                                </a>
                                <a class="dropdown-item" href="deleted_members_list.php">
                                    <i class="fas fa-trash-alt mr-2"></i>Deleted Members
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($can_export): ?>
                        <div class="btn-group mr-2 mb-2" role="group">
                            <button type="button" class="btn btn-outline-light dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-download mr-2"></i>Export
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">
                                    <i class="fas fa-file-csv mr-2"></i>Export CSV
                                </a>
                                <button class="dropdown-item" id="export-pdf">
                                    <i class="fas fa-file-pdf mr-2"></i>Export PDF
                                </button>
                                <button class="dropdown-item" id="print-table">
                                    <i class="fas fa-print mr-2"></i>Print
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="stats-card mb-2">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-chart-bar fa-2x mr-3"></i>
                            <div>
                                <div class="h4 mb-0"><?= number_format($total_members) ?></div>
                                <small>Total Members</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-4">
        <!-- Alert Messages -->
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_success']) ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php elseif (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['flash_error']) ?>
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php elseif (isset($_GET['added'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-plus mr-2"></i>Member added successfully!
            </div>
        <?php elseif (isset($_GET['updated'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-edit mr-2"></i>Member updated successfully!
            </div>
        <?php elseif (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-modern">
                <i class="fas fa-user-minus mr-2"></i>Member deleted successfully!
            </div>
        <?php elseif (isset($_GET['deactivated'])): ?>
            <div class="alert alert-warning alert-modern">
                <i class="fas fa-user-times mr-2"></i>Member de-activated successfully!
            </div>
        <?php endif; ?>
        
        <!-- Search and Filter Section -->
        <div class="filter-section">
            <h5 class="mb-4">
                <button class="btn btn-link p-0 text-dark font-weight-bold" type="button" data-toggle="collapse" data-target="#filterCollapse" aria-expanded="false" aria-controls="filterCollapse" style="text-decoration: none;">
                    <i class="fas fa-filter mr-2"></i>Search & Filter Options
                    <i class="fas fa-chevron-down ml-2 text-muted" id="filterToggleIcon"></i>
                </button>
            </h5>
            
            <div class="collapse" id="filterCollapse">
            <!-- Search Form -->
            <form method="get" class="mb-4">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label for="search" class="form-label font-weight-bold">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" 
                               placeholder="Name, CRN, or Phone">
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="church_id" class="form-label font-weight-bold">Church</label>
                        <select class="form-control" id="church_id" name="church_id">
                            <option value="">All Churches</option>
                            <?php
                            $churches_query = "SELECT id, name FROM churches ORDER BY name";
                            $churches_result = $conn->query($churches_query);
                            while ($church = $churches_result->fetch_assoc()):
                            ?>
                                <option value="<?= $church['id'] ?>" <?= (isset($_GET['church_id']) && $_GET['church_id'] == $church['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($church['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="class_id" class="form-label font-weight-bold">Bible Class</label>
                        <select class="form-control" id="class_id" name="class_id">
                            <option value="">All Classes</option>
                            <?php
                            $classes_query = "SELECT id, name FROM bible_classes ORDER BY name";
                            $classes_result = $conn->query($classes_query);
                            while ($class = $classes_result->fetch_assoc()):
                            ?>
                                <option value="<?= $class['id'] ?>" <?= (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="gender" class="form-label font-weight-bold">Gender</label>
                        <select class="form-control" id="gender" name="gender">
                            <option value="">All Genders</option>
                            <option value="Male" <?= (isset($_GET['gender']) && $_GET['gender'] == 'Male') ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= (isset($_GET['gender']) && $_GET['gender'] == 'Female') ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="day_born" class="form-label font-weight-bold">Day Born</label>
                        <select class="form-control" id="day_born" name="day_born">
                            <option value="">All Days</option>
                            <?php
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            foreach ($days as $day):
                            ?>
                                <option value="<?= $day ?>" <?= (isset($_GET['day_born']) && $_GET['day_born'] == $day) ? 'selected' : '' ?>>
                                    <?= $day ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="status_filter" class="form-label font-weight-bold">Status</label>
                        <select class="form-control" id="status_filter" name="status_filter">
                            <option value="">All Members</option>
                            <option value="full_member" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'full_member') ? 'selected' : '' ?>>Full Members</option>
                            <option value="catechumen" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'catechumen') ? 'selected' : '' ?>>Catechumens</option>
                            <option value="no_status" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'no_status') ? 'selected' : '' ?>>No Status</option>
                            <option value="juvenile" <?= (isset($_GET['status_filter']) && $_GET['status_filter'] == 'juvenile') ? 'selected' : '' ?>>Juveniles</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label for="page_size" class="form-label font-weight-bold">Per Page</label>
                        <select class="form-control" id="page_size" name="page_size">
                            <?php foreach($allowed_sizes as $size): ?>
                                <option value="<?= $size ?>" <?= $page_size == $size ? 'selected' : '' ?>>
                                    <?= $size == 'all' ? 'All' : $size ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 mb-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-block">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
            </div>
        </div>

        <!-- Members Table -->
        <div class="table-responsive member-table">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th class="sortable-header" data-sort="crn">
                            CRN <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="last_name">
                            Name <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="phone">
                            Phone <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="church_name">
                            Church <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="class_name">
                            Bible Class <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="day_born">
                            Day Born <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <th class="sortable-header" data-sort="status">
                            Status <i class="fas fa-sort sort-icon"></i>
                        </th>
                        <?php if ($can_edit || $can_delete): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($member = $members->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <?php if (!empty($member['photo']) && file_exists("../uploads/members/" . $member['photo'])): ?>
                                    <img src="<?= BASE_URL ?>/uploads/members/<?= htmlspecialchars($member['photo']) ?>" 
                                         alt="Member Photo" class="member-photo">
                                <?php else: ?>
                                    <div class="member-photo d-flex align-items-center justify-content-center" 
                                         style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; font-weight: bold;">
                                        <?= strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="crn-cell">
                                <?= htmlspecialchars($member['crn']) ?>
                                <br><span class="total-payments text-success font-weight-bold" data-member-id="<?= $member['id'] ?>">
                                    <i class="fas fa-spinner fa-spin"></i> Loading...
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?></strong>
                            </td>
                            <td><?= htmlspecialchars($member['phone']) ?></td>
                            <td><?= htmlspecialchars($member['church_name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($member['class_name'] ?? 'N/A') ?></td>
                            <td>
                                <?= htmlspecialchars($member['day_born'] ?? 'N/A') ?>
                                <?php if (!empty($member['gender'])): ?>
                                    <br><span class="gender-badge gender-<?= strtolower($member['gender']) ?>">
                                        <?= htmlspecialchars($member['gender']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // Determine membership status based on member type and confirmed/baptized status
                                if ($member['member_type'] === 'sunday_school') {
                                    // Sunday school members always show as Juvenile
                                    $status = 'Juvenile';
                                    $status_class = 'info';
                                    $show_info = false;
                                } else {
                                    // Regular members - determine status based on confirmed and baptized
                                    $is_confirmed = (strtolower($member['confirmed']) === 'yes');
                                    $is_baptized = (strtolower($member['baptized']) === 'yes');
                                    
                                    if ($is_confirmed && $is_baptized) {
                                        $status = 'Full Member';
                                        $status_class = 'success';
                                        $show_info = false;
                                    } elseif ($is_confirmed || $is_baptized) {
                                        $status = 'Catechumen';
                                        $status_class = 'warning';
                                        $show_info = true;
                                    } else {
                                        $status = 'No Status';
                                        $status_class = 'secondary';
                                        $show_info = true;
                                    }
                                }
                                
                                // Prepare missing requirements for modal
                                $missing_requirements = [];
                                if ($member['member_type'] !== 'sunday_school') {
                                    // Only check requirements for regular members (not Sunday school)
                                    if (!$is_confirmed) $missing_requirements[] = 'Not Confirmed';
                                    if (!$is_baptized) $missing_requirements[] = 'Not Baptized';
                                }
                                ?>
                                <div class="d-flex align-items-center">
                                    <span class="badge badge-<?php echo $status_class; ?>">
                                        <?php echo $status; ?>
                                    </span>
                                    <?php if ($show_info): ?>
                                        <button type="button" class="btn btn-link btn-sm p-0 ml-1" 
                                                data-toggle="modal" 
                                                data-target="#statusInfoModal"
                                                data-member-name="<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>"
                                                data-missing="<?php echo htmlspecialchars(implode(', ', $missing_requirements)); ?>"
                                                title="View missing requirements">
                                            <i class="fas fa-info-circle text-info" style="font-size: 12px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php if ($can_edit || $can_delete): ?>
                                <td>
                                    <?php if ($member['member_type'] === 'sunday_school'): ?>
                                        <!-- Sunday school member actions -->
                                        <?php if ($can_view): ?>
                                            <a href="sundayschool_view.php?id=<?= $member['id'] ?>" class="action-btn btn-view" title="View Sunday School Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit): ?>
                                            <a href="sundayschool_form.php?id=<?= $member['id'] ?>" class="action-btn btn-edit" title="Edit Sunday School Member">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Regular member actions -->
                                        <?php if ($can_view): ?>
                                            <a href="member_view.php?id=<?= $member['id'] ?>" class="action-btn btn-view" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($can_edit): ?>
                                            <a href="admin_member_edit.php?id=<?= $member['id'] ?>" class="action-btn btn-edit" title="Edit Member">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <button class="action-btn btn-adherent" 
                                                    data-toggle="modal" 
                                                    data-target="#markAdherentModal"
                                                    data-member-id="<?= $member['id'] ?>" 
                                                    data-member-name="<?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>"
                                                    title="Mark as Adherent">
                                                <i class="fas fa-user-tag"></i>
                                            </button>
                                            
                                            <?php
                                            // Check if member has adherent history
                                            $history_check = $conn->prepare("SELECT COUNT(*) as count FROM adherents WHERE member_id = ?");
                                            $history_check->bind_param("i", $member['id']);
                                            $history_check->execute();
                                            $has_history = $history_check->get_result()->fetch_assoc()['count'] > 0;
                                            ?>
                                            
                                            <?php if ($has_history): ?>
                                                <button class="action-btn btn-history" 
                                                        data-toggle="modal" 
                                                        data-target="#adherentHistoryModal"
                                                        data-member-id="<?= $member['id'] ?>" 
                                                        data-member-name="<?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>"
                                                        title="View Adherent History">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <a href="<?= BASE_URL ?>/views/member_deactivate.php?id=<?= $member['id'] ?>" 
                                               class="action-btn btn-deactivate" 
                                               onclick="return confirm('Are you sure you want to de-activate this member?')" 
                                               title="De-activate Member">
                                                <i class="fas fa-user-times"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination controls -->
        <nav aria-label="Member list pagination">
            <ul class="pagination justify-content-center">
                <?php
                // Build query string for pagination links
                $query_params = $_GET;
                unset($query_params['page']); // Remove page parameter to avoid duplication
                $query_string = http_build_query($query_params);
                $query_prefix = !empty($query_string) ? '?' . $query_string . '&page=' : '?page=';
                ?>
                
                <?php if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?>1">First</a></li>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $page-1 ?>">&laquo; Prev</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">First</span></li>
                    <li class="page-item disabled"><span class="page-link">&laquo; Prev</span></li>
                <?php endif; ?>
                <?php
                // Show up to 5 pages around current
                $start = max(1, $page-2);
                $end = min($total_pages, $page+2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <li class="page-item<?= $i == $page ? ' active' : '' ?>">
                        <a class="page-link" href="<?= $query_prefix ?><?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $page+1 ?>">Next &raquo;</a></li>
                    <li class="page-item"><a class="page-link" href="<?= $query_prefix ?><?= $total_pages ?>">Last</a></li>
                <?php else: ?>
                    <li class="page-item disabled"><span class="page-link">Next &raquo;</span></li>
                    <li class="page-item disabled"><span class="page-link">Last</span></li>
                <?php endif; ?>
            </ul>
            <div class="text-center text-muted small mb-3">Page <?= $page ?> of <?= $total_pages ?> (<?= $total_members ?> members)</div>
        </nav>
    </div>
</div>

<?php
// Include status modals using output buffering (following visitor_list pattern)
ob_start();
include 'status_modals.php';
$status_modal_html = ob_get_clean();

// Include status scripts
ob_start();
include 'status_scripts.php';
$status_script_html = ob_get_clean();

// Include adherent modals using output buffering (following visitor_list pattern)
ob_start();
include 'adherent_modals.php';
$modal_html = ob_get_clean();

// Include adherent scripts
ob_start();
include 'adherent_scripts.php';
$script_html = ob_get_clean();

$page_content = ob_get_clean();
include '../includes/layout.php';
?>

<?= $status_modal_html ?>
<?= $status_script_html ?>
<?= $modal_html ?>
<?= $script_html ?>

<script>
$(document).ready(function() {
    // Enhanced sortable header functionality
    $('.sortable-header').click(function() {
        const field = $(this).data('sort');
        const currentSort = new URLSearchParams(window.location.search).get('sort');
        const currentOrder = new URLSearchParams(window.location.search).get('order');
        
        let newOrder = 'asc';
        if (currentSort === field && currentOrder === 'asc') {
            newOrder = 'desc';
        }
        
        // Update URL parameters
        const url = new URL(window.location);
        url.searchParams.set('sort', field);
        url.searchParams.set('order', newOrder);
        
        // Add loading state
        $(this).addClass('filter-loading');
        
        // Navigate to new URL
        window.location.href = url.toString();
    });
    
    // Update active sort header based on current URL
    const urlParams = new URLSearchParams(window.location.search);
    const currentSort = urlParams.get('sort');
    const currentOrder = urlParams.get('order');
    
    if (currentSort) {
        $(`.sortable-header[data-sort="${currentSort}"]`).addClass('active');
        const icon = $(`.sortable-header[data-sort="${currentSort}"] .sort-icon`);
        if (currentOrder === 'desc') {
            icon.removeClass('fa-sort-up').addClass('fa-sort-down');
        } else {
            icon.removeClass('fa-sort-down').addClass('fa-sort-up');
        }
    }
    
    // Enhanced filter form submission with loading state
    $('#filterForm').submit(function() {
        $('.filter-card').addClass('filter-loading');
        return true;
    });
    
    // Auto-submit form when status filter changes
    $('#status_filter').change(function() {
        $('#filterForm').submit();
    });
    
    // Enhanced table row hover effects
    $('.table tbody tr').hover(
        function() {
            $(this).find('.btn').addClass('shadow-sm');
        },
        function() {
            $(this).find('.btn').removeClass('shadow-sm');
        }
    );
    
    // Smooth scroll to table after filter
    if (window.location.search.includes('search=') || window.location.search.includes('status_filter=')) {
        $('html, body').animate({
            scrollTop: $('.table-responsive').offset().top - 100
        }, 500);
    }
    
    // Enhanced export functionality with loading states
    $('#export-pdf, #print-table').click(function() {
        const button = $(this);
        const originalText = button.html();
        button.html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        button.prop('disabled', true);
        
        setTimeout(function() {
            button.html(originalText);
            button.prop('disabled', false);
        }, 2000);
    });
});
</script>