<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../helpers/payment_report_context.php';
require_once __DIR__ . '/../../../helpers/report_pagination.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit('Your session has expired. Please sign in again.');
}

$reportCode = (string) ($_GET['report'] ?? 'day_born');
$reportPermissions = [
    'day_born' => ['view_day_born_payment_report', 'export_day_born_payment_report'],
    'age_bracket' => ['view_age_bracket_payment_report', 'export_age_bracket_payment_report'],
    'bible_class' => ['view_bibleclass_payment_report', 'export_bibleclass_payment_report'],
    'organization' => ['view_organisation_payment_report', 'export_organisation_payment_report'],
    'payment_made' => ['view_payment_made_report', 'export_payment_made_report'],
];
if (!isset($reportPermissions[$reportCode])) {
    http_response_code(422);
    exit('The requested payment-report context is not supported.');
}
$canView = is_super_admin() || has_permission($reportPermissions[$reportCode][0]);
if (!$canView) {
    http_response_code(403);
    exit('You do not have permission to view these payment transactions.');
}

$memberId = max(0, (int) ($_GET['member_id'] ?? 0));
if ($memberId < 1) {
    http_response_code(422);
    exit('Select a valid member.');
}

$memberWhere = ['member.id = ?', "member.status = 'active'"];
$memberScope = payment_report_member_scope_condition($conn, 'member');
if ($memberScope !== '') $memberWhere[] = $memberScope;
$memberStmt = $conn->prepare(
    "SELECT member.id, member.crn, member.first_name, member.middle_name, member.last_name, member.dob
       FROM members member
      WHERE " . implode(' AND ', $memberWhere) . ' LIMIT 1'
);
$memberStmt->bind_param('i', $memberId);
$memberStmt->execute();
$member = $memberStmt->get_result()->fetch_assoc();
$memberStmt->close();
if (!$member) {
    http_response_code(404);
    exit('The selected member was not found in your reporting scope.');
}

$paymentTypeId = max(0, (int) ($_GET['payment_type_id'] ?? 0));
$classId = max(0, (int) ($_GET['class_id'] ?? 0));
$organizationId = max(0, (int) ($_GET['organization_id'] ?? 0));
$day = max(0, min(7, (int) ($_GET['day'] ?? 0)));
$ageBrackets = [
    '0-12' => [0, 12], '13-17' => [13, 17], '18-25' => [18, 25],
    '26-35' => [26, 35], '36-45' => [36, 45], '46-60' => [46, 60],
    '61+' => [61, 200],
];
$ageBracket = (string) ($_GET['age_bracket'] ?? '');
if (!isset($ageBrackets[$ageBracket])) $ageBracket = '';
$startDate = payment_report_valid_date((string) ($_GET['start_date'] ?? ''));
$endDate = payment_report_valid_date((string) ($_GET['end_date'] ?? ''));
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}
[$periodFrom, $periodTo, $periodClauses, $periodValues] = payment_report_reporting_month_filter(
    (string) ($_GET['period_from'] ?? ''),
    (string) ($_GET['period_to'] ?? '')
);

$where = ['p.member_id = ?'];
$params = [$memberId];
$types = 'i';
$paymentScope = payment_report_payment_scope_condition($conn, 'p');
if ($paymentScope !== '') $where[] = $paymentScope;
if ($reportCode === 'day_born' && $day > 0) {
    $where[] = 'DAYOFWEEK(member.dob) = ?';
    $params[] = $day;
    $types .= 'i';
}
if ($reportCode === 'age_bracket' && $ageBracket !== '') {
    $where[] = 'TIMESTAMPDIFF(YEAR, member.dob, CURDATE()) BETWEEN ? AND ?';
    $params[] = $ageBrackets[$ageBracket][0];
    $params[] = $ageBrackets[$ageBracket][1];
    $types .= 'ii';
}
if ($reportCode === 'bible_class' && $classId > 0) {
    $where[] = 'member.class_id = ?';
    $params[] = $classId;
    $types .= 'i';
}
if ($reportCode === 'organization' && $organizationId > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM member_organizations member_org WHERE member_org.member_id = member.id AND member_org.organization_id = ?)';
    $params[] = $organizationId;
    $types .= 'i';
}
if ($paymentTypeId > 0) {
    $where[] = 'p.payment_type_id = ?';
    $params[] = $paymentTypeId;
    $types .= 'i';
}
if ($startDate !== '') {
    $where[] = 'p.payment_date >= ?';
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}
if ($endDate !== '') {
    $where[] = 'p.payment_date < ?';
    $params[] = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $types .= 's';
}
foreach ($periodClauses as $clause) $where[] = $clause;
foreach ($periodValues as $value) {
    $params[] = $value;
    $types .= 's';
}
$whereSql = implode(' AND ', $where);
$periodExpression = payment_report_reporting_period_expression('p');
$transactionSql = "SELECT p.id, p.payment_date, {$periodExpression} AS reporting_period,
                          COALESCE(payment_type.name, 'Unclassified') AS payment_type,
                          p.mode, p.amount, p.description, p.client_reference,
                          COALESCE(user_account.name, 'Member portal') AS recorded_by_name
                     FROM v_posted_payments p
                     JOIN members member ON member.id = p.member_id
                     LEFT JOIN payment_types payment_type ON payment_type.id = p.payment_type_id
                     LEFT JOIN users user_account ON user_account.id = CAST(p.recorded_by AS UNSIGNED)
                    WHERE {$whereSql}
                    ORDER BY p.payment_date DESC, p.id DESC";

