<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/permissions_v2.php';
require_once __DIR__ . '/../helpers/church_helper.php';
require_once __DIR__ . '/../helpers/csrf.php';

if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$is_super_admin = is_super_admin();
if (!$is_super_admin && !has_permission('view_payment_bulk')) {
    http_response_code(403);
    include __DIR__ . '/errors/403.php';
    exit;
}

$canSubmit = $is_super_admin
    || has_permission('submit_bulk_payment')
    || has_permission('create_payment');
$actorChurchId = $is_super_admin ? 0 : (int) get_user_church_id($conn);
$churches = [];
$churchSql = $is_super_admin
    ? 'SELECT id, name FROM churches ORDER BY name'
    : 'SELECT id, name FROM churches WHERE id = ? LIMIT 1';
$churchStmt = $conn->prepare($churchSql);
if (!$is_super_admin) {
    $churchStmt->bind_param('i', $actorChurchId);
}
$churchStmt->execute();
$churchResult = $churchStmt->get_result();
while ($church = $churchResult->fetch_assoc()) {
    $churches[] = $church;
}
$churchStmt->close();

$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
ob_start();
?>
<link rel="stylesheet" href="<?= $h(BASE_URL) ?>/views/payment_bulk_member_multi.css">
<div class="container-fluid bulk-payment-workspace py-3"
     id="bulkPaymentWorkspace"
     data-can-submit="<?= $canSubmit ? '1' : '0' ?>"
     data-members-url="<?= $h(BASE_URL) ?>/views/ajax_members_by_church.php"
     data-payment-types-url="<?= $h(BASE_URL) ?>/views/ajax_payment_types.php"
     data-submit-url="<?= $h(BASE_URL) ?>/views/ajax_bulk_payment.php">
  <section class="bulk-hero mb-4">
    <div>
      <span class="bulk-eyebrow">Payment operations</span>
      <h1><i class="fas fa-layer-group mr-2"></i>Bulk Payment Entry</h1>
      <p>Build one ordered batch, add payment lines, review the sequence, and submit with a clear audit trail.</p>
    </div>
    <div class="bulk-hero-status">
      <span><i class="fas fa-shield-alt"></i> Church scoped</span>
      <span><i class="fas fa-sort-numeric-down"></i> Sequence preserved</span>
    </div>
  </section>

  <?php if (!$canSubmit): ?>
    <div class="alert alert-warning"><i class="fas fa-lock mr-2"></i>You may review this workspace, but you do not have permission to submit bulk payments.</div>
  <?php endif; ?>

  <div class="bulk-stepper mb-4" aria-label="Bulk payment workflow">
    <div class="bulk-step is-active"><span>1</span><div><strong>Batch setup</strong><small>Church and date</small></div></div>
    <div class="bulk-step"><span>2</span><div><strong>Beneficiaries</strong><small>Add in the required order</small></div></div>
    <div class="bulk-step"><span>3</span><div><strong>Review</strong><small>Confirm and submit</small></div></div>
  </div>

  <form id="bulkPaymentForm" autocomplete="off" aria-label="Bulk Payment Form">
    <?= csrf_input() ?>
    <section class="bulk-card mb-4">
      <header class="bulk-card-header">
        <div><span class="bulk-card-kicker">Step 1</span><h2>Batch details</h2></div>
        <span class="bulk-card-note">Required before adding beneficiaries</span>
      </header>
      <div class="bulk-card-body">
        <div class="form-row">
          <div class="form-group col-lg-5 mb-lg-0">
            <label for="church_id">Church <span class="text-danger">*</span></label>
            <select class="form-control" id="church_id" name="church_id" required>
              <?php if ($is_super_admin): ?><option value="">Select church</option><?php endif; ?>
              <?php foreach ($churches as $church): ?>
                <option value="<?= (int) $church['id'] ?>" <?= !$is_super_admin ? 'selected' : '' ?>><?= $h($church['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="form-text text-muted">Beneficiary searches and posting are limited to this church.</small>
          </div>
          <div class="form-group col-lg-4 mb-lg-0">
            <label for="payment_date">Receipt date <span class="text-danger">*</span></label>
            <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= $h(date('Y-m-d')) ?>" required>
            <small class="form-text text-muted">Applied to every payment line in this batch.</small>
          </div>
          <div class="form-group col-lg-3 mb-0">
            <label>Payment mode</label>
            <div class="bulk-readonly-field"><i class="fas fa-money-bill-wave"></i><span><strong>Cash</strong><small>Bulk-entry default</small></span></div>
          </div>
        </div>
      </div>
    </section>

    <section class="bulk-card mb-4">
      <header class="bulk-card-header">
        <div><span class="bulk-card-kicker">Step 2</span><h2>Add beneficiaries</h2></div>
        <span class="bulk-card-note">The order added becomes the posting order</span>
      </header>
      <div class="bulk-card-body">
        <label for="member_search">Search active members or Sunday School beneficiaries</label>
        <select class="form-control" id="member_search" style="width:100%" <?= $canSubmit ? '' : 'disabled' ?>></select>
        <div class="bulk-guidance mt-3"><i class="fas fa-info-circle"></i><span>A batch may contain either members (CRN) or Sunday School beneficiaries (SRN), not both. Use the arrow controls to correct the sequence before submission.</span></div>
      </div>
    </section>
  </form>

  <section class="bulk-card bulk-register-card mb-4">
    <header class="bulk-card-header">
      <div><span class="bulk-card-kicker">Ordered register</span><h2>Beneficiaries and payment lines</h2></div>
      <div class="bulk-summary-pills"><span><strong id="beneficiaryCount">0</strong> beneficiaries</span><span><strong id="paymentLineCount">0</strong> lines</span></div>
    </header>
    <div class="table-responsive">
      <table id="bulkTable" class="table bulk-table mb-0">
        <thead><tr><th class="sequence-column">Sequence</th><th>Beneficiary</th><th>Payment lines</th><th class="subtotal-column">Subtotal</th><th class="action-column">Actions</th></tr></thead>
        <tbody><tr class="bulk-empty-row"><td colspan="5"><div class="bulk-empty-state"><i class="fas fa-users"></i><strong>No beneficiaries added</strong><span>Select a church, then search and add people in the required order.</span></div></td></tr></tbody>
      </table>
    </div>
  </section>

  <section class="bulk-review-bar">
    <div><span>Batch total</span><strong id="bulkTotals">GH₵0.00</strong><small id="bulkReadiness">Add a beneficiary and at least one payment line.</small></div>
    <button type="button" class="btn btn-success btn-lg" id="submitBulkBtn" disabled <?= $canSubmit ? '' : 'title="Submission permission required"' ?>><i class="fas fa-clipboard-check mr-2"></i>Review batch</button>
  </section>
</div>

<div aria-live="polite" aria-atomic="true" class="bulk-toast-region">
  <div id="bulkToast" class="toast" data-delay="6000" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header bg-info text-white"><i class="fas fa-info-circle mr-2"></i><strong class="mr-auto">Bulk Payment</strong><button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
    <div class="toast-body"></div>
  </div>
</div>

<?php ob_start(); ?>
<div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document">
    <div class="modal-content bulk-confirm-modal">
      <div class="modal-header"><div><span class="bulk-card-kicker">Final review</span><h5 class="modal-title" id="confirmModalLabel">Confirm ordered bulk payment</h5><small class="text-muted">Payments will be posted from sequence 1 downward.</small></div><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
      <div class="modal-body"><div id="confirmSummary"></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Return to editing</button><button type="button" class="btn btn-success" id="confirmSubmitBtn"><i class="fas fa-check-circle mr-2"></i>Confirm and submit</button></div>
    </div>
  </div>
</div>
<?php $modal_html = ob_get_clean(); ?>

<script src="<?= $h(BASE_URL) ?>/views/payment_bulk_member_multi.js"></script>
<?php
$page_content = ob_get_clean();
include __DIR__ . '/../includes/layout.php';
?>
