<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

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
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_payment');
$can_edit = $is_super_admin || has_permission('edit_payment');
$can_delete = $is_super_admin || has_permission('delete_payment');
$can_view = true; // Already validated above

// Get filter values
$filter_class = $_GET['class_id'] ?? '';
$filter_org = $_GET['organization_id'] ?? '';
$filter_gender = $_GET['gender'] ?? '';
$filter_church = $_GET['church_id'] ?? '';
$filter_payment_type = $_GET['payment_type_id'] ?? '';
$filter_mode = $_GET['mode'] ?? '';
$search_term = trim($_GET['search'] ?? '');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Fetch filter options
$churches = $conn->query("SELECT id, name FROM churches ORDER BY name ASC");
$payment_types = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");
$genders = $conn->query("SELECT DISTINCT gender FROM members WHERE gender IS NOT NULL AND gender != '' ORDER BY gender");

// Fetch classes and organizations based on selected church
$bible_classes = null;
$organizations = null;
if ($filter_church) {
    $bible_classes = $conn->prepare("SELECT id, name FROM bible_classes WHERE church_id = ? ORDER BY name ASC");
    $bible_classes->bind_param('i', $filter_church);
    $bible_classes->execute();
    $bible_classes = $bible_classes->get_result();
    
    $organizations = $conn->prepare("SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name ASC");
    $organizations->bind_param('i', $filter_church);
    $organizations->execute();
    $organizations = $organizations->get_result();
}

// Build SQL with enhanced filters
$sql = "SELECT p.*, 
    m.crn, m.first_name, m.last_name, m.middle_name, m.gender, 
    ss.srn, ss.first_name AS ss_first_name, ss.last_name AS ss_last_name, ss.middle_name AS ss_middle_name, 
    pt.name AS payment_type,
    c.name AS church_name,
    bc.name AS class_name,
    org.name AS organization_name,
    u.name AS recorded_by_username
FROM payments p
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN churches c ON m.church_id = c.id
    LEFT JOIN bible_classes bc ON m.class_id = bc.id
    LEFT JOIN member_organizations mo ON mo.member_id = m.id
    LEFT JOIN organizations org ON mo.organization_id = org.id
    LEFT JOIN users u ON p.recorded_by = u.id
WHERE 1";
$params = [];
$types = '';

// Apply filters
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
    $sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ? OR ss.first_name LIKE ? OR ss.last_name LIKE ? OR ss.srn LIKE ? OR p.description LIKE ?)";
    $search_like = "%$search_term%";
    for ($i = 0; $i < 8; $i++) {
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

// Get total count for pagination (before adding LIMIT)
// Create a proper count query that wraps the original query as a subquery
$count_sql = "SELECT COUNT(*) as total FROM (
    SELECT p.id
    FROM payments p
        LEFT JOIN members m ON p.member_id = m.id
        LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
        LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
        LEFT JOIN churches c ON m.church_id = c.id
        LEFT JOIN bible_classes bc ON m.class_id = bc.id
        LEFT JOIN member_organizations mo ON mo.member_id = m.id
        LEFT JOIN organizations org ON mo.organization_id = org.id
        LEFT JOIN users u ON p.recorded_by = u.id
        WHERE 1";

// Apply the same filters to count query
if ($filter_church) {
    $count_sql .= " AND m.church_id = ?";
}
if ($filter_class) {
    $count_sql .= " AND m.class_id = ?";
}
if ($filter_org) {
    $count_sql .= " AND mo.organization_id = ?";
}
if ($filter_gender) {
    $count_sql .= " AND m.gender = ?";
}
if ($filter_payment_type) {
    $count_sql .= " AND p.payment_type_id = ?";
}
if ($filter_mode) {
    $count_sql .= " AND p.mode = ?";
}
if ($search_term) {
    $count_sql .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.middle_name LIKE ? OR m.crn LIKE ? OR ss.first_name LIKE ? OR ss.last_name LIKE ? OR ss.srn LIKE ? OR p.description LIKE ?)";
}
if ($date_from) {
    $count_sql .= " AND DATE(p.payment_date) >= ?";
}
if ($date_to) {
    $count_sql .= " AND DATE(p.payment_date) <= ?";
}

