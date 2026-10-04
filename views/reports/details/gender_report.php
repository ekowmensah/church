<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions_v2.php';
require_once __DIR__.'/../../../helpers/report_scope.php';
require_once __DIR__.'/../../../helpers/report_pagination.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('view_gender_report')) {
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
$can_export = $is_super_admin || has_permission('export_gender_report');

//require_once __DIR__.'/../../../includes/admin_auth.php';
require_once __DIR__.'/../../../config/config.php';
ob_start();

// Gender filter setup
$conn = $GLOBALS['conn'];
$memberScope = report_scope_member_condition($conn, 'm');
$selected_gender = isset($_GET['gender']) ? $_GET['gender'] : '';

// Fetch gender options
$gender_options = [];
$res = $conn->query("SELECT DISTINCT m.gender FROM members m WHERE {$memberScope} ORDER BY m.gender");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $gender_options[] = $row['gender'];
    }
}

// Fetch member data
$sql = "SELECT m.crn, m.last_name, m.first_name, bc.name AS class_name, m.gender, m.phone FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id";
$where = ["m.status = 'active'", $memberScope];
$params = [];
if ($selected_gender !== '' && $selected_gender !== 'all') {
    $where[] = "m.gender = ?";
    $params[] = $selected_gender;
}
$sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY m.gender, m.last_name, m.first_name";
$types = $params ? str_repeat('s', count($params)) : '';
$pagination = report_paginate_query($conn, $sql, $types, $params);
$result = $pagination['result'];
$members = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
}

?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-venus-mars mr-2"></i>Gender Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="gender" class="mr-2 font-weight-bold">Filter by Gender:</label>
            <select name="gender" id="gender" class="form-control">
                <option value="all"<?php if ($selected_gender === 'all' || $selected_gender === '') echo ' selected'; ?>>All</option>
                <?php foreach ($gender_options as $gender): ?>
                    <option value="<?php echo htmlspecialchars($gender); ?>"<?php if ($selected_gender === $gender) echo ' selected'; ?>><?php echo htmlspecialchars($gender ?: 'Not Specified'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Filter</button>
    </form>
    <div class="mb-3">
    <?php if ($can_export): ?>
        <button id="export-csv" class="btn btn-success btn-sm mr-2"><i class="fas fa-file-csv"></i> Export CSV</button>
        <button id="export-pdf" class="btn btn-danger btn-sm mr-2"><i class="fas fa-file-pdf"></i> Export PDF</button>
    <?php endif; ?>
        <button id="print-table" class="btn btn-secondary btn-sm"><i class="fas fa-print"></i> Print</button>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered table-hover" data-report-pagination="server">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>CRN</th>
                    <th>Full Name</th>
                    <th>Class Name</th>
                    <th>Gender</th>
                    <th>Contact</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="6" class="text-center">No members found.</td></tr>
                <?php else: ?>
                    <?php foreach ($members as $i => $member): ?>
                        <tr>
                            <td><?php echo $pagination['offset'] + $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($member['crn']); ?></td>
                            <td><?php echo htmlspecialchars($member['last_name'] . ', ' . $member['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($member['class_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($member['gender'] ?: 'Not Specified'); ?></td>
                            <td><?php echo htmlspecialchars($member['phone']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php report_render_server_pagination($pagination['total_rows'], $pagination['page'], $pagination['per_page'], 'Gender report pages'); ?>
</div>
<!-- DataTables and JS export dependencies -->
<script src="<?= BASE_URL ?>/assets/js/report-export-branding.js"></script>
<script>
$(document).ready(function() {
    var table = $(".table").DataTable({
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-success btn-sm mr-2',
                title: 'Gender Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Gender Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Gender Report'
            }
        ],
        paging: false,
        searching: false,
        info: false,
        ordering: true
    });
    // Hide custom buttons if DataTables is used
    $('#export-csv, #export-pdf, #print-table').hide();
});
</script>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
