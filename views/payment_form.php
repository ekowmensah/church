<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
require_once __DIR__.'/../helpers/permissions.php';

// Only allow logged-in users
if (!is_logged_in()) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

// Robust super admin bypass and permission check
$is_super_admin = (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 3) || 
                  (isset($_SESSION['role_id']) && $_SESSION['role_id'] == 1);

// Check if editing or creating
$editing = isset($_GET['id']) && is_numeric($_GET['id']);
$required_permission = $editing ? 'edit_payment' : 'create_payment';

if (!$is_super_admin && !has_permission($required_permission)) {
    http_response_code(403);
    if (file_exists(__DIR__.'/errors/403.php')) {
        include __DIR__.'/errors/403.php';
    } else if (file_exists(dirname(__DIR__).'/views/errors/403.php')) {
        include dirname(__DIR__).'/views/errors/403.php';
    } else {
        echo '<div class="alert alert-danger"><h4>403 Forbidden</h4><p>You do not have permission to access this page.</p></div>';
    }
    exit;
}

// Set permission flags for UI elements
$can_add = $is_super_admin || has_permission('create_payment');
$can_edit = $is_super_admin || has_permission('edit_payment');
$can_view = true; // Already validated above

// Fetch payment types
$types = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name");

$error = '';
$success = '';

$modal_html = '';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <!-- <h1 class="h3 mb-0 text-gray-800"><i class="fas fa-university mr-2"></i>Payments</h1>  -->
        <a href="payment_list.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3 bg-primary text-white d-flex align-items-center">
                    <h6 class="m-0 font-weight-bold flex-grow-1"><i class="fas fa-user-check mr-2"></i>Find Member</h6>
                </div>
                <div class="card-body pb-1">
                    <form id="searchMemberForm" autocomplete="off">
                        <div class="form-row align-items-center">
                            <div class="col-md-8 mb-2">
                                <label for="crn">CRN/SRN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg" id="crn" name="crn" maxlength="50" placeholder="Enter CRN or SRN" required autocomplete="off">
                            </div>
                            <div class="col-md-4 mb-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-info btn-lg w-100" id="findMemberBtn"><i class="fas fa-search"></i> Find</button>
                                <span id="crn-spinner" class="spinner-border text-primary ml-2 d-none" style="width:1.5rem;height:1.5rem;vertical-align:middle;" role="status"><span class="sr-only">Loading...</span></span>
                            </div>
                        </div>
                        <div id="crn-feedback" class="small text-danger mt-1 font-weight-bold"></div>
                    </form>
                    <div id="member-summary" class="mt-3 d-none"></div>
                </div>
            </div>
            <div id="payment-panels" class="d-none animate__animated animate__fadeIn">
                 <ul class="nav nav-tabs mb-3" id="paymentTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="single-tab" data-toggle="tab" href="#singlePanel" role="tab" aria-controls="singlePanel" aria-selected="true"><i class="fas fa-money-bill-wave"></i> Single Payment</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="bulk-tab" data-toggle="tab" href="#bulkPanel" role="tab" aria-controls="bulkPanel" aria-selected="false"><i class="fas fa-layer-group"></i> Multiple Payment</a>
                    </li>
                </ul> 
                <div class="tab-content" id="paymentTabContent">
                    <!-- Single Payment Panel -->
                    <div class="tab-pane fade show active" id="singlePanel" role="tabpanel" aria-labelledby="single-tab">
                        <form id="singlePaymentForm" autocomplete="off">
                            <input type="hidden" name="member_id" id="single_member_id">
