<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permissions_v2.php';
require_once __DIR__ . '/../../helpers/audit_report_helper.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$auditIsSuperAdmin = is_super_admin();
if (!$auditIsSuperAdmin && !has_permission('view_audit_report')) {
    http_response_code(403);
    include __DIR__ . '/../errors/403.php';
    exit;
}

$canExportAudit = $auditIsSuperAdmin || has_permission('export_audit_report');
$context = audit_report_context($conn, $_GET, $auditIsSuperAdmin);
$filters = $context['filters'];
$whereSql = $context['where_sql'];
$params = $context['params'];
$types = $context['types'];

$allowedPerPage = [25, 50, 100];
$perPage = (int) ($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}
$page = max(1, (int) ($_GET['page'] ?? 1));

$statsSql = "SELECT COUNT(*) AS total_events,
                    COUNT(DISTINCT a.user_id) AS actor_count,
                    COUNT(DISTINCT a.action) AS action_count,
                    SUM(DATE(a.created_at) = CURDATE()) AS today_count
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             {$whereSql}";
$statsStatement = $conn->prepare($statsSql);
audit_report_bind($statsStatement, $types, $params);
$statsStatement->execute();
$auditStats = $statsStatement->get_result()->fetch_assoc() ?: [];
$statsStatement->close();

$totalRows = (int) ($auditStats['total_events'] ?? 0);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$rowsSql = "SELECT a.id, a.user_id, a.action, a.entity_type, a.entity_id,
                   a.details, a.ip_address, a.created_at,
                   COALESCE(NULLIF(u.name, ''), 'System') AS user_name
            FROM audit_log a
            LEFT JOIN users u ON u.id = a.user_id
            {$whereSql}
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT ? OFFSET ?";
$rowParams = array_merge($params, [$perPage, $offset]);
$rowsStatement = $conn->prepare($rowsSql);
audit_report_bind($rowsStatement, $types . 'ii', $rowParams);
$rowsStatement->execute();
$auditRows = $rowsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$rowsStatement->close();

$trendSql = "SELECT DATE_FORMAT(a.created_at, '%Y-%m') AS month_key,
                    DATE_FORMAT(a.created_at, '%b %Y') AS month_label,
                    COUNT(*) AS event_count
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             {$whereSql}
             GROUP BY month_key, month_label
             ORDER BY month_key";
$trendStatement = $conn->prepare($trendSql);
audit_report_bind($trendStatement, $types, $params);
$trendStatement->execute();
$trendRows = $trendStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$trendStatement->close();
$trendRows = array_slice($trendRows, -12);
$maximumTrend = 1;
foreach ($trendRows as $trendRow) {
    $maximumTrend = max($maximumTrend, (int) $trendRow['event_count']);
}

$scopeClause = '';
$scopeTypes = '';
$scopeParams = [];
if (!$auditIsSuperAdmin) {
    $scopeClause = 'WHERE u.church_id = ?';
    $scopeTypes = 'i';
    $scopeParams = [(int) $context['actor_church_id']];
} elseif ($filters['church_id'] > 0) {
    $scopeClause = 'WHERE u.church_id = ?';
    $scopeTypes = 'i';
    $scopeParams = [$filters['church_id']];
}

