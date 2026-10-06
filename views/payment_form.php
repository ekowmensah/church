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
.payment-entry-page { --payment-navy:#173f5f; --payment-green:#236b45; --payment-ink:#243444; --payment-muted:#6d7d8c; }
.payment-entry-shell { width:100%; max-width:1120px; margin:0 auto; }
.payment-form-column { width:100%; max-width:880px; margin-left:auto; margin-right:auto; }
.payment-entry-hero { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:1.25rem 1.4rem; border-radius:16px; color:#fff; background:linear-gradient(135deg,#173f5f 0%,#20597a 58%,#236b45 100%); box-shadow:0 12px 30px rgba(23,63,95,.18); }
.payment-entry-hero .eyebrow { margin-bottom:.25rem; font-size:.7rem; font-weight:800; letter-spacing:.12em; opacity:.75; text-transform:uppercase; }
.payment-entry-hero h1 { margin:0; font-size:1.65rem; font-weight:750; }
.payment-entry-hero p { max-width:650px; margin:.35rem 0 0; color:rgba(255,255,255,.78); font-size:.9rem; }
.payment-entry-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:8px; }
.payment-entry-actions .btn { white-space:nowrap; border-radius:9px; }
.payment-progress { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:14px 0; }
.payment-step { display:flex; align-items:center; gap:9px; min-width:0; padding:.72rem .85rem; border:1px solid #e1e7ec; border-radius:12px; background:#fff; box-shadow:0 4px 14px rgba(21,42,67,.04); }
.payment-step-number { width:28px; height:28px; flex:0 0 28px; display:grid; place-items:center; border-radius:50%; background:#e9f0f5; color:var(--payment-navy); font-size:.78rem; font-weight:800; }
.payment-step:first-child .payment-step-number { background:var(--payment-navy); color:#fff; }
.payment-step strong { display:block; color:var(--payment-ink); font-size:.8rem; line-height:1.1; }
.payment-step small { display:block; color:var(--payment-muted); font-size:.7rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.payment-panel { overflow:hidden; border:0; border-radius:15px; box-shadow:0 7px 22px rgba(21,42,67,.07); }
.payment-panel .card-header { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:.85rem 1rem; border-bottom:1px solid #e7ecef; background:#fff; }
.payment-panel .panel-title { margin:0; color:var(--payment-ink); font-size:.92rem; font-weight:750; }
.payment-panel .panel-kicker { display:block; margin-top:2px; color:var(--payment-muted); font-size:.72rem; font-weight:400; }
.payment-panel .card-body { padding:1rem; }
.payer-search-grid { display:grid; grid-template-columns:minmax(0,1fr) 150px; gap:10px; align-items:end; }
.compact-label { display:block; margin-bottom:.32rem; color:#465666; font-size:.75rem; font-weight:700; }
.payment-entry-page .form-control { min-height:40px; border-color:#d9e1e7; border-radius:8px; font-size:.9rem; }
.payment-entry-page .form-control:focus { border-color:#4b86a5; box-shadow:0 0 0 .18rem rgba(32,89,122,.12); }
.payment-entry-page .btn { font-weight:650; }
.payment-line-grid { display:grid; grid-template-columns:minmax(190px,1.4fr) minmax(120px,.75fr) minmax(190px,1.15fr) minmax(170px,1fr); gap:12px; align-items:end; }
.payment-line-grid .form-group, .payment-detail-grid .form-group { margin-bottom:.75rem; }
.payment-detail-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:12px; }
.payment-description-row { display:grid; grid-template-columns:minmax(0,1fr) 170px; gap:12px; align-items:end; }
.payment-method-switch { display:grid; grid-template-columns:1fr 1fr; gap:5px; padding:4px; border:1px solid #d9e1e7; border-radius:9px; background:#f5f7f9; }
.payment-method-switch .btn { padding:.44rem .65rem; border:0; border-radius:7px!important; font-size:.82rem; }
.payment-method-switch .btn:not(.active) { background:transparent; color:#526273; }
.payment-method-switch .btn.active:first-child { background:#dff2e7; color:#1f6a43; box-shadow:none; }
.payment-method-switch .btn.active:last-child { background:#e1edf7; color:#235b87; box-shadow:none; }
.auto-description { background:#f6f8fa!important; color:#526273; }
.field-hint { margin-top:.28rem; color:#7c8995; font-size:.7rem; }
.cheque-fields { margin:.15rem 0 .75rem; padding:.8rem; border:1px solid #d8e5ee; border-radius:11px; background:#f7fbfd; }
.payment-feedback .alert { margin-bottom:.8rem; padding:.65rem .8rem; border-radius:9px; font-size:.85rem; }
.payment-lines-header { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:1rem; margin-bottom:.55rem; }
.payment-lines-header h6 { margin:0; color:var(--payment-ink); font-weight:750; }
.payment-line-count { border-radius:999px; padding:.3rem .58rem; background:#edf2f5; color:#536372; font-size:.72rem; font-weight:700; }
.payment-lines-table { min-width:850px; }
.payment-lines-table thead th { padding:.48rem .55rem; border-top:0; border-bottom:1px solid #dfe6eb; color:#607080; font-size:.67rem; letter-spacing:.05em; text-transform:uppercase; }
.payment-lines-table td { padding:.48rem .55rem; vertical-align:middle; font-size:.82rem; }
.payment-lines-empty td { padding:1.6rem .75rem!important; color:#7a8792; text-align:center; }
.payment-submit-bar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin:0 -1rem -1rem; padding:.82rem 1rem; border-top:1px solid #e4eaee; background:#f8fafb; }
.payment-total-label { color:#6d7d8c; font-size:.72rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.payment-total-value { color:var(--payment-green); font-size:1.15rem; font-weight:800; }
.payer-photo { width:58px; height:58px; object-fit:cover; border:2px solid #e3e9ed; border-radius:50%; }
#member-summary .card { margin-bottom:0; border:1px solid #dce9e2!important; border-radius:12px; box-shadow:none; }
#member-summary .card-body { padding:.8rem; }
.payment-summary-grid { display:grid; grid-template-columns:repeat(4,minmax(100px,1fr)); gap:.55rem 1rem; }
.payment-summary-label { color:#798794; font-size:.66rem; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
.payment-summary-value { color:#273746; font-size:.82rem; font-weight:650; }
.payment-confirm-modal .modal-content { overflow:hidden; border:0; border-radius:15px; box-shadow:0 24px 60px rgba(0,0,0,.24); }
.payment-confirm-modal .modal-header { padding:.9rem 1rem; }
.payment-confirm-modal .modal-body { padding:1rem; }
@media (max-width:991.98px) { .payment-line-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:767.98px) { .payment-entry-hero { align-items:flex-start; flex-direction:column; } .payment-entry-actions { width:100%; justify-content:flex-start; } .payment-progress { grid-template-columns:1fr; gap:6px; } .payment-step { padding:.6rem .75rem; } .payer-search-grid, .payment-description-row { grid-template-columns:1fr; } .payment-line-grid, .payment-detail-grid { grid-template-columns:1fr; } .payment-summary-grid { grid-template-columns:1fr 1fr; } .payment-submit-bar .btn { width:100%; } }
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
            <div class="payment-step"><span class="payment-step-number">1</span><div><strong>Find payer</strong><small>Use the CRN or SRN</small></div></div>
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
                        <div class="payment-line-grid">
                            <div class="form-group"><label class="compact-label" for="bulk_payment_type_id">Payment type <span class="text-danger">*</span></label><select class="form-control" id="bulk_payment_type_id"><option value="">Select payment type</option><?php foreach ($paymentTypes as $type): ?><option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label class="compact-label" for="bulk_amount">Amount (GH&#8373;) <span class="text-danger">*</span></label><input type="number" step="0.01" min="0.01" class="form-control" id="bulk_amount" placeholder="0.00" inputmode="decimal"></div>
                            <div class="form-group"><label class="compact-label">Payment method <span class="text-danger">*</span></label><div class="btn-group-toggle payment-method-switch" data-toggle="buttons" id="payment-method-options"><label class="btn active"><input type="radio" name="payment_method" value="Cash" checked> <i class="fas fa-coins mr-1"></i>Cash</label><label class="btn"><input type="radio" name="payment_method" value="Cheque"> <i class="fas fa-money-check-alt mr-1"></i>Cheque</label></div></div>
                            <div class="form-group"><label class="compact-label" for="bulk_payment_period">Reporting period <span class="text-danger">*</span></label><select class="form-control" id="bulk_payment_period"><?php for ($i = 0; $i < 24; $i++): $periodDate = date('Y-m-01', strtotime("-{$i} months")); ?><option value="<?= $periodDate ?>"><?= date('F Y', strtotime($periodDate)) ?></option><?php endfor; ?></select></div>
                        </div>

                        <div class="cheque-fields d-none" id="bulk_cheque_fields">
                            <div class="d-flex align-items-center mb-2"><i class="fas fa-shield-alt text-primary mr-2"></i><strong class="small">Cheque evidence</strong><span class="small text-muted ml-2">Required before the line can be added</span></div>
                            <div class="payment-detail-grid">
                                <div class="form-group mb-0"><label class="compact-label" for="bulk_bank_name">Bank name <span class="text-danger">*</span></label><input type="text" class="form-control" id="bulk_bank_name" maxlength="120" autocomplete="off" placeholder="Issuing bank"></div>
                                <div class="form-group mb-0"><label class="compact-label" for="bulk_cheque_number">Cheque number <span class="text-danger">*</span></label><input type="text" class="form-control" id="bulk_cheque_number" maxlength="100" autocomplete="off" placeholder="Cheque reference"></div>
                            </div>
                        </div>

                        <div class="payment-description-row">
                            <div class="form-group"><label class="compact-label" for="bulk_description">Description <span class="badge badge-light border ml-1">Automatic</span></label><input type="text" class="form-control auto-description" id="bulk_description" maxlength="255" readonly placeholder="Choose a payment type to generate the description"><div class="field-hint"><i class="fas fa-lock mr-1"></i>Updated automatically when payment type or reporting period changes.</div></div>
                            <div class="form-group"><button type="button" class="btn btn-success btn-block" id="addToBulkBtn"><i class="fas fa-plus-circle mr-1"></i>Add line</button></div>
                        </div>
                        <p class="small text-muted mb-0"><i class="far fa-clock mr-1"></i>The server records the exact submission date and time.</p>
                    </form>

                    <div id="bulk-payment-feedback" class="payment-feedback mt-2" aria-live="polite"></div>
                    <div class="payment-lines-header"><h6><i class="fas fa-list-ul mr-2 text-primary"></i>Lines ready to submit</h6><span class="payment-line-count" id="bulkPaymentsCount">0 lines</span></div>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm payment-lines-table mb-0" id="bulkPaymentsTable">
                            <thead class="thead-light"><tr><th>#</th><th>Type</th><th class="text-right">Amount</th><th>Method</th><th>Cheque details</th><th>Period</th><th>Description</th><th aria-label="Actions"></th></tr></thead>
                            <tbody><tr class="payment-lines-empty"><td colspan="8"><i class="fas fa-receipt mr-1"></i>No payment lines added yet.</td></tr></tbody>
                        </table>
                    </div>
                    <div class="payment-submit-bar">
                        <div><div class="payment-total-label">Batch total</div><div class="payment-total-value" id="bulkPaymentsTotal">GH&#8373;0.00</div></div>
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
            <div class="table-responsive border rounded"><table class="table table-sm mb-0" id="bulkConfirmTable"><thead class="thead-light"><tr><th>#</th><th>Type</th><th>Amount</th><th>Method</th><th>Details</th><th>Period</th></tr></thead><tbody></tbody><tfoot><tr><td colspan="2" class="text-right font-weight-bold">Total</td><td colspan="4" id="bulkConfirmTotal" class="font-weight-bold text-success"></td></tr></tfoot></table></div>
            <div class="alert alert-warning d-none mt-3 mb-0" id="chequeConfirmationPanel"><div class="font-weight-bold mb-2"><i class="fas fa-money-check-alt mr-1"></i>Cheque recorder checklist</div><div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input" id="cheque_entry_confirmed"><label class="custom-control-label" for="cheque_entry_confirmed">I checked the payer, bank, cheque number, amount, payment type, and reporting period. I understand the cheque remains Pending until an authorized reviewer verifies it.</label></div></div>
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
<script src="payment_form_multi.js?v=0081-2"></script>
<?php
$page_content = ob_get_clean();
$page_title = 'Record Payments';
include __DIR__ . '/../includes/layout.php';
