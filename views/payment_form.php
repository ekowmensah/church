<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$roleIds = array_map('intval', (array) ($_SESSION['role_ids'] ?? []));
if (isset($_SESSION['role_id'])) $roleIds[] = (int) $_SESSION['role_id'];
$isSuperAdmin = !empty($_SESSION['is_super_admin'])
    || (int) ($_SESSION['user_id'] ?? 0) === 3
    || in_array(1, $roleIds, true);
if (!$isSuperAdmin && !has_permission('create_payment')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$paymentTypes = [];
$result = $conn->query('SELECT id, name FROM payment_types WHERE active = 1 ORDER BY name');
if ($result) $paymentTypes = $result->fetch_all(MYSQLI_ASSOC);

ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800"><i class="fas fa-money-check-alt mr-2"></i>Record Payments</h1>
            <p class="text-muted mb-0">Enter one or more cash or cheque lines for a member or Sunday School child.</p>
        </div>
        <div class="mt-2 mt-md-0">
            <a href="payment_bulk_upload.php" class="btn btn-warning btn-sm mr-2"><i class="fas fa-upload mr-1"></i>CSV Bulk Upload</a>
            <a href="payment_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left mr-1"></i>Payment List</a>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3 bg-primary text-white">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-search mr-2"></i>Find payer by CRN or SRN</h6>
        </div>
        <div class="card-body">
            <form id="searchMemberForm" autocomplete="off" onsubmit="return false;">
                <div class="form-row align-items-end">
                    <div class="form-group col-lg-9 mb-2">
                        <label for="crn">Registration number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-lg" id="crn" maxlength="50"
                               placeholder="Enter CRN or SRN" required autocomplete="off">
                    </div>
                    <div class="form-group col-lg-3 mb-2">
                        <button type="submit" class="btn btn-info btn-lg btn-block" id="findMemberBtn">
                            <i class="fas fa-search mr-1"></i>Find payer
                        </button>
                    </div>
                </div>
                <div id="crn-feedback" class="small font-weight-bold" aria-live="polite"></div>
            </form>
            <div id="member-summary" class="mt-3 d-none"></div>
        </div>
    </div>

    <div id="payment-panels" class="d-none">
        <div class="card shadow mb-4">
            <div class="card-header py-3 bg-primary text-white">
                <h6 class="m-0 font-weight-bold"><i class="fas fa-list mr-2"></i>Cash and cheque payment lines</h6>
            </div>
            <div class="card-body">
                <form id="bulkPaymentEntryForm" autocomplete="off" onsubmit="return false;">
                    <input type="hidden" id="bulk_mode" value="Cash">
                    <div class="form-row align-items-end">
                        <div class="form-group col-xl-3 col-lg-4 col-md-6">
                            <label for="bulk_payment_type_id">Payment type <span class="text-danger">*</span></label>
                            <select class="form-control" id="bulk_payment_type_id">
                                <option value="">-- Select type --</option>
                                <?php foreach ($paymentTypes as $type): ?>
                                    <option value="<?= (int) $type['id'] ?>"><?= htmlspecialchars($type['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-xl-2 col-lg-3 col-md-6">
                            <label for="bulk_amount">Amount (GH&#8373;) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" id="bulk_amount" placeholder="100.00">
                        </div>
                        <div class="form-group col-xl-3 col-lg-5 col-md-6">
                            <label>Payment method <span class="text-danger">*</span></label>
                            <div class="btn-group btn-group-toggle d-flex" data-toggle="buttons" id="payment-method-options">
                                <label class="btn btn-outline-success active flex-fill">
                                    <input type="radio" name="payment_method" value="Cash" checked> Cash
                                </label>
                                <label class="btn btn-outline-primary flex-fill">
                                    <input type="radio" name="payment_method" value="Cheque"> Cheque
                                </label>
                            </div>
                        </div>
                        <div class="form-group col-xl-4 col-lg-6 col-md-6">
                            <label for="bulk_payment_period">Reporting period <span class="text-danger">*</span></label>
                            <select class="form-control" id="bulk_payment_period">
                                <?php for ($i = 0; $i < 24; $i++):
                                    $periodDate = date('Y-m-01', strtotime("-{$i} months")); ?>
                                    <option value="<?= $periodDate ?>"><?= date('F Y', strtotime($periodDate)) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row d-none" id="bulk_cheque_fields">
                        <div class="form-group col-md-6">
                            <label for="bulk_bank_name">Bank name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bulk_bank_name" maxlength="120" autocomplete="off">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="bulk_cheque_number">Cheque number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="bulk_cheque_number" maxlength="100" autocomplete="off">
                        </div>
                    </div>

                    <div class="form-row align-items-end">
                        <div class="form-group col-lg-9">
                            <label for="bulk_description">Description</label>
                            <input type="text" class="form-control" id="bulk_description" maxlength="255"
                                   placeholder="Optional; a period/type description is generated when left blank">
                        </div>
                        <div class="form-group col-lg-3 text-right">
                            <button type="button" class="btn btn-success btn-block" id="addToBulkBtn">
                                <i class="fas fa-plus-circle mr-1"></i>Add payment line
                            </button>
                        </div>
                    </div>
                    <p class="small text-muted mb-0"><i class="fas fa-clock mr-1"></i>The recorded date and time are generated by the server when submitted.</p>
                </form>

                <div id="bulk-payment-feedback" class="mt-3" aria-live="polite"></div>
                <div class="table-responsive mt-3">
                    <table class="table table-bordered table-sm" id="bulkPaymentsTable">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th><th>Type</th><th>Amount</th><th>Method</th>
                                <th>Cheque details</th><th>Period</th><th>Description</th><th></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div class="d-flex flex-wrap justify-content-end align-items-center">
                    <strong class="mr-3">Total: <span id="bulkPaymentsTotal">GH&#8373;0.00</span></strong>
                    <button type="button" class="btn btn-primary" id="submitBulkPaymentsBtn" disabled>
                        <i class="fas fa-check mr-1"></i>Review and submit
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkPaymentConfirmModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-clipboard-check mr-2"></i>Confirm payment lines</h5>
                <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="bulkConfirmTable">
                        <thead class="thead-light">
                            <tr><th>#</th><th>Type</th><th>Amount</th><th>Method</th><th>Details</th><th>Period</th></tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot><tr><td colspan="2" class="text-right font-weight-bold">Total</td><td colspan="4" id="bulkConfirmTotal" class="font-weight-bold"></td></tr></tfoot>
                    </table>
                </div>
                <div class="alert alert-warning d-none" id="chequeConfirmationPanel">
                    <div class="font-weight-bold mb-2">Cheque recorder checklist</div>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="cheque_entry_confirmed">
                        <label class="custom-control-label" for="cheque_entry_confirmed">
                            I checked the payer, bank, cheque number, amount, payment type, and reporting period. I understand the cheque remains Pending until an authorized reviewer verifies it.
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmBulkPaymentBtn">
                    <i class="fas fa-check-circle mr-1"></i>Confirm and submit
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.paymentFormConfig = <?= json_encode([
    'csrfToken' => csrf_token(),
    'submitUrl' => 'ajax_bulk_payments_single_member.php',
    'listUrl' => 'payment_list.php?added=1',
], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="payment_form_multi.js?v=0019-3"></script>
<style>
.payer-photo { width: 72px; height: 72px; object-fit: cover; border-radius: 50%; border: 3px solid #e5e7eb; }
.payment-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(135px, 1fr)); gap: .8rem; }
.payment-summary-label { color: #6c757d; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
.payment-summary-value { color: #212529; font-weight: 600; }
#bulkPaymentsTable td { vertical-align: middle; }
</style>
<?php $page_content = ob_get_clean(); include __DIR__ . '/../includes/layout.php'; ?>
