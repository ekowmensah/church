<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../helpers/payment_report_context.php';
require_once __DIR__ . '/../../../helpers/report_pagination.php';

if (!is_logged_in()) {
    http_response_code(403);
    exit('Authentication required.');
}
$canView = is_super_admin()
    || has_permission('view_payments_by_user_report')
    || has_permission('view_payment_list');
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view these transactions.');
}

$conn = $GLOBALS['conn'];
$userId = max(0, (int) ($_GET['user_id'] ?? 0));
if ($userId < 1) {
    http_response_code(422);
    exit('Select a valid responsible user.');
}
$paymentTypeId = max(0, (int) ($_GET['payment_type_id'] ?? 0));
$dateFrom = payment_report_valid_date((string) ($_GET['date_from'] ?? ''));
$dateTo = payment_report_valid_date((string) ($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}
[$periodFrom, $periodTo, $periodClauses, $periodValues] = payment_report_reporting_month_filter(
    (string) ($_GET['period_from'] ?? ''),
    (string) ($_GET['period_to'] ?? '')
);

$where = ['payment.recorded_by = ?'];
$params = [$userId];
$types = 'i';
$scopeCondition = payment_report_payment_scope_condition($conn, 'payment');
if ($scopeCondition !== '') {
    $where[] = $scopeCondition;
}
if ($paymentTypeId > 0) {
    $where[] = 'payment.payment_type_id = ?';
    $params[] = $paymentTypeId;
    $types .= 'i';
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
$whereSql = ' WHERE ' . implode(' AND ', $where);
$reportingPeriodExpression = payment_report_reporting_period_expression('payment');
$beneficiaryName = "CASE
    WHEN payment.member_id IS NOT NULL THEN TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name))
    WHEN payment.sundayschool_id IS NOT NULL THEN TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name))
    ELSE 'Unassigned'
END";
$beneficiaryReference = "CASE
    WHEN payment.member_id IS NOT NULL THEN member.crn
    WHEN payment.sundayschool_id IS NOT NULL THEN child.srn
    ELSE NULL
END";
$transactionSql = "SELECT payment.id, payment.payment_date,
                          {$reportingPeriodExpression} AS reporting_period,
                          payment.amount, payment.description,
                          COALESCE(payment_type.name, 'Unclassified') AS payment_type,
                          {$beneficiaryName} AS beneficiary_name,
                          {$beneficiaryReference} AS beneficiary_reference
                     FROM v_posted_payments payment
                LEFT JOIN members member ON member.id = payment.member_id
                LEFT JOIN sunday_school child ON child.id = payment.sundayschool_id
                LEFT JOIN payment_types payment_type ON payment_type.id = payment.payment_type_id
                   {$whereSql}
                 ORDER BY payment.payment_date DESC, payment.id DESC";

$summarySql = "SELECT COUNT(*) AS payment_count,
                      COALESCE(SUM(payment.amount), 0) AS total_amount,
                      COUNT(DISTINCT payment.payment_type_id) AS payment_type_count,
                      MIN(payment.payment_date) AS first_payment_date,
                      MAX(payment.payment_date) AS last_payment_date
                 FROM v_posted_payments payment {$whereSql}";
$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param($types, ...$params);
$summaryStmt->execute();
$summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();

if (($_GET['export'] ?? '') === 'csv') {
    $exportStmt = $conn->prepare($transactionSql);
    $exportStmt->bind_param($types, ...$params);
    $exportStmt->execute();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="user_recorded_transactions.csv"');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Transaction ID', 'Transaction Date', 'Reporting Period', 'Beneficiary', 'Reference', 'Payment Type', 'Description', 'Amount (GHS)']);
    $rows = $exportStmt->get_result();
    while ($row = $rows->fetch_assoc()) {
        fputcsv($output, [
            $row['id'], $row['payment_date'], $row['reporting_period'],
            $row['beneficiary_name'], $row['beneficiary_reference'],
            $row['payment_type'], $row['description'], $row['amount'],
        ]);
    }
    fclose($output);
    $exportStmt->close();
    exit;
}