<input type="hidden" name="sundayschool_id" id="single_sundayschool_id">
                            <div class="form-row">
                                <div class="form-group col-md-4">
                                    <label for="single_payment_type_id">Payment Type <span class="text-danger">*</span></label>
                                    <select class="form-control form-control-lg" id="single_payment_type_id" name="payment_type_id" required>
                                        <option value="">-- Select Type --</option>
                                        <?php if ($types && $types->num_rows > 0): while($t = $types->fetch_assoc()): ?>
                                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                        <?php endwhile; endif; ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-3">
                                    <label for="single_amount">Amount (₵) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" class="form-control form-control-lg" id="single_amount" name="amount" placeholder="e.g. 100.00" required>
                                </div>
                                <div class="form-group col-md-3">
                                    <label for="single_mode">Mode <span class="text-danger">*</span></label>
                                    <select class="form-control form-control-lg" id="single_mode" name="mode" required>
                                        <option value="">-- Select --</option>
                                        <option value="Cash">Cash</option>

                                        <option value="Cheque">Cheque</option>
                                        <!-- <option value="Transfer">Transfer</option>
                                        
                                        <option value="POS">POS</option>
                                        <option value="Online">Online</option>
                                        <option value="Offline">Offline</option>
                                        <option value="Other">Other</option> -->
                                    </select>
                                </div>
                                <div class="form-group col-md-2">
                                    <label for="single_payment_date">Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control form-control-lg" id="single_payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" readonly required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="single_payment_period">Period <span class="text-danger">*</span></label>
                                    <select class="form-control form-control-lg" id="single_payment_period" name="payment_period" required>
                                        <option value="">-- Select Period --</option>
                                        <?php
                                        // Generate payment period options (current month and previous 12 months)
                                        for ($i = 0; $i < 12; $i++) {
                                            $date = date('Y-m-01', strtotime("-$i months"));
                                            $display = date('F Y', strtotime($date));
                                            $selected = ($i == 0) ? 'selected' : ''; // Default to current month
                                            echo "<option value=\"$date\" $selected>$display</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-12">
                                    <label for="single_description">Description</label>
                                    <input type="text" class="form-control form-control-lg" id="single_description" name="description" placeholder="Optional" autocomplete="off">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="col-md-12 text-right">
                                    <button type="button" class="btn btn-success btn-lg px-4 shadow-sm" id="submitSinglePaymentBtn"><i class="fas fa-check-circle mr-1"></i> Submit Payment</button>
                                </div>
                            </div>
                            <div id="single-payment-feedback" class="mt-2"></div>
<?php ob_start(); ?>
<!-- Single Payment Confirmation Modal -->
<div class="modal fade" id="singlePaymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="singlePaymentConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="singlePaymentConfirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Payment</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Are you sure you want to submit this payment?</div>
        <ul class="list-group mb-2">
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Type</span>
            <span id="confirmSingleType" class="font-weight-bold"></span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Amount</span>
            <span id="confirmSingleAmount" class="font-weight-bold"></span>
          </li>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <span>Mode</span>
            <span id="confirmSingleMode" class="font-weight-bold"></span>
          </li>
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmSinglePaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
      </div>
    </div>
  </div>
</div>
<?php $modal_html .= ob_get_clean(); ?>
                        </form>
                    </div>
                    <!-- Bulk Payment Panel -->
                    <div class="tab-pane fade" id="bulkPanel" role="tabpanel" aria-labelledby="bulk-tab">
                        <div class="card border-primary shadow-sm mb-2">
                            <div class="card-header bg-primary text-white py-2"><b>Bulk Payment Entry</b></div>
                            <div class="card-body">
                                <form id="bulkPaymentEntryForm" autocomplete="off" onsubmit="return false;">
                                    <div class="form-row align-items-end">
                                        <div class="form-group col-md-4">
                                            <label for="bulk_payment_type_id">Payment Type <span class="text-danger">*</span></label>
                                            <select class="form-control form-control-lg" id="bulk_payment_type_id" name="bulk_payment_type_id">
                                                <option value="">-- Select Type --</option>
                                                <?php $types2 = $conn->query("SELECT id, name FROM payment_types ORDER BY name");
                                                if ($types2 && $types2->num_rows > 0): while($t = $types2->fetch_assoc()): ?>
                                                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label for="bulk_amount">Amount (₵) <span class="text-danger">*</span></label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-lg" id="bulk_amount" name="bulk_amount" placeholder="e.g. 100.00">
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label for="bulk_mode">Mode <span class="text-danger">*</span></label>
                                            <select class="form-control form-control-lg" id="bulk_mode" name="bulk_mode">
                                                <option value="">-- Select --</option>
                                                <option value="Cash">Cash</option>
                                                <option value="Cheque">Cheque</option>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label for="bulk_payment_date">Date <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control form-control-lg" id="bulk_payment_date" name="bulk_payment_date" value="<?= date('Y-m-d') ?>" required>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label for="bulk_payment_period">Period <span class="text-danger">*</span></label>
                                            <select class="form-control form-control-lg" id="bulk_payment_period" name="bulk_payment_period" required>
                                                <option value="">-- Select Period --</option>
                                                <?php
                                                // Generate payment period options (current month and previous 12 months)
                                                for ($i = 0; $i < 12; $i++) {
                                                    $date = date('Y-m-01', strtotime("-$i months"));
                                                    $display = date('F Y', strtotime($date));
                                                    $selected = ($i == 0) ? 'selected' : ''; // Default to current month
                                                    echo "<option value=\"$date\" $selected>$display</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                <!--        <div class="form-group col-md-2">
                                            <label for="bulk_description">Description</label>
                                            <input type="text" class="form-control form-control-lg" id="bulk_description" name="bulk_description" placeholder="Optional">
                                        </div> -->
                                    </div>
                                    <div class="form-row">
                                        <div class="col-md-12 text-right">
                                            <button type="button" class="btn btn-success btn-lg px-4 shadow-sm" id="addToBulkBtn"><i class="fas fa-plus-circle mr-1"></i> Add to Bulk</button>
                                        </div>
                                    </div>
                                </form>
                                <hr>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mt-2" id="bulkPaymentsTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>#</th>
                                                <th>Type</th>
                                                <th>Amount</th>
                                                <th>Mode</th>
                                                <th>Date</th>
                                                <th>Period</th>
                                                <th>Description</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>
                                <div id="bulkPaymentsFooter" class="text-right mt-2">
                                    <span class="font-weight-bold">Total: <span id="bulkPaymentsTotal">₵0.00</span></span>
                                    <button type="button" class="btn btn-primary btn-lg ml-3" id="submitBulkPaymentsBtn" disabled><i class="fas fa-check mr-1"></i> Submit All Payments</button>
                                </div>
                                <div id="bulk-payment-feedback" class="mt-2"></div>
<?php ob_start(); ?>
<!-- Bulk Payment Confirmation Modal -->
<div class="modal fade" id="bulkPaymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="bulkPaymentConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="bulkPaymentConfirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Bulk Payments</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="mb-2">Are you sure you want to submit all these payments?</div>
        <div class="table-responsive">
          <table class="table table-bordered table-sm mb-0" id="bulkConfirmTable">
            <thead class="thead-light">
              <tr>
                <th>#</th>
                <th>Type</th>
                <th>Amount</th>
                <th>Mode</th>
                <th>Date</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody></tbody>
            <tfoot>
              <tr>
                <td colspan="2" class="text-right font-weight-bold">Total</td>
                <td colspan="4" class="font-weight-bold" id="bulkConfirmTotal"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmBulkPaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
      </div>
    </div>
  </div>
</div>
<?php $modal_html .= ob_get_clean(); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/js/all.min.js"></script>
<script src="payment_form_multi.js"></script>
<script>
$(function(){
    // Auto-populate description field
    function updateDescriptionField() {
        var paymentType = $('#single_payment_type_id option:selected').text();
        var periodVal = $('#single_payment_period').val();
        var periodText = $('#single_payment_period option:selected').text();
        
        if (paymentType && paymentType !== '-- Select --' && periodText && periodText !== '-- Select Period --') {
            $('#single_description').val('Payment for ' + periodText + ' ' + paymentType);
        } else {
            $('#single_description').val('');
        }
    }
    $('#single_payment_type_id, #single_payment_period').on('change', updateDescriptionField);
    // Initial auto-populate on page load (if both fields have value)
    updateDescriptionField();

    // --- Member Search ---
    $('#searchMemberForm').on('submit', function(e) {
        e.preventDefault();
        var crn = $('#crn').val().trim();
        if (!crn) {
            $('#crn-feedback').text('Please enter a CRN.');
            $('#member-summary').addClass('d-none').empty();
            $('#payment-panels').addClass('d-none');
            return;
        }
        $('#findMemberBtn').prop('disabled', true);
        $('#crn-spinner').removeClass('d-none');
        $('#crn-feedback').text('Searching...');
        $.get('ajax_get_person_by_id.php', {id: crn}, function(resp) {
            $('#findMemberBtn').prop('disabled', false);
            $('#crn-spinner').addClass('d-none');
            if (resp.success) {
                let summaryHtml = '';
                let m = resp.data;
                if (resp.type === 'member') {
                    summaryHtml = `<div class="card border-success shadow-sm mb-2 animate__animated animate__fadeIn member-summary-card">
  <div class="card-body py-2">
    <div class="d-flex align-items-center mb-2">
      <span class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-2 icon"><i class="fas fa-check"></i></span>
      <span class="h6 mb-0 font-weight-bold text-success">Member Found</span>
      <span class="badge badge-primary ml-3">Member</span>
    </div>
    <div class="member-details-row row justify-content-center py-2">
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-id-card text-primary mr-1 icon"></i>CRN</div>
        <div class="info-value">${m.crn}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-user text-secondary mr-1 icon"></i>Name</div>
        <div class="info-value">${m.first_name} ${m.last_name}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-phone-alt text-info mr-1 icon"></i>Phone</div>
        <div class="info-value">${m.phone ? m.phone : '-'}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto">
        <div class="info-label"><i class="fas fa-chalkboard-teacher text-success mr-1 icon"></i>Class</div>
        <div class="info-value">${m.class_name ? m.class_name : '-'}</div>
      </div>
    </div>
  </div>
</div>`;
                    $('#single_member_id').val(m.id);
                    $('#single_sundayschool_id').val('');
                } else if (resp.type === 'sundayschool') {
                    summaryHtml = `<div class="card border-success shadow-sm mb-2 animate__animated animate__fadeIn member-summary-card">
  <div class="card-body py-2">
    <div class="d-flex align-items-center mb-2">
      <span class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mr-2 icon"><i class="fas fa-check"></i></span>
      <span class="h6 mb-0 font-weight-bold text-success">Child Found</span>
      <span class="badge badge-warning ml-3">Sunday School</span>
    </div>
    <div class="member-details-row row justify-content-center py-2">
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-id-card text-primary mr-1 icon"></i>SRN</div>
        <div class="info-value">${m.srn}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-user text-secondary mr-1 icon"></i>Name</div>
        <div class="info-value">${m.first_name} ${m.last_name}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto mb-2 mb-md-0 mr-md-3">
        <div class="info-label"><i class="fas fa-phone-alt text-info mr-1 icon"></i>Phone</div>
        <div class="info-value">${m.contact ? m.contact : '-'}</div>
      </div>
      <span class="divider d-none d-md-inline mx-2"></span>
      <div class="details-item text-center col-6 col-md-auto">
        <div class="info-label"><i class="fas fa-chalkboard-teacher text-success mr-1 icon"></i>Class</div>
        <div class="info-value">${m.class_id ? m.class_id : '-'}</div>
      </div>
    </div>
  </div>
</div>`;
                    $('#single_member_id').val('');
                    $('#single_sundayschool_id').val(m.id);
                }
                $('#member-summary').removeClass('d-none').html(summaryHtml);
                $('#payment-panels').removeClass('d-none');
                window.setBulkMember && window.setBulkMember(m, resp.type); // For bulk, pass type
                $('#crn-feedback').text('');
            } else {
                $('#crn-feedback').text(resp.msg||'Member not found.');
                $('#member-summary').addClass('d-none').empty();
                $('#payment-panels').addClass('d-none');
            }
        }, 'json').fail(function(){
            $('#findMemberBtn').prop('disabled', false);
            $('#crn-spinner').addClass('d-none');
            $('#crn-feedback').text('Network/server error.');
        });
    });

    // --- Single Payment Submission ---
    // Confirmation modal logic for single payment
    let singlePaymentConfirmed = false;
    $('#submitSinglePaymentBtn').on('click', function(e){
        // Validate fields
        var member_id = $('#single_member_id').val();
        var sundayschool_id = $('#single_sundayschool_id').val();
        var type_id = $('#single_payment_type_id').val();
        var amount = $('#single_amount').val();
        var mode = $('#single_mode').val();
        var date = $('#single_payment_date').val();
        var desc = $('#single_description').val();
        if ((!member_id && !sundayschool_id) || !type_id || !amount || !mode || !date) {
            $('#single-payment-feedback').html('<div class="alert alert-danger">Please fill all required fields.</div>');
            return;
        }
        // Populate modal summary
        $('#confirmSingleType').text($('#single_payment_type_id option:selected').text());
        $('#confirmSingleAmount').text('₵' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits:2}));
        $('#confirmSingleMode').text($('#single_mode option:selected').text());
        // Show modal
        $('#singlePaymentConfirmModal').modal('show');
    });
    // Modal confirm button
    $('#confirmSinglePaymentBtn').on('click', function(){
        if ($(this).prop('disabled')) return;
        $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...');
        $('#single-payment-feedback').html('<div class="alert alert-info"><i class="fas fa-clock mr-2"></i>Processing payment and sending notifications...</div>');
        var member_id = $('#single_member_id').val();
        var sundayschool_id = $('#single_sundayschool_id').val();
        var type_id = $('#single_payment_type_id').val();
        var amount = $('#single_amount').val();
        var mode = $('#single_mode').val();
        var date = $('#single_payment_date').val();
        var desc = $('#single_description').val();
        var period = $('#single_payment_period').val();
        var period_text = $('#single_payment_period option:selected').text();
        var payload = {payments: [{type_id: type_id, amount: amount, mode: mode, date: date, desc: desc, period: period, period_text: period_text, type_text: $('#single_payment_type_id option:selected').text()}]};
        if (member_id) payload.member_id = member_id;
        if (sundayschool_id) payload.sundayschool_id = sundayschool_id;
        $.ajax({
            url: 'ajax_bulk_payments_single_member.php',
            type: 'POST',
            data: JSON.stringify(payload),
            contentType: 'application/json',
            dataType: 'json',
            timeout: 30000, // 30 second timeout for SMS processing
            success: function(resp){
                let typeMap = {};
                $('#single_payment_type_id option').each(function(){
                    if ($(this).val()) typeMap[$(this).val()] = $(this).text();
                });
                if (resp.success) {
                    let successMsg = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Payment saved successfully.';
                    if (resp.sms_sent) {
                        successMsg += ' <i class="fas fa-sms text-info" title="SMS notification sent"></i>';
                    }
                    successMsg += '</div>';
                    $('#single-payment-feedback').html(successMsg);
                    setTimeout(function(){ window.location.href = 'payment_list.php?added=1'; }, 1500);
                } else {
                    let msg = resp.msg || 'Error saving payment.';
                    if (resp.failed && Array.isArray(resp.failed)) {
                        msg += '<br><br>Failed payments:';
                        resp.failed.forEach(function(f){
                            let typeName = typeMap[f.type_id] || ('Type ID ' + f.type_id);
                            let reason = f.reason.replace(/type ID (\d+)/i, typeName);
                            msg += `<br>- ${typeName}: ${reason}`;
                        });
                    }
                    $('#single-payment-feedback').html('<div class="alert alert-danger">'+msg+'</div>');
                }
            },
            error: function(xhr, status, err){
                let msg = 'Network/server error.';
                if (status === 'timeout') {
                    msg = 'Payment processing is taking longer than expected. Please check the payment list to verify if your payment was recorded.';
                } else if (xhr && xhr.responseText) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        msg = resp.msg || msg;
                    } catch(e) {
                        msg = 'Server response error. Please check if payment was recorded.';
                    }
                }
                $('#single-payment-feedback').html('<div class="alert alert-warning"><i class="fas fa-exclamation-triangle mr-2"></i>'+msg+'</div>');
            },
            complete: function(){
                $('#submitSinglePaymentBtn').prop('disabled', false).text('Submit Payment');
                $('#confirmSinglePaymentBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i>Confirm & Submit');
                $('#singlePaymentConfirmModal').modal('hide');
            }
        });
    });
    // Prevent form submit on Enter
    $('#singlePaymentForm').on('submit', function(e){ e.preventDefault(); });

    // --- Tab switching resets feedback ---
    $('a[data-toggle="tab"]').on('shown.bs.tab', function(e){
        $('#single-payment-feedback').empty();
        $('#bulk-payment-feedback').empty();
    });
});
</script>
<style>
.member-summary-card .icon {
  width: 1.5rem; height: 1.5rem; font-size: 1rem;
}
.member-summary-card .info-label {
  font-size: 0.95rem; color: #888;
}
.member-summary-card .info-value {
  font-size: 1.25rem; font-weight: bold; color: #222;
  line-height: 1.2;
}
.member-summary-card .card-body { padding: 0.9rem 1rem; }
.member-details-row .details-item {
  min-width: 110px;
  display: flex;
  flex-direction: column;
  align-items: center;
}
@media (max-width: 767.98px) {
  .member-details-row {
    flex-direction: row !important;
    flex-wrap: wrap;
    text-align: center;
  }
  .member-details-row .divider {
    display: none !important;
  }
  .member-details-row .details-item {
    margin-right: 0 !important;
    margin-bottom: 0.7rem !important;
  }
}

.member-details-row .divider {
  border-left: 1.5px solid #e0e0e0;
  height: 2.2em;
  margin: 0 8px;
}
.member-details-row .info-label {
  font-size: 0.95rem;
  color: #888;
  font-weight: 600;
  line-height: 1.1;
}
.member-details-row .info-value {
  font-size: 1.15rem;
  font-weight: 500;
  color: #222;
  line-height: 1.2;
}
.member-summary-card .fa-phone-alt { color: #17a2b8; }
</style>
<?php $page_content = ob_get_clean(); include '../includes/layout.php'; ?>
