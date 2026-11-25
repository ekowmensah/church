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
if (!$isSuperAdmin && !has_permission('create_payment')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}
$canVerifyCheques = $isSuperAdmin || has_permission('verify_cheque_payments');

$paymentTypes = [];
$result = $conn->query('SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name');
if ($result) $paymentTypes = $result->fetch_all(MYSQLI_ASSOC);

ob_start();
?>
<style>
.payment-entry-page { --payment-navy:#173f5f; --payment-green:#236b45; --payment-ink:#243444; --payment-muted:#6d7d8c; max-width:100%; overflow-x:clip; }
.payment-entry-page, .payment-entry-page * { box-sizing:border-box; }
.payment-entry-shell { width:100%; max-width:1120px; margin:0 auto; }
.payment-form-column { width:100%; max-width:880px; margin-left:auto; margin-right:auto; }
.payment-entry-hero { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:1.25rem 1.4rem; border-radius:16px; color:#fff; background:linear-gradient(135deg,#173f5f 0%,#20597a 58%,#236b45 100%); box-shadow:0 12px 30px rgba(23,63,95,.18); }
.payment-entry-hero .eyebrow { margin-bottom:.3rem; font-size:.92rem; font-weight:800; letter-spacing:.12em; opacity:.88; text-transform:uppercase; }
.payment-entry-hero h1 { margin:0; font-size:2.15rem; font-weight:750; }
.payment-entry-hero p { max-width:650px; margin:.45rem 0 0; color:rgba(255,255,255,.94); font-size:1.16rem; }
.payment-entry-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; }
.payment-entry-actions .btn { white-space:nowrap; border-radius:9px; }
.payment-progress { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:14px 0; }
.payment-step { display:flex; align-items:center; gap:9px; min-width:0; padding:.72rem .85rem; border:1px solid #e1e7ec; border-radius:12px; background:#fff; box-shadow:0 4px 14px rgba(21,42,67,.04); }
.payment-step-number { width:32px; height:32px; flex:0 0 32px; display:grid; place-items:center; border-radius:50%; background:#e9f0f5; color:var(--payment-navy); font-size:1rem; font-weight:800; }
.payment-step:first-child .payment-step-number { background:var(--payment-navy); color:#fff; }
.payment-step strong { display:block; color:var(--payment-ink); font-size:1.08rem; line-height:1.2; }
.payment-step small { display:block; color:var(--payment-muted); font-size:1rem; white-space:normal; }
.payment-panel { min-width:0; overflow:hidden; border:0; border-radius:15px; box-shadow:0 7px 22px rgba(21,42,67,.07); }
.payment-panel .card-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:.85rem 1rem; border-bottom:1px solid #e7ecef; background:#fff; }
.payment-panel .panel-title { margin:0; color:var(--payment-ink); font-size:1.25rem; font-weight:750; }
.payment-panel .panel-kicker { display:block; margin-top:3px; color:var(--payment-muted); font-size:1.02rem; font-weight:400; }
.payment-panel .card-body { padding:1rem; }
.payer-search-grid { display:grid; grid-template-columns:minmax(0,1fr) 150px; gap:10px; align-items:end; }
.compact-label { display:block; margin-bottom:.4rem; color:#465666; font-size:1.08rem; font-weight:700; }
.payment-entry-page .form-control { width:100%; min-width:0; min-height:48px; border-color:#d9e1e7; border-radius:8px; font-size:1.16rem; }
.payment-entry-page .form-control:focus { border-color:#4b86a5; box-shadow:0 0 0 .18rem rgba(32,89,122,.12); }
.payment-entry-page .btn { font-size:1.08rem; font-weight:650; }
.payment-line-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; align-items:end; }
.payment-method-field { max-width:360px; margin-bottom:.85rem; }
.payment-line-grid .form-group, .payment-detail-grid .form-group { margin-bottom:.75rem; }
.payment-detail-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; }
.payment-description-row { display:grid; grid-template-columns:minmax(0,1fr) 170px; gap:12px; align-items:end; }
.payment-method-switch { display:grid; grid-template-columns:1fr 1fr; gap:5px; padding:4px; border:1px solid #d9e1e7; border-radius:9px; background:#f5f7f9; }
.payment-method-switch .btn { padding:.5rem .72rem; border:0; border-radius:7px!important; font-size:1.08rem; }
.payment-method-switch .btn:not(.active) { background:transparent; color:#526273; }
.payment-method-switch .btn.active:first-child { background:#dff2e7; color:#1f6a43; box-shadow:none; }
.payment-method-switch .btn.active:last-child { background:#e1edf7; color:#235b87; box-shadow:none; }
.auto-description { background:#f6f8fa!important; color:#526273; }
.field-hint { margin-top:.34rem; color:#596b7a; font-size:1rem; }
.cheque-fields { margin:.15rem 0 .9rem; padding:.9rem; border:1px solid #cfe0ec; border-radius:11px; background:#f5fafe; }
.cheque-fields-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:.75rem; }
.cheque-fields-header strong, .cheque-fields-header span { display:block; }
.cheque-fields-header span { margin-top:2px; color:var(--payment-muted); font-size:.94rem; }
.cheque-shared-badge { flex:0 0 auto; padding:.32rem .58rem; border-radius:999px; background:#dcecf8; color:#245f88; font-size:.84rem; font-weight:750; }
.payment-feedback .alert { margin-bottom:.8rem; padding:.72rem .88rem; border-radius:9px; font-size:1.12rem; }
.payment-lines-header { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:1rem; margin-bottom:.55rem; }
.payment-lines-header h6 { margin:0; color:var(--payment-ink); font-weight:750; }
.payment-line-count { border-radius:999px; padding:.36rem .68rem; background:#edf2f5; color:#536372; font-size:1rem; font-weight:700; }
.payment-lines-list { display:grid; gap:9px; min-width:0; }
.payment-mode-group { min-width:0; }
.payment-mode-group + .payment-mode-group { margin-top:.45rem; padding-top:.75rem; border-top:1px solid #e1e7ec; }
.payment-mode-group-header { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:.55rem; color:var(--payment-ink); font-size:1.04rem; font-weight:750; }
.payment-mode-group-lines { display:grid; gap:9px; }
.payment-line-card { min-width:0; padding:.78rem .85rem; border:1px solid #dde5ea; border-radius:11px; background:#fff; }
.payment-line-card-head { display:grid; grid-template-columns:34px minmax(0,1fr) auto 36px; gap:10px; align-items:center; }
.payment-line-index { width:30px; height:30px; display:grid; place-items:center; border-radius:50%; background:#eaf1f5; color:var(--payment-navy); font-size:.88rem; font-weight:800; }
.payment-line-title { min-width:0; color:var(--payment-ink); font-size:1.08rem; font-weight:750; overflow-wrap:anywhere; }
.payment-line-amount { color:var(--payment-green); font-size:1.14rem; font-weight:800; white-space:nowrap; }
.payment-line-card-body { display:flex; flex-wrap:wrap; gap:6px 12px; margin:.62rem 0 0 44px; }
.payment-line-meta { min-width:0; color:#536474; font-size:.94rem; overflow-wrap:anywhere; }
.payment-line-meta i { width:16px; color:#78909f; }
.payment-line-description { flex:1 1 100%; margin:0; color:#61717f; font-size:.92rem; overflow-wrap:anywhere; }
.payment-lines-empty { padding:1.6rem .75rem; border:1px dashed #d4dde3; border-radius:11px; color:#687784; font-size:1.08rem; text-align:center; }
.payment-submit-bar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin:0 -1rem -1rem; padding:.82rem 1rem; border-top:1px solid #e4eaee; background:#f8fafb; }
.payment-batch-totals { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; flex:1 1 420px; }
.payment-batch-total strong { display:block; color:var(--payment-ink); font-size:1.16rem; font-weight:800; }
.payment-batch-total.grand-total strong { color:var(--payment-green); font-size:1.4rem; }
.payment-total-label { color:#5f7080; font-size:1rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.payment-total-value { color:var(--payment-green); font-size:1.6rem; font-weight:800; }
.payer-photo { width:58px; height:58px; object-fit:cover; border:2px solid #e3e9ed; border-radius:50%; }
#member-summary .card { margin-bottom:0; border:1px solid #dce9e2!important; border-radius:12px; box-shadow:none; }
#member-summary .card-body { padding:.8rem; }
.payment-summary-grid { display:grid; grid-template-columns:repeat(4,minmax(100px,1fr)); gap:.55rem 1rem; }
.payment-summary-label { color:#687987; font-size:.94rem; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
.payment-summary-value { color:#273746; font-size:1.14rem; font-weight:650; }
.payment-confirm-modal .modal-content { overflow:hidden; border:0; border-radius:15px; box-shadow:0 24px 60px rgba(0,0,0,.24); }
.payment-confirm-modal .modal-header { padding:.9rem 1rem; }
.payment-confirm-modal .modal-body { padding:1rem; }
.payment-confirm-modal .modal-title { font-size:1.65rem; }
.payment-confirm-modal .modal-body .table { font-size:1.14rem; }
.payment-confirm-modal .modal-footer .btn { font-size:1.08rem; }
.payment-confirm-modal .custom-control-label, .payment-confirm-modal .modal-header small { font-size:1.08rem; }
.payment-confirm-list { display:grid; gap:8px; }
.payment-confirm-group + .payment-confirm-group { margin-top:.6rem; padding-top:.8rem; border-top:1px solid #e1e7ec; }
.payment-confirm-group-header { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:.5rem; color:var(--payment-ink); font-weight:750; }
.payment-confirm-group-lines { display:grid; gap:8px; }
.payment-confirm-line { display:grid; grid-template-columns:30px minmax(0,1fr) auto; gap:10px; align-items:start; padding:.7rem .75rem; border:1px solid #e0e6ea; border-radius:10px; }
.payment-confirm-line > span { width:27px; height:27px; display:grid; place-items:center; border-radius:50%; background:#eef2f5; color:#526273; font-size:.8rem; font-weight:800; }
.payment-confirm-line strong, .payment-confirm-line small { display:block; overflow-wrap:anywhere; }
.payment-confirm-line small { margin-top:3px; color:var(--payment-muted); }
.payment-confirm-line .confirm-amount { color:var(--payment-green); white-space:nowrap; }
.payment-confirm-total { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:10px; padding:.72rem .8rem; border-radius:10px; background:#f4f8f6; }
.payment-confirm-total strong { color:var(--payment-green); font-size:1.28rem; }
.payment-entry-page .small { font-size:1.06rem; }
@media (max-width:991.98px) { .payment-line-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:767.98px) { .payment-entry-page { padding-left:10px!important; padding-right:10px!important; } .payment-entry-hero { align-items:flex-start; flex-direction:column; padding:1.05rem; } .payment-entry-hero h1 { font-size:1.65rem; } .payment-entry-hero p { font-size:1rem; } .payment-entry-actions { width:100%; justify-content:flex-start; } .payment-entry-actions .btn { flex:1 1 145px; } .payment-progress { grid-template-columns:1fr; gap:6px; } .payment-step { padding:.6rem .75rem; } .payer-search-grid, .payment-description-row { grid-template-columns:1fr; } .payment-line-grid, .payment-detail-grid { grid-template-columns:1fr; } .payment-summary-grid { grid-template-columns:1fr 1fr; } .payment-submit-bar .btn { width:100%; } .cheque-fields-header { flex-direction:column; } }
@media (max-width:520px) { .payment-panel .card-header { align-items:flex-start; flex-wrap:wrap; } .payment-summary-grid { grid-template-columns:1fr; } .payment-line-card-head { grid-template-columns:30px minmax(0,1fr) 34px; } .payment-line-amount { grid-column:2; grid-row:2; justify-self:start; } .payment-line-card-body { margin-left:40px; } .payment-confirm-line { grid-template-columns:27px minmax(0,1fr); } .payment-confirm-line .confirm-amount { grid-column:2; } .payment-batch-totals { grid-template-columns:repeat(2,minmax(0,1fr)); } .payment-batch-total.grand-total { grid-column:1 / -1; } }
</style>

<div class="container-fluid py-3 payment-entry-page">
    <div class="payment-entry-shell">
        <header class="payment-entry-hero">
            <div>
                <div class="eyebrow">Payments workspace</div>
                <h1><i class="fas fa-receipt mr-2"></i>Record Payments</h1>
                <p>Find one payer, build a compact batch of cash or cheque lines, then review everything once before posting.</p>
            </div>
            <div class="payment-entry-actions">
                <a href="payment_bulk_upload.php" class="btn btn-sm btn-warning"><i class="fas fa-file-upload mr-1"></i>Bulk upload</a>
                <?php if ($canVerifyCheques): ?><a href="cheque_payment_verification.php" class="btn btn-sm btn-outline-light"><i class="fas fa-money-check-alt mr-1"></i>Cheque approvals</a><?php endif; ?>
                <a href="payment_list.php" class="btn btn-sm btn-light"><i class="fas fa-arrow-left mr-1"></i>Payment list</a>
            </div>
        </header>

        <div class="payment-progress" aria-label="Payment entry steps">
            <div class="payment-step"><span class="payment-step-number">1</span><div><strong>Find Member</strong><small>Use the CRN or SRN</small></div></div>
            <div class="payment-step"><span class="payment-step-number">2</span><div><strong>Add payment lines</strong><small>Cash and cheque can share a batch</small></div></div>
            <div class="payment-step"><span class="payment-step-number">3</span><div><strong>Review and submit</strong><small>Confirm the complete batch once</small></div></div>
        </div>

        <section class="card payment-panel payment-form-column mb-3">
            <div class="card-header">
                <div><h2 class="panel-title"><i class="fas fa-user-check text-primary mr-2"></i>Select payer</h2><span class="panel-kicker">Member CRN and Sunday School SRN are both supported.</span></div>
                <span class="badge badge-light border">Step 1</span>
            </div>
            <div class="card-body">
                <form id="searchMemberForm" autocomplete="off" onsubmit="return false;">
                    <div class="payer-search-grid">
                        <div><label class="compact-label" for="crn">Registration number <span class="text-danger">*</span></label><div class="input-group"><div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-id-card"></i></span></div><input type="text" class="form-control text-uppercase" id="crn" maxlength="12" placeholder="FMC-K0101-KM" required autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="crn-format-hint"></div><div class="field-hint" id="crn-format-hint"><i class="fas fa-magic mr-1"></i>Dashes are added automatically for CRNs and SRNs.</div></div>
                        <button type="submit" class="btn btn-info btn-block" id="findMemberBtn"><i class="fas fa-search mr-1"></i>Find payer</button>
                    </div>
                    <div id="crn-feedback" class="small font-weight-bold mt-2" aria-live="polite"></div>
                </form>
                <div id="member-summary" class="mt-2 d-none"></div>
            </div>
        </section>

        <div id="payment-panels" class="payment-form-column d-none">
            <section class="card payment-panel mb-3">
                <div class="card-header">
                    <div><h2 class="panel-title"><i class="fas fa-layer-group text-success mr-2"></i>Payment line</h2><span class="panel-kicker">Add up to 30 lines for the selected payer.</span></div>
                    <span class="badge badge-light border">Step 2</span>
                </div>
                <div class="card-body">
                    <form id="bulkPaymentEntryForm" autocomplete="off" onsubmit="return false;">
                        <input type="hidden" id="bulk_mode" value="Cash">
                        <div class="payment-method-field form-group">
                            <label class="compact-label">Payment method <span class="text-danger">*</span></label>
                            <div class="btn-group-toggle payment-method-switch" data-toggle="buttons" id="payment-method-options"><label class="btn active"><input type="radio" name="payment_method" value="Cash" checked> <i class="fas fa-coins mr-1"></i>Cash</label><label class="btn"><input type="radio" name="payment_method" value="Cheque"> <i class="fas fa-money-check-alt mr-1"></i>Cheque</label></div>
                        </div>

                        <div class="cheque-fields d-none" id="bulk_cheque_fields">
                            <div class="cheque-fields-header"><div><strong><i class="fas fa-money-check-alt text-primary mr-2"></i>Shared cheque evidence</strong><span>Enter this once. Every cheque allocation added below will use the same bank, cheque number, and cheque amount.</span></div><span class="cheque-shared-badge"><i class="fas fa-link mr-1"></i>One cheque</span></div>
                            <div class="payment-detail-grid">
                                <div class="form-group mb-0"><label class="compact-label" for="bulk_bank_name">Bank name <span class="text-danger">*</span></label><input type="text" class="form-control" id="bulk_bank_name" maxlength="120" autocomplete="off" placeholder="Issuing bank"></div>
                                <div class="form-group mb-0"><label class="compact-label" for="bulk_cheque_number">Cheque number <span class="text-danger">*</span></label><input type="text" class="form-control" id="bulk_cheque_number" maxlength="100" autocomplete="off" placeholder="Cheque reference"></div>
                            </div>
                            <div class="form-group mb-0 mt-3"><label class="compact-label" for="bulk_cheque_amount">Cheque amount (GH&#8373;) <span class="text-danger">*</span></label><input type="number" step="0.01" min="0.01" class="form-control" id="bulk_cheque_amount" placeholder="0.00" inputmode="decimal"><div class="field-hint">Informational cheque value only; it does not change the payment allocation amount.</div></div>
                        </div>

                        <div class="payment-line-grid">
                            <div class="form-group"><label class="compact-label" for="bulk_payment_type_id">Payment type <span class="text-danger">*</span></label><select class="form-control" id="bulk_payment_type_id"><option value="">Select payment type</option><?php foreach ($paymentTypes as $type): ?><option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="compact-label" for="bulk_amount">Amount (GH&#8373;) <span class="text-danger">*</span></label><input type="number" step="0.01" min="0.01" class="form-control" id="bulk_amount" placeholder="0.00" inputmode="decimal"></div>
                            <div class="form-group"><label class="compact-label" for="bulk_payment_period">Reporting period <span class="text-danger">*</span></label><select class="form-control" id="bulk_payment_period"><?php for ($i = 0; $i < 24; $i++): $periodDate = date('Y-m-01', strtotime("-{$i} months")); ?><option value="<?= $periodDate ?>"><?= date('F Y', strtotime($periodDate)) ?></option><?php endfor; ?></select></div>
                        </div>

                        <div class="payment-description-row">
                            <div class="form-group"><label class="compact-label" for="bulk_description">Description <span class="badge badge-light border ml-1">Automatic</span></label><input type="text" class="form-control auto-description" id="bulk_description" maxlength="255" readonly placeholder="Choose a payment type to generate the description"><div class="field-hint"><i class="fas fa-lock mr-1"></i>Updated automatically when payment type or reporting period changes.</div></div>
                            <div class="form-group"><button type="button" class="btn btn-success btn-block" id="addToBulkBtn"><i class="fas fa-plus-circle mr-1"></i>Add line</button></div>
                        </div>
                        <p class="small text-muted mb-0"><i class="far fa-clock mr-1"></i>The server records the exact submission date and time.</p>
                    </form>

                    <div id="bulk-payment-feedback" class="payment-feedback mt-2" aria-live="polite"></div>
                    <div class="payment-lines-header"><h6><i class="fas fa-list-ul mr-2 text-primary"></i>Lines ready to submit</h6><span class="payment-line-count" id="bulkPaymentsCount">0 lines</span></div>
                    <div class="payment-lines-list" id="bulkPaymentsTable" aria-live="polite"><div class="payment-lines-empty"><i class="fas fa-receipt mr-1"></i>No payment lines added yet.</div></div>
                    <div class="alert alert-info d-none mt-3 mb-0" id="cheque-reconciliation" role="status" aria-live="polite">
                        <div class="row">
                            <div class="col-sm-4"><span class="detail-label">Cheque face value</span><strong id="chequeReconciliationFaceValue">Not entered</strong></div>
                            <div class="col-sm-4"><span class="detail-label">Cheque allocations</span><strong id="chequeReconciliationAllocated">GH&#8373;0.00</strong></div>
                            <div class="col-sm-4"><span class="detail-label">Remaining / over</span><strong id="chequeReconciliationDifference">Not calculated</strong></div>
                        </div>
                        <div class="small mt-2" id="chequeReconciliationMessage"></div>
                    </div>
                    <div class="payment-submit-bar">
                        <div class="payment-batch-totals" aria-live="polite">
                            <div class="payment-batch-total"><div class="payment-total-label">Cash subtotal</div><strong id="bulkPaymentsCashTotal">GH&#8373;0.00</strong></div>
                            <div class="payment-batch-total"><div class="payment-total-label">Cheque allocations</div><strong id="bulkPaymentsChequeTotal">GH&#8373;0.00</strong></div>
                            <div class="payment-batch-total grand-total"><div class="payment-total-label">Batch total</div><strong id="bulkPaymentsTotal">GH&#8373;0.00</strong></div>
                        </div>
                        <button type="button" class="btn btn-primary px-4" id="submitBulkPaymentsBtn" disabled><i class="fas fa-clipboard-check mr-1"></i>Review and submit</button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="modal fade payment-confirm-modal" id="bulkPaymentConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document"><div class="modal-content">
        <div class="modal-header bg-primary text-white"><div><h5 class="modal-title"><i class="fas fa-clipboard-check mr-2"></i>Review payment batch</h5><small class="text-white-50">Confirm the lines before anything is recorded.</small></div><button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button></div>
        <div class="modal-body">
            <div class="payment-confirm-list" id="bulkConfirmTable"></div>
            <div class="payment-confirm-total"><span>Batch total</span><strong id="bulkConfirmTotal">GH&#8373;0.00</strong></div>
            <div class="alert alert-warning d-none mt-3 mb-0" id="chequeConfirmationPanel"><div class="font-weight-bold mb-2"><i class="fas fa-money-check-alt mr-1"></i>Cheque recorder checklist</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="cheque_entry_confirmed"><label class="custom-control-label" for="cheque_entry_confirmed">I checked the payer, bank, cheque number, cheque amount, payment allocation, payment type, and reporting period. I understand the cheque remains Pending until an authorized reviewer verifies it.</label></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light border" data-dismiss="modal">Go back</button><button type="button" class="btn btn-success" id="confirmBulkPaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm and submit</button></div>
    </div></div>
</div>

<script>
window.paymentFormConfig = <?= json_encode([
    'csrfToken' => csrf_token(),
    'submitUrl' => 'ajax_bulk_payments_single_member.php',
    'listUrl' => 'payment_list.php?added=1',
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="payment_form_multi.js?v=0111-6"></script>
<?php
$page_content = ob_get_clean();
$page_title = 'Record Payments';
include __DIR__ . '/../includes/layout.php';
