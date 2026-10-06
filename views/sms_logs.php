<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('view_sms_logs') && !has_permission('view_sms_report')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$smsLogColumns = [];
$schemaResult = $conn->query('SHOW COLUMNS FROM sms_logs');
while ($schemaRow = $schemaResult->fetch_assoc()) {
    $smsLogColumns[$schemaRow['Field']] = true;
}
$requiredAuditColumns = [
    'sundayschool_id', 'church_id', 'payment_id', 'type', 'sender',
    'provider_message_id', 'attempt_number', 'retry_of_sms_log_id',
    'attempted_by_user_id', 'error_message',
];
$missingAuditColumns = array_values(array_filter(
    $requiredAuditColumns,
    static fn($column) => !isset($smsLogColumns[$column])
));
$auditSchemaReady = !$missingAuditColumns;
$canResend = $auditSchemaReady && ($isSuperAdmin || has_permission('resend_sms'));
$canExport = $auditSchemaReady && ($isSuperAdmin || has_permission('export_sms_logs') || has_permission('export_sms_report'));
$churchId = (int) ($_SESSION['church_id'] ?? 0);
if (!$isSuperAdmin && $churchId <= 0 && !empty($_SESSION['user_id'])) {
    $scope = $conn->prepare('SELECT church_id FROM users WHERE id = ? LIMIT 1');
    $userId = (int) $_SESSION['user_id'];
    $scope->bind_param('i', $userId);
    $scope->execute();
    $churchId = (int) (($scope->get_result()->fetch_assoc()['church_id'] ?? 0));
    $scope->close();
}

$phone = substr(trim((string) ($_GET['phone'] ?? '')), 0, 30);
$sender = substr(trim((string) ($_GET['sender'] ?? '')), 0, 100);
$typeFilter = substr(trim((string) ($_GET['type'] ?? '')), 0, 50);
if (!isset($smsLogColumns['sender'])) {
    $sender = '';
}
if (!isset($smsLogColumns['type'])) {
    $typeFilter = '';
}
$statusFilter = in_array($_GET['status'] ?? '', ['sent', 'failed', 'other'], true)
    ? (string) $_GET['status']
    : '';
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_from'] ?? ''))
    ? (string) $_GET['date_from']
    : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['date_to'] ?? ''))
    ? (string) $_GET['date_to']
    : '';

