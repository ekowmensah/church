<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/feedback_report_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('view_feedback_report')) {
    http_response_code(403);
    include __DIR__ . '/../errors/403.php';
    exit;
}
$canExport = $isSuperAdmin || has_permission('export_feedback_report');
$context = feedback_report_context($_GET, $isSuperAdmin);
$filters = $context['filters'];
$whereSql = $context['where_sql'];
$params = $context['params'];
$types = $context['types'];
$requestedPerPage = (int) ($_GET['per_page'] ?? 25);
$perPage = in_array($requestedPerPage, [25, 50, 100], true) ? $requestedPerPage : 25;
$page = max(1, (int) ($_GET['page'] ?? 1));

$statsStatement = $conn->prepare("SELECT COUNT(*) total_count, COUNT(DISTINCT f.member_id) contributor_count,
    SUM(DATE(f.submitted_at)=CURDATE()) today_count, MAX(f.submitted_at) latest_at
    FROM member_feedback f LEFT JOIN members m ON m.id=f.member_id {$whereSql}");
feedback_report_bind($statsStatement, $types, $params);
$statsStatement->execute();
$stats = $statsStatement->get_result()->fetch_assoc() ?: [];
$statsStatement->close();
$totalRows = (int) ($stats['total_count'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsStatement = $conn->prepare("SELECT f.id,f.message,f.submitted_at,m.crn,
    TRIM(CONCAT_WS(' ',m.first_name,m.middle_name,m.last_name)) member_name,c.name church_name
    FROM member_feedback f LEFT JOIN members m ON m.id=f.member_id
    LEFT JOIN churches c ON c.id=m.church_id {$whereSql}
    ORDER BY f.submitted_at DESC,f.id DESC LIMIT ? OFFSET ?");
feedback_report_bind($rowsStatement, $types . 'ii', array_merge($params, [$perPage, $offset]));
$rowsStatement->execute();
$rows = $rowsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$rowsStatement->close();

$trendStatement = $conn->prepare("SELECT DATE_FORMAT(f.submitted_at,'%Y-%m') month_key,
    DATE_FORMAT(f.submitted_at,'%b %Y') month_label,COUNT(*) event_count
    FROM member_feedback f LEFT JOIN members m ON m.id=f.member_id {$whereSql}
    GROUP BY month_key,month_label ORDER BY month_key");
feedback_report_bind($trendStatement, $types, $params);
$trendStatement->execute();
$trendRows = array_slice($trendStatement->get_result()->fetch_all(MYSQLI_ASSOC), -12);
$trendStatement->close();
$trendMaximum = 1;
foreach ($trendRows as $trendRow) $trendMaximum = max($trendMaximum, (int) $trendRow['event_count']);

$churches = [];
if ($isSuperAdmin) {
    $churchResult = $conn->query('SELECT id,name FROM churches ORDER BY name');
    $churches = $churchResult ? $churchResult->fetch_all(MYSQLI_ASSOC) : [];
}
$baseQuery = array_merge($filters, ['per_page' => $perPage]);
$page_title = 'Feedback Report';
ob_start();
?>
<main class="report-page-shell feedback-report-page">
  <header class="report-page-header"><div><h1><i class="fas fa-comment-dots mr-2"></i>Feedback Report</h1><p>Review member feedback using the fields the system actually records: contributor, church, message and submission date.</p></div><div class="d-flex flex-wrap" style="gap:8px"><a class="btn btn-outline-light" href="<?= BASE_URL ?>/views/reports.php"><i class="fas fa-arrow-left mr-1"></i>Report Centre</a><?php if($canExport): ?><a class="btn btn-light" href="feedback_report_export.php?<?= htmlspecialchars(feedback_report_query($filters),ENT_QUOTES,'UTF-8') ?>"><i class="fas fa-download mr-1"></i>Export Excel</a><?php endif; ?></div></header>
  <section class="report-stat-grid" aria-label="Feedback summary">
    <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-comments"></i></span><div><strong><?= number_format($totalRows) ?></strong><span>Submissions</span></div></div>
    <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-users"></i></span><div><strong><?= number_format((int)($stats['contributor_count']??0)) ?></strong><span>Contributors</span></div></div>
    <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-calendar-day"></i></span><div><strong><?= number_format((int)($stats['today_count']??0)) ?></strong><span>Submitted today</span></div></div>
    <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-clock"></i></span><div><strong><?= !empty($stats['latest_at'])?htmlspecialchars(date('d M',strtotime($stats['latest_at']))):'-' ?></strong><span>Latest submission</span></div></div>
  </section>
  <form class="report-filter-panel" method="get">
    <div class="form-row">
      <?php if($isSuperAdmin): ?><div class="form-group col-lg-3 col-md-6"><label for="feedbackChurch">Church</label><select id="feedbackChurch" name="church_id" class="form-control"><option value="">All churches</option><?php foreach($churches as $church): ?><option value="<?= (int)$church['id'] ?>" <?= $filters['church_id']===(int)$church['id']?'selected':'' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="form-group col-lg-3 col-md-6"><label for="feedbackFrom">From date</label><input id="feedbackFrom" type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($filters['from_date']) ?>"></div>
      <div class="form-group col-lg-3 col-md-6"><label for="feedbackTo">To date</label><input id="feedbackTo" type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($filters['to_date']) ?>"></div>
      <div class="form-group col-lg-3 col-md-6"><label for="feedbackSearch">Search</label><input id="feedbackSearch" type="search" name="search" maxlength="100" class="form-control" placeholder="Member, CRN or message..." value="<?= htmlspecialchars($filters['search']) ?>"></div>
      <div class="form-group col-lg-2 col-md-4"><label for="feedbackRows">Rows</label><select id="feedbackRows" name="per_page" class="form-control"><?php foreach([25,50,100] as $option): ?><option value="<?= $option ?>" <?= $perPage===$option?'selected':'' ?>><?= $option ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="d-flex justify-content-end" style="gap:8px"><a class="btn btn-outline-secondary" href="feedback_report.php">Reset</a><button class="btn btn-primary" type="submit"><i class="fas fa-filter mr-1"></i>Apply filters</button></div>
  </form>
  <?php if($trendRows): ?><section class="card mb-3"><div class="card-header"><strong>Submission trend</strong></div><div class="card-body"><div class="feedback-trend"><?php foreach($trendRows as $trend): $height=max(5,(int)round(((int)$trend['event_count']/$trendMaximum)*100)); ?><div class="feedback-trend-column"><span><?= number_format((int)$trend['event_count']) ?></span><i style="height:<?= $height ?>%"></i><small><?= htmlspecialchars($trend['month_label']) ?></small></div><?php endforeach; ?></div></div></section><?php endif; ?>
  <section class="card"><div class="card-header d-flex justify-content-between align-items-center"><div><strong>Submitted feedback</strong><div class="small text-muted">Showing <?= $totalRows?number_format($offset+1):0 ?>-<?= number_format(min($offset+$perPage,$totalRows)) ?> of <?= number_format($totalRows) ?></div></div><span class="badge badge-light">Page <?= $page ?> of <?= $totalPages ?></span></div>
    <div class="table-responsive"><table class="table table-hover"><thead><tr><th>Submitted</th><th>Contributor</th><th>Church</th><th>Message</th></tr></thead><tbody>
      <?php if(!$rows): ?><tr><td colspan="4"><div class="report-empty-state"><i class="fas fa-comment-slash"></i>No feedback matches the selected filters.</div></td></tr><?php else: foreach($rows as $row): ?><tr><td class="text-nowrap"><strong><?= htmlspecialchars(date('d M Y',strtotime($row['submitted_at']))) ?></strong><div class="small text-muted"><?= htmlspecialchars(date('H:i',strtotime($row['submitted_at']))) ?></div></td><td><strong><?= htmlspecialchars($row['member_name']?:'Unknown member') ?></strong><div class="small text-muted"><?= htmlspecialchars($row['crn']?:'-') ?></div></td><td><?= htmlspecialchars($row['church_name']?:'-') ?></td><td class="feedback-message"><?= nl2br(htmlspecialchars($row['message']?:'No message recorded.')) ?></td></tr><?php endforeach; endif; ?>
    </tbody></table></div>
    <?php if($totalPages>1): ?><div class="card-footer d-flex justify-content-between align-items-center"><small class="text-muted"><?= number_format($totalRows) ?> submissions</small><nav><ul class="pagination pagination-sm"><li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= htmlspecialchars(feedback_report_query($baseQuery,['page'=>max(1,$page-1)])) ?>">Previous</a></li><?php for($number=max(1,$page-2);$number<=min($totalPages,$page+2);$number++): ?><li class="page-item <?= $number===$page?'active':'' ?>"><a class="page-link" href="?<?= htmlspecialchars(feedback_report_query($baseQuery,['page'=>$number])) ?>"><?= $number ?></a></li><?php endfor; ?><li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="?<?= htmlspecialchars(feedback_report_query($baseQuery,['page'=>min($totalPages,$page+1)])) ?>">Next</a></li></ul></nav></div><?php endif; ?>
  </section>
</main>
<style>.feedback-message{min-width:280px;max-width:620px;white-space:normal;line-height:1.55}.feedback-trend{display:flex;align-items:flex-end;gap:10px;height:190px;overflow-x:auto;padding:22px 3px 0}.feedback-trend-column{display:grid;grid-template-rows:20px 1fr 28px;align-items:end;min-width:58px;height:100%;flex:1;text-align:center}.feedback-trend-column>span{align-self:start;color:#637988;font-size:.68rem;font-weight:700}.feedback-trend-column>i{display:block;width:min(34px,75%);min-height:5px;margin:auto auto 0;border-radius:7px 7px 2px 2px;background:linear-gradient(180deg,#3a8b70,#173f5f)}.feedback-trend-column>small{align-self:center;color:#708492;font-size:.64rem;white-space:nowrap}@media(max-width:767.98px){.feedback-report-page .table{min-width:760px}}</style>
<?php $page_content=ob_get_clean(); include __DIR__.'/../../includes/layout.php'; ?>
