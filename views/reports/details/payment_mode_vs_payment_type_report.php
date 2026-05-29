<?php
require_once __DIR__.'/../../../config/config.php';
require_once __DIR__.'/../../../helpers/auth.php';
require_once __DIR__.'/../../../helpers/permissions_v2.php';
require_once __DIR__.'/../../../helpers/role_based_filter.php';
require_once __DIR__.'/../../../includes/report_ui_helpers.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3)
    || (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

if (!$is_super_admin && !has_permission('view_payment_report') && !has_permission('view_payment_made_report') && !has_permission('view_payment_list')) {
    http_response_code(403);
    if (file_exists(__DIR__.'/../../errors/403.php')) {
        include __DIR__.'/../../errors/403.php';
    } else if (file_exists(__DIR__.'/../../../views/errors/403.php')) {
        include __DIR__.'/../../../views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this report.</p></div>';
    }
    exit;
}

function normalize_payment_mode_key($raw_mode): string
{
    $mode = strtolower(trim((string) $raw_mode));
    $mode = str_replace([' ', '-'], '_', $mode);
    $mode = preg_replace('/_+/', '_', $mode);

    if ($mode === '' || $mode === 'null') {
        return 'unknown';
    }
    if (in_array($mode, ['check', 'cheques'], true)) {
        return 'cheque';
    }
    if (in_array($mode, ['mobilemoney', 'mobile_money', 'momo'], true)) {
        return 'mobile_money';
    }
    if (in_array($mode, ['banktransfer', 'bank_transfer', 'transfer'], true)) {
        return 'transfer';
    }
    if ($mode === 'pos') {
        return 'pos';
    }
    if ($mode === 'card') {
        return 'card';
    }
    if ($mode === 'cash') {
        return 'cash';
    }
    if ($mode === 'paystack') {
        return 'paystack';
    }
    if ($mode === 'online') {
        return 'online';
    }
    if ($mode === 'offline') {
        return 'offline';
    }
    if ($mode === 'other') {
        return 'other';
    }

    return $mode;
}

function payment_mode_label(string $mode_key): string
{
    $labels = [
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'mobile_money' => 'Mobile Money',
        'transfer' => 'Transfer',
        'pos' => 'POS',
        'card' => 'Card',
        'paystack' => 'Paystack',
        'online' => 'Online',
        'offline' => 'Offline',
        'other' => 'Other',
        'unknown' => 'Unknown',
    ];

    return $labels[$mode_key] ?? ucwords(str_replace('_', ' ', $mode_key));
}

function payment_mode_badge_class(string $mode_key): string
{
    $classes = [
        'cash' => 'success',
        'cheque' => 'secondary',
        'mobile_money' => 'warning',
        'transfer' => 'info',
        'pos' => 'primary',
        'card' => 'primary',
        'paystack' => 'dark',
        'online' => 'dark',
        'offline' => 'light',
        'other' => 'light',
        'unknown' => 'light',
    ];

    return $classes[$mode_key] ?? 'light';
}

$today = date('Y-m-d');
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])
    ? $_GET['start_date']
    : date('Y-m-01');
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])
    ? $_GET['end_date']
    : $today;

if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$selected_mode_raw = isset($_GET['mode']) ? trim((string) $_GET['mode']) : '';
$selected_mode = $selected_mode_raw === '' ? '' : normalize_payment_mode_key($selected_mode_raw);
$selected_payment_type_id = isset($_GET['payment_type_id']) ? max(0, (int) $_GET['payment_type_id']) : 0;
$selected_user_id = isset($_GET['user_id']) ? max(0, (int) $_GET['user_id']) : 0;

$can_view_all = $is_super_admin || has_permission('view_all_payments');

$payment_types = [];
$payment_types_rs = $conn->query("SELECT id, name FROM payment_types ORDER BY name ASC");
if ($payment_types_rs) {
    while ($row = $payment_types_rs->fetch_assoc()) {
        $payment_types[] = $row;
    }
}

$users = [];
if ($can_view_all) {
    $users_rs = $conn->query("SELECT id, name FROM users ORDER BY name ASC");
    if ($users_rs) {
        while ($row = $users_rs->fetch_assoc()) {
            $users[] = $row;
        }
    }
}