$export = (string) ($_GET['export'] ?? '');
$canExport = is_super_admin() || has_permission($reportPermissions[$reportCode][1]);
if ($export === 'csv') {
    if (!$canExport) {
        http_response_code(403);
        exit('You do not have permission to export these transactions.');
    }
    $exportStmt = $conn->prepare($transactionSql);
    if ($types !== '') $exportStmt->bind_param($types, ...$params);
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    if (ob_get_level()) ob_end_clean();
    $safeReference = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($member['crn'] ?: $memberId));
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="member_payment_transactions_' . $safeReference . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Transaction ID', 'Transaction Date', 'Payment Period', 'Payment Type', 'Mode', 'Amount (GHS)', 'Reference', 'Recorded By', 'Description']);
    while ($row = $exportResult->fetch_assoc()) {
        fputcsv($output, [
            $row['id'], $row['payment_date'], $row['reporting_period'], $row['payment_type'],
            $row['mode'], $row['amount'], $row['client_reference'], $row['recorded_by_name'], $row['description'],
        ]);
    }
    fclose($output);
    exit;
}

$statsSql = "SELECT COUNT(*) AS transaction_count,
                    COALESCE(SUM(p.amount), 0) AS total_amount,
                    MIN(p.payment_date) AS first_payment_date,
                    MAX(p.payment_date) AS last_payment_date,
                    COUNT(DISTINCT COALESCE(p.payment_type_id, 0)) AS payment_type_count
               FROM v_posted_payments p
               JOIN members member ON member.id = p.member_id
              WHERE {$whereSql}";
$statsStmt = $conn->prepare($statsSql);
if ($types !== '') $statsStmt->bind_param($types, ...$params);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc() ?: [];
$statsStmt->close();