$where = [];
$params = [];
$parameterTypes = '';
if (!$isSuperAdmin) {
    if (isset($smsLogColumns['church_id'])) {
        $where[] = 'log.church_id = ?';
        $params[] = $churchId;
        $parameterTypes .= 'i';
    } else {
        // The legacy table has no reliable church boundary. Never expose its
        // cross-church rows to an ordinary scoped user.
        $where[] = '1 = 0';
    }
}
if ($phone !== '') {
    $where[] = 'log.phone LIKE ?';
    $params[] = '%' . $phone . '%';
    $parameterTypes .= 's';
}
if ($sender !== '' && isset($smsLogColumns['sender'])) {
    $where[] = 'log.sender LIKE ?';
    $params[] = '%' . $sender . '%';
    $parameterTypes .= 's';
}
if ($typeFilter !== '' && isset($smsLogColumns['type'])) {
    $where[] = 'log.type = ?';
    $params[] = $typeFilter;
    $parameterTypes .= 's';
}
if ($statusFilter === 'sent') {
    $where[] = "LOWER(TRIM(COALESCE(log.status, ''))) IN ('sent','success')";
} elseif ($statusFilter === 'failed') {
    $where[] = "(LOWER(COALESCE(log.status, '')) LIKE '%fail%' OR LOWER(COALESCE(log.status, '')) LIKE '%error%')";
} elseif ($statusFilter === 'other') {
    $where[] = "LOWER(TRIM(COALESCE(log.status, ''))) NOT IN ('sent','success','fail','failed','error')";
}
if ($dateFrom !== '') {
    $where[] = 'DATE(log.sent_at) >= ?';
    $params[] = $dateFrom;
    $parameterTypes .= 's';
}
if ($dateTo !== '') {
    $where[] = 'DATE(log.sent_at) <= ?';
    $params[] = $dateTo;
    $parameterTypes .= 's';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$bindStatement = static function (mysqli_stmt $statement, string $types, array &$values): void {
    if ($types === '') {
        return;
    }
    $bind = [$types];
    foreach ($values as $index => $value) {
        $values[$index] = $value;
        $bind[] = &$values[$index];
    }
    call_user_func_array([$statement, 'bind_param'], $bind);
};

$retryCountExpression = isset($smsLogColumns['retry_of_sms_log_id'])
    ? 'SUM(log.retry_of_sms_log_id IS NOT NULL)'
    : '0';
$paymentCountExpression = isset($smsLogColumns['payment_id'])
    ? 'SUM(log.payment_id IS NOT NULL)'
    : '0';
$statsSql = "SELECT COUNT(*) AS total_count,
                    SUM(LOWER(TRIM(COALESCE(log.status, ''))) IN ('sent','success')) AS sent_count,
                    SUM(LOWER(COALESCE(log.status, '')) LIKE '%fail%'
                        OR LOWER(COALESCE(log.status, '')) LIKE '%error%') AS failed_count,
                    {$retryCountExpression} AS retry_count,
                    {$paymentCountExpression} AS payment_count
               FROM sms_logs log" . $whereSql;
$statsStatement = $conn->prepare($statsSql);
$statsParams = $params;
$bindStatement($statsStatement, $parameterTypes, $statsParams);
$statsStatement->execute();
$stats = $statsStatement->get_result()->fetch_assoc() ?: [];
$statsStatement->close();
$totalCount = (int) ($stats['total_count'] ?? 0);
$sentCount = (int) ($stats['sent_count'] ?? 0);
$failedCount = (int) ($stats['failed_count'] ?? 0);
$retryCount = (int) ($stats['retry_count'] ?? 0);
$paymentCount = (int) ($stats['payment_count'] ?? 0);
$deliveryRate = $totalCount > 0 ? ($sentCount / $totalCount) * 100 : 0;

$memberNameExpression = "NULLIF(TRIM(CONCAT_WS(' ', member.first_name, member.middle_name, member.last_name)), '')";
$recipientNameExpression = $memberNameExpression;
$recipientReferenceExpression = 'member.crn';
$rowJoins = ' LEFT JOIN members member ON member.id = log.member_id';
if (isset($smsLogColumns['sundayschool_id'])) {
    $childNameExpression = "NULLIF(TRIM(CONCAT_WS(' ', child.first_name, child.middle_name, child.last_name)), '')";
    $recipientNameExpression = "COALESCE({$memberNameExpression}, {$childNameExpression})";
    $recipientReferenceExpression = 'COALESCE(member.crn, child.srn)';
    $rowJoins .= ' LEFT JOIN sunday_school child ON child.id = log.sundayschool_id';
}
$actorNameExpression = 'NULL';
if (isset($smsLogColumns['attempted_by_user_id'])) {
    $actorNameExpression = 'actor.name';
    $rowJoins .= ' LEFT JOIN users actor ON actor.id = log.attempted_by_user_id';
}
$churchNameExpression = 'NULL';
if (isset($smsLogColumns['church_id'])) {
    $churchNameExpression = 'church.name';
    $rowJoins .= ' LEFT JOIN churches church ON church.id = log.church_id';
}
$rowsSql = "SELECT log.*,
                   {$recipientNameExpression} AS recipient_name,
                   {$recipientReferenceExpression} AS recipient_reference,
                   {$actorNameExpression} AS attempted_by_name,
                   {$churchNameExpression} AS church_name
              FROM sms_logs log"
    . $rowJoins
    . $whereSql
    . ' ORDER BY log.sent_at DESC, log.id DESC LIMIT 2000';
$rowsStatement = $conn->prepare($rowsSql);
$rowParams = $params;
$bindStatement($rowsStatement, $parameterTypes, $rowParams);
$rowsStatement->execute();
$result = $rowsStatement->get_result();
$smsLogs = [];
while ($row = $result->fetch_assoc()) {
    $smsLogs[] = $row;
}
$rowsStatement->close();

$availableTypes = [];
if (isset($smsLogColumns['type'])) {
    $typeSql = 'SELECT DISTINCT log.type FROM sms_logs log WHERE log.type IS NOT NULL AND TRIM(log.type) <> ?';
    $typeParams = [''];
    $typeTypes = 's';
    if (!$isSuperAdmin) {
        if (isset($smsLogColumns['church_id'])) {
            $typeSql .= ' AND log.church_id = ?';
            $typeParams[] = $churchId;
            $typeTypes .= 'i';
        } else {
            $typeSql .= ' AND 1 = 0';
        }
    }
    $typeSql .= ' ORDER BY log.type';
    $typeStatement = $conn->prepare($typeSql);
    $bindStatement($typeStatement, $typeTypes, $typeParams);
    $typeStatement->execute();
    $typeResult = $typeStatement->get_result();
    while ($typeRow = $typeResult->fetch_assoc()) {
        $availableTypes[] = (string) $typeRow['type'];
    }
    $typeStatement->close();
}

$hasFilters = $phone !== '' || $sender !== '' || $typeFilter !== '' || $statusFilter !== ''
    || $dateFrom !== '' || $dateTo !== '';
$filterQuery = array_filter([
    'phone' => $phone,
    'sender' => $sender,
    'type' => $typeFilter,
    'status' => $statusFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], static fn($value) => $value !== '');
$page_title = 'SMS Delivery Centre';

// Buffer only the rendered page body. Authentication, redirects, schema
// checks and database failures above must remain outside an output buffer.
$smsPageBufferLevel = ob_get_level();
ob_start();
?>
<style>
.sms-audit-page{--sms-indigo:#4f46e5;--sms-violet:#7c3aed;--sms-green:#059669;--sms-red:#dc2626;--sms-amber:#d97706;--sms-ink:#172033;--sms-muted:#64748b;--sms-line:#e5eaf1;--sms-bg:#f6f8fc;color:var(--sms-ink)}
.sms-hero{background:linear-gradient(135deg,#3730a3 0%,var(--sms-indigo) 48%,var(--sms-violet) 100%);border-radius:20px;padding:1.6rem 1.75rem;color:#fff;box-shadow:0 16px 38px rgba(79,70,229,.22);position:relative;overflow:hidden}.sms-hero:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.1);right:-70px;top:-95px}.sms-hero h1{font-size:1.55rem;font-weight:800;margin:0}.sms-hero p{color:rgba(255,255,255,.82);margin:.35rem 0 0;max-width:700px}.sms-hero-icon{width:52px;height:52px;border-radius:16px;background:rgba(255,255,255,.17);display:flex;align-items:center;justify-content:center;font-size:1.4rem;border:1px solid rgba(255,255,255,.22)}
.sms-action-btn{border-radius:999px;background:#fff;color:#3730a3!important;border:0;font-weight:700;padding:.62rem 1rem;box-shadow:0 5px 14px rgba(30,41,59,.18);position:relative;z-index:1}.sms-action-btn:hover{background:#f8fafc;transform:translateY(-1px)}
.sms-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:1rem;margin:1rem 0}.sms-metric{background:#fff;border:1px solid var(--sms-line);border-radius:16px;padding:1rem 1.05rem;box-shadow:0 7px 20px rgba(15,23,42,.055);display:flex;align-items:center;gap:.8rem;min-width:0}.sms-metric-icon{width:42px;height:42px;flex:0 0 42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1rem}.metric-indigo .sms-metric-icon{background:#eef2ff;color:var(--sms-indigo)}.metric-green .sms-metric-icon{background:#ecfdf5;color:var(--sms-green)}.metric-red .sms-metric-icon{background:#fef2f2;color:var(--sms-red)}.metric-amber .sms-metric-icon{background:#fffbeb;color:var(--sms-amber)}.metric-slate .sms-metric-icon{background:#f1f5f9;color:#475569}.sms-metric-label{display:block;color:var(--sms-muted);font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}.sms-metric-value{display:block;font-size:1.35rem;font-weight:800;line-height:1.15;white-space:nowrap}
.sms-panel{background:#fff;border:1px solid var(--sms-line);border-radius:18px;box-shadow:0 9px 26px rgba(15,23,42,.06);overflow:hidden}.sms-filter-head,.sms-table-head{padding:1rem 1.2rem;border-bottom:1px solid var(--sms-line);display:flex;align-items:center;justify-content:space-between;gap:1rem}.sms-filter-head h2,.sms-table-head h2{font-size:1rem;font-weight:800;margin:0}.sms-filter-body{padding:1rem 1.2rem}.sms-filter-body label{font-size:.72rem;text-transform:uppercase;letter-spacing:.045em;color:var(--sms-muted);font-weight:800}.sms-filter-body .form-control{border-radius:11px;border-color:#dbe2ea;min-height:42px}.sms-filter-body .form-control:focus{border-color:#818cf8;box-shadow:0 0 0 .18rem rgba(99,102,241,.13)}.sms-filter-actions .btn{border-radius:11px;min-height:42px;font-weight:700}.sms-filter-chip{display:inline-flex;align-items:center;border-radius:999px;background:#eef2ff;color:#4338ca;padding:.3rem .65rem;font-size:.75rem;font-weight:700;margin:.2rem .2rem 0 0}
.sms-table{margin:0}.sms-table thead th{border:0;background:#f8fafc;color:#64748b;font-size:.7rem;text-transform:uppercase;letter-spacing:.045em;font-weight:800;padding:.85rem .75rem;white-space:nowrap}.sms-table tbody td{border-color:#edf0f5;padding:.85rem .75rem;vertical-align:middle}.sms-table tbody tr:hover{background:#fafbff}.sms-recipient{display:flex;align-items:center;gap:.65rem;min-width:185px}.sms-avatar{width:38px;height:38px;flex:0 0 38px;border-radius:12px;background:linear-gradient(135deg,#e0e7ff,#ede9fe);color:#4338ca;display:flex;align-items:center;justify-content:center;font-weight:800}.sms-recipient-name{display:block;font-weight:750;color:var(--sms-ink);max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.sms-recipient-meta{display:block;color:var(--sms-muted);font-size:.76rem}.sms-message-preview{max-width:330px}.sms-message-text{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.35;color:#334155}.sms-context{font-size:.72rem;color:var(--sms-muted);margin-top:.35rem}.sms-pill{display:inline-flex;align-items:center;gap:.3rem;border-radius:999px;padding:.34rem .62rem;font-size:.73rem;font-weight:800;white-space:nowrap}.sms-pill-success{background:#ecfdf5;color:#047857}.sms-pill-danger{background:#fef2f2;color:#b91c1c}.sms-pill-neutral{background:#f1f5f9;color:#475569}.sms-pill-retry{background:#fff7ed;color:#c2410c}.sms-row-actions{display:flex;justify-content:flex-end;gap:.4rem}.sms-icon-btn{width:35px;height:35px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;padding:0}.sms-empty{padding:4rem 1rem;text-align:center;color:var(--sms-muted)}.sms-empty-icon{width:74px;height:74px;border-radius:22px;background:#eef2ff;color:var(--sms-indigo);display:flex;align-items:center;justify-content:center;font-size:1.65rem;margin:0 auto 1rem}.sms-result-note{font-size:.78rem;color:var(--sms-muted)}
.dataTables_wrapper{padding:1rem 1.2rem}.dataTables_wrapper .row:first-child{align-items:center}.dataTables_wrapper .dataTables_filter input,.dataTables_wrapper .dataTables_length select{border:1px solid #dbe2ea;border-radius:9px;padding:.35rem .55rem}.dataTables_wrapper .page-link{border-radius:8px!important;margin:0 2px;border:0;color:#475569}.dataTables_wrapper .page-item.active .page-link{background:var(--sms-indigo)}
.sms-modal-icon{width:54px;height:54px;border-radius:16px;background:#fff7ed;color:var(--sms-amber);display:flex;align-items:center;justify-content:center;font-size:1.25rem}.sms-toast{position:fixed;right:20px;bottom:20px;z-index:1080;min-width:300px;max-width:420px;border-radius:14px;box-shadow:0 16px 35px rgba(15,23,42,.2);display:none}
@media(max-width:1199.98px){.sms-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:767.98px){.sms-hero{padding:1.25rem;border-radius:16px}.sms-hero h1{font-size:1.25rem}.sms-hero-actions{margin-top:1rem}.sms-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}.sms-metric{padding:.85rem}.sms-panel{border-radius:14px}.sms-table-head{align-items:flex-start;flex-direction:column}.dataTables_wrapper{padding:.75rem}.sms-message-preview{min-width:240px}}@media(max-width:420px){.sms-metrics{grid-template-columns:1fr}.sms-metric{min-height:72px}.sms-action-btn{width:100%;text-align:center}}
</style>

<main class="sms-audit-page container-fluid py-4 animate__animated animate__fadeIn">
    <section class="sms-hero">
        <div class="d-lg-flex align-items-center justify-content-between position-relative" style="z-index:1">
            <div class="d-flex align-items-center">
                <span class="sms-hero-icon mr-3"><i class="fas fa-comment-dots"></i></span>
                <div>
                    <h1>SMS Delivery Centre</h1>
                    <p>Monitor provider outcomes, payment receipts and retry evidence from one church-scoped workspace.</p>
                </div>
            </div>
            <div class="sms-hero-actions d-flex flex-wrap align-items-center" style="gap:.5rem">
                <?php if ($canExport): ?>
                    <a href="export_sms_logs.php?<?=htmlspecialchars(http_build_query($filterQuery))?>" class="btn sms-action-btn"><i class="fas fa-file-export mr-1"></i>Export current view</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if (!$auditSchemaReady): ?>
        <div class="alert alert-warning border-0 shadow-sm mt-3 mb-0">
            <div class="d-flex align-items-start">
                <i class="fas fa-tools fa-lg mr-3 mt-1"></i>
                <div>
                    <strong>SMS delivery audit upgrade pending</strong>
                    <div class="small mt-1">
                        This page is running in safe legacy mode. Apply database Phase 0056 to enable church-scoped evidence,
                        payment links, exports and failed-message retries.
                    </div>
                    <?php if ($isSuperAdmin): ?>
                        <div class="small mt-1 text-monospace">Missing: <?=htmlspecialchars(implode(', ', $missingAuditColumns))?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <section class="sms-metrics" aria-label="SMS delivery summary">
        <div class="sms-metric metric-indigo"><span class="sms-metric-icon"><i class="fas fa-paper-plane"></i></span><span><span class="sms-metric-label">Attempts</span><span class="sms-metric-value"><?=number_format($totalCount)?></span></span></div>
        <div class="sms-metric metric-green"><span class="sms-metric-icon"><i class="fas fa-check"></i></span><span><span class="sms-metric-label">Delivered</span><span class="sms-metric-value"><?=number_format($sentCount)?></span></span></div>
        <div class="sms-metric metric-red"><span class="sms-metric-icon"><i class="fas fa-exclamation"></i></span><span><span class="sms-metric-label">Failed</span><span class="sms-metric-value"><?=number_format($failedCount)?></span></span></div>
        <div class="sms-metric metric-amber"><span class="sms-metric-icon"><i class="fas fa-redo"></i></span><span><span class="sms-metric-label">Retries</span><span class="sms-metric-value"><?=number_format($retryCount)?></span></span></div>
        <div class="sms-metric metric-slate"><span class="sms-metric-icon"><i class="fas fa-chart-line"></i></span><span><span class="sms-metric-label">Delivery rate</span><span class="sms-metric-value"><?=number_format($deliveryRate, 1)?>%</span></span></div>
    </section>

    <?php if (($_GET['retry'] ?? '') === 'success'): ?>
        <div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle mr-2"></i>The retry was accepted and recorded as a new delivery attempt.</div>
    <?php endif; ?>

    <section class="sms-panel mb-3">
        <div class="sms-filter-head">
            <h2><i class="fas fa-sliders-h text-primary mr-2"></i>Filter delivery evidence</h2>
            <?php if ($hasFilters): ?><a href="sms_logs.php" class="small font-weight-bold"><i class="fas fa-times mr-1"></i>Clear all</a><?php endif; ?>
        </div>
        <div class="sms-filter-body">
            <form method="get">
                <div class="form-row">
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-phone">Recipient</label><input id="sms-phone" class="form-control" name="phone" placeholder="Phone number" value="<?=htmlspecialchars($phone)?>"></div>
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-sender">Sender ID</label><input id="sms-sender" class="form-control" name="sender" placeholder="e.g. MyFreeman" value="<?=htmlspecialchars($sender)?>" <?=isset($smsLogColumns['sender']) ? '' : 'disabled'?>></div>
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-type">Message type</label><select id="sms-type" class="form-control" name="type" <?=isset($smsLogColumns['type']) ? '' : 'disabled'?>><option value="">All message types</option><?php foreach ($availableTypes as $availableType): ?><option value="<?=htmlspecialchars($availableType)?>" <?=$typeFilter === $availableType ? 'selected' : ''?>><?=htmlspecialchars(ucwords(str_replace('_', ' ', $availableType)))?></option><?php endforeach; ?></select></div>
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-status">Outcome</label><select id="sms-status" class="form-control" name="status"><option value="">All outcomes</option><option value="sent" <?=$statusFilter === 'sent' ? 'selected' : ''?>>Delivered</option><option value="failed" <?=$statusFilter === 'failed' ? 'selected' : ''?>>Failed</option><option value="other" <?=$statusFilter === 'other' ? 'selected' : ''?>>Other / pending</option></select></div>
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-from">From</label><input id="sms-from" type="date" class="form-control" name="date_from" value="<?=htmlspecialchars($dateFrom)?>"></div>
                    <div class="form-group col-sm-6 col-lg-2"><label for="sms-to">To</label><input id="sms-to" type="date" class="form-control" name="date_to" value="<?=htmlspecialchars($dateTo)?>"></div>
                </div>
                <div class="sms-filter-actions d-flex flex-wrap align-items-center justify-content-between" style="gap:.7rem">
                    <div>
                        <?php if ($phone !== ''): ?><span class="sms-filter-chip"><i class="fas fa-phone mr-1"></i><?=htmlspecialchars($phone)?></span><?php endif; ?>
                        <?php if ($typeFilter !== ''): ?><span class="sms-filter-chip"><i class="fas fa-tag mr-1"></i><?=htmlspecialchars(ucwords(str_replace('_', ' ', $typeFilter)))?></span><?php endif; ?>
                        <?php if ($dateFrom !== '' || $dateTo !== ''): ?><span class="sms-filter-chip"><i class="far fa-calendar mr-1"></i><?=htmlspecialchars($dateFrom ?: 'Beginning')?> – <?=htmlspecialchars($dateTo ?: 'Today')?></span><?php endif; ?>
                    </div>
                    <button class="btn btn-primary px-4" type="submit"><i class="fas fa-search mr-1"></i>Apply filters</button>
                </div>
            </form>
        </div>
    </section>

    <section class="sms-panel">
        <div class="sms-table-head">
            <div><h2>Delivery attempts</h2><div class="sms-result-note"><?=number_format($totalCount)?> matching record<?=$totalCount === 1 ? '' : 's'?><?php if ($totalCount > count($smsLogs)): ?> · newest <?=number_format(count($smsLogs))?> shown<?php endif; ?> · <?=number_format($paymentCount)?> payment-linked</div></div>
            <div class="small text-muted"><i class="fas fa-shield-alt mr-1"></i><?= $isSuperAdmin ? 'All authorized churches' : 'Your church only' ?></div>
        </div>
        <?php if (!$smsLogs): ?>
            <div class="sms-empty"><div class="sms-empty-icon"><i class="far fa-comment-dots"></i></div><h5>No delivery attempts found</h5><p class="mb-2">Try changing the filters or date range.</p><?php if ($hasFilters): ?><a class="btn btn-outline-primary btn-sm" href="sms_logs.php">Reset filters</a><?php endif; ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table id="smsLogsTable" class="table sms-table">
                    <thead><tr><th>Sent at</th><th>Recipient</th><th>Message</th><th>Delivery</th><th>Attempt</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($smsLogs as $row):
                        $rowStatus = strtolower(trim((string) ($row['status'] ?? '')));
                        $failed = strpos($rowStatus, 'fail') !== false || strpos($rowStatus, 'error') !== false;
                        $delivered = in_array($rowStatus, ['sent', 'success'], true);
                        $recipientName = trim((string) ($row['recipient_name'] ?? '')) ?: 'Unlinked recipient';
                        $initial = function_exists('mb_substr') ? mb_substr($recipientName, 0, 1) : substr($recipientName, 0, 1);
                        $sentAt = strtotime((string) ($row['sent_at'] ?? ''));
                    ?>
                        <tr>
                            <td data-order="<?=htmlspecialchars((string) ($row['sent_at'] ?? ''))?>"><strong><?=htmlspecialchars($sentAt ? date('M j, Y', $sentAt) : 'Unknown')?></strong><span class="sms-recipient-meta"><?=htmlspecialchars($sentAt ? date('g:i A', $sentAt) : '')?></span><?php if ($isSuperAdmin && !empty($row['church_name'])): ?><span class="sms-recipient-meta"><?=htmlspecialchars((string) $row['church_name'])?></span><?php endif; ?></td>
                            <td><div class="sms-recipient"><span class="sms-avatar"><?=htmlspecialchars(strtoupper($initial ?: '?'))?></span><span class="min-w-0"><span class="sms-recipient-name" title="<?=htmlspecialchars($recipientName)?>"><?=htmlspecialchars($recipientName)?></span><span class="sms-recipient-meta"><?=htmlspecialchars((string) $row['phone'])?><?php if (!empty($row['recipient_reference'])): ?> · <?=htmlspecialchars((string) $row['recipient_reference'])?><?php endif; ?></span></span></div></td>
                            <td><div class="sms-message-preview"><span class="sms-message-text" title="<?=htmlspecialchars((string) $row['message'])?>"><?=htmlspecialchars((string) $row['message'])?></span><div class="sms-context"><i class="fas fa-tag mr-1"></i><?=htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($row['type'] ?: 'general'))))?><?php if (!empty($row['payment_id'])): ?> · Payment #<?=intval($row['payment_id'])?><?php endif; ?></div></div></td>
                            <td><?php if ($delivered): ?><span class="sms-pill sms-pill-success"><i class="fas fa-check-circle"></i>Delivered</span><?php elseif ($failed): ?><span class="sms-pill sms-pill-danger"><i class="fas fa-exclamation-circle"></i>Failed</span><?php else: ?><span class="sms-pill sms-pill-neutral"><i class="fas fa-clock"></i><?=htmlspecialchars(ucfirst($rowStatus ?: 'Unknown'))?></span><?php endif; ?><span class="sms-recipient-meta mt-1"><?=htmlspecialchars(ucfirst((string) ($row['provider'] ?: 'Unknown provider')))?><?php if (!empty($row['provider_message_id'])): ?> · ID recorded<?php endif; ?></span></td>
                            <td><strong>#<?=intval($row['attempt_number'] ?? 1)?></strong><?php if (!empty($row['retry_of_sms_log_id'])): ?><div><span class="sms-pill sms-pill-retry mt-1"><i class="fas fa-redo"></i>Retry of #<?=intval($row['retry_of_sms_log_id'])?></span></div><?php endif; ?><?php if (!empty($row['attempted_by_name'])): ?><span class="sms-recipient-meta mt-1">by <?=htmlspecialchars((string) $row['attempted_by_name'])?></span><?php endif; ?></td>
                            <td><div class="sms-row-actions"><?php if ($auditSchemaReady): ?><a class="btn btn-outline-primary sms-icon-btn" href="sms_log.php?id=<?=intval($row['id'])?>" title="View delivery evidence" aria-label="View delivery evidence"><i class="fas fa-eye"></i></a><?php else: ?><span class="btn btn-outline-secondary sms-icon-btn disabled" title="Apply Phase 0056 to view evidence"><i class="fas fa-lock"></i></span><?php endif; ?><?php if ($canResend && $failed): ?><button type="button" class="btn btn-outline-warning sms-icon-btn resend-sms-btn" data-log-id="<?=intval($row['id'])?>" data-recipient="<?=htmlspecialchars($recipientName)?>" data-phone="<?=htmlspecialchars((string) $row['phone'])?>" title="Retry failed SMS" aria-label="Retry failed SMS"><i class="fas fa-redo"></i></button><?php endif; ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>

<div class="modal fade" id="smsRetryModal" tabindex="-1" role="dialog" aria-labelledby="smsRetryTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden"><div class="modal-body p-4"><div class="d-flex align-items-start"><span class="sms-modal-icon mr-3"><i class="fas fa-redo"></i></span><div><h5 id="smsRetryTitle" class="font-weight-bold mb-1">Retry failed SMS?</h5><p class="text-muted mb-2">This creates a new provider attempt and keeps the original failure as evidence.</p><div class="small bg-light rounded p-2"><strong id="smsRetryRecipient">Recipient</strong><br><span id="smsRetryPhone" class="text-muted"></span></div></div></div></div><div class="modal-footer border-0 bg-light"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button><button type="button" class="btn btn-warning font-weight-bold" id="confirmSmsRetry"><i class="fas fa-paper-plane mr-1"></i>Retry SMS</button></div></div></div>
</div>
<div class="alert sms-toast" id="smsToast" role="status" aria-live="polite"></div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
<script>
$(function() {
    var csrfToken = <?=json_encode(csrf_token())?>;
    var selectedLogId = 0;
    var table = $('#smsLogsTable');
    if (table.length) {
        table.DataTable({order:[[0,'desc']],pageLength:25,lengthMenu:[10,25,50,100],stateSave:false,language:{search:'Search loaded records:',lengthMenu:'Show _MENU_',info:'Showing _START_–_END_ of _TOTAL_ loaded attempts',emptyTable:'No delivery attempts found'}});
    }
    function showToast(message, success) {
        $('#smsToast').removeClass('alert-success alert-danger').addClass(success ? 'alert-success' : 'alert-danger').html('<i class="fas ' + (success ? 'fa-check-circle' : 'fa-exclamation-circle') + ' mr-2"></i>' + $('<div>').text(message).html()).stop(true,true).fadeIn(180).delay(4000).fadeOut(300);
    }
    $(document).on('click', '.resend-sms-btn', function() {
        selectedLogId = Number($(this).data('log-id')) || 0;
        $('#smsRetryRecipient').text($(this).data('recipient') || 'Recipient');
        $('#smsRetryPhone').text($(this).data('phone') || '');
        $('#smsRetryModal').modal('show');
    });
    $('#confirmSmsRetry').on('click', function() {
        if (!selectedLogId) return;
        var button = $(this), original = button.html();
        button.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Sending');
        $.ajax({url:'ajax_resend_sms.php',method:'POST',dataType:'json',data:{id:selectedLogId,csrf_token:csrfToken}})
            .done(function(response) {
                $('#smsRetryModal').modal('hide');
                showToast(response.message || 'SMS resent and logged.', true);
                window.setTimeout(function(){ window.location.href='sms_logs.php?retry=success'; }, 700);
            })
            .fail(function(xhr) {
                var response = xhr.responseJSON || {};
                $('#smsRetryModal').modal('hide');
                showToast(response.error || 'The SMS retry failed.', false);
            })
            .always(function() { button.prop('disabled', false).html(original); selectedLogId = 0; });
    });
});
</script>
<?php
$page_content = ob_get_level() > $smsPageBufferLevel ? ob_get_clean() : '';
include __DIR__ . '/../includes/layout.php';