$count_sql .= " GROUP BY p.id
) as count_subquery";

if ($types) {
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
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
$current_page = min($current_page, max(1, $total_pages)); // Ensure current page is within bounds

// Add ORDER BY and LIMIT to main query
$sql .= " GROUP BY p.id ORDER BY p.payment_date DESC, p.id DESC LIMIT $records_per_page OFFSET $offset";

// Execute the main query
if ($types) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $payments = $stmt->get_result();
} else {
    $payments = $conn->query($sql);
}

// Calculate totals
$total_amount = 0;
$payment_count = 0;
$payments_array = [];
while ($row = $payments->fetch_assoc()) {
    $payments_array[] = $row;
    $total_amount += $row['amount'];
    $payment_count++;
}

ob_start();
?>
<!-- Page Header -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-money-bill-wave text-primary mr-2"></i>Payment Management</h1>
        <p class="mb-0 text-muted">Track and manage church payments and contributions</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($can_add): ?>
            <a href="payment_form.php" class="btn btn-primary btn-sm shadow-sm">
                <i class="fas fa-plus mr-1"></i> Add Payment
            </a>
        <?php endif; ?>
        <button class="btn btn-success btn-sm shadow-sm" onclick="exportToExcel()">
            <i class="fas fa-file-excel mr-1"></i> Export
        </button>
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

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Payments</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($payment_count) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-receipt fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Amount</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= number_format($total_amount, 2) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Average Payment</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800">₵<?= $payment_count > 0 ? number_format($total_amount / $payment_count, 2) : '0.00' ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Active Filters</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800" id="active-filters-count">0</div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-filter fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Filter Panel -->
<div class="card shadow mb-4 border-0">
    <div class="card-header bg-gradient-primary py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-white">
                <i class="fas fa-filter mr-2"></i>Advanced Filters
            </h6>
            <button class="btn btn-sm btn-outline-light" type="button" data-toggle="collapse" data-target="#filterCollapse">
                <i class="fas fa-chevron-down"></i>
            </button>
        </div>
    </div>
    <div class="collapse show" id="filterCollapse">
        <div class="card-body bg-light">
            <form method="get" id="filterForm">
                <!-- Search Row -->
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label for="search" class="font-weight-bold text-dark"><i class="fas fa-search mr-1"></i>Search</label>
                        <input type="text" class="form-control" name="search" id="search" 
                               placeholder="Search by name, CRN, SRN, or description..." 
                               value="<?= htmlspecialchars($search_term) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="font-weight-bold text-dark">&nbsp;</label>
                        <div class="d-flex">
                            <button type="submit" class="btn btn-primary mr-2 flex-fill">
                                <i class="fas fa-search mr-1"></i> Search
                            </button>
                            <a href="payment_list.php" class="btn btn-outline-secondary flex-fill">
                                <i class="fas fa-times mr-1"></i> Clear
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Row 1 -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label for="church_id" class="font-weight-bold text-dark"><i class="fas fa-church mr-1"></i>Church</label>
                        <select class="form-control custom-select" name="church_id" id="church_id">
                            <option value="">All Churches</option>
                            <?php if ($churches && $churches->num_rows > 0): while($ch = $churches->fetch_assoc()): ?>
                                <option value="<?=$ch['id']?>" <?=($filter_church==$ch['id']?'selected':'')?>>
                                    <?=htmlspecialchars($ch['name'])?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="class_id" class="font-weight-bold text-dark"><i class="fas fa-users mr-1"></i>Bible Class</label>
                        <select class="form-control custom-select" name="class_id" id="class_id" <?= !$filter_church ? 'disabled' : '' ?>>
                            <option value="">All Classes</option>
                            <?php if ($filter_church && $bible_classes && $bible_classes->num_rows > 0): while($cl = $bible_classes->fetch_assoc()): ?>
                                <option value="<?=$cl['id']?>" <?=($filter_class==$cl['id']?'selected':'')?>>
                                    <?=htmlspecialchars($cl['name'])?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="organization_id" class="font-weight-bold text-dark"><i class="fas fa-sitemap mr-1"></i>Organization</label>
                        <select class="form-control custom-select" name="organization_id" id="organization_id" <?= !$filter_church ? 'disabled' : '' ?>>
                            <option value="">All Organizations</option>
                            <?php if ($filter_church && $organizations && $organizations->num_rows > 0): while($org = $organizations->fetch_assoc()): ?>
                                <option value="<?=$org['id']?>" <?=($filter_org==$org['id']?'selected':'')?>>
                                    <?=htmlspecialchars($org['name'])?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="gender" class="font-weight-bold text-dark"><i class="fas fa-venus-mars mr-1"></i>Gender</label>
                        <select class="form-control custom-select" name="gender" id="gender">
                            <option value="">All Genders</option>
                            <?php if ($genders && $genders->num_rows > 0): while($g = $genders->fetch_assoc()): ?>
                                <option value="<?=htmlspecialchars($g['gender'])?>" <?=($filter_gender==$g['gender']?'selected':'')?>>
                                    <?=htmlspecialchars($g['gender'])?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Filter Row 2 -->
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label for="payment_type_id" class="font-weight-bold text-dark"><i class="fas fa-tags mr-1"></i>Payment Type</label>
                        <select class="form-control custom-select" name="payment_type_id" id="payment_type_id">
                            <option value="">All Types</option>
                            <?php if ($payment_types && $payment_types->num_rows > 0): while($pt = $payment_types->fetch_assoc()): ?>
                                <option value="<?=$pt['id']?>" <?=($filter_payment_type==$pt['id']?'selected':'')?>>
                                    <?=htmlspecialchars($pt['name'])?>
                                </option>
                            <?php endwhile; endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="mode" class="font-weight-bold text-dark"><i class="fas fa-credit-card mr-1"></i>Payment Mode</label>
                        <select class="form-control custom-select" name="mode" id="mode">
                            <option value="">All Modes</option>
                            <option value="cash" <?=($filter_mode=='cash'?'selected':'')?>><i class="fas fa-money-bill"></i> Cash</option>
                            <option value="mobile_money" <?=($filter_mode=='mobile_money'?'selected':'')?>><i class="fas fa-mobile-alt"></i> Mobile Money</option>
                            <option value="bank_transfer" <?=($filter_mode=='bank_transfer'?'selected':'')?>><i class="fas fa-university"></i> Bank Transfer</option>
                            <option value="cheque" <?=($filter_mode=='cheque'?'selected':'')?>><i class="fas fa-file-invoice"></i> Cheque</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="date_from" class="font-weight-bold text-dark"><i class="fas fa-calendar-alt mr-1"></i>From Date</label>
                        <input type="date" class="form-control" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="date_to" class="font-weight-bold text-dark"><i class="fas fa-calendar-alt mr-1"></i>To Date</label>
                        <input type="date" class="form-control" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>">
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Enhanced Payment Table -->
<div class="card shadow mb-4 border-0">
    <div class="card-header bg-white py-3 border-bottom">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-table mr-2"></i>Payment Records
                <span class="badge badge-primary ml-2"><?= number_format($payment_count) ?> records</span>
            </h6>
            <div class="d-flex align-items-center">
                <label class="mr-2 mb-0 text-muted">Show:</label>
                <select class="form-control form-control-sm" id="recordsPerPage" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="-1">All</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($payments_array)): ?>
            <div class="text-center py-5">
                <i class="fas fa-search fa-3x text-gray-300 mb-3"></i>
                <h5 class="text-gray-600">No payments found</h5>
                <p class="text-muted">Try adjusting your search criteria or filters.</p>
                <a href="payment_list.php" class="btn btn-primary">
                    <i class="fas fa-refresh mr-1"></i> Clear Filters
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="paymentTable">
                    <thead class="bg-light">
                        <tr>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="crn">
                                <i class="fas fa-id-card mr-1"></i>ID/CRN
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="name">
                                <i class="fas fa-user mr-1"></i>Member Name
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="type">
                                <i class="fas fa-tags mr-1"></i>Type
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="amount">
                                <i class="fas fa-money-bill mr-1"></i>Amount
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="mode">
                                <i class="fas fa-credit-card mr-1"></i>Mode
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary">
                                <i class="fas fa-comment mr-1"></i>Description
                            </th>
                            <th class="border-0 font-weight-bold text-primary sortable" data-sort="date">
                                <i class="fas fa-calendar mr-1"></i>Date
                                <i class="fas fa-sort text-muted ml-1"></i>
                            </th>
                            <th class="border-0 font-weight-bold text-primary">
    <i class="fas fa-user-edit mr-1"></i>Recorded By
