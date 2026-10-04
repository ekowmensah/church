<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../helpers/payment_report_context.php';
require_once __DIR__ . '/../../../helpers/report_pagination.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
$canView = $isSuperAdmin
    || has_permission('view_payments_by_user_report')
    || has_permission('view_payment_list');
if (!$canView) {
    http_response_code(403);
    include __DIR__ . '/../../errors/403.php';
    exit;
}

// There is no separate historical export permission for this report. Keep
// export access exactly aligned with its existing report access contract.
$canExport = $canView;
$conn = $GLOBALS['conn'];

$dateFrom = payment_report_valid_date((string) ($_GET['date_from'] ?? ''));
$dateTo = payment_report_valid_date((string) ($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
$selectedUser = max(0, (int) ($_GET['user_id'] ?? 0));
$selectedPaymentType = max(0, (int) ($_GET['payment_type_id'] ?? 0));
[$periodFrom, $periodTo, $periodClauses, $periodValues] = payment_report_reporting_month_filter(
    (string) ($_GET['period_from'] ?? ''),
    (string) ($_GET['period_to'] ?? '')
);

$where = [];
$params = [];
$types = '';
$scopeCondition = payment_report_payment_scope_condition($conn, 'payment');
if ($scopeCondition !== '') {
    $where[] = $scopeCondition;
}
if ($dateFrom !== '') {
    $where[] = 'payment.payment_date >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo !== '') {
    $where[] = 'payment.payment_date <= ?';
    $params[] = $dateTo;
    $types .= 's';
}
foreach ($periodClauses as $periodClause) {
    $where[] = str_replace('p.', 'payment.', $periodClause);
}
foreach ($periodValues as $periodValue) {
    $params[] = $periodValue;
    $types .= 's';
}
if ($selectedUser > 0) {
    $where[] = 'user_account.id = ?';
    $params[] = $selectedUser;
    $types .= 'i';
}
if ($selectedPaymentType > 0) {
    $where[] = 'payment.payment_type_id = ?';
    $params[] = $selectedPaymentType;
    $types .= 'i';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$groupSql = "SELECT user_account.id AS user_id,
                    user_account.name AS user_name,
                    user_account.email AS user_email,
                    COUNT(payment.id) AS payment_count,
                    COALESCE(SUM(payment.amount), 0) AS total_amount,
                    GROUP_CONCAT(DISTINCT COALESCE(payment_type.name, 'Unclassified')
                                 ORDER BY payment_type.name SEPARATOR ', ') AS payment_types,
                    MIN(payment.payment_date) AS first_payment_date,
                    MAX(payment.payment_date) AS last_payment_date
               FROM v_posted_payments payment
               JOIN users user_account ON user_account.id = payment.recorded_by
          LEFT JOIN payment_types payment_type ON payment_type.id = payment.payment_type_id
             {$whereSql}
           GROUP BY user_account.id, user_account.name, user_account.email";
$reportSql = $groupSql . ' ORDER BY total_amount DESC, payment_count DESC, user_account.name';

$summaryStmt = $conn->prepare(
    'SELECT COUNT(*) AS responsible_users,
            COALESCE(SUM(payment_count), 0) AS payment_count,
            COALESCE(SUM(total_amount), 0) AS total_amount,
            MAX(last_payment_date) AS last_payment_date
       FROM (' . $groupSql . ') report_summary'
);
if ($types !== '') {
    $summaryStmt->bind_param($types, ...$params);
}
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

if (($_GET['export'] ?? '') === 'summary_csv') {
    if (!$canExport) {
        http_response_code(403);
        exit('Export permission required.');
    }
    $exportStmt = $conn->prepare($reportSql);
    if ($types !== '') {
        $exportStmt->bind_param($types, ...$params);
    }
    $exportStmt->execute();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payments_by_user_summary.csv"');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Responsible User', 'Email', 'Payments', 'Payment Types', 'First Payment', 'Last Payment', 'Total Amount (GHS)']);
    $exportRows = $exportStmt->get_result();
    while ($row = $exportRows->fetch_assoc()) {
        fputcsv($output, [
            $row['user_name'], $row['user_email'], $row['payment_count'],
            $row['payment_types'], $row['first_payment_date'],
            $row['last_payment_date'], $row['total_amount'],
        ]);
    }
    fclose($output);
    $exportStmt->close();
    exit;
}

$pagination = report_paginate_query($conn, $reportSql, $types, $params);
$usersOnPage = $pagination['result']->fetch_all(MYSQLI_ASSOC);

$churchId = payment_report_current_church_id($conn);
if ($isSuperAdmin) {
    $usersResult = $conn->query('SELECT id, name FROM users ORDER BY name');
} else {
    $usersStmt = $conn->prepare('SELECT id, name FROM users WHERE church_id = ? ORDER BY name');
    $usersStmt->bind_param('i', $churchId);
    $usersStmt->execute();
    $usersResult = $usersStmt->get_result();
}
$userOptions = $usersResult ? $usersResult->fetch_all(MYSQLI_ASSOC) : [];
$paymentTypeResult = $conn->query('SELECT id, name FROM payment_types ORDER BY name');
$paymentTypeOptions = $paymentTypeResult ? $paymentTypeResult->fetch_all(MYSQLI_ASSOC) : [];

$exportQuery = $_GET;
unset($exportQuery['page'], $exportQuery['per_page']);
$exportQuery['export'] = 'summary_csv';
$averagePayment = (int) ($summary['payment_count'] ?? 0) > 0
    ? (float) $summary['total_amount'] / (int) $summary['payment_count']
    : 0.0;

ob_start();
?>
<div class="container-fluid mt-4 member-payment-summary payments-by-user-report">
  <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap:12px">
    <div>
      <a href="../../reports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="fas fa-arrow-left mr-1"></i>Report Centre</a>
      <h2 class="mb-1 font-weight-bold"><i class="fas fa-user-shield mr-2"></i>Payments by User</h2>
      <p class="text-muted mb-0">Accountability summary of posted payments by the user who recorded them.</p>
    </div>
    <div class="d-flex flex-wrap" style="gap:8px">
      <?php if ($canExport): ?>
        <a class="btn btn-success btn-sm" href="?<?= htmlspecialchars(http_build_query($exportQuery), ENT_QUOTES) ?>"><i class="fas fa-file-csv mr-1"></i>Export complete summary</a>
      <?php endif; ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button>
    </div>
  </div>

  <div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <form method="get" class="form-row align-items-end">
      <div class="form-group col-xl-3 col-md-6"><label for="user_id">Responsible user</label><select class="form-control" id="user_id" name="user_id"><option value="0">All users</option><?php foreach ($userOptions as $user): ?><option value="<?= (int) $user['id'] ?>" <?= $selectedUser === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-xl-3 col-md-6"><label for="payment_type_id">Payment type</label><select class="form-control" id="payment_type_id" name="payment_type_id"><option value="0">All payment types</option><?php foreach ($paymentTypeOptions as $paymentType): ?><option value="<?= (int) $paymentType['id'] ?>" <?= $selectedPaymentType === (int) $paymentType['id'] ? 'selected' : '' ?>><?= htmlspecialchars($paymentType['name']) ?></option><?php endforeach; ?></select></div>
      <div class="form-group col-xl-2 col-md-3"><label for="date_from">Transaction date from</label><input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
      <div class="form-group col-xl-2 col-md-3"><label for="date_to">Transaction date to</label><input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
      <div class="form-group col-xl-2 col-md-3"><label for="period_from">Reporting month from</label><input type="month" class="form-control" id="period_from" name="period_from" value="<?= htmlspecialchars($periodFrom) ?>"></div>
      <div class="form-group col-xl-2 col-md-3"><label for="period_to">Reporting month to</label><input type="month" class="form-control" id="period_to" name="period_to" value="<?= htmlspecialchars($periodTo) ?>"></div>
      <div class="form-group col-xl-2 col-md-3"><button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Apply filters</button></div>
      <div class="form-group col-xl-2 col-md-3"><a href="payments_by_user_report.php" class="btn btn-outline-secondary btn-block">Reset</a></div>
    </form>
  </div></div>

  <div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3"><div class="summary-card"><small>Responsible users</small><strong><?= number_format((int) ($summary['responsible_users'] ?? 0)) ?></strong></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="summary-card"><small>Posted payments</small><strong><?= number_format((int) ($summary['payment_count'] ?? 0)) ?></strong></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="summary-card"><small>Total recorded</small><strong>GHS <?= number_format((float) ($summary['total_amount'] ?? 0), 2) ?></strong></div></div>
    <div class="col-xl-3 col-md-6 mb-3"><div class="summary-card"><small>Average payment</small><strong>GHS <?= number_format($averagePayment, 2) ?></strong></div></div>
  </div>

  <div class="card shadow-sm border-0"><div class="card-body">
    <div class="table-responsive">
      <table class="table table-hover" data-report-pagination="server">
        <thead class="thead-light"><tr><th>#</th><th>Responsible user</th><th>Payments</th><th>Payment types</th><th>Coverage</th><th class="text-right">Total</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$usersOnPage): ?><tr><td colspan="7" class="text-center text-muted py-5">No posted payments match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($usersOnPage as $index => $user): ?>
          <tr>
            <td><?= number_format($pagination['offset'] + $index + 1) ?></td>
            <td><div class="member-name"><?= htmlspecialchars($user['user_name']) ?></div><small class="text-muted"><?= htmlspecialchars($user['user_email'] ?: 'No email recorded') ?></small></td>
            <td class="text-center"><span class="badge badge-primary"><?= number_format((int) $user['payment_count']) ?></span></td>
            <td><div class="compact-list"><?= htmlspecialchars($user['payment_types'] ?: 'Unclassified') ?></div></td>
            <td><?= htmlspecialchars(date('j M Y', strtotime($user['first_payment_date']))) ?><?php if ($user['first_payment_date'] !== $user['last_payment_date']): ?> &ndash; <?= htmlspecialchars(date('j M Y', strtotime($user['last_payment_date']))) ?><?php endif; ?></td>
            <td class="text-right font-weight-bold text-success">GHS <?= number_format((float) $user['total_amount'], 2) ?></td>
            <td><button type="button" class="btn btn-outline-primary btn-sm payment-count-btn" data-user-id="<?= (int) $user['user_id'] ?>" data-user-name="<?= htmlspecialchars($user['user_name'], ENT_QUOTES) ?>"><i class="fas fa-receipt mr-1"></i>View transactions</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php report_render_server_pagination($pagination['total_rows'], $pagination['page'], $pagination['per_page'], 'Payments-by-user summary pages'); ?>
  </div></div>

  <div class="modal fade" id="userTransactionsModal" tabindex="-1" role="dialog" aria-labelledby="userTransactionsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title" id="userTransactionsModalLabel">Recorded transactions</h5><small class="text-white-50">Posted payment evidence</small></div><button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
      <div class="modal-body">
        <form id="user-transactions-filter" class="form-row align-items-end mb-3">
          <input type="hidden" id="modal_user_id" name="user_id">
          <div class="form-group col-lg-3 col-md-6"><label for="modal_payment_type_id">Payment type</label><select class="form-control" id="modal_payment_type_id" name="payment_type_id"><option value="0">All payment types</option><?php foreach ($paymentTypeOptions as $paymentType): ?><option value="<?= (int) $paymentType['id'] ?>"><?= htmlspecialchars($paymentType['name']) ?></option><?php endforeach; ?></select></div>
          <div class="form-group col-lg-2 col-md-3"><label for="modal_date_from">Date from</label><input type="date" class="form-control" id="modal_date_from" name="date_from"></div>
          <div class="form-group col-lg-2 col-md-3"><label for="modal_date_to">Date to</label><input type="date" class="form-control" id="modal_date_to" name="date_to"></div>
          <div class="form-group col-lg-2 col-md-3"><label for="modal_period_from">Month from</label><input type="month" class="form-control" id="modal_period_from" name="period_from"></div>
          <div class="form-group col-lg-2 col-md-3"><label for="modal_period_to">Month to</label><input type="month" class="form-control" id="modal_period_to" name="period_to"></div>
          <div class="form-group col-lg-1 col-md-3"><button type="submit" class="btn btn-primary btn-block">Apply</button></div>
        </form>
        <div id="user-transactions-table-area"><div class="text-center text-muted py-5">Select a user to view transactions.</div></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
    </div></div>
  </div>
</div>

<script>
$(function () {
  function transactionRequest(page, perPage, exportFormat) {
    return {
      user_id: $('#modal_user_id').val(),
      payment_type_id: $('#modal_payment_type_id').val(),
      date_from: $('#modal_date_from').val(),
      date_to: $('#modal_date_to').val(),
      period_from: $('#modal_period_from').val(),
      period_to: $('#modal_period_to').val(),
      page: page || 1,
      per_page: perPage || $('#user-transactions-page-size').val() || 25,
      export: exportFormat || ''
    };
  }

  function loadTransactions(page, perPage) {
    var $area = $('#user-transactions-table-area');
    $area.html('<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm mr-2"></span>Loading transactions...</div>');
    $.ajax({url: 'ajax_user_transactions.php', data: transactionRequest(page, perPage), dataType: 'html'})
      .done(function (html) { $area.html(html); })
      .fail(function (xhr) {
        var message = xhr.responseText && xhr.responseText.charAt(0) !== '<'
          ? xhr.responseText
          : 'The transaction history could not be loaded. Please try again.';
        $area.html('<div class="alert alert-danger mb-0"></div>').find('.alert').text(message);
      });
  }

  $(document).on('click', '.payment-count-btn', function () {
    var userName = String($(this).data('user-name') || 'User');
    $('#modal_user_id').val($(this).data('user-id'));
    $('#userTransactionsModalLabel').text('Transactions recorded by ' + userName);
    $('#modal_payment_type_id').val(<?= json_encode((string) $selectedPaymentType) ?>);
    $('#modal_date_from').val(<?= json_encode($dateFrom) ?>);
    $('#modal_date_to').val(<?= json_encode($dateTo) ?>);
    $('#modal_period_from').val(<?= json_encode($periodFrom) ?>);
    $('#modal_period_to').val(<?= json_encode($periodTo) ?>);
    $('#userTransactionsModal').modal('show');
    loadTransactions(1, 25);
  });

  $('#user-transactions-filter').on('submit', function (event) {
    event.preventDefault();
    loadTransactions(1, 25);
  });
  $('#user-transactions-table-area')
    .on('click', '.user-transactions-page-link', function (event) {
      event.preventDefault();
      if (!$(this).closest('.page-item').hasClass('disabled')) loadTransactions($(this).data('page'), $(this).data('per-page'));
    })
    .on('change', '#user-transactions-page-size', function () { loadTransactions(1, $(this).val()); })
    .on('click', '.export-user-transactions', function () {
      window.location.href = 'ajax_user_transactions.php?' + $.param(transactionRequest(1, 25, 'csv'));
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../../../includes/layout.php';
?>
