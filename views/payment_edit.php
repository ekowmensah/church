<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../services/PaymentCorrectionService.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !has_permission('correct_payment')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$id = (int) (($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST['payment_id'] : $_GET['id']) ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Invalid payment ID.');
}

$error = '';
$payment = null;
$paymentTypes = [];
$service = null;
$schemaReady = false;

$publicError = static function (Throwable $e): string {
    if ($e instanceof mysqli_sql_exception || $e instanceof JsonException) {
        error_log('Payment correction error: ' . $e->getMessage());
        return 'The payment correction service is temporarily unavailable. Please try again or contact an administrator.';
    }
    return $e->getMessage();
};

try {
    $service = new PaymentCorrectionService($conn, (int) ($_SESSION['user_id'] ?? 0), $isSuperAdmin);
    $schemaCheck = $conn->query("SHOW TABLES LIKE 'payment_correction_audit'");
    $schemaReady = $schemaCheck && $schemaCheck->num_rows === 1;
    $payment = $service->load($id);
    $paymentTypes = $service->paymentTypes((int) $payment['payment_type_id']);
} catch (Throwable $e) {
    http_response_code($e instanceof mysqli_sql_exception ? 500 : 404);
    $error = $publicError($e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $payment) {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (!$schemaReady) {
        $error = 'Payment corrections require database migration Phase 0084.';
    } else {
        try {
            $service->correct($id, $_POST, (string) ($_POST['correction_reason'] ?? ''));
            header('Location: payment_view.php?id=' . $id . '&corrected=1');
            exit;
        } catch (Throwable $e) {
            $error = $publicError($e);
            try {
                $payment = $service->load($id);
                $paymentTypes = $service->paymentTypes((int) $payment['payment_type_id']);
            } catch (Throwable $reloadError) {
                $error = $publicError($reloadError);
                $payment = null;
            }
        }
    }
}

$postedTypeId = (int) ($_POST['payment_type_id'] ?? ($payment['payment_type_id'] ?? 0));
$postedAmount = (string) ($_POST['amount'] ?? ($payment['amount'] ?? ''));
$postedDate = (string) ($_POST['payment_date'] ?? ($payment ? date('Y-m-d', strtotime((string) $payment['payment_date'])) : ''));
$defaultPeriod = '';
if ($payment) {
    $periodSource = (string) ($payment['payment_period'] ?: $payment['payment_date']);
    $defaultPeriod = date('Y-m', strtotime($periodSource));
}
$postedPeriod = (string) ($_POST['reporting_month'] ?? $defaultPeriod);
$postedReason = (string) ($_POST['correction_reason'] ?? '');

ob_start();
?>
<style>
.payment-correction-page { --navy:#173f5f; --green:#236b45; --ink:#243444; --muted:#6f7f8e; }
.payment-correction-shell { width:100%; max-width:880px; margin:0 auto; }
.correction-hero { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:1.25rem 1.4rem; border-radius:16px; color:#fff; background:linear-gradient(135deg,#173f5f,#20597a 58%,#236b45); box-shadow:0 12px 30px rgba(23,63,95,.18); }
.correction-hero h1 { margin:0; font-size:1.55rem; font-weight:750; }
.correction-hero p { margin:.35rem 0 0; color:rgba(255,255,255,.78); font-size:.88rem; }
.correction-card { overflow:hidden; border:0; border-radius:15px; box-shadow:0 7px 22px rgba(21,42,67,.08); }
.correction-card .card-header { padding:.9rem 1rem; border-bottom:1px solid #e5ebef; background:#fff; }
.correction-card .card-body { padding:1rem; }
.identity-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.identity-item { min-width:0; padding:.72rem; border:1px solid #e2e8ec; border-radius:10px; background:#f8fafb; }
.identity-item span { display:block; margin-bottom:3px; color:var(--muted); font-size:.67rem; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
.identity-item strong { display:block; overflow:hidden; color:var(--ink); font-size:.84rem; text-overflow:ellipsis; white-space:nowrap; }
.correction-grid { display:grid; grid-template-columns:1.35fr .8fr; gap:12px; }
.correction-grid .form-group { margin-bottom:.8rem; }
.compact-label { display:block; margin-bottom:.32rem; color:#465666; font-size:.75rem; font-weight:700; }
.payment-correction-page .form-control { min-height:40px; border-color:#d9e1e7; border-radius:8px; font-size:.9rem; }
.payment-correction-page .form-control:focus { border-color:#4b86a5; box-shadow:0 0 0 .18rem rgba(32,89,122,.12); }
.locked-field { background:#f4f6f8!important; color:#627180; }
.audit-note { display:flex; gap:10px; padding:.75rem .85rem; border:1px solid #d9e8df; border-radius:10px; background:#f4faf6; color:#3d5f4b; font-size:.78rem; }
.correction-footer { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:1rem -1rem -1rem; padding:.85rem 1rem; border-top:1px solid #e5eaee; background:#f8fafb; }
@media (max-width:767.98px) { .correction-hero { align-items:flex-start; flex-direction:column; } .identity-grid { grid-template-columns:1fr 1fr; } .correction-grid { grid-template-columns:1fr; } .correction-footer { align-items:stretch; flex-direction:column-reverse; } .correction-footer .btn { width:100%; } }
@media (max-width:420px) { .identity-grid { grid-template-columns:1fr; } }
</style>

<div class="container-fluid py-3 payment-correction-page">
    <div class="payment-correction-shell">
        <header class="correction-hero mb-3">
            <div>
                <h1><i class="fas fa-edit mr-2"></i>Correct Payment</h1>
                <p>Adjust a manual ledger entry without changing its payer, source, method, recorder, or lifecycle evidence.</p>
            </div>
            <a href="payment_view.php?id=<?= $id ?>" class="btn btn-sm btn-light"><i class="fas fa-arrow-left mr-1"></i>Payment details</a>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger shadow-sm"><i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($payment): ?>
            <section class="card correction-card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <div><strong>TXN-<?= str_pad((string) $payment['id'], 6, '0', STR_PAD_LEFT) ?></strong><div class="small text-muted">Protected source details</div></div>
                    <span class="badge badge-light border"><?= htmlspecialchars((string) ($payment['church_name'] ?: 'Church unavailable')) ?></span>
                </div>
                <div class="card-body">
                    <div class="identity-grid">
                        <div class="identity-item"><span>Payer</span><strong title="<?= htmlspecialchars((string) $payment['payer_name']) ?>"><?= htmlspecialchars((string) ($payment['payer_name'] ?: 'Unknown payer')) ?></strong></div>
                        <div class="identity-item"><span>CRN / SRN</span><strong><?= htmlspecialchars((string) ($payment['registration_number'] ?: '-')) ?></strong></div>
                        <div class="identity-item"><span>Method</span><strong><?= htmlspecialchars((string) ($payment['mode'] ?: '-')) ?></strong></div>
                        <div class="identity-item"><span>Recorded by</span><strong><?= htmlspecialchars((string) ($payment['recorded_by_name'] ?: $payment['recorded_by'] ?: '-')) ?></strong></div>
                    </div>
                </div>
            </section>

            <?php if (!$schemaReady): ?>
                <div class="alert alert-warning"><strong>Migration required.</strong> Apply Phase 0084 before enabling audited corrections.</div>
            <?php elseif (!$payment['correction_allowed']): ?>
                <div class="card correction-card">
                    <div class="card-body py-4 text-center">
                        <div class="text-warning mb-2" style="font-size:2rem"><i class="fas fa-lock"></i></div>
                        <h2 class="h5 text-dark">This payment is managed elsewhere</h2>
                        <p class="text-muted mb-3"><?= htmlspecialchars((string) $payment['correction_block_reason']) ?></p>
                        <a href="<?= htmlspecialchars((string) $payment['correction_route']) ?>" class="btn btn-primary">Open governed workflow</a>
                    </div>
                </div>
            <?php else: ?>
                <form method="post" class="card correction-card" autocomplete="off" id="paymentCorrectionForm">
                    <input type="hidden" name="payment_id" value="<?= $id ?>">
                    <?= csrf_input() ?>
                    <div class="card-header"><strong><i class="fas fa-sliders-h text-primary mr-2"></i>Correction details</strong><div class="small text-muted">Only the fields below can change.</div></div>
                    <div class="card-body">
                        <div class="correction-grid">
                            <div class="form-group">
                                <label class="compact-label" for="payment_type_id">Payment type <span class="text-danger">*</span></label>
                                <select class="form-control" id="payment_type_id" name="payment_type_id" required>
                                    <?php foreach ($paymentTypes as $type): ?>
                                        <option value="<?= (int) $type['id'] ?>" data-name="<?= htmlspecialchars((string) $type['name']) ?>" <?= $postedTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $type['name']) ?><?= (int) $type['active'] !== 1 ? ' (inactive)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="compact-label" for="amount">Amount (GH&#8373;) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="amount" name="amount" min="0.01" max="99999999.99" step="0.01" value="<?= htmlspecialchars($postedAmount) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="compact-label" for="payment_date">Transaction date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= htmlspecialchars($postedDate) ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="compact-label" for="reporting_month">Reporting month <span class="text-danger">*</span></label>
                                <input type="month" class="form-control" id="reporting_month" name="reporting_month" value="<?= htmlspecialchars($postedPeriod) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="compact-label" for="generated_description">Description <span class="badge badge-light border ml-1">Automatic</span></label>
                            <input type="text" class="form-control locked-field" id="generated_description" value="<?= htmlspecialchars((string) $payment['description']) ?>" readonly>
                            <small class="form-text text-muted"><i class="fas fa-lock mr-1"></i>Generated from the selected payment type and reporting month.</small>
                        </div>

                        <div class="form-group">
                            <label class="compact-label" for="correction_reason">Correction reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="correction_reason" name="correction_reason" rows="3" maxlength="500" required placeholder="Explain what was wrong and why this correction is accurate."><?= htmlspecialchars($postedReason) ?></textarea>
                        </div>

                        <div class="audit-note"><i class="fas fa-shield-alt mt-1"></i><div><strong>Audited correction</strong><br>The original and corrected values, reason, user, church, and time are retained. Payer and payment method cannot be changed here.</div></div>
                        <div class="correction-footer">
                            <a href="payment_list.php" class="btn btn-light border">Cancel</a>
                            <button type="submit" class="btn btn-success px-4" onclick="return confirm('Save this audited payment correction?');"><i class="fas fa-check-circle mr-1"></i>Save correction</button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <a href="payment_list.php" class="btn btn-secondary"><i class="fas fa-arrow-left mr-1"></i>Back to payment list</a>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var type = document.getElementById('payment_type_id');
    var month = document.getElementById('reporting_month');
    var description = document.getElementById('generated_description');
    if (!type || !month || !description) return;
    function refreshDescription() {
        var option = type.options[type.selectedIndex];
        var typeName = option ? option.getAttribute('data-name') || option.text : '';
        var period = month.value;
        var periodLabel = '';
        if (/^\d{4}-\d{2}$/.test(period)) {
            var parts = period.split('-');
            periodLabel = new Intl.DateTimeFormat('en-GB', {month:'long', year:'numeric', timeZone:'UTC'}).format(new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, 1)));
        }
        description.value = typeName && periodLabel ? 'Payment for ' + periodLabel + ' ' + typeName : '';
    }
    type.addEventListener('change', refreshDescription);
    month.addEventListener('change', refreshDescription);
    refreshDescription();
})();
</script>
<?php
$page_content = ob_get_clean();
$page_title = 'Correct Payment';
include __DIR__ . '/../includes/layout.php';