$sql = "
    SELECT
        COALESCE(NULLIF(TRIM(p.mode), ''), 'unknown') AS raw_mode,
        p.payment_type_id,
        COALESCE(pt.name, 'Unspecified') AS payment_type_name,
        COUNT(p.id) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS total_amount
    FROM payments p
    LEFT JOIN payment_types pt ON p.payment_type_id = pt.id
    LEFT JOIN members m ON p.member_id = m.id
    LEFT JOIN sunday_school ss ON p.sundayschool_id = ss.id
    WHERE DATE(p.payment_date) >= ? AND DATE(p.payment_date) <= ?
";

$params = [$start_date, $end_date];
$types = 'ss';

$class_ids = get_user_class_ids();
if ($class_ids !== null && count($class_ids) > 0) {
    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $sql .= " AND ((p.member_id IS NOT NULL AND m.class_id IN ($placeholders)) OR (p.sundayschool_id IS NOT NULL AND ss.class_id IN ($placeholders)))";
    foreach ($class_ids as $class_id) {
        $params[] = $class_id;
        $types .= 'i';
    }
    foreach ($class_ids as $class_id) {
        $params[] = $class_id;
        $types .= 'i';
    }
}

$org_filter = apply_organizational_leader_filter('m');
if (!empty($org_filter['sql'])) {
    $sql .= " AND " . $org_filter['sql'];
    foreach ($org_filter['params'] as $param) {
        $params[] = $param;
    }
    $types .= $org_filter['types'];
}

$ss_filter = apply_sunday_school_filter('m');
if (!empty($ss_filter['sql'])) {
    $sql .= " AND " . $ss_filter['sql'];
}

$cashier_filter = apply_cashier_filter('p');
if (!empty($cashier_filter['sql'])) {
    $sql .= " AND " . $cashier_filter['sql'];
    foreach ($cashier_filter['params'] as $param) {
        $params[] = $param;
    }
    $types .= $cashier_filter['types'];
}

if ($can_view_all && $selected_user_id > 0) {
    $sql .= " AND p.recorded_by = ?";
    $params[] = $selected_user_id;
    $types .= 'i';
}

if ($selected_payment_type_id > 0) {
    $sql .= " AND p.payment_type_id = ?";
    $params[] = $selected_payment_type_id;
    $types .= 'i';
}

$sql .= "
    GROUP BY raw_mode, p.payment_type_id, payment_type_name
    ORDER BY total_amount DESC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die('Failed to prepare query.');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$raw_rows = [];
while ($row = $result->fetch_assoc()) {
    $raw_rows[] = $row;
}
$stmt->close();

$matrix = [];
$mode_totals = [];
$type_totals = [];
$mode_options = [];
$type_options = [];
$grand_total_amount = 0.0;
$grand_total_count = 0;

foreach ($raw_rows as $row) {
    $mode_key = normalize_payment_mode_key($row['raw_mode'] ?? 'unknown');
    $mode_options[$mode_key] = payment_mode_label($mode_key);

    if ($selected_mode !== '' && $mode_key !== $selected_mode) {
        continue;
    }

    $type_name = trim((string) ($row['payment_type_name'] ?? ''));
    if ($type_name === '') {
        $type_name = 'Unspecified';
    }

    $amount = (float) ($row['total_amount'] ?? 0);
    $count = (int) ($row['payment_count'] ?? 0);

    if (!isset($matrix[$mode_key])) {
        $matrix[$mode_key] = [];
    }
    if (!isset($matrix[$mode_key][$type_name])) {
        $matrix[$mode_key][$type_name] = ['amount' => 0.0, 'count' => 0];
    }
    $matrix[$mode_key][$type_name]['amount'] += $amount;
    $matrix[$mode_key][$type_name]['count'] += $count;

    if (!isset($mode_totals[$mode_key])) {
        $mode_totals[$mode_key] = ['amount' => 0.0, 'count' => 0];
    }
    $mode_totals[$mode_key]['amount'] += $amount;
    $mode_totals[$mode_key]['count'] += $count;

    if (!isset($type_totals[$type_name])) {
        $type_totals[$type_name] = ['amount' => 0.0, 'count' => 0];
    }
    $type_totals[$type_name]['amount'] += $amount;
    $type_totals[$type_name]['count'] += $count;
    $type_options[$type_name] = true;

    $grand_total_amount += $amount;
    $grand_total_count += $count;
}

