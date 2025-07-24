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

if (!$is_super_admin && !has_permission('view_organisation_payment_report')) {
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
$can_export = $is_super_admin || has_permission('export_organisation_payment_report');

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_auth.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
ob_start();

$conn = $GLOBALS['conn'];
// Fetch organizations for filter
$orgs = [];
$org_result = $conn->query("SELECT id, name FROM organizations ORDER BY name");
if ($org_result) {
    while ($row = $org_result->fetch_assoc()) {
        $orgs[] = $row;
    }
}
// Fetch payment types for filter
$payment_types = [];
$pt_result = $conn->query("SELECT id, name FROM payment_types ORDER BY name");
if ($pt_result) {
    while ($row = $pt_result->fetch_assoc()) {
        $payment_types[] = $row;
    }
}
$selected_org = isset($_GET['organization_id']) ? intval($_GET['organization_id']) : 0;
$selected_payment_type = isset($_GET['payment_type_id']) ? intval($_GET['payment_type_id']) : 0;
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

$where = ["m.status = 'active'"];
if ($selected_org) {
    $where[] = "org.id = $selected_org";
}
if ($start_date) {
    $where[] = "p.payment_date >= '" . $conn->real_escape_string($start_date) . "'";
}
if ($end_date) {
    $where[] = "p.payment_date <= '" . $conn->real_escape_string($end_date) . "'";
}
if ($selected_payment_type) {
    $where[] = "pt.id = $selected_payment_type";
} 
$where_sql = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total amount
$total_sql = "SELECT SUM(p.amount) AS total_amount FROM members m
LEFT JOIN member_organizations mo ON m.id = mo.member_id
LEFT JOIN organizations org ON mo.organization_id = org.id
INNER JOIN payments p ON m.id = p.member_id
LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
LEFT JOIN bible_classes bc ON m.class_id = bc.id
$where_sql";
$total_result = $conn->query($total_sql);
$total_amount = 0;
if ($total_result && ($row = $total_result->fetch_assoc())) {
    $total_amount = $row['total_amount'] ?: 0;
}

// Get paginated results
$sql = "SELECT m.crn, m.last_name, m.first_name, bc.name AS class_name, m.gender, m.phone, m.dob, m.marital_status, m.home_town, p.amount, pt.name AS payment_type, org.name AS organization_name
FROM members m
LEFT JOIN member_organizations mo ON m.id = mo.member_id
LEFT JOIN organizations org ON mo.organization_id = org.id
INNER JOIN payments p ON m.id = p.member_id
LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
LEFT JOIN bible_classes bc ON m.class_id = bc.id
$where_sql
ORDER BY org.name, m.last_name, m.first_name, p.payment_date DESC
LIMIT $per_page OFFSET $offset";
$result = $conn->query($sql);
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
// Get total count for pagination
$count_sql = "SELECT COUNT(*) AS total_count FROM members m
LEFT JOIN member_organizations mo ON m.id = mo.member_id
LEFT JOIN organizations org ON mo.organization_id = org.id
INNER JOIN payments p ON m.id = p.member_id
LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
LEFT JOIN bible_classes bc ON m.class_id = bc.id
$where_sql";
$count_result = $conn->query($count_sql);
$total_count = 0;
if ($count_result && ($row = $count_result->fetch_assoc())) {
    $total_count = $row['total_count'] ?: 0;
}
$total_pages = ceil($total_count / $per_page);
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-building mr-2"></i>Organisation Payment Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="organization_id" class="mr-2 font-weight-bold">Filter by Organization:</label>
            <select name="organization_id" id="organization_id" class="form-control">
                <option value="0">All</option>
                <?php foreach ($orgs as $org): ?>
                    <option value="<?php echo $org['id']; ?>"<?php if ($selected_org === intval($org['id'])) echo ' selected'; ?>><?php echo htmlspecialchars($org['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group mr-2">
            <label for="start_date" class="mr-2 font-weight-bold">From:</label>
            <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="form-group mr-2">
            <label for="end_date" class="mr-2 font-weight-bold">To:</label>
            <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="form-group mr-2">
            <label for="payment_type_id" class="mr-2 font-weight-bold">Payment Type:</label>
            <select name="payment_type_id" id="payment_type_id" class="form-control">
                <option value="0">All</option>
                <?php foreach ($payment_types as $pt): ?>
                    <option value="<?php echo $pt['id']; ?>"<?php if ($selected_payment_type === intval($pt['id'])) echo ' selected'; ?>><?php echo htmlspecialchars($pt['name']); ?></option>
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
                    <th>Class Name</th>
                    <th>Gender</th>
                    <th>Contact</th>
                    <th>Dob</th>
                    <th>Marital Status</th>
                    <th>Home Town</th>
                    <th>Amount</th>
                    <th>Payment Type</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" class="text-center">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($row['crn']); ?></td>
                            <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['class_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['gender'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['phone']); ?></td>
                            <td><?php echo htmlspecialchars($row['dob']); ?></td>
                            <td><?php echo htmlspecialchars($row['marital_status'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['home_town'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['amount'] !== null ? number_format($row['amount'], 2) : '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['payment_type'] ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        <h5 class="font-weight-bold">Total Amount: <span class="text-primary">₵<?php echo number_format($total_amount, 2); ?></span></h5>
    </div>
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Page navigation">
        <ul class="pagination justify-content-center mt-3">
            <?php
                $query_params = $_GET;
                for ($i = 1; $i <= $total_pages; $i++):
                    $query_params['page'] = $i;
                    $url = '?' . http_build_query($query_params);
            ?>
                <li class="page-item<?php if ($i == $page) echo ' active'; ?>">
                    <a class="page-link" href="<?php echo $url; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
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
                title: 'Organisation Payment Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Organisation Payment Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Organisation Payment Report'
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
<?php $page_content = ob_get_clean(); include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php'; ?>
