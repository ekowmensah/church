<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions_v2.php';
require_once __DIR__.'/../../../helpers/payment_report_context.php';
require_once __DIR__.'/../../../helpers/report_pagination.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = is_super_admin();

if (!$is_super_admin && !has_permission('view_zero_payment_type_report')) {
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
$can_export = $is_super_admin || has_permission('export_zero_payment_type_report');

//require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_auth.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
ob_start();

$conn = $GLOBALS['conn'];
$period_preset = (string) ($_GET['period'] ?? 'custom');
[$period_preset, $start_date, $end_date] = payment_report_resolve_period(
    $period_preset,
    (string) ($_GET['start_date'] ?? ''),
    (string) ($_GET['end_date'] ?? '')
);
$period_label = payment_report_period_label($start_date, $end_date);
$payment_period_from = payment_report_valid_month((string) ($_GET['payment_period_from'] ?? ''));
$payment_period_to = payment_report_valid_month((string) ($_GET['payment_period_to'] ?? ''));
if ($payment_period_from !== '' && $payment_period_to !== '' && $payment_period_from > $payment_period_to) {
    [$payment_period_from, $payment_period_to] = [$payment_period_to, $payment_period_from];
}
$allocation_period_label = ($payment_period_from || $payment_period_to)
    ? trim(($payment_period_from ?: 'Beginning') . ' to ' . ($payment_period_to ?: 'Present'))
    : $period_label;
$where = ["m.status = 'active'", 'm.is_archived = 0', 'pt.active = 1'];
$scopeCondition = payment_report_member_scope_condition($conn, 'm');
if ($scopeCondition !== '') $where[] = $scopeCondition;
// Get all payment types
$payment_types = [];
$pt_result = $conn->query("SELECT id, name FROM payment_types ORDER BY name");
if ($pt_result) {
    while ($row = $pt_result->fetch_assoc()) {
        $payment_types[] = $row;
    }
}
$selected_payment_type = isset($_GET['payment_type_id']) ? intval($_GET['payment_type_id']) : 0;
$where[] = $selected_payment_type ? 'pt.id = ' . $selected_payment_type : '1 = 1';
$where_sql = 'WHERE ' . implode(' AND ', $where);
$paymentDateConditions = '';
if ($start_date !== '') $paymentDateConditions .= " AND p.payment_date >= '" . $conn->real_escape_string($start_date) . "'";
if ($end_date !== '') $paymentDateConditions .= " AND p.payment_date <= '" . $conn->real_escape_string($end_date) . "'";
if ($payment_period_from !== '') $paymentDateConditions .= " AND COALESCE(p.payment_period, p.payment_date) >= '" . $conn->real_escape_string($payment_period_from . '-01') . "'";
if ($payment_period_to !== '') $paymentDateConditions .= " AND COALESCE(p.payment_period, p.payment_date) < '" . $conn->real_escape_string(date('Y-m-d', strtotime($payment_period_to . '-01 +1 month'))) . "'";
$organizationsExpression = payment_report_organizations_expression('m');
$sql = "SELECT pt.name AS payment_type, m.last_name, m.first_name, m.crn,
               m.phone, bible_class.name AS class_name,
               $organizationsExpression AS organizations
FROM members m
CROSS JOIN payment_types pt
LEFT JOIN bible_classes bible_class ON bible_class.id = m.class_id
$where_sql
AND NOT EXISTS (
    SELECT 1 FROM v_posted_payments p
    WHERE p.member_id = m.id AND p.payment_type_id = pt.id $paymentDateConditions
)
ORDER BY pt.name, m.last_name, m.first_name
";
$pagination = report_paginate_query($conn, $sql);
$rows = $pagination['result']->fetch_all(MYSQLI_ASSOC);
$page = $pagination['page'];
$per_page = $pagination['per_page'];
$offset = $pagination['offset'];
$total_rows = $pagination['total_rows'];
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left mr-1"></i>Back to Reports</a>
    <h2 class="mb-4 font-weight-bold"><i class="fas fa-ban mr-2"></i>Zero Payment Type Report</h2>
    <p class="text-muted"><strong>Reporting Period:</strong> <?= htmlspecialchars($period_label) ?></p>
    <form method="get" class="form-inline mb-3">
        <div class="form-group mr-2">
            <label for="period" class="mr-2 font-weight-bold">Period:</label>
            <select name="period" id="period" class="form-control">
                <?php foreach (payment_report_period_options() as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= $period_preset === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
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
        <div class="form-group mr-2">
            <label for="start_date" class="mr-2 font-weight-bold">From:</label>
            <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div class="form-group mr-2">
            <label for="end_date" class="mr-2 font-weight-bold">To:</label>
            <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>
        <div class="form-group mr-2"><label for="payment_period_from" class="mr-2 font-weight-bold">Payment Period From:</label><input type="month" name="payment_period_from" id="payment_period_from" class="form-control" value="<?= htmlspecialchars($payment_period_from) ?>"></div>
        <div class="form-group mr-2"><label for="payment_period_to" class="mr-2 font-weight-bold">Payment Period To:</label><input type="month" name="payment_period_to" id="payment_period_to" class="form-control" value="<?= htmlspecialchars($payment_period_to) ?>"></div>
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
                    <th>Payment Type</th>
                    <th>Member Name</th>
                    <th>CRN</th>
                    <th>Contact</th>
                    <th>Bible Class</th>
                    <th>Organization(s)</th>
                    <th>Reporting Period</th>
                    <th>Amount Paid</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" class="text-center">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td><?php echo $i + 1 + $offset; ?></td>
                            <td><?php echo htmlspecialchars($row['payment_type']); ?></td>
                            <td><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['crn']); ?></td>
                            <td><?php echo htmlspecialchars($row['phone'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['class_name'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($row['organizations'] ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars($allocation_period_label); ?></td>
                            <td>0.00</td>
                            <td><span class="badge badge-warning">No Payment</span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php report_render_server_pagination($total_rows, $page, $per_page, 'Zero-payment report pages'); ?>
</div>
<!-- DataTables and JS export dependencies -->
<script src="<?= BASE_URL ?>/assets/js/report-export-branding.js"></script>
<script>
$(document).ready(function() {
    var table = $(".table").DataTable({
        dom: 'Bfrtip',
        buttons: [
            <?php if ($can_export): ?>
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv"></i> CSV',
                className: 'btn btn-success btn-sm mr-2',
                title: <?= json_encode('Zero Payment Type Report - ' . $period_label) ?>
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-danger btn-sm mr-2',
                title: <?= json_encode('Zero Payment Type Report - ' . $period_label) ?>
            },
            <?php endif; ?>
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Print',
                className: 'btn btn-secondary btn-sm',
                title: <?= json_encode('Zero Payment Type Report - ' . $period_label) ?>
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