$userOptionsStatement = $conn->prepare("SELECT DISTINCT u.id, u.name
    FROM users u
    JOIN audit_log a ON a.user_id = u.id
    {$scopeClause}
    ORDER BY u.name");
audit_report_bind($userOptionsStatement, $scopeTypes, $scopeParams);
$userOptionsStatement->execute();
$userOptions = $userOptionsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$userOptionsStatement->close();

$optionPrefix = $scopeClause === '' ? 'WHERE' : $scopeClause . ' AND';
$actionOptionsStatement = $conn->prepare("SELECT DISTINCT a.action
    FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
    {$optionPrefix} a.action IS NOT NULL AND a.action <> '' ORDER BY a.action");
audit_report_bind($actionOptionsStatement, $scopeTypes, $scopeParams);
$actionOptionsStatement->execute();
$actionOptions = $actionOptionsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$actionOptionsStatement->close();

$entityOptionsStatement = $conn->prepare("SELECT DISTINCT a.entity_type
    FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
    {$optionPrefix} a.entity_type IS NOT NULL AND a.entity_type <> '' ORDER BY a.entity_type");
audit_report_bind($entityOptionsStatement, $scopeTypes, $scopeParams);
$entityOptionsStatement->execute();
$entityOptions = $entityOptionsStatement->get_result()->fetch_all(MYSQLI_ASSOC);
$entityOptionsStatement->close();

$churchOptions = [];
if ($auditIsSuperAdmin) {
    $churchResult = $conn->query('SELECT id, name FROM churches ORDER BY name');
    $churchOptions = $churchResult ? $churchResult->fetch_all(MYSQLI_ASSOC) : [];
}

$baseQuery = $filters;
$baseQuery['per_page'] = $perPage;
$page_title = 'Audit & Security Report';
ob_start();
?>
<main class="report-page-shell audit-report-page">
    <header class="report-page-header">
        <div>
            <h1><i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>Audit &amp; Security Report</h1>
            <p>Trace accountable system activity using scoped actors, actions, entities, dates and recorded evidence.</p>
        </div>
        <div class="d-flex flex-wrap" style="gap:8px">
            <a href="<?= BASE_URL ?>/views/reports.php" class="btn btn-outline-light"><i class="fas fa-arrow-left mr-1"></i>Report Centre</a>
            <?php if ($canExportAudit): ?>
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-download mr-1"></i>Export
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="audit_report_export.php?<?= htmlspecialchars(audit_report_query($filters, ['format' => 'excel']), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-excel text-success mr-2"></i>Excel workbook</a>
                        <a class="dropdown-item" href="audit_report_export.php?<?= htmlspecialchars(audit_report_query($filters, ['format' => 'pdf']), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-pdf text-danger mr-2"></i>PDF document</a>
                        <a class="dropdown-item" href="audit_report_export.php?<?= htmlspecialchars(audit_report_query($filters, ['format' => 'csv']), ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-csv text-info mr-2"></i>CSV data</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <section class="report-stat-grid" aria-label="Filtered audit summary">
        <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-list"></i></span><div><strong><?= number_format($totalRows) ?></strong><span>Matching events</span></div></div>
        <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-calendar-day"></i></span><div><strong><?= number_format((int) ($auditStats['today_count'] ?? 0)) ?></strong><span>Events today</span></div></div>
        <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-user-shield"></i></span><div><strong><?= number_format((int) ($auditStats['actor_count'] ?? 0)) ?></strong><span>Distinct actors</span></div></div>
        <div class="report-stat-card"><span class="report-stat-icon"><i class="fas fa-fingerprint"></i></span><div><strong><?= number_format((int) ($auditStats['action_count'] ?? 0)) ?></strong><span>Action types</span></div></div>
    </section>

    <form class="report-filter-panel" method="get" action="audit_report.php">
        <div class="form-row">
            <?php if ($auditIsSuperAdmin): ?>
                <div class="form-group col-lg-3 col-md-6">
                    <label for="auditChurch">Church</label>
                    <select id="auditChurch" name="church_id" class="form-control">
                        <option value="">All churches</option>
                        <?php foreach ($churchOptions as $church): ?>
                            <option value="<?= (int) $church['id'] ?>" <?= $filters['church_id'] === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="form-group col-lg-3 col-md-6">
                <label for="auditUser">Actor</label>
                <select id="auditUser" name="user_id" class="form-control">
                    <option value="">All actors</option>
                    <?php foreach ($userOptions as $user): ?>
                        <option value="<?= (int) $user['id'] ?>" <?= $filters['user_id'] === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-3 col-md-6">
                <label for="auditAction">Action</label>
                <select id="auditAction" name="action" class="form-control">
                    <option value="">All actions</option>
                    <?php foreach ($actionOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option['action'], ENT_QUOTES, 'UTF-8') ?>" <?= $filters['action'] === $option['action'] ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $option['action'])), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-3 col-md-6">
                <label for="auditEntity">Entity</label>
                <select id="auditEntity" name="entity_type" class="form-control">
                    <option value="">All entities</option>
                    <?php foreach ($entityOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option['entity_type'], ENT_QUOTES, 'UTF-8') ?>" <?= $filters['entity_type'] === $option['entity_type'] ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $option['entity_type'])), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-lg-3 col-md-6"><label for="auditFrom">From date</label><input id="auditFrom" type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($filters['from_date'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group col-lg-3 col-md-6"><label for="auditTo">To date</label><input id="auditTo" type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($filters['to_date'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group col-lg-4 col-md-8"><label for="auditSearch">Search evidence</label><input id="auditSearch" type="search" name="search" class="form-control" maxlength="100" placeholder="Actor, action, entity, details or IP..." value="<?= htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="form-group col-lg-2 col-md-4"><label for="auditPerPage">Rows per page</label><select id="auditPerPage" name="per_page" class="form-control"><?php foreach ($allowedPerPage as $option): ?><option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="d-flex flex-wrap justify-content-end" style="gap:8px">
            <a class="btn btn-outline-secondary" href="audit_report.php"><i class="fas fa-undo mr-1"></i>Reset</a>
            <button class="btn btn-primary" type="submit"><i class="fas fa-filter mr-1"></i>Apply filters</button>
        </div>
    </form>

    <?php if ($trendRows): ?>
        <section class="card mb-3 audit-trend-card">
            <div class="card-header d-flex align-items-center justify-content-between"><strong>Activity trend</strong><small class="text-muted">Latest 12 matching months</small></div>
            <div class="card-body">
                <div class="audit-trend" role="img" aria-label="Monthly event activity trend">
                    <?php foreach ($trendRows as $trendRow):
                        $height = max(5, (int) round(((int) $trendRow['event_count'] / $maximumTrend) * 100));
                    ?>
                        <div class="audit-trend-column" title="<?= htmlspecialchars($trendRow['month_label'] . ': ' . number_format((int) $trendRow['event_count']) . ' events', ENT_QUOTES, 'UTF-8') ?>">
                            <span class="audit-trend-value"><?= number_format((int) $trendRow['event_count']) ?></span>
                            <span class="audit-trend-bar" style="height:<?= $height ?>%"></span>
                            <span class="audit-trend-label"><?= htmlspecialchars($trendRow['month_label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="card audit-record-card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <div><strong>Audit evidence</strong><div class="small text-muted">Showing <?= $totalRows ? number_format($offset + 1) : 0 ?>-<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?></div></div>
            <span class="badge badge-light">Page <?= $page ?> of <?= $totalPages ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover" data-report-pagination="server" aria-label="Audit evidence records">
                <thead><tr><th>Date and time</th><th>Actor</th><th>Action</th><th>Target</th><th>IP address</th><th class="text-right">Evidence</th></tr></thead>
                <tbody>
                <?php if (!$auditRows): ?>
                    <tr><td colspan="6"><div class="report-empty-state"><i class="fas fa-shield-alt"></i>No audit events match the selected filters.</div></td></tr>
                <?php else: ?>
                    <?php foreach ($auditRows as $row):
                        $detailsText = audit_report_details_text($row['details']);
                        $preview = function_exists('mb_strimwidth') ? mb_strimwidth(str_replace(["\r", "\n"], ' ', $detailsText), 0, 80, '...') : substr(str_replace(["\r", "\n"], ' ', $detailsText), 0, 77) . (strlen($detailsText) > 77 ? '...' : '');
                    ?>
                        <tr>
                            <td class="text-nowrap"><strong><?= htmlspecialchars(date('d M Y', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></strong><div class="small text-muted"><?= htmlspecialchars(date('H:i:s', strtotime($row['created_at'])), ENT_QUOTES, 'UTF-8') ?></div></td>
                            <td><strong><?= htmlspecialchars($row['user_name'], ENT_QUOTES, 'UTF-8') ?></strong><?php if ($row['user_id']): ?><div class="small text-muted">User #<?= (int) $row['user_id'] ?></div><?php endif; ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['action'])), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars(audit_report_entity_label($row['entity_type'], $row['entity_id']), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><code><?= htmlspecialchars($row['ip_address'] ?: '-', ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td class="text-right"><button type="button" class="btn btn-sm btn-outline-primary audit-detail-button" data-detail-id="audit-detail-<?= (int) $row['id'] ?>" title="<?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-eye mr-1"></i>View</button><pre id="audit-detail-<?= (int) $row['id'] ?>" hidden><?= htmlspecialchars($detailsText, ENT_QUOTES, 'UTF-8') ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer report-server-pagination d-flex flex-wrap align-items-center justify-content-between"
                 data-report-pagination-meta="true"
                 data-total-rows="<?= (int) $totalRows ?>"
                 data-total-pages="<?= (int) $totalPages ?>"
                 data-current-page="<?= (int) $page ?>"
                 data-per-page="<?= (int) $perPage ?>"
                 style="gap:10px">
                <small class="text-muted"><?= number_format($totalRows) ?> matching records</small>
                <nav aria-label="Audit report pages"><ul class="pagination pagination-sm">
                    <?php $previousQuery = audit_report_query($baseQuery, ['page' => max(1, $page - 1)]); $nextQuery = audit_report_query($baseQuery, ['page' => min($totalPages, $page + 1)]); ?>
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars($previousQuery, ENT_QUOTES, 'UTF-8') ?>">Previous</a></li>
                    <?php for ($number = max(1, $page - 2); $number <= min($totalPages, $page + 2); $number++): ?>
                        <li class="page-item <?= $number === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars(audit_report_query($baseQuery, ['page' => $number]), ENT_QUOTES, 'UTF-8') ?>"><?= $number ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>"><a class="page-link" href="?<?= htmlspecialchars($nextQuery, ENT_QUOTES, 'UTF-8') ?>">Next</a></li>
                </ul></nav>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade" id="auditDetailModal" tabindex="-1" role="dialog" aria-labelledby="auditDetailTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="auditDetailTitle"><i class="fas fa-fingerprint mr-2"></i>Recorded evidence</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
        <div class="modal-body"><pre id="auditDetailContent" class="audit-detail-content"></pre></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button></div>
    </div></div>
</div>

<style>
.audit-trend{display:flex;align-items:flex-end;gap:10px;height:205px;overflow-x:auto;padding:25px 4px 2px}.audit-trend-column{display:grid;grid-template-rows:20px 1fr 30px;align-items:end;min-width:54px;height:100%;flex:1;text-align:center}.audit-trend-value{align-self:start;color:#536b7d;font-size:.68rem;font-weight:700}.audit-trend-bar{display:block;width:min(34px,75%);min-height:5px;margin:0 auto;border-radius:7px 7px 2px 2px;background:linear-gradient(180deg,#2e7d69,#173f5f)}.audit-trend-label{align-self:center;color:#708492;font-size:.64rem;white-space:nowrap}.audit-record-card code{color:#3e596b;font-size:.76rem}.audit-detail-content{min-height:180px;margin:0;padding:17px;border-radius:10px;background:#102534;color:#e6f2f6;white-space:pre-wrap;word-break:break-word;font-size:.78rem}.audit-report-page .dropdown-menu{border:1px solid #dce5ea;border-radius:11px;box-shadow:0 14px 30px rgba(23,63,95,.14)}
@media(max-width:767.98px){.audit-trend-column{min-width:62px}.audit-record-card .card-header{align-items:flex-start!important;flex-direction:column}.audit-record-card .table{min-width:850px}}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.audit-detail-button'), function (button) {
        button.addEventListener('click', function () {
            var source = document.getElementById(button.getAttribute('data-detail-id'));
            document.getElementById('auditDetailContent').textContent = source ? source.textContent : 'No evidence was recorded.';
            if (window.jQuery && typeof window.jQuery.fn.modal === 'function') {
                window.jQuery('#auditDetailModal').modal('show');
            }
        });
    });
});
</script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../../includes/layout.php';
?>
