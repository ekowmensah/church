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
    fputcsv($out, ['#','CRN','Full Name','Bible Class','Payment Type','Amount (GHS)','Date']);
    $csv_total = 0;
    foreach ($payments as $i => $row) {
        $csv_total += floatval($row['amount']);
        fputcsv($out, [
            $i + 1,
            $row['crn'],
            $row['last_name'] . ', ' . $row['first_name'],
            $row['class_name'],
            $row['payment_type'],
            'GHS ' . number_format($row['amount'], 2),
            $row['payment_date']
        ]);
    }
    fputcsv($out, ['','','','','Total','GHS ' . number_format($csv_total, 2),'']);
    fclose($out);
    exit;
}
// Compute summary statistics
$total_amount = 0;
$unique_members = [];
$unique_classes = [];
$payment_type_totals = [];
foreach ($payments as $row) {
    $total_amount += floatval($row['amount']);
    if (!empty($row['crn'])) $unique_members[$row['crn']] = true;
    if (!empty($row['class_name'])) $unique_classes[$row['class_name']] = true;
    $pt = $row['payment_type'] ?: 'Unknown';
    if (!isset($payment_type_totals[$pt])) $payment_type_totals[$pt] = 0;
    $payment_type_totals[$pt] += floatval($row['amount']);
}
$record_count = count($payments);
$member_count = count($unique_members);
$class_count = count($unique_classes);
$avg_payment = $record_count > 0 ? $total_amount / $record_count : 0;

// Build active filter description
$active_filters = [];
if ($class_id) {
    foreach ($classes as $cl) {
        if (intval($cl['id']) === $class_id) { $active_filters[] = $cl['name']; break; }
    }
}
if ($payment_type_id) {
    foreach ($types_arr as $t) {
        if (intval($t['id']) === $payment_type_id) { $active_filters[] = $t['name']; break; }
    }
}
if ($date_from) $active_filters[] = "From: $date_from";
if ($date_to) $active_filters[] = "To: $date_to";

ob_start();
?>

<!-- Content Header -->
<div class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6">
                <h1 class="m-0" style="font-size:1.6rem;">
                    <i class="fas fa-chalkboard-teacher mr-2 text-primary"></i>Bible Class Payment Report
                </h1>
            </div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/views/user_dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/views/reports.php">Reports</a></li>
                    <li class="breadcrumb-item active">Bible Class Payments</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<section class="content">