</th>
<th class="border-0 font-weight-bold text-primary text-center">
    <i class="fas fa-cogs mr-1"></i>Actions
</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments_array as $row): ?>
                            <tr class="payment-row">
                                <td class="align-middle">
                                    <?php if (!empty($row['member_id'])): ?>
                                        <span class="badge badge-info"><?=htmlspecialchars($row['crn'])?></span>
                                    <?php elseif (!empty($row['sundayschool_id'])): ?>
                                        <span class="badge badge-warning">
                                            <?= !empty($row['srn']) ? htmlspecialchars($row['srn']) : 'SRN: '.$row['sundayschool_id'].' (no match)' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle">
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center mr-2">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <?php if (!empty($row['member_id'])): ?>
                                                <div class="font-weight-bold"><?= htmlspecialchars(trim(($row['last_name'] ?? '').' '.($row['first_name'] ?? '').' '.($row['middle_name'] ?? ''))) ?></div>
                                                <?php if (!empty($row['church_name'])): ?>
                                                    <small class="text-muted"><i class="fas fa-church mr-1"></i><?= htmlspecialchars($row['church_name']) ?></small>
                                                <?php endif; ?>
                                            <?php elseif (!empty($row['sundayschool_id'])): ?>
                                                <?php if (!empty($row['ss_last_name']) || !empty($row['ss_first_name'])): ?>
                                                    <div class="font-weight-bold"><?= htmlspecialchars(trim(($row['ss_last_name'] ?? '').' '.($row['ss_first_name'] ?? '').' '.($row['ss_middle_name'] ?? ''))) ?></div>
                                                    <small class="text-muted"><i class="fas fa-graduation-cap mr-1"></i>Sunday School</small>
                                                <?php else: ?>
                                                    <div class="font-weight-bold text-danger">Unknown Student (ID: <?= htmlspecialchars($row['sundayschool_id']) ?>)</div>
                                                    <small class="text-muted">No match in Sunday School table</small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <span class="badge badge-outline-primary"><?=htmlspecialchars($row['payment_type'] ?? 'N/A')?></span>
                                </td>
                                <td class="align-middle">
                                    <span class="font-weight-bold text-success">₵<?=number_format($row['amount'],2)?></span>
                                </td>
                                <td class="align-middle">
                                    <?php 
                                    $mode_icons = [
                                        'cash' => 'fas fa-money-bill text-success',
                                        'cheque' => 'fas fa-file-invoice text-warning',
                                        'bank_transfer' => 'fas fa-university text-primary',
                                        'mobile_money' => 'fas fa-mobile-alt text-info',
                                        'transfer' => 'fas fa-exchange-alt text-secondary',
                                        'pos' => 'fas fa-credit-card text-dark',
                                        'online' => 'fas fa-globe text-primary',
                                        'offline' => 'fas fa-unlink text-muted',
                                        'other' => 'fas fa-ellipsis-h text-muted',
                                    ];
                                    // Normalize mode value for display and icon
                                    $raw_mode = $row['mode'] ?? '';
                                    $mode = strtolower(trim(str_replace([' ', '-'], ['_', '_'], $raw_mode)));
                                    $icon = $mode_icons[$mode] ?? 'fas fa-question-circle text-muted';
                                    ?>
                                    <span class="d-flex align-items-center">
                                        <i class="<?= $icon ?> mr-1"></i>
                                        <?php
    if (!empty($raw_mode)) {
        // Clean up for display
        $display_mode = ucwords(str_replace(['_', '-'], ' ', strtolower($raw_mode)));
        echo htmlspecialchars($display_mode);
    } else {
        echo '<span class="text-muted">Unknown</span>';
    }