if ($selected_mode !== '' && !isset($mode_options[$selected_mode])) {
    $mode_options[$selected_mode] = payment_mode_label($selected_mode);
}

uasort($mode_totals, static function ($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
uasort($type_totals, static function ($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
asort($mode_options);

$ordered_modes = array_keys($mode_totals);
$ordered_types = array_keys($type_totals);

$chart_labels = $ordered_types;
$chart_palette = [
    '#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed',
    '#0891b2', '#be123c', '#15803d', '#ea580c', '#4338ca'
];

$chart_datasets = [];
foreach ($ordered_modes as $index => $mode_key) {
    $dataset_values = [];
    foreach ($ordered_types as $type_name) {
        $dataset_values[] = isset($matrix[$mode_key][$type_name])
            ? round((float) $matrix[$mode_key][$type_name]['amount'], 2)
            : 0;
    }
    $chart_datasets[] = [
        'label' => payment_mode_label($mode_key),
        'data' => $dataset_values,
        'backgroundColor' => $chart_palette[$index % count($chart_palette)],
        'borderWidth' => 1,
    ];
}

ob_start();
?>
<div class="container mt-4">
    <a href="../../reports.php" class="btn btn-secondary mb-3">
        <i class="fas fa-arrow-left mr-1"></i>Back to Reports
    </a>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h2 class="mb-2 mb-md-0 font-weight-bold">
            <i class="fas fa-table mr-2"></i>Payment Mode vs Payment Type Analysis
        </h2>
    </div>

    <form method="get" class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-2">
                    <label for="start_date" class="font-weight-bold">From</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="form-group col-md-2">
                    <label for="end_date" class="font-weight-bold">To</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="form-group col-md-3">
                    <label for="payment_type_id" class="font-weight-bold">Payment Type</label>
                    <select class="form-control" id="payment_type_id" name="payment_type_id">
                        <option value="0">All Payment Types</option>
                        <?php foreach ($payment_types as $pt): ?>
                            <option value="<?= (int) $pt['id'] ?>" <?= $selected_payment_type_id === (int) $pt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($pt['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="mode" class="font-weight-bold">Payment Mode</label>
                    <select class="form-control" id="mode" name="mode">
                        <option value="">All Modes</option>
                        <?php foreach ($mode_options as $mode_key => $mode_label): ?>
                            <option value="<?= htmlspecialchars($mode_key) ?>" <?= $selected_mode === $mode_key ? 'selected' : '' ?>>
                                <?= htmlspecialchars($mode_label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($can_view_all): ?>
                <div class="form-group col-md-3">
                    <label for="user_id" class="font-weight-bold">Recorded By</label>
                    <select class="form-control" id="user_id" name="user_id">
                        <option value="0">All Users</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int) $user['id'] ?>" <?= $selected_user_id === (int) $user['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="d-flex flex-wrap">
                <button type="submit" class="btn btn-primary mr-2 mb-2">
                    <i class="fas fa-filter mr-1"></i>Apply Filters
                </button>
                <a href="payment_mode_vs_payment_type_report.php" class="btn btn-outline-secondary mb-2">
                    <i class="fas fa-undo mr-1"></i>Reset
                </a>
            </div>
        </div>
    </form>

    <div class="row mb-3">
        <div class="col-md-3 mb-3">
            <div class="card border-left-primary shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-xs text-uppercase text-primary font-weight-bold">Total Amount</div>
                    <div class="h5 mb-0 font-weight-bold">GHS <?= number_format($grand_total_amount, 2) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-success shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-xs text-uppercase text-success font-weight-bold">Total Transactions</div>
                    <div class="h5 mb-0 font-weight-bold"><?= number_format($grand_total_count) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-info shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-xs text-uppercase text-info font-weight-bold">Active Modes</div>
                    <div class="h5 mb-0 font-weight-bold"><?= count($ordered_modes) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card border-left-warning shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-xs text-uppercase text-warning font-weight-bold">Active Types</div>
                    <div class="h5 mb-0 font-weight-bold"><?= count($ordered_types) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header bg-light">
            <strong>Type x Mode Matrix</strong>
            <span class="text-muted ml-2">(Amount shown with count per cell)</span>
        </div>
        <div class="card-body">
            <?php if (empty($ordered_modes) || empty($ordered_types)): ?>
                <div class="alert alert-info mb-0">
                    No payment data found for the selected filters.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover" id="modeTypeTable">
                        <thead class="thead-light">
                            <tr>
                                <th style="min-width: 220px;">Payment Type</th>
                                <?php foreach ($ordered_modes as $mode_key): ?>
                                    <th class="text-right">
                                        <span class="badge badge-<?= payment_mode_badge_class($mode_key) ?>">
                                            <?= htmlspecialchars(payment_mode_label($mode_key)) ?>
                                        </span>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-right bg-light">Row Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordered_types as $type_name): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars($type_name) ?></td>
                                    <?php foreach ($ordered_modes as $mode_key): ?>
                                        <?php
                                        $cell = $matrix[$mode_key][$type_name] ?? ['amount' => 0.0, 'count' => 0];
                                        ?>
                                        <td class="text-right">
                                            <div class="font-weight-bold">GHS <?= number_format((float) $cell['amount'], 2) ?></div>
                                            <small class="text-muted"><?= number_format((int) $cell['count']) ?> txns</small>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-right bg-light">
                                        <div class="font-weight-bold">GHS <?= number_format((float) $type_totals[$type_name]['amount'], 2) ?></div>
                                        <small class="text-muted"><?= number_format((int) $type_totals[$type_name]['count']) ?> txns</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td>Column Total</td>
                                <?php foreach ($ordered_modes as $mode_key): ?>
                                    <td class="text-right bg-light">
                                        <div>GHS <?= number_format((float) $mode_totals[$mode_key]['amount'], 2) ?></div>
                                        <small class="text-muted"><?= number_format((int) $mode_totals[$mode_key]['count']) ?> txns</small>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-right bg-primary text-white">
                                    <div>GHS <?= number_format($grand_total_amount, 2) ?></div>
                                    <small><?= number_format($grand_total_count) ?> txns</small>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($ordered_modes) && !empty($ordered_types)): ?>
    <div class="card shadow mb-4">
        <div class="card-header bg-light"><strong>Visual Comparison</strong></div>
        <div class="card-body">
            <canvas id="modeTypeChart" height="110"></canvas>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($ordered_modes) && !empty($ordered_types)): ?>
<?php include_datatables_scripts(); ?>
<?php
datatables_init_script('modeTypeTable', [
    'dom' => 'Bfrtip',
    'buttons' => [
        [
            'extend' => 'copy',
            'className' => 'btn btn-sm btn-outline-secondary',
            'title' => 'Payment Mode vs Payment Type Analysis (' . $start_date . ' to ' . $end_date . ')'
        ],
        [
            'extend' => 'csv',
            'className' => 'btn btn-sm btn-outline-primary',
            'title' => 'Payment Mode vs Payment Type Analysis (' . $start_date . ' to ' . $end_date . ')'
        ],
        [
            'extend' => 'excel',
            'className' => 'btn btn-sm btn-outline-success',
            'title' => 'Payment Mode vs Payment Type Analysis (' . $start_date . ' to ' . $end_date . ')'
        ],
        [
            'extend' => 'pdf',
            'className' => 'btn btn-sm btn-outline-danger',
            'title' => 'Payment Mode vs Payment Type Analysis (' . $start_date . ' to ' . $end_date . ')'
        ],
        [
            'extend' => 'print',
            'className' => 'btn btn-sm btn-outline-dark',
            'title' => 'Payment Mode vs Payment Type Analysis (' . $start_date . ' to ' . $end_date . ')'
        ]
    ],
    'responsive' => true,
    'pageLength' => 25,
    'order' => [[0, 'asc']]
]);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('modeTypeChart');
    if (!ctx) return;

    const chartLabels = <?= json_encode($chart_labels) ?>;
    const chartDatasets = <?= json_encode($chart_datasets) ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: chartLabels,
            datasets: chartDatasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = Number(context.raw || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            return context.dataset.label + ': GHS ' + value;
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'GHS ' + Number(value).toLocaleString();
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php
$page_content = ob_get_clean();
include dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'layout.php';
?>