$pagination = report_paginate_query($conn, $transactionSql, $types, $params);
$transactions = $pagination['result']->fetch_all(MYSQLI_ASSOC);
$memberName = trim(implode(' ', array_filter([
    $member['first_name'] ?? '', $member['middle_name'] ?? '', $member['last_name'] ?? '',
])));
$exportQuery = $_GET;
$exportQuery['member_id'] = $memberId;
$exportQuery['export'] = 'csv';
unset($exportQuery['page'], $exportQuery['per_page']);
?>
<div class="member-payment-detail" data-member-id="<?= $memberId ?>">
  <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap:10px">
    <div>
      <h5 class="mb-1"><?= htmlspecialchars($memberName) ?></h5>
      <div class="text-muted small">CRN: <?= htmlspecialchars((string) ($member['crn'] ?: '-')) ?></div>
    </div>
    <?php if ($canExport && (int) ($stats['transaction_count'] ?? 0) > 0): ?>
      <a class="btn btn-outline-success btn-sm" href="ajax_member_payment_transactions.php?<?= htmlspecialchars(http_build_query($exportQuery)) ?>">
        <i class="fas fa-file-csv mr-1"></i>Export full history
      </a>
    <?php endif; ?>
  </div>

  <div class="row mb-3">
    <div class="col-6 col-lg-3 mb-2"><div class="border rounded p-2 h-100"><small class="text-muted text-uppercase">Transactions</small><strong class="d-block"><?= number_format((int) ($stats['transaction_count'] ?? 0)) ?></strong></div></div>
    <div class="col-6 col-lg-3 mb-2"><div class="border rounded p-2 h-100"><small class="text-muted text-uppercase">Total</small><strong class="d-block text-success">GHS <?= number_format((float) ($stats['total_amount'] ?? 0), 2) ?></strong></div></div>
    <div class="col-6 col-lg-3 mb-2"><div class="border rounded p-2 h-100"><small class="text-muted text-uppercase">Payment Types</small><strong class="d-block"><?= number_format((int) ($stats['payment_type_count'] ?? 0)) ?></strong></div></div>
    <div class="col-6 col-lg-3 mb-2"><div class="border rounded p-2 h-100"><small class="text-muted text-uppercase">Last Payment</small><strong class="d-block"><?= !empty($stats['last_payment_date']) ? htmlspecialchars(date('j M Y', strtotime($stats['last_payment_date']))) : '-' ?></strong></div></div>
  </div>

  <div class="d-flex flex-wrap justify-content-between align-items-center mb-2" style="gap:8px">
    <small class="text-muted">Showing <?= number_format($pagination['total_rows'] === 0 ? 0 : $pagination['offset'] + 1) ?>&ndash;<?= number_format(min($pagination['total_rows'], $pagination['offset'] + $pagination['per_page'])) ?> of <?= number_format($pagination['total_rows']) ?> transactions</small>
    <label class="small text-muted mb-0">Rows
      <select class="custom-select custom-select-sm ml-1 member-payment-page-size" style="width:auto">
        <?php foreach ([25, 50, 100] as $size): ?><option value="<?= $size ?>" <?= $pagination['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover table-bordered mb-0" data-report-pagination="off">
      <thead><tr><th>ID</th><th>Transaction Date</th><th>Payment Period</th><th>Type</th><th>Mode</th><th class="text-right">Amount</th><th>Reference</th><th>Description</th></tr></thead>
      <tbody>
      <?php if (!$transactions): ?><tr><td colspan="8" class="text-center text-muted py-4">No transactions match the selected filters.</td></tr><?php endif; ?>
      <?php foreach ($transactions as $transaction): ?>
        <tr>
          <td>#<?= number_format((int) $transaction['id']) ?></td>
          <td><?= htmlspecialchars(date('j M Y, g:i A', strtotime($transaction['payment_date']))) ?></td>
          <td><?= htmlspecialchars((string) ($transaction['reporting_period'] ?: '-')) ?></td>
          <td><?= htmlspecialchars((string) $transaction['payment_type']) ?></td>
          <td><span class="badge badge-light"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $transaction['mode']))) ?></span></td>
          <td class="text-right font-weight-bold">GHS <?= number_format((float) $transaction['amount'], 2) ?></td>
          <td><code><?= htmlspecialchars((string) ($transaction['client_reference'] ?: '-')) ?></code></td>
          <td><?= htmlspecialchars((string) ($transaction['description'] ?: '-')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pagination['total_pages'] > 1): ?>
    <nav class="mt-3" aria-label="Member transaction pages"><ul class="pagination pagination-sm justify-content-center mb-0">
      <li class="page-item <?= $pagination['page'] <= 1 ? 'disabled' : '' ?>"><a href="#" class="page-link member-payment-page-link" data-page="<?= max(1, $pagination['page'] - 1) ?>">Previous</a></li>
      <?php
      $pages = array_values(array_unique(array_merge([1, $pagination['total_pages']], range(max(1, $pagination['page'] - 2), min($pagination['total_pages'], $pagination['page'] + 2)))));
      sort($pages);
      $previous = null;
      foreach ($pages as $pageNumber):
          if ($previous !== null && $pageNumber > $previous + 1): ?>
            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
          <?php endif; ?>
          <li class="page-item <?= $pageNumber === $pagination['page'] ? 'active' : '' ?>"><a href="#" class="page-link member-payment-page-link" data-page="<?= $pageNumber ?>"><?= number_format($pageNumber) ?></a></li>
      <?php $previous = $pageNumber; endforeach; ?>
      <li class="page-item <?= $pagination['page'] >= $pagination['total_pages'] ? 'disabled' : '' ?>"><a href="#" class="page-link member-payment-page-link" data-page="<?= min($pagination['total_pages'], $pagination['page'] + 1) ?>">Next</a></li>
    </ul></nav>
  <?php endif; ?>
</div>
