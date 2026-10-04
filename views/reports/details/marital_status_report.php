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

if (!$is_super_admin && !has_permission('view_marital_status_report')) {
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
$can_export = $is_super_admin || has_permission('export_marital_status_report');

//require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_auth.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
ob_start();

$conn = $GLOBALS['conn'];
$memberScope = report_scope_member_condition($conn, 'm');
// Fetch marital status options for filter
$statuses = [];
$status_result = $conn->query("SELECT DISTINCT m.marital_status FROM members m WHERE {$memberScope} AND m.marital_status IS NOT NULL AND m.marital_status != '' ORDER BY m.marital_status");
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $statuses[] = $row['marital_status'];
    }
}
$selected_status = isset($_GET['marital_status']) ? $_GET['marital_status'] : '';

$sql = "SELECT m.crn, m.last_name, m.first_name, m.marital_status, m.gender, m.phone, m.dob, m.home_town FROM members m WHERE m.status = 'active' AND {$memberScope}";
if ($selected_status !== '') {
    $safe_status = $conn->real_escape_string($selected_status);
    $sql .= " AND m.marital_status = '$safe_status'";
}
$sql .= " ORDER BY m.marital_status, m.last_name, m.first_name";
$pagination = report_paginate_query($conn, $sql);
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
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-ring mr-2"></i>Marital Status Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="marital_status" class="mr-2 font-weight-bold">Filter by Marital Status:</label>
            <select name="marital_status" id="marital_status" class="form-control">
                <option value="">All</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?php echo htmlspecialchars($status); ?>"<?php if ($selected_status === $status) echo ' selected'; ?>><?php echo htmlspecialchars($status); ?></option>
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
                    <th>Marital Status</th>
                    <th>Gender</th>
                    <th>Contact</th>
                    <th>Dob</th>
                    <th>Home Town</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="8" class="text-center">No members found.</td></tr>
                <?php else: ?>
                    <?php foreach ($members as $i => $member): ?>
                        <tr>
                            <td><?php echo $pagination['offset'] + $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($member['crn']); ?></td>
                            <td><?php echo htmlspecialchars($member['last_name'] . ', ' . $member['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($member['marital_status'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($member['gender'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($member['phone']); ?></td>
                            <td><?php echo htmlspecialchars($member['dob']); ?></td>
                            <td><?php echo htmlspecialchars($member['home_town'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php report_render_server_pagination($pagination['total_rows'], $pagination['page'], $pagination['per_page'], 'Marital status report pages'); ?>
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
                title: 'Marital Status Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Marital Status Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Marital Status Report'
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
<?php 
$page_content = ob_get_clean(); 
include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php'; 
?>
