<?php
// Modern Bulk Payment UI
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) { header('Location: ' . BASE_URL . '/login.php'); exit; }
require_once __DIR__.'/../helpers/permissions_v2.php';
require_once __DIR__.'/../helpers/csrf.php';
$is_super_admin = is_super_admin();
if (!$is_super_admin && !has_permission('view_payment_bulk')) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
    exit;
}
ob_start();
?>
<div class="container py-4">

  <h2 class="mb-4 font-weight-bold"><i class="fas fa-layer-group mr-2"></i>Bulk Payments</h2>
  <form id="bulkPaymentForm" autocomplete="off" aria-label="Bulk Payment Form">
    <?= csrf_input() ?>
    <div class="row align-items-end">
      <div class="col-md-4 mb-3">
        <label for="church_id" class="font-weight-bold">Church <span class="text-danger">*</span></label>
        <select class="form-control" id="church_id" name="church_id" required></select>
      </div>
      <div class="col-md-4 mb-3">
        <label for="payment_date" class="font-weight-bold">Payment Date <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" readonly required>
      </div>
      <div class="col-md-8 mb-3">
        <label for="member_search" class="font-weight-bold">Add Member <span class="text-danger">*</span></label>
        <select class="form-control" id="member_search" style="width:100%"></select>
      </div>
    </div>
  </form>
  <div id="bulkPreview" class="mt-4">
    <table id="bulkTable" class="table table-striped table-bordered" style="width:100%">
      <thead>
        <tr>
          <th>#</th>
          <th>Name</th>
          <th>Payment Types</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      </tbody>
    </table>
  </div>
  <div class="row mt-3">
    <div class="col-12">
      <div id="bulkTotals" class="alert alert-info font-weight-bold text-right" style="font-size:1.2rem;">
        Total: ₵0.00
      </div>
    </div>
  </div>
  <div class="d-flex mt-4">
    <button type="button" class="btn btn-success ml-auto d-none" id="submitBulkBtn"><i class="fas fa-check-circle mr-1"></i>Submit Bulk Payment</button>
  </div>
</div>
<!-- Toasts for feedback -->
<div aria-live="polite" aria-atomic="true" style="position: fixed; top: 1.5rem; right: 1.5rem; min-width: 300px; z-index: 1080;">
  <div id="bulkToast" class="toast" data-delay="6000" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="toast-header bg-info text-white">
      <i class="fas fa-info-circle mr-2"></i><strong class="mr-auto">Bulk Payment</strong>
      <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast" aria-label="Close"><span aria-hidden="true">&times;</span></button>
    </div>
    <div class="toast-body"></div>
  </div>
</div>
<?php ob_start(); ?>
<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" role="dialog" aria-labelledby="confirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title font-weight-bold" id="confirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Bulk Payment</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div id="confirmSummary"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmSubmitBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
      </div>
    </div>
  </div>
</div>
<?php $modal_html = ob_get_clean(); ?>
<!-- Plugin Imports -->
<link href="payment_bulk_member_multi.css" rel="stylesheet" />
<script src="payment_bulk_member_multi.js"></script>
<?php
$page_content = ob_get_clean();
include '../includes/layout.php';
?>
