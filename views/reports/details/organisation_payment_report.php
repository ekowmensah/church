<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../helpers/auth.php';
require_once __DIR__ . '/../../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../../helpers/payment_report_context.php';
require_once __DIR__ . '/../../../helpers/report_pagination.php';

if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('view_organisation_payment_report')) {
    http_response_code(403); include __DIR__ . '/../../errors/403.php'; exit;
}
$canExport = $isSuperAdmin || has_permission('export_organisation_payment_report');

$selectedOrganization = max(0, (int) ($_GET['organization_id'] ?? 0));
$selectedPaymentType = max(0, (int) ($_GET['payment_type_id'] ?? 0));
$startDate = payment_report_valid_date((string) ($_GET['start_date'] ?? ''));
$endDate = payment_report_valid_date((string) ($_GET['end_date'] ?? ''));
if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) [$startDate, $endDate] = [$endDate, $startDate];
[$periodFrom, $periodTo, $periodClauses, $periodValues] = payment_report_reporting_month_filter(
    (string) ($_GET['period_from'] ?? ''), (string) ($_GET['period_to'] ?? '')
);

$churchId = payment_report_current_church_id($conn);
$organizations = [];
$orgSql = payment_report_is_super_admin()
    ? 'SELECT id, name FROM organizations ORDER BY name'
    : 'SELECT id, name FROM organizations WHERE church_id = ? ORDER BY name';
$orgStmt = $conn->prepare($orgSql);
if (!payment_report_is_super_admin()) $orgStmt->bind_param('i', $churchId);
$orgStmt->execute();
$organizations = $orgStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$orgStmt->close();

$paymentTypes = [];
$paymentTypeResult = $conn->query('SELECT id, name FROM payment_types ORDER BY name');
while ($paymentTypeResult && ($paymentType = $paymentTypeResult->fetch_assoc())) $paymentTypes[] = $paymentType;

$where = ["member.status = 'active'"];
$params = [];
$types = '';
$scopeCondition = payment_report_payment_scope_condition($conn, 'payment');
if ($scopeCondition !== '') $where[] = $scopeCondition;
if ($selectedOrganization > 0) {
    $where[] = 'EXISTS (SELECT 1 FROM member_organizations selected_membership WHERE selected_membership.member_id = member.id AND selected_membership.organization_id = ?)';
    $params[] = $selectedOrganization;
    $types .= 'i';
}
if ($selectedPaymentType > 0) { $where[] = 'payment.payment_type_id = ?'; $params[] = $selectedPaymentType; $types .= 'i'; }
if ($startDate !== '') { $where[] = 'payment.payment_date >= ?'; $params[] = $startDate . ' 00:00:00'; $types .= 's'; }
if ($endDate !== '') { $where[] = 'payment.payment_date < ?'; $params[] = (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; $types .= 's'; }
foreach ($periodClauses as $clause) $where[] = str_replace('p.', 'payment.', $clause);
foreach ($periodValues as $value) { $params[] = $value; $types .= 's'; }
$whereSql = implode(' AND ', $where);
$periodDate = 'COALESCE(payment.payment_period, payment.payment_date)';
$organizationNames = "COALESCE((SELECT GROUP_CONCAT(DISTINCT organization.name ORDER BY organization.name SEPARATOR ', ')
                                  FROM member_organizations membership
                                  JOIN organizations organization ON organization.id = membership.organization_id
                                 WHERE membership.member_id = member.id
                                   AND organization.church_id = member.church_id), 'Unassigned')";

$summarySql = "SELECT member.id AS member_id, member.crn, member.first_name, member.middle_name,
                      member.last_name, {$organizationNames} AS organization_names,
                      COUNT(*) AS transaction_count,
                      GROUP_CONCAT(DISTINCT COALESCE(payment_type.name, 'Unclassified') ORDER BY payment_type.name SEPARATOR ', ') AS payment_types,
                      MIN({$periodDate}) AS first_reporting_period, MAX({$periodDate}) AS last_reporting_period,
                      MAX(payment.payment_date) AS last_payment_date, COALESCE(SUM(payment.amount), 0) AS total_amount
                 FROM members member
                 JOIN v_posted_payments payment ON payment.member_id = member.id
                 LEFT JOIN payment_types payment_type ON payment_type.id = payment.payment_type_id
                WHERE {$whereSql}
                GROUP BY member.id, member.crn, member.first_name, member.middle_name, member.last_name
                ORDER BY organization_names, total_amount DESC, member.last_name, member.first_name";

$statsSql = "SELECT COUNT(DISTINCT member.id) AS member_count, COUNT(*) AS transaction_count,
                    COALESCE(SUM(payment.amount), 0) AS total_amount,
                    COALESCE(SUM(payment.amount) / NULLIF(COUNT(DISTINCT member.id), 0), 0) AS average_per_member
               FROM members member JOIN v_posted_payments payment ON payment.member_id = member.id
              WHERE {$whereSql}";