$pagination = report_paginate_query($conn, $transactionSql, $types, $params);
$transactions = $pagination['result']->fetch_all(MYSQLI_ASSOC);
?>
<div class="row mb-3">
  <div class="col-lg-4 col-md-6 mb-2"><div class="summary-card"><small>Filtered payments</small><strong><?= number_format((int) ($summary['payment_count'] ?? 0)) ?></strong></div></div>
  <div class="col-lg-4 col-md-6 mb-2"><div class="summary-card"><small>Filtered total</small><strong>GHS <?= number_format((float) ($summary['total_amount'] ?? 0), 2) ?></strong></div></div>
  <div class="col-lg-4 col-md-6 mb-2"><div class="summary-card"><small>Payment types</small><strong><?= number_format((int) ($summary['payment_type_count'] ?? 0)) ?></strong></div></div>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap:8px">
  <label class="small text-muted mb-0">Rows <select class="custom-select custom-select-sm ml-1" id="user-transactions-page-size" style="width:auto"><?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $pagination['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?></select></label>
  <button type="button" class="btn btn-success btn-sm export-user-transactions"><i class="fas fa-file-csv mr-1"></i>Export complete transaction history</button>
</div>

<div class="table-responsive">
  <table class="table table-hover table-sm">
    <thead class="thead-light"><tr><th>Date</th><th>Reporting period</th><th>Beneficiary</th><th>Payment type</th><th>Description</th><th class="text-right">Amount</th></tr></thead>
    <tbody>
    <?php if (!$transactions): ?><tr><td colspan="6" class="text-center text-muted py-4">No transactions match the selected filters.</td></tr><?php endif; ?>
    <?php foreach ($transactions as $transaction): ?>
      <tr>
        <td><?= htmlspecialchars(date('j M Y', strtotime($transaction['payment_date']))) ?></td>
        <td><?= htmlspecialchars($transaction['reporting_period'] ?: '-') ?></td>
        <td><div class="font-weight-bold"><?= htmlspecialchars($transaction['beneficiary_name']) ?></div><small class="text-muted"><?= htmlspecialchars($transaction['beneficiary_reference'] ?: 'No reference') ?></small></td>
        <td><?= htmlspecialchars($transaction['payment_type']) ?></td>
        <td><?= htmlspecialchars($transaction['description'] ?: '-') ?></td>
        <td class="text-right font-weight-bold">GHS <?= number_format((float) $transaction['amount'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php
$totalPages = $pagination['total_pages'];
$page = $pagination['page'];
$pages = array_unique(array_merge([1, $totalPages], range(max(1, $page - 2), min($totalPages, $page + 2))));
sort($pages);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between mt-3" style="gap:10px">
  <small class="text-muted">Showing <?= number_format($pagination['total_rows'] ? $pagination['offset'] + 1 : 0) ?>&ndash;<?= number_format(min($pagination['total_rows'], $pagination['offset'] + $pagination['per_page'])) ?> of <?= number_format($pagination['total_rows']) ?> transactions</small>
  <?php if ($totalPages > 1): ?><nav aria-label="Transaction pages"><ul class="pagination pagination-sm mb-0">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a href="#" class="page-link user-transactions-page-link" data-page="<?= max(1, $page - 1) ?>" data-per-page="<?= $pagination['per_page'] ?>">Previous</a></li>
    <?php $previous = null; foreach ($pages as $number): if ($previous !== null && $number > $previous + 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?><li class="page-item <?= $number === $page ? 'active' : '' ?>"><a href="#" class="page-link user-transactions-page-link" data-page="<?= $number ?>" data-per-page="<?= $pagination['per_page'] ?>"><?= $number ?></a></li><?php $previous = $number; endforeach; ?>
    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a href="#" class="page-link user-transactions-page-link" data-page="<?= min($totalPages, $page + 1) ?>" data-per-page="<?= $pagination['per_page'] ?>">Next</a></li>
  </ul></nav><?php endif; ?>
</div>