?>
                                    </span>
                                </td>
                                <td class="align-middle">
                                    <span class="text-truncate d-inline-block" style="max-width: 150px;" title="<?=htmlspecialchars($row['description'] ?? '')?>">
                                        <?=htmlspecialchars($row['description'] ?? 'No description')?>
                                    </span>
                                </td>
                                <td class="align-middle">
                                    <div>
                                        <span class="font-weight-bold"><?=date('M d, Y', strtotime($row['payment_date']))?></span><br>
                                        <small class="text-muted"><?=date('h:i A', strtotime($row['payment_date']))?></small>
                                    </div>
                                </td>
                                <td class="align-middle">
                                    <?php if (!empty($row['recorded_by_username'])): ?>
                                        <span class="badge badge-secondary"><i class="fas fa-user-edit mr-1"></i><?= htmlspecialchars($row['recorded_by_username']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="align-middle text-center">
                                    <div class="btn-group" role="group">
                                        <?php if ($can_edit): ?>
                                            <a href="payment_edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit Payment">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($can_delete): ?>
                                            <a href="payment_delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Are you sure you want to delete this payment?');" title="Delete Payment">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Reversal Status -->
                                    <?php
                                        $is_pending = !empty($row['reversal_requested_at']) && empty($row['reversal_approved_at']);
                                        $is_reversed = !empty($row['reversal_approved_at']) && empty($row['reversal_undone_at']);
                                        $can_approve = isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1;
                                        $can_undo = $can_approve;
                                    ?>
                                    <div class="mt-1">
                                        <?php if ($is_pending): ?>
                                            <span class="badge badge-warning">Reversal Pending</span>
                                            <?php if ($can_approve): ?>
                                                <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=approve" class="btn btn-xs btn-success ml-1" 
                                                   onclick="return confirm('Approve this payment reversal?');" title="Approve Reversal">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif ($is_reversed): ?>
                                            <span class="badge badge-danger">Reversed</span>
                                            <?php if ($can_undo): ?>
                                                <a href="payment_reverse.php?id=<?= $row['id'] ?>&action=undo" class="btn btn-xs btn-info ml-1" 
                                                   onclick="return confirm('Undo this payment reversal?');" title="Undo Reversal">
                                                    <i class="fas fa-undo"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php elseif (empty($row['reversal_requested_at'])): ?>
                                            <a href="payment_reverse.php?id=<?= $row['id'] ?>" class="btn btn-xs btn-secondary" 
                                               onclick="return confirm('Request reversal for this payment?');" title="Request Reversal">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="d-flex justify-content-between align-items-center mt-4">
    <div class="pagination-info">
        <small class="text-muted">
            Showing <?= (($current_page - 1) * $records_per_page) + 1 ?> to 
            <?= min($current_page * $records_per_page, $total_records) ?> of 
            <?= number_format($total_records) ?> entries
        </small>
    </div>
    <nav aria-label="Payment pagination">
        <ul class="pagination pagination-sm mb-0">
            <?php if ($current_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" aria-label="First">
                        <span aria-hidden="true">&laquo;&laquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            
            if ($start_page > 1): ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a></li>
                <?php if ($start_page > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            
            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a></li>
            <?php endif; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" aria-label="Last">
                        <span aria-hidden="true">&raquo;&raquo;</span>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>

<!-- Enhanced JavaScript -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Count active filters
    function updateActiveFiltersCount() {
        let count = 0;
        const filters = ['church_id', 'class_id', 'organization_id', 'gender', 'payment_type_id', 'mode', 'search', 'date_from', 'date_to'];
        
        filters.forEach(function(filter) {
            if ($('#' + filter).val() && $('#' + filter).val() !== '') {
                count++;
            }
        });
        
        $('#active-filters-count').text(count);
    }
    
    // Update count on page load
    updateActiveFiltersCount();
    
    // Church filter functionality
    $('#church_id').on('change', function() {
        const churchId = $(this).val();
        
        // Clear dependent filters
        $('#class_id').val('').prop('disabled', !churchId);
        $('#organization_id').val('').prop('disabled', !churchId);
        
        if (churchId) {
            // Show loading state
            $('#class_id').html('<option value="">Loading...</option>');
            $('#organization_id').html('<option value="">Loading...</option>');
            
            // Load Bible Classes
            $.get('ajax_get_classes_by_church.php', {church_id: churchId})
                .done(function(data) {
                    $('#class_id').html('<option value="">All Classes</option>' + data);
                })
                .fail(function() {
                    $('#class_id').html('<option value="">All Classes</option>');
                });
            
            // Load Organizations
            $.get('ajax_get_organizations_by_church.php', {church_id: churchId})
                .done(function(data) {
                    $('#organization_id').html('<option value="">All Organizations</option>' + data);
                })
                .fail(function() {
                    $('#organization_id').html('<option value="">All Organizations</option>');
                });
        } else {
            $('#class_id').html('<option value="">All Classes</option>');
            $('#organization_id').html('<option value="">All Organizations</option>');
        }
        
        updateActiveFiltersCount();
    });
    
    // Update filter count when any filter changes
    $('#filterForm input, #filterForm select').on('change keyup', function() {
        updateActiveFiltersCount();
    });
    
    // Table sorting functionality
    $('.sortable').on('click', function() {
        const column = $(this).data('sort');
        const table = $('#paymentTable tbody');
        const rows = table.find('tr').toArray();
        const isAsc = $(this).hasClass('sort-asc');
        
        // Remove all sort classes
        $('.sortable').removeClass('sort-asc sort-desc');
        $('.sortable i.fa-sort').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
        
        // Add sort class to current column
        if (isAsc) {
            $(this).addClass('sort-desc');
            $(this).find('i.fa-sort').removeClass('fa-sort').addClass('fa-sort-down');
        } else {
            $(this).addClass('sort-asc');
            $(this).find('i.fa-sort').removeClass('fa-sort').addClass('fa-sort-up');
        }
        
        // Sort rows
        rows.sort(function(a, b) {
            let aVal, bVal;
            
            switch(column) {
                case 'amount':
                    aVal = parseFloat($(a).find('td:nth-child(4)').text().replace(/[₵,]/g, ''));
                    bVal = parseFloat($(b).find('td:nth-child(4)').text().replace(/[₵,]/g, ''));
                    break;
                case 'date':
                    aVal = new Date($(a).find('td:nth-child(7)').text());
                    bVal = new Date($(b).find('td:nth-child(7)').text());
                    break;
                default:
                    aVal = $(a).find('td:nth-child(' + ($(this).index() + 1) + ')').text().toLowerCase();
                    bVal = $(b).find('td:nth-child(' + ($(this).index() + 1) + ')').text().toLowerCase();
            }
            
            if (aVal < bVal) return isAsc ? 1 : -1;
            if (aVal > bVal) return isAsc ? -1 : 1;
            return 0;
        });
        
        // Reorder table
        $.each(rows, function(index, row) {
            table.append(row);
        });
    });
    
    // Export to Excel functionality
    window.exportToExcel = function() {
        const table = document.getElementById('paymentTable');
        const wb = XLSX.utils.table_to_book(table, {sheet: 'Payments'});
        const filename = 'payments_' + new Date().toISOString().split('T')[0] + '.xlsx';
        XLSX.writeFile(wb, filename);
    };
    
    // Auto-submit form on filter change (optional)
    $('#filterForm select, #filterForm input[type="date"]').on('change', function() {
        // Uncomment the line below to auto-submit on filter change
        // $('#filterForm').submit();
    });
    
    // Search on Enter key
    $('#search').on('keypress', function(e) {
        if (e.which === 13) {
            $('#filterForm').submit();
        }
    });
    
    // Initialize tooltips
    $('[title]').tooltip();
});
</script>

<!-- Include SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
.avatar-sm {
    width: 32px;
    height: 32px;
    font-size: 12px;
}

.sortable {
    cursor: pointer;
    user-select: none;
}

.sortable:hover {
    background-color: #f8f9fa;
}

.badge-outline-primary {
    color: #007bff;
    border: 1px solid #007bff;
    background-color: transparent;
}

.btn-xs {
    padding: 0.125rem 0.25rem;
    font-size: 0.75rem;
    line-height: 1.5;
    border-radius: 0.15rem;
}

.payment-row:hover {
    background-color: #f8f9fa;
}

.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.text-truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

@media (max-width: 768px) {
    .d-flex.gap-2 {
        flex-direction: column;
    }
    
    .btn-group {
        flex-direction: column;
    }
}
</style>

<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