<div class="container-fluid">

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-lg-3 col-md-6 col-sm-6">
            <div class="small-box bg-info">
                <div class="inner">
                    <h3>GHS <?= number_format($total_amount, 2) ?></h3>
                    <p>Total Amount</p>
                </div>
                <div class="icon"><i class="fas fa-money-bill-wave"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-sm-6">
            <div class="small-box bg-success">
                <div class="inner">
                    <h3><?= number_format($record_count) ?></h3>
                    <p>Total Transactions</p>
                </div>
                <div class="icon"><i class="fas fa-receipt"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-sm-6">
            <div class="small-box bg-warning">
                <div class="inner">
                    <h3><?= number_format($member_count) ?></h3>
                    <p>Unique Members</p>
                </div>
                <div class="icon"><i class="fas fa-users"></i></div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 col-sm-6">
            <div class="small-box bg-primary">
                <div class="inner">
                    <h3>GHS <?= number_format($avg_payment, 2) ?></h3>
                    <p>Average per Transaction</p>
                </div>
                <div class="icon"><i class="fas fa-calculator"></i></div>
            </div>
        </div>
    </div>

    <!-- Filter Card -->
    <div class="card card-outline card-primary collapsed-card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-filter mr-2"></i>Filters
                <?php if (!empty($active_filters)): ?>
                    <span class="ml-2">
                        <?php foreach ($active_filters as $af): ?>
                            <span class="badge badge-info"><?= htmlspecialchars($af) ?></span>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
        </div>
        <div class="card-body" style="display:none;">
            <form method="get" id="filterForm">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="class_id"><i class="fas fa-book-reader mr-1 text-muted"></i>Bible Class</label>
                            <select name="class_id" id="class_id" class="form-control form-control-sm">
                                <option value="0">-- All Classes --</option>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?= $cl['id'] ?>"<?= $class_id === intval($cl['id']) ? ' selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="payment_type"><i class="fas fa-tags mr-1 text-muted"></i>Payment Type</label>
                            <select name="payment_type" id="payment_type" class="form-control form-control-sm">
                                <option value="0">-- All Types --</option>
                                <?php foreach ($types_arr as $type): ?>
                                    <option value="<?= $type['id'] ?>"<?= $payment_type_id === intval($type['id']) ? ' selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date_from"><i class="fas fa-calendar mr-1 text-muted"></i>Date From</label>
                            <input type="date" name="date_from" id="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($date_from) ?>">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label for="date_to"><i class="fas fa-calendar-check mr-1 text-muted"></i>Date To</label>
                            <input type="date" name="date_to" id="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($date_to) ?>">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12 text-right">
                        <a href="?" class="btn btn-default btn-sm mr-2"><i class="fas fa-undo mr-1"></i>Reset</a>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search mr-1"></i>Apply Filters</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card card-outline card-dark">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-table mr-2"></i>Payment Records
                <small class="text-muted ml-2">(<?= number_format($record_count) ?> records<?= $class_count > 0 ? " across $class_count class" . ($class_count > 1 ? 'es' : '') : '' ?>)</small>
            </h3>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="bibleclass-payments-table" class="table table-bordered table-hover table-striped mb-0">
                    <thead>
                        <tr style="background:linear-gradient(135deg,#3c8dbc,#367fa9);color:#fff;">
                            <th style="width:50px;">#</th>
                            <th>CRN</th>
                            <th>Full Name</th>
                            <th>Bible Class</th>
                            <th>Payment Type</th>
                            <th class="text-right" style="width:130px;">Amount</th>
                            <th style="width:120px;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                    No payments found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($payments as $i => $row): ?>
                                <tr>
                                    <td class="text-center text-muted"><?= $i + 1 ?></td>
                                    <td><code><?= htmlspecialchars($row['crn']) ?></code></td>
                                    <td class="font-weight-bold"><?= htmlspecialchars($row['last_name'] . ', ' . $row['first_name']) ?></td>
                                    <td><span class="badge badge-light border"><?= htmlspecialchars($row['class_name'] ?: '-') ?></span></td>
                                    <td><span class="badge badge-info"><?= htmlspecialchars($row['payment_type'] ?: '-') ?></span></td>
                                    <td class="text-right font-weight-bold">GHS <?= number_format($row['amount'], 2) ?></td>
                                    <td><?= date('d M Y', strtotime($row['payment_date'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f4f6f9; border-top:2px solid #3c8dbc;">
                            <td></td>
                            <td></td>
                            <td></td>
                            <td></td>
                            <td class="text-right font-weight-bold" style="font-size:1.05rem;">Grand Total</td>
                            <td class="text-right font-weight-bold text-primary" style="font-size:1.1rem;">GHS <?= number_format($total_amount, 2) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

</div>
</section>

<!-- DataTables CSS (Bootstrap 4 integration) -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.3.6/css/buttons.bootstrap4.min.css">

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap4.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.36/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.pdf.min.js"></script>

<script>
$(document).ready(function() {
    // Expand filter card if any filters are active
    <?php if (!empty($active_filters)): ?>
    $('.collapsed-card .card-header .btn-tool').trigger('click');
    <?php endif; ?>

    var table = $("#bibleclass-payments-table").DataTable({
        dom: '<"row mb-2"<"col-sm-6 col-md-4"l><"col-sm-6 col-md-8 text-right"B>>' +
             'rtip',
        buttons: [
            <?php if ($can_export): ?>
            {
                extend: 'csv',
                text: '<i class="fas fa-file-csv mr-1"></i>CSV',
                className: 'btn btn-sm btn-outline-success',
                title: 'Bible Class Payment Report',
                footer: true,
                exportOptions: { columns: ':visible' }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf mr-1"></i>PDF',
                className: 'btn btn-sm btn-outline-danger',
                title: 'Bible Class Payment Report',
                footer: false,
                orientation: 'landscape',
                exportOptions: { columns: ':visible' },
                customize: function(doc) {
                    doc.defaultStyle.fontSize = 9;
                    doc.styles.tableHeader.fontSize = 10;
                    doc.styles.tableHeader.fillColor = '#3c8dbc';
                    // Manually add totals row
                    var body = doc.content[1].table.body;
                    var colCount = body[0].length;
                    var totalRow = [];
                    for (var j = 0; j < colCount; j++) {
                        if (j === colCount - 2) {
                            totalRow.push({ text: 'GHS <?= number_format($total_amount, 2) ?>', bold: true, fillColor: '#f4f6f9', fontSize: 10, alignment: 'right' });
                        } else if (j === colCount - 3) {
                            totalRow.push({ text: 'Grand Total', bold: true, fillColor: '#f4f6f9', fontSize: 10, alignment: 'right' });
                        } else {
                            totalRow.push({ text: '', fillColor: '#f4f6f9' });
                        }
                    }
                    body.push(totalRow);
                }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print mr-1"></i>Print',
                className: 'btn btn-sm btn-outline-secondary',
                title: 'Bible Class Payment Report',
                footer: true,
                exportOptions: { columns: ':visible' }
            },
            <?php endif; ?>
        ],
        paging: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        searching: true,
        info: true,
        ordering: true,
        order: [],
        language: {
            search: '<i class="fas fa-search text-muted"></i>',
            searchPlaceholder: 'Search records...',
            lengthMenu: 'Show _MENU_ entries',
            info: 'Showing _START_ to _END_ of _TOTAL_ records',
            infoEmpty: 'No records available',
            infoFiltered: '(filtered from _MAX_ total)',
            paginate: {
                first: '<i class="fas fa-angle-double-left"></i>',
                previous: '<i class="fas fa-angle-left"></i>',
                next: '<i class="fas fa-angle-right"></i>',
                last: '<i class="fas fa-angle-double-right"></i>'
            }
        }
    });
});
</script>

<style>
.small-box .inner h3 { font-size: 1.6rem; }
.small-box .inner p { font-size: 0.85rem; }
.dataTables_wrapper .dt-buttons .btn { margin-left: 4px; }
.dataTables_filter input { border-radius: 20px !important; padding-left: 12px !important; }
table.dataTable thead th { border-bottom: none; }
table.dataTable tfoot td { border-top: 2px solid #3c8dbc; }
.table-striped tbody tr:nth-of-type(odd) { background-color: rgba(0,0,0,.02); }
</style>

<?php $page_content = ob_get_clean(); include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php'; ?>
