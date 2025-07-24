<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_membership_status_report')) {
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
$can_export = $is_super_admin || has_permission('export_membership_status_report');

require_once __DIR__.'/../../../includes/admin_auth.php';
require_once __DIR__.'/../../../config/config.php';
ob_start();

$conn = $GLOBALS['conn'];
$status_options = ['Full Member', 'Cathcumen'];
$selected_status = isset($_GET['membership_status']) ? $_GET['membership_status'] : '';

$sql = "SELECT m.crn, m.last_name, m.first_name, m.baptized, m.confirmed, m.gender, m.phone, m.dob, m.home_town FROM members m WHERE m.status = 'active'";
$sql .= " ORDER BY m.last_name, m.first_name";
$result = $conn->query($sql);
$members = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $is_full = (strtolower($row['baptized']) === 'yes' && strtolower($row['confirmed']) === 'yes');
        $row['membership_status'] = $is_full ? 'Full Member' : 'Cathcumen';
        $members[] = $row;
    }
}
if ($selected_status !== '' && in_array($selected_status, $status_options)) {
    $members = array_filter($members, function($m) use ($selected_status) {
        return $m['membership_status'] === $selected_status;
    });
}
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-users mr-2"></i>Membership Status Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="membership_status" class="mr-2 font-weight-bold">Filter by Membership Status:</label>
            <select name="membership_status" id="membership_status" class="form-control">
                <option value="">All</option>
                <?php foreach ($status_options as $opt): ?>
                    <option value="<?php echo $opt; ?>"<?php if ($selected_status === $opt) echo ' selected'; ?>><?php echo $opt; ?></option>
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
        <table class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>CRN</th>
                    <th>Full Name</th>
                    <th>Membership Status</th>
                    <th>Baptized</th>
                    <th>Confirmed</th>
                    <th>Gender</th>
                    <th>Contact</th>
                    <th>Dob</th>
                    <th>Home Town</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="10" class="text-center">No members found.</td></tr>
                <?php else: ?>
                    <?php $i=1; foreach ($members as $member): ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo htmlspecialchars($member['crn']); ?></td>
                            <td><?php echo htmlspecialchars($member['last_name'] . ', ' . $member['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($member['membership_status']); ?></td>
                            <td><?php echo htmlspecialchars($member['baptized'] ?: 'No'); ?></td>
                            <td><?php echo htmlspecialchars($member['confirmed'] ?: 'No'); ?></td>
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
    var table = $(".table").DataTable({
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-success btn-sm mr-2',
                title: 'Membership Status Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Membership Status Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Membership Status Report'
            }
        ],
        paging: false,
        searching: false,
        info: false,
        ordering: false
    });
    // Hide custom buttons if DataTables is used
    $('#export-csv, #export-pdf, #print-table').hide();
});
</script>
<?php $page_content = ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
