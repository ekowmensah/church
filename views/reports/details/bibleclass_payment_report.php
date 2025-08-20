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

if (!$is_super_admin && !has_permission('view_bibleclass_payment_report')) {
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
$can_export = $is_super_admin || has_permission('export_bibleclass_payment_report');

// Remove admin_auth.php include as it has incorrect permission check

// Filtering
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$payment_type_id = isset($_GET['payment_type']) ? intval($_GET['payment_type']) : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

// Build WHERE clause
$where = "WHERE m.status = 'active'";
$params = [];
$types = '';
if ($class_id) {
    $where .= " AND m.class_id = ?";
    $params[] = $class_id;
    $types .= 'i';
}
if ($payment_type_id) {
    $where .= " AND p.payment_type_id = ?";
    $params[] = $payment_type_id;
    $types .= 'i';
}
if ($date_from) {
    $where .= " AND p.payment_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}
if ($date_to) {
    $where .= " AND p.payment_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql = "SELECT p.payment_date, m.crn, m.last_name, m.first_name, bc.name AS class_name, pt.name AS payment_type, p.amount FROM payments p LEFT JOIN members m ON p.member_id = m.id LEFT JOIN bible_classes bc ON m.class_id = bc.id LEFT JOIN payment_types pt ON p.payment_type_id = pt.id $where ORDER BY bc.name, m.last_name, m.first_name, p.payment_date DESC";
$stmt = $conn->prepare($sql);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$payments = [];
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}

// Get all classes and payment types for dropdowns
$classes = [];
$class_result = $conn->query("SELECT id, name FROM bible_classes ORDER BY name");
if ($class_result) {
    while ($row = $class_result->fetch_assoc()) {
        $classes[] = $row;
    }
}
$types_arr = [];
$type_result = $conn->query("SELECT id, name FROM payment_types ORDER BY name");
if ($type_result) {
    while ($row = $type_result->fetch_assoc()) {
        $types_arr[] = $row;
    }
}
// CSV Export
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=bibleclass_payment_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','CRN','Full Name','Bible Class','Payment Type','Amount','Date']);
    foreach ($payments as $i => $row) {
        fputcsv($out, [
            $i + 1,
            $row['crn'],
            $row['last_name'] . ', ' . $row['first_name'],
            $row['class_name'],
            $row['payment_type'],
            $row['amount'],
            $row['payment_date']
        ]);
    }
    fclose($out);
    exit;
}
ob_start();
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-chalkboard-teacher mr-2"></i>Bible Class Payment Report</h2>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="class_id" class="mr-2 font-weight-bold">Bible Class:</label>
            <select name="class_id" id="class_id" class="form-control">
                <option value="0">All</option>
                <?php foreach ($classes as $cl): ?>
                    <option value="<?php echo $cl['id']; ?>"<?php if ($class_id === intval($cl['id'])) echo ' selected'; ?>><?php echo htmlspecialchars($cl['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group mr-2">
            <label for="payment_type" class="mr-2 font-weight-bold">Payment Type:</label>
            <select name="payment_type" id="payment_type" class="form-control">
                <option value="0">All</option>
                <?php foreach ($types_arr as $type): ?>
                    <option value="<?php echo $type['id']; ?>"<?php if ($payment_type_id === intval($type['id'])) echo ' selected'; ?>><?php echo htmlspecialchars($type['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group mr-2">
            <label for="date_from" class="mr-2 font-weight-bold">From:</label>
            <input type="date" name="date_from" id="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>" />
        </div>
        <div class="form-group mr-2">
            <label for="date_to" class="mr-2 font-weight-bold">To:</label>
            <input type="date" name="date_to" id="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>" />
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
        <table id="bibleclass-payments-table" class="table table-bordered table-hover">
            <thead class="thead-light">
                <tr>
                    <th>#</th>
                    <th>CRN</th>
                    <th>Full Name</th>
                    <th>Bible Class</th>
                    <th>Payment Type</th>
                    <th>Amount</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_amount = 0;
                if (empty($payments)): ?>
                    <tr>
                        <td></td>
                        <td></td>
                        <td class="text-center">No payments found.</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $i => $row): 
                        $total_amount += floatval($row['amount']); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($row['crn']) ?></td>
                            <td><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td><?= htmlspecialchars($row['class_name'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($row['payment_type'] ?: '-') ?></td>
                            <td>₵<?= number_format($row['amount'],2) ?></td>
                            <td><?= htmlspecialchars($row['payment_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="font-weight-bold bg-light">
                    <td class="text-right">Total</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td>₵<?= number_format($total_amount,2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
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
    var table = $("#bibleclass-payments-table").DataTable({
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-success btn-sm mr-2',
                title: 'Bible Class Payment Report'
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: 'Bible Class Payment Report'
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: 'Bible Class Payment Report'
            }
        ],
        paging: false,
        searching: false,
        info: false,
        ordering: false
    });
    // Hide custom buttons if DataTables is used
    $('#export-csv, #export-pdf, #print-table').hide();
    // Wire up custom buttons to DataTables
    $('#export-csv').on('click', function() { table.button('.buttons-csv').trigger(); });
    $('#export-pdf').on('click', function() { table.button('.buttons-pdf').trigger(); });
    $('#print-table').on('click', function() { table.button('.buttons-print').trigger(); });
});
</script>
<?php $page_content = ob_get_clean(); include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php'; ?>
