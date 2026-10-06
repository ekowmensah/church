<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/report_pagination.php';
require_once __DIR__ . '/../services/ChequePaymentVerificationService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('verify_cheque_payments')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$service = new ChequePaymentVerificationService($conn, (int) $_SESSION['user_id'], $isSuperAdmin);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'review');
        $decision = (string) ($_POST['decision'] ?? '');
        try {
            if ($action === 'correct_evidence') {
                $service->correctEvidence(
                    (int) ($_POST['payment_id'] ?? 0),
                    (string) ($_POST['bank_name'] ?? ''),
                    (string) ($_POST['cheque_number'] ?? ''),
                    (string) ($_POST['correction_reason'] ?? '')
                );
                $resultCode = 'evidence_updated';
            } elseif ($action === 'review') {
                $service->review(
                    (int) ($_POST['payment_id'] ?? 0),
                    $decision,
                    (string) ($_POST['review_notes'] ?? '')
                );
                $resultCode = $decision === 'verified' ? 'verified' : 'rejected';
            } else {
                throw new InvalidArgumentException('Choose a valid cheque workflow action.');
            }
            header('Location: cheque_payment_verification.php?result=' . $resultCode);
            exit;
        } catch (mysqli_sql_exception $exception) {
            error_log('Cheque approval persistence failed: ' . $exception->getMessage());
            $error = 'The cheque decision could not be saved. Please try again.';
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Cheque approval failed: ' . $exception->getMessage());
            $error = 'The cheque decision could not be completed.';
        }
    }
}

$allowedStatuses = ['pending', 'verified', 'rejected', 'all'];
$status = strtolower(trim((string) ($_GET['status'] ?? 'pending')));
if (!in_array($status, $allowedStatuses, true)) $status = 'pending';
$search = mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 120);
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$churchId = $isSuperAdmin ? max(0, (int) ($_GET['church_id'] ?? 0)) : 0;
$perPage = report_pagination_page_size($_GET['per_page'] ?? null, [25, 50, 100], 25);
$page = max(1, (int) ($_GET['page'] ?? 1));

$result = $service->search([
    'status' => $status,
    'search' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'church_id' => $churchId,
], $page, $perPage);
$rows = $result['rows'];
$page = $result['page'];
$summary = $service->summary($churchId);
$churches = $service->listChurches();

$resultMessages = [
    'verified' => 'Cheque approved and posted as a completed payment.',
    'rejected' => 'Cheque rejected and retained in the decision history.',
    'evidence_updated' => 'Cheque evidence corrected. The cheque remains pending until it is reviewed and approved.',
];
$success = $resultMessages[(string) ($_GET['result'] ?? '')] ?? '';
$statusMeta = [
    'pending' => ['label' => 'Pending review', 'class' => 'warning', 'icon' => 'clock'],
    'verified' => ['label' => 'Approved', 'class' => 'success', 'icon' => 'check-circle'],
    'rejected' => ['label' => 'Rejected', 'class' => 'danger', 'icon' => 'times-circle'],
    'all' => ['label' => 'All cheques', 'class' => 'secondary', 'icon' => 'list'],
];
$tabUrl = static function (string $tab): string {
    $query = $_GET;
    $query['status'] = $tab;
    $query['page'] = 1;
    unset($query['result']);
    return '?' . http_build_query($query);
};
$formatDate = static function ($value, string $format = 'd M Y'): string {
    if (!$value) return '—';
    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : (string) $value;
};