$statsStmt = $conn->prepare($statsSql);
if ($types !== '') $statsStmt->bind_param($types, ...$params);
$statsStmt->execute();
$stats = $statsStmt->get_result()->fetch_assoc() ?: [];
$statsStmt->close();

if ((string) ($_GET['export'] ?? '') === 'summary_csv') {
    if (!$canExport) { http_response_code(403); exit('You do not have permission to export this report.'); }
    $exportStmt = $conn->prepare($summarySql);
    if ($types !== '') $exportStmt->bind_param($types, ...$params);
    $exportStmt->execute(); $exportResult = $exportStmt->get_result();
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="organization_member_payment_summary.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Organizations', 'CRN', 'Member Name', 'Transactions', 'Payment Types', 'First Reporting Period', 'Last Reporting Period', 'Last Payment', 'Total Amount (GHS)']);
    while ($row = $exportResult->fetch_assoc()) {
        $name = trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name']])));
        fputcsv($output, [$row['organization_names'], $row['crn'], $name, $row['transaction_count'], $row['payment_types'], $row['first_reporting_period'], $row['last_reporting_period'], $row['last_payment_date'], $row['total_amount']]);
    }
    fclose($output); exit;
}

$pagination = report_paginate_query($conn, $summarySql, $types, $params);
$members = $pagination['result']->fetch_all(MYSQLI_ASSOC);
$exportQuery = $_GET; $exportQuery['export'] = 'summary_csv'; unset($exportQuery['page'], $exportQuery['per_page']);
$page_title = 'Organization Payment Report';
ob_start();
?>
<div class="container-fluid mt-4 member-payment-summary org-payment-report">
  <div class="d-flex flex-wrap justify-content-between align-items-start mb-3" style="gap:12px"><div><a href="../../reports.php" class="btn btn-outline-secondary btn-sm mb-2"><i class="fas fa-arrow-left mr-1"></i>Report Centre</a><h2 class="mb-1 font-weight-bold"><i class="fas fa-building mr-2"></i>Organization Payment Report</h2><p class="text-muted mb-0">Each member and payment is counted once, even when the member belongs to multiple organizations.</p></div><div class="d-flex flex-wrap" style="gap:8px"><?php if($canExport):?><a href="?<?=htmlspecialchars(http_build_query($exportQuery))?>" class="btn btn-success btn-sm"><i class="fas fa-file-csv mr-1"></i>Export member summary</a><?php endif;?><button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="fas fa-print mr-1"></i>Print</button></div></div>

  <div class="card mb-3"><div class="card-body"><form method="get"><div class="form-row">
    <div class="form-group col-lg-3 col-md-4"><label for="organization_id">Organization</label><select name="organization_id" id="organization_id" class="form-control"><option value="0">All organizations</option><?php foreach($organizations as $organization):?><option value="<?=(int)$organization['id']?>" <?=$selectedOrganization===(int)$organization['id']?'selected':''?>><?=htmlspecialchars($organization['name'])?></option><?php endforeach;?></select></div>
    <div class="form-group col-lg-2 col-md-4"><label for="payment_type_id">Payment Type</label><select name="payment_type_id" id="payment_type_id" class="form-control"><option value="0">All types</option><?php foreach($paymentTypes as $paymentType):?><option value="<?=(int)$paymentType['id']?>" <?=$selectedPaymentType===(int)$paymentType['id']?'selected':''?>><?=htmlspecialchars($paymentType['name'])?></option><?php endforeach;?></select></div>
    <div class="form-group col-lg-2 col-md-4"><label for="start_date">Transaction From</label><input type="date" name="start_date" id="start_date" class="form-control" value="<?=htmlspecialchars($startDate)?>"></div><div class="form-group col-lg-2 col-md-4"><label for="end_date">Transaction To</label><input type="date" name="end_date" id="end_date" class="form-control" value="<?=htmlspecialchars($endDate)?>"></div>
    <div class="form-group col-lg-2 col-md-4"><label for="period_from">Reporting From</label><input type="month" name="period_from" id="period_from" class="form-control" value="<?=htmlspecialchars($periodFrom)?>"></div><div class="form-group col-lg-2 col-md-4"><label for="period_to">Reporting To</label><input type="month" name="period_to" id="period_to" class="form-control" value="<?=htmlspecialchars($periodTo)?>"></div>
    <div class="form-group col-lg-2 col-md-4 d-flex align-items-end"><button class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Apply filters</button></div><div class="form-group col-lg-2 col-md-4 d-flex align-items-end"><a href="organisation_payment_report.php" class="btn btn-outline-secondary btn-block">Reset</a></div>
  </div></form></div></div>

  <div class="alert alert-info py-2"><i class="fas fa-info-circle mr-1"></i>When All organizations is selected, shared members are counted once and all their organization memberships are displayed. Selecting one organization limits the member population without multiplying transactions.</div>
  <div class="row mb-3"><?php foreach([['Members',(int)($stats['member_count']??0),'users'],['Transactions',(int)($stats['transaction_count']??0),'receipt'],['Total Amount','GHS '.number_format((float)($stats['total_amount']??0),2),'coins'],['Average / Member','GHS '.number_format((float)($stats['average_per_member']??0),2),'chart-line']] as $metric):?><div class="col-6 col-xl-3 mb-2"><div class="summary-card"><small><i class="fas fa-<?=$metric[2]?> mr-1"></i><?=htmlspecialchars($metric[0])?></small><strong><?=is_int($metric[1])?number_format($metric[1]):htmlspecialchars($metric[1])?></strong></div></div><?php endforeach;?></div>

  <div class="card shadow-sm mb-4"><div class="card-header d-flex flex-wrap justify-content-between align-items-center"><strong>Organization Member Payment Summary</strong><small class="text-muted">One row per member; no multi-membership duplication.</small></div><div class="card-body"><div class="table-responsive"><table class="table table-hover table-bordered" data-report-pagination="server"><thead><tr><th>#</th><th>Member</th><th>Organizations</th><th class="text-center">Transactions</th><th>Payment Types</th><th>Reporting Coverage</th><th>Last Payment</th><th class="text-right">Total</th><th>Action</th></tr></thead><tbody>
  <?php if(!$members):?><tr><td colspan="9" class="text-center text-muted py-5">No organization member payments match the selected filters.</td></tr><?php endif;?>
  <?php foreach($members as $index=>$member):$memberName=trim(implode(' ',array_filter([$member['first_name'],$member['middle_name'],$member['last_name']])));$firstPeriod=!empty($member['first_reporting_period'])?date('M Y',strtotime($member['first_reporting_period'])):'-';$lastPeriod=!empty($member['last_reporting_period'])?date('M Y',strtotime($member['last_reporting_period'])):'-';$coverage=$firstPeriod===$lastPeriod?$firstPeriod:$firstPeriod.' - '.$lastPeriod;?><tr>
    <td><?=number_format($pagination['offset']+$index+1)?></td><td><div class="member-name"><?=htmlspecialchars($memberName)?></div><small class="text-muted">CRN: <?=htmlspecialchars((string)($member['crn']?:'-'))?></small></td><td><div class="compact-list"><?=htmlspecialchars($member['organization_names'])?></div></td><td class="text-center"><span class="badge badge-primary"><?=number_format((int)$member['transaction_count'])?></span></td><td><div class="compact-list"><?=htmlspecialchars((string)($member['payment_types']?:'Unclassified'))?></div></td><td><?=htmlspecialchars($coverage)?></td><td><?=!empty($member['last_payment_date'])?htmlspecialchars(date('j M Y',strtotime($member['last_payment_date']))):'-'?></td><td class="text-right font-weight-bold text-success">GHS <?=number_format((float)$member['total_amount'],2)?></td><td><button type="button" class="btn btn-outline-primary btn-sm view-member-payments" data-member-id="<?=(int)$member['member_id']?>" data-member-name="<?=htmlspecialchars($memberName,ENT_QUOTES)?>"><i class="fas fa-receipt mr-1"></i>View payments</button></td>
  </tr><?php endforeach;?></tbody></table></div><?php report_render_server_pagination($pagination['total_rows'],$pagination['page'],$pagination['per_page'],'Organization member payment summary pages');?></div></div>

  <div class="modal fade" id="memberPaymentsModal" tabindex="-1" role="dialog" aria-labelledby="memberPaymentsTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable" role="document"><div class="modal-content"><div class="modal-header"><div><h5 class="modal-title" id="memberPaymentsTitle">Member payment history</h5><small class="text-white-50">Filtered transaction evidence</small></div><button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div><div class="modal-body" id="memberPaymentsBody"><div class="text-center text-muted py-5">Select a member to view payments.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div></div></div></div>
</div>
<script>
window.MemberPaymentDrilldownConfig = {
  endpoint: <?=json_encode(BASE_URL.'/views/reports/details/ajax_member_payment_transactions.php')?>,
  filters: <?=json_encode(['report'=>'organization','organization_id'=>$selectedOrganization,'payment_type_id'=>$selectedPaymentType,'start_date'=>$startDate,'end_date'=>$endDate,'period_from'=>$periodFrom,'period_to'=>$periodTo])?>
};
</script>
<?php $page_content=ob_get_clean(); include __DIR__.'/../../../includes/layout.php'; ?>
