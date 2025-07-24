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

if (!$is_super_admin && !has_permission('view_age_bracket_report')) {
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
$can_export = $is_super_admin || has_permission('export_age_bracket_report');

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_auth.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
ob_start();

$conn = $GLOBALS['conn'];
$age_brackets = [
    'all' => 'All',
    '0-12' => '0-12',
    '13-17' => '13-17',
    '18-25' => '18-25',
    '26-35' => '26-35',
    '36-50' => '36-50',
    '51-65' => '51-65',
    '66plus' => '66+',
];
$selected_bracket = isset($_GET['age_bracket']) ? $_GET['age_bracket'] : 'all';

$sql = "SELECT m.crn, m.last_name, m.first_name, bc.name AS class_name, m.gender, m.dob, m.phone, m.marital_status, m.home_town FROM members m LEFT JOIN bible_classes bc ON m.class_id = bc.id WHERE m.status = 'active'";

// Filtering by age bracket
if ($selected_bracket !== 'all') {
    $now = date('Y-m-d');
    switch ($selected_bracket) {
        case '0-12':
            $min = 0; $max = 12; break;
        case '13-17':
            $min = 13; $max = 17; break;
        case '18-25':
            $min = 18; $max = 25; break;
        case '26-35':
            $min = 26; $max = 35; break;
        case '36-50':
            $min = 36; $max = 50; break;
        case '51-65':
            $min = 51; $max = 65; break;
        case '66plus':
            $min = 66; $max = 150; break;
        default:
            $min = 0; $max = 200; break;
    }
    $min_date = date('Y-m-d', strtotime("-$max years", strtotime($now)));
    $max_date = date('Y-m-d', strtotime("-$min years", strtotime($now)));
    $sql .= " AND m.dob BETWEEN '$min_date' AND '$max_date'";
}
$sql .= " ORDER BY m.dob DESC";
$result = $conn->query($sql);
$members = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Calculate age
        $row['age'] = ($row['dob'] && $row['dob'] !== '0000-00-00') ? (date('Y') - date('Y', strtotime($row['dob']))) : '';
        $members[] = $row;
    }
}
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-users mr-2"></i>Age Bracket Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="age_bracket" class="mr-2 font-weight-bold">Filter by Age Bracket:</label>
            <select name="age_bracket" id="age_bracket" class="form-control">
                <?php foreach ($age_brackets as $key => $label): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>"<?php if ($selected_bracket === $key) echo ' selected'; ?>><?php echo htmlspecialchars($label); ?></option>
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
                    <th>Age</th>
                    <th>Contact</th>
                    <th>Dob</th>
                    <th>Marital Status</th>
                    <th>Home Town</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr><td colspan="10" class="text-center">No members found.</td></tr>
                <?php else: ?>
                    <?php foreach ($members as $i => $member): ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td><?php echo htmlspecialchars($member['crn']); ?></td>
                            <td><?php echo htmlspecialchars($member['last_name'] . ', ' . $member['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($member['class_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($member['gender'] ?: 'Not Specified'); ?></td>
                            <td><?php echo htmlspecialchars($member['age']); ?></td>
                            <td><?php echo htmlspecialchars($member['phone']); ?></td>
                            <td><?php echo htmlspecialchars($member['dob']); ?></td>
                            <td><?php echo htmlspecialchars($member['marital_status'] ?: '-'); ?></td>
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
                title: 'Age Bracket Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Age Bracket Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Age Bracket Report'
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