ob_start();
?>
<style>
.cheque-workspace { --cheque-navy:#173f5f; --cheque-green:#236b45; }
.cheque-hero { background:linear-gradient(135deg,#132f49 0%,#1d5875 58%,#236b45 100%); color:#fff; border-radius:18px; padding:1.6rem; box-shadow:0 14px 34px rgba(23,63,95,.2); }
.cheque-hero .eyebrow { font-size:.72rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; opacity:.78; }
.cheque-summary { border:0; border-radius:14px; box-shadow:0 7px 20px rgba(21,42,67,.07); height:100%; }
.cheque-summary .summary-icon { width:44px; height:44px; display:grid; place-items:center; border-radius:12px; font-size:1.1rem; }
.cheque-filter, .cheque-table-card { border:0; border-radius:16px; box-shadow:0 7px 22px rgba(21,42,67,.07); }
.cheque-tabs { display:flex; flex-wrap:wrap; gap:.5rem; }
.cheque-tabs .btn { border-radius:999px; font-weight:700; padding:.48rem .9rem; }
.cheque-table { min-width:1120px; }
.cheque-table thead th { border-top:0; border-bottom:1px solid #dfe6ec; color:#526273; font-size:.72rem; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap; }
.cheque-table td { vertical-align:top; }
.cheque-reference { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-weight:700; }
.evidence-action { margin-top:.65rem; }
.decision-panel { min-width:275px; }
.decision-panel textarea { resize:vertical; min-height:64px; }
.status-badge { border-radius:999px; padding:.42rem .68rem; font-weight:700; }
.detail-label { color:#7b8794; display:block; font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
.empty-cheques { padding:4rem 1rem; text-align:center; }
.empty-cheques .empty-icon { width:68px; height:68px; display:grid; place-items:center; border-radius:50%; background:#e8f5ee; color:#236b45; font-size:1.7rem; margin:0 auto 1rem; }
@media (max-width:767.98px) { .cheque-hero { padding:1.25rem; } .cheque-hero h1 { font-size:1.55rem; } }
</style>

<div class="container-fluid py-4 cheque-workspace">
    <section class="cheque-hero mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between" style="gap:16px">
            <div>
                <div class="eyebrow mb-2">Payment control desk</div>
                <h1 class="h2 font-weight-bold mb-2"><i class="fas fa-money-check-alt mr-2"></i>Cheque Approvals</h1>
                <p class="mb-0 text-white-50">Review cheque evidence before it enters completed-income totals. Every decision is retained for audit.</p>
            </div>
            <div class="d-flex flex-wrap" style="gap:8px">
                <a href="payment_list.php?mode=cheque" class="btn btn-light"><i class="fas fa-list mr-1"></i>Payment List</a>
                <a href="payment_form.php" class="btn btn-outline-light"><i class="fas fa-plus mr-1"></i>Record Payment</a>
            </div>
        </div>
    </section>

    <?php if ($error): ?><div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success shadow-sm"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <div class="row mb-4">
        <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0"><div class="card cheque-summary"><div class="card-body d-flex align-items-center"><div class="summary-icon bg-warning text-dark mr-3"><i class="fas fa-hourglass-half"></i></div><div><span class="detail-label">Awaiting decision</span><div class="h3 mb-0"><?= number_format((int) ($summary['pending_count'] ?? 0)) ?></div></div></div></div></div>
        <div class="col-xl-3 col-sm-6 mb-3 mb-xl-0"><div class="card cheque-summary"><div class="card-body d-flex align-items-center"><div class="summary-icon bg-info text-white mr-3"><i class="fas fa-coins"></i></div><div><span class="detail-label">Pending value</span><div class="h4 mb-0">GH&#8373;<?= number_format((float) ($summary['pending_value'] ?? 0), 2) ?></div></div></div></div></div>
        <div class="col-xl-3 col-sm-6 mb-3 mb-sm-0"><div class="card cheque-summary"><div class="card-body d-flex align-items-center"><div class="summary-icon bg-success text-white mr-3"><i class="fas fa-check"></i></div><div><span class="detail-label">Approved</span><div class="h3 mb-0"><?= number_format((int) ($summary['verified_count'] ?? 0)) ?></div></div></div></div></div>
        <div class="col-xl-3 col-sm-6"><div class="card cheque-summary"><div class="card-body d-flex align-items-center"><div class="summary-icon bg-danger text-white mr-3"><i class="fas fa-times"></i></div><div><span class="detail-label">Rejected</span><div class="h3 mb-0"><?= number_format((int) ($summary['rejected_count'] ?? 0)) ?></div></div></div></div></div>
    </div>

    <div class="card cheque-filter mb-4"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3" style="gap:12px">
            <div class="cheque-tabs" aria-label="Cheque status filters">
                <?php foreach (['pending', 'verified', 'rejected', 'all'] as $tab): $meta = $statusMeta[$tab]; ?>
                    <a href="<?= htmlspecialchars($tabUrl($tab)) ?>" class="btn btn-<?= $status === $tab ? $meta['class'] : 'outline-secondary' ?> btn-sm"><i class="fas fa-<?= $meta['icon'] ?> mr-1"></i><?= htmlspecialchars($meta['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <span class="text-muted small"><?= number_format((int) $result['total_rows']) ?> matching cheque<?= (int) $result['total_rows'] === 1 ? '' : 's' ?></span>
        </div>
        <form method="get" class="row align-items-end">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <div class="form-group col-xl-4 col-md-6"><label>Search</label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div><input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Payer, CRN/SRN, bank or cheque number"></div></div>
            <?php if ($isSuperAdmin): ?><div class="form-group col-xl-2 col-md-6"><label>Church</label><select class="form-control" name="church_id"><option value="0">All churches</option><?php foreach ($churches as $church): ?><option value="<?= (int) $church['id'] ?>" <?= $churchId === (int) $church['id'] ? 'selected' : '' ?>><?= htmlspecialchars($church['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <div class="form-group col-xl-2 col-md-4"><label>Payment date from</label><input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></div>
            <div class="form-group col-xl-2 col-md-4"><label>Payment date to</label><input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></div>
            <div class="form-group col-xl-2 col-md-4"><button class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i>Apply</button></div>
            <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== '' || $churchId > 0): ?><div class="col-12"><a href="?status=<?= urlencode($status) ?>" class="small"><i class="fas fa-times mr-1"></i>Clear filters</a></div><?php endif; ?>
        </form>
    </div></div>

    <div class="card cheque-table-card">
        <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center py-3" style="gap:10px">
            <div><strong><?= htmlspecialchars($statusMeta[$status]['label']) ?></strong><div class="small text-muted"><?= $status === 'pending' ? 'Approve only after confirming the bank, cheque number, payer, amount and reporting period.' : 'Completed decisions remain visible as financial-control evidence.' ?></div></div>
            <?php if ($status === 'pending'): ?><span class="badge badge-warning status-badge"><i class="fas fa-shield-alt mr-1"></i>Authorization required</span><?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-hover cheque-table mb-0">
            <thead><tr><th>Payment / payer</th><th>Cheque evidence</th><th>Payment details</th><th>Workflow</th><th>Decision</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $payment):
                $rowStatus = strtolower((string) $payment['cheque_verification_status']);
                $meta = $statusMeta[$rowStatus] ?? $statusMeta['all'];
                $hasRequiredEvidence = trim((string) $payment['bank_name']) !== '' && trim((string) $payment['cheque_number']) !== '';
                $period = trim((string) ($payment['reporting_period_label'] ?: $payment['payment_period_description']));
            ?>
                <tr>
                    <td><span class="cheque-reference text-primary">PAY-<?= str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT) ?></span><div class="font-weight-bold mt-1"><?= htmlspecialchars($payment['payer_name'] ?: 'Unknown payer') ?></div><small class="text-muted"><?= htmlspecialchars($payment['registration_number'] ?: 'No registration number') ?></small><?php if ($isSuperAdmin): ?><div class="small mt-1"><i class="fas fa-church text-muted mr-1"></i><?= htmlspecialchars($payment['church_name'] ?: 'No church') ?></div><?php endif; ?></td>
                    <td><span class="detail-label">Bank</span><strong><?= htmlspecialchars($payment['bank_name'] ?: 'Missing') ?></strong><span class="detail-label mt-2">Cheque number</span><span class="cheque-reference"><?= htmlspecialchars($payment['cheque_number'] ?: 'Missing') ?></span><?php if (!$hasRequiredEvidence): ?><div class="text-danger small mt-2"><i class="fas fa-exclamation-triangle mr-1"></i>Evidence incomplete</div><?php endif; ?><?php if ($rowStatus === 'pending'): ?><button type="button" class="btn btn-sm <?= $hasRequiredEvidence ? 'btn-outline-secondary' : 'btn-outline-warning' ?> evidence-action edit-cheque-evidence" data-payment-id="<?= (int) $payment['id'] ?>" data-reference="PAY-<?= str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT) ?>" data-payer="<?= htmlspecialchars($payment['payer_name'] ?: 'Unknown payer', ENT_QUOTES) ?>" data-bank-name="<?= htmlspecialchars((string) $payment['bank_name'], ENT_QUOTES) ?>" data-cheque-number="<?= htmlspecialchars((string) $payment['cheque_number'], ENT_QUOTES) ?>"><i class="fas fa-pen mr-1"></i><?= $hasRequiredEvidence ? 'Correct evidence' : 'Add evidence' ?></button><?php endif; ?></td>
                    <td><div class="font-weight-bold text-success">GH&#8373;<?= number_format((float) $payment['amount'], 2) ?></div><div><?= htmlspecialchars($payment['payment_type'] ?: 'Unknown payment type') ?></div><small class="text-muted">Payment date: <?= htmlspecialchars($formatDate($payment['payment_date'])) ?></small><div class="small mt-1"><span class="detail-label">Reporting period</span><?= htmlspecialchars($period ?: 'Not supplied') ?></div></td>
                    <td><span class="badge badge-<?= $meta['class'] ?> status-badge"><i class="fas fa-<?= $meta['icon'] ?> mr-1"></i><?= htmlspecialchars($meta['label']) ?></span><div class="small mt-2"><span class="detail-label">Recorded by</span><?= htmlspecialchars($payment['recorded_by_name'] ?: 'Legacy/unknown recorder') ?></div><?php if ($payment['manual_batch_reference']): ?><div class="small mt-1"><span class="detail-label">Batch</span><?= htmlspecialchars($payment['manual_batch_reference']) ?></div><?php endif; ?><?php if ($rowStatus !== 'pending'): ?><div class="small mt-2"><span class="detail-label">Reviewed by</span><?= htmlspecialchars($payment['verified_by_name'] ?: 'Unknown reviewer') ?> · <?= htmlspecialchars($formatDate($payment['cheque_verified_at'], 'd M Y H:i')) ?></div><div class="small mt-1"><span class="detail-label">Decision note</span><?= nl2br(htmlspecialchars($payment['cheque_verification_notes'] ?: 'No note retained')) ?></div><?php endif; ?></td>
                    <td class="decision-panel">
                        <?php if ($rowStatus === 'pending'): ?><form method="post"><?= csrf_input() ?><input type="hidden" name="action" value="review"><input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>"><label class="small font-weight-bold" for="review_notes_<?= (int) $payment['id'] ?>">Decision note</label><textarea id="review_notes_<?= (int) $payment['id'] ?>" class="form-control form-control-sm mb-2" name="review_notes" maxlength="500" required placeholder="State what was checked or why it was rejected"></textarea><div class="d-flex flex-wrap" style="gap:6px"><button type="submit" class="btn btn-sm btn-success" name="decision" value="verified" <?= $hasRequiredEvidence ? '' : 'disabled' ?> onclick="return confirm('Approve this cheque and post it as completed income?')"><i class="fas fa-check mr-1"></i>Approve &amp; post</button><button type="submit" class="btn btn-sm btn-outline-danger" name="decision" value="rejected" onclick="return confirm('Reject this cheque payment?')"><i class="fas fa-times mr-1"></i>Reject</button></div></form><?php else: ?><div class="text-muted small mb-2">This decision is complete and cannot be silently overwritten.</div><?php endif; ?>
                        <a href="payment_view.php?id=<?= (int) $payment['id'] ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-eye mr-1"></i>Full payment</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="5"><div class="empty-cheques"><div class="empty-icon"><i class="fas fa-money-check-alt"></i></div><h4>No matching cheques</h4><p class="text-muted mb-0"><?= $status === 'pending' ? 'There are no cheque payments awaiting approval in your scope.' : 'Try changing the status, date or search filters.' ?></p></div></td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <?php if ((int) $result['total_rows'] > 0): ?><div class="card-footer bg-white border-0"><?php report_render_server_pagination($result['total_rows'], $page, $perPage, 'Cheque approval pages'); ?></div><?php endif; ?>
    </div>
</div>

<div class="modal fade" id="chequeEvidenceModal" tabindex="-1" role="dialog" aria-labelledby="chequeEvidenceModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document"><div class="modal-content">
        <form method="post" autocomplete="off">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="correct_evidence">
            <input type="hidden" name="payment_id" id="evidence_payment_id" value="">
            <div class="modal-header bg-warning text-dark">
                <div><h5 class="modal-title" id="chequeEvidenceModalTitle"><i class="fas fa-money-check-alt mr-2"></i>Correct cheque evidence</h5><small id="evidence_payment_context">Pending cheque</small></div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small"><i class="fas fa-shield-alt mr-1"></i>Only the bank and cheque number will change. The previous and new values are retained in the audit trail.</div>
                <div class="form-group"><label for="evidence_bank_name">Bank name <span class="text-danger">*</span></label><input type="text" class="form-control" id="evidence_bank_name" name="bank_name" maxlength="120" required></div>
                <div class="form-group"><label for="evidence_cheque_number">Cheque number <span class="text-danger">*</span></label><input type="text" class="form-control" id="evidence_cheque_number" name="cheque_number" maxlength="100" required></div>
                <div class="form-group mb-0"><label for="evidence_correction_reason">Correction reason <span class="text-danger">*</span></label><textarea class="form-control" id="evidence_correction_reason" name="correction_reason" maxlength="500" rows="3" required placeholder="Explain where the corrected evidence was confirmed"></textarea><small class="form-text text-muted">The cheque remains pending after this correction.</small></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning"><i class="fas fa-save mr-1"></i>Save evidence</button></div>
        </form>
    </div></div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = $('#chequeEvidenceModal').appendTo(document.body);
    $('.edit-cheque-evidence').on('click', function () {
        var button = $(this);
        $('#evidence_payment_id').val(button.data('payment-id'));
        $('#evidence_bank_name').val(button.attr('data-bank-name') || '');
        $('#evidence_cheque_number').val(button.attr('data-cheque-number') || '');
        $('#evidence_correction_reason').val('');
        $('#evidence_payment_context').text(button.attr('data-reference') + ' · ' + button.attr('data-payer'));
        modal.modal('show');
    });
});
</script>
<?php
$page_content = ob_get_clean();
$page_title = 'Cheque Approvals';
include __DIR__ . '/../includes/layout.php';
