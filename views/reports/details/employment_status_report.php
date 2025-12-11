<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions_v2.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_employment_status_report')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../../views/errors/403.php')) {
        include __DIR__.'/../../../views/errors/403.php';
    } else if (file_exists(__DIR__.'/../../errors/403.php')) {
        include __DIR__.'/../../errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_view = true; // Already validated above
$can_export = $is_super_admin || has_permission('export_employment_status_report');

//require_once __DIR__.'/../../../includes/admin_auth.php';
require_once __DIR__.'/../../../config/config.php';
ob_start();

$conn = $GLOBALS['conn'];
// Employment status options (from registration forms)
$statuses = ['Formal', 'Informal', 'Self Employed', 'Unemployed', 'Retired', 'Student'];
$selected_status = isset($_GET['employment_status']) ? $_GET['employment_status'] : '';

// Get unemployment statistics
$unemployment_stats = [];
$unemployment_sql = "SELECT 
    COUNT(*) as total_unemployed,
    SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as male_count,
    SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as female_count,
    AVG(YEAR(CURDATE()) - YEAR(dob)) as avg_age,
    MIN(YEAR(CURDATE()) - YEAR(dob)) as min_age,
    MAX(YEAR(CURDATE()) - YEAR(dob)) as max_age
FROM members 
WHERE status = 'active' AND employment_status = 'Unemployed'";
$unemployment_result = $conn->query($unemployment_sql);
if ($unemployment_result) {
    $unemployment_stats = $unemployment_result->fetch_assoc();
}

// Get unemployment by age groups
$age_groups = [];
$age_group_sql = "SELECT 
    CASE 
        WHEN YEAR(CURDATE()) - YEAR(dob) < 18 THEN 'Under 18'
        WHEN YEAR(CURDATE()) - YEAR(dob) BETWEEN 18 AND 25 THEN '18-25'
        WHEN YEAR(CURDATE()) - YEAR(dob) BETWEEN 26 AND 35 THEN '26-35'
        WHEN YEAR(CURDATE()) - YEAR(dob) BETWEEN 36 AND 45 THEN '36-45'
        WHEN YEAR(CURDATE()) - YEAR(dob) BETWEEN 46 AND 55 THEN '46-55'
        WHEN YEAR(CURDATE()) - YEAR(dob) BETWEEN 56 AND 65 THEN '56-65'
        ELSE 'Over 65'
    END as age_group,
    COUNT(*) as count,
    GROUP_CONCAT(CONCAT(first_name, ' ', last_name) SEPARATOR ', ') as members
FROM members 
WHERE status = 'active' AND employment_status = 'Unemployed'
GROUP BY age_group
ORDER BY 
    CASE age_group
        WHEN 'Under 18' THEN 1
        WHEN '18-25' THEN 2
        WHEN '26-35' THEN 3
        WHEN '36-45' THEN 4
        WHEN '46-55' THEN 5
        WHEN '56-65' THEN 6
        ELSE 7
    END";
$age_group_result = $conn->query($age_group_sql);
if ($age_group_result) {
    while ($row = $age_group_result->fetch_assoc()) {
        $age_groups[] = $row;
    }
}

// Get total active members for percentage calculation
$total_members_sql = "SELECT COUNT(*) as total FROM members WHERE status = 'active'";
$total_result = $conn->query($total_members_sql);
$total_members = 0;
if ($total_result) {
    $total_row = $total_result->fetch_assoc();
    $total_members = $total_row['total'];
}

$sql = "SELECT m.crn, m.last_name, m.first_name, m.employment_status, m.gender, m.phone, m.dob, m.home_town FROM members m WHERE m.status = 'active'";
if ($selected_status !== '' && in_array($selected_status, $statuses)) {
    $safe_status = $conn->real_escape_string($selected_status);
    $sql .= " AND m.employment_status = '$safe_status'";
}
$sql .= " ORDER BY m.employment_status, m.last_name, m.first_name";
$result = $conn->query($sql);
$members = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
}
?>
<style>
.unemployment-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2rem;
    border-radius: 10px;
    margin-bottom: 2rem;
    box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
}
.stat-box {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}
.stat-box:hover {
    transform: translateY(-5px);
}
.stat-box h3 {
    color: #667eea;
    font-size: 2.5rem;
    font-weight: bold;
    margin: 0.5rem 0;
}
.stat-box p {
    color: #6c757d;
    margin: 0;
    font-size: 0.9rem;
    text-transform: uppercase;
}
.age-group-bar {
    background: #e9ecef;
    height: 30px;
    border-radius: 5px;
    overflow: hidden;
    position: relative;
}
.age-group-fill {
    background: linear-gradient(90deg, #667eea, #764ba2);
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 0.85rem;
}
</style>

<div class="container-fluid mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-briefcase mr-2"></i>Employment Status Report</h2>
    
    <!-- Unemployment Statistics Section -->
    <?php if ($unemployment_stats && $unemployment_stats['total_unemployed'] > 0): ?>
    <div class="unemployment-card">
        <h3 class="mb-4"><i class="fas fa-chart-bar mr-2"></i>Unemployment Analysis</h3>
        <div class="row">
            <div class="col-md-3 mb-3">
                <div class="stat-box">
                    <p><i class="fas fa-users"></i> Total Unemployed</p>
                    <h3><?= number_format($unemployment_stats['total_unemployed']) ?></h3>
                    <small class="text-muted"><?= $total_members > 0 ? number_format(($unemployment_stats['total_unemployed'] / $total_members) * 100, 1) : 0 ?>% of members</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-box">
                    <p><i class="fas fa-male"></i> Male</p>
                    <h3 class="text-primary"><?= number_format($unemployment_stats['male_count']) ?></h3>
                    <small class="text-muted"><?= $unemployment_stats['total_unemployed'] > 0 ? number_format(($unemployment_stats['male_count'] / $unemployment_stats['total_unemployed']) * 100, 1) : 0 ?>%</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-box">
                    <p><i class="fas fa-female"></i> Female</p>
                    <h3 class="text-danger"><?= number_format($unemployment_stats['female_count']) ?></h3>
                    <small class="text-muted"><?= $unemployment_stats['total_unemployed'] > 0 ? number_format(($unemployment_stats['female_count'] / $unemployment_stats['total_unemployed']) * 100, 1) : 0 ?>%</small>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="stat-box">
                    <p><i class="fas fa-calendar"></i> Average Age</p>
                    <h3 class="text-success"><?= number_format($unemployment_stats['avg_age'], 1) ?></h3>
                    <small class="text-muted">Range: <?= round($unemployment_stats['min_age']) ?>-<?= round($unemployment_stats['max_age']) ?> years</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Unemployment by Age Groups -->
    <?php if (count($age_groups) > 0): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="fas fa-chart-pie mr-2"></i>Unemployment Distribution by Age Group</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Age Group</th>
                            <th>Count</th>
                            <th>Percentage</th>
                            <th>Visual Distribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $max_count = max(array_column($age_groups, 'count'));
                        foreach ($age_groups as $group): 
                            $percentage = $unemployment_stats['total_unemployed'] > 0 ? ($group['count'] / $unemployment_stats['total_unemployed']) * 100 : 0;
                            $bar_width = $max_count > 0 ? ($group['count'] / $max_count) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($group['age_group']) ?></strong></td>
                            <td><span class="badge badge-primary badge-pill"><?= $group['count'] ?></span></td>
                            <td><?= number_format($percentage, 1) ?>%</td>
                            <td>
                                <div class="age-group-bar">
                                    <div class="age-group-fill" style="width: <?= $bar_width ?>%">
                                        <?= $group['count'] ?> member<?= $group['count'] != 1 ? 's' : '' ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <!-- Filter Section -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="form-inline">
                <div class="form-group mr-2">
                    <label for="employment_status" class="mr-2 font-weight-bold"><i class="fas fa-filter mr-1"></i>Filter by Employment Status:</label>
                    <select name="employment_status" id="employment_status" class="form-control">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>"<?php if ($selected_status === $status) echo ' selected'; ?>><?php echo htmlspecialchars($status); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search mr-1"></i>Apply Filter</button>
                <?php if ($selected_status): ?>
                <a href="?" class="btn btn-secondary ml-2"><i class="fas fa-times mr-1"></i>Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="mb-3">
        <?php if ($can_export): ?>
            <button id="export-csv" class="btn btn-success btn-sm mr-2"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button id="export-pdf" class="btn btn-danger btn-sm mr-2"><i class="fas fa-file-pdf"></i> Export PDF</button>
        <?php endif; ?>
        <button id="print-table" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Print</button>
    </div>
    <!-- Members List -->
    <div class="card shadow-sm">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-list mr-2"></i>Members List <?= $selected_status ? '- ' . htmlspecialchars($selected_status) : '' ?> (<?= count($members) ?> members)</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped" id="membersTable">
                    <thead class="thead-dark">
                        <tr>
                            <th>#</th>
                            <th>CRN</th>
                            <th>Full Name</th>
                            <th>Employment Status</th>
                            <th>Gender</th>
                            <th>Age</th>
                            <th>Contact</th>
                            <th>DOB</th>
                            <th>Home Town</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($members)): ?>
                            <tr><td colspan="9" class="text-center text-muted">No members found for the selected criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($members as $i => $member): 
                                $age = $member['dob'] ? (date('Y') - date('Y', strtotime($member['dob']))) : 'N/A';
                                $status_badge = [
                                    'Formal' => 'badge-success',
                                    'Informal' => 'badge-info',
                                    'Self Employed' => 'badge-primary',
                                    'Unemployed' => 'badge-danger',
                                    'Retired' => 'badge-secondary',
                                    'Student' => 'badge-warning'
                                ];
                                $badge_class = $status_badge[$member['employment_status']] ?? 'badge-dark';
                            ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($member['crn']); ?></strong></td>
                                <td><?php echo htmlspecialchars($member['last_name'] . ', ' . $member['first_name']); ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?php echo htmlspecialchars($member['employment_status'] ?: '-'); ?></span></td>
                                <td><?php echo htmlspecialchars($member['gender'] ?: '-'); ?></td>
                                <td><?php echo $age; ?></td>
                                <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                <td><?php echo htmlspecialchars($member['dob']); ?></td>
                                <td><?php echo htmlspecialchars($member['home_town'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<!-- DataTables and JS export dependencies -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.pdf.min.js"></script>
<script>
$(document).ready(function() {
    <?php if (!empty($members)): ?>
    var table = $("#membersTable").DataTable({
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-success btn-sm mr-2',
                title: 'Employment Status Report',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-success btn-sm mr-2',
                title: 'Employment Status Report',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Employment Status Report',
                orientation: 'landscape',
                exportOptions: {
                    columns: ':visible'
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Employment Status Report',
                exportOptions: {
                    columns: ':visible'
                }
            }
        ],
        paging: true,
        pageLength: 25,
        searching: true,
        info: true,
        ordering: true,
        order: [[2, 'asc']], // Sort by name
        language: {
            search: '<i class="fas fa-search"></i>',
            searchPlaceholder: 'Search members...'
        }
    });
    // Hide custom buttons if DataTables is used
    $('#export-csv, #export-pdf, #print-table').hide();
    <?php endif; ?>
});
</script>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
