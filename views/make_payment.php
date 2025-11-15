<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../helpers/auth.php';
if (!is_logged_in()) {
    header('Location: '.BASE_URL.'/login.php');
    exit;
}
// Permission check
if (!has_permission('make_payment')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

ob_start();
?>
<div class="container py-4" style="max-width: 900px;">
  <!-- Member Summary -->
  <div class="card mb-4 shadow-sm border-0 bg-light">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
      <div class="mb-2 mb-md-0">
        <?php
// Patch: Always show class and phone, even if not in session
$member_id = $_SESSION['member_id'] ?? null;
$crn = $_SESSION['crn'] ?? '';
$full_name = $_SESSION['full_name'] ?? '';
$class_name = $_SESSION['class_name'] ?? '';
$phone = $_SESSION['phone'] ?? '';
$church_id = $_SESSION['church_id'] ?? null;
if (!$church_id && $member_id) {
    $stmt = $conn->prepare('SELECT church_id FROM members WHERE id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $church_id = $stmt->get_result()->fetch_assoc()['church_id'] ?? null;
}
if ((!$class_name || !$phone) && $member_id) {
  $stmt = $conn->prepare('SELECT crn, CONCAT(last_name, ", ", first_name, IF(middle_name != "", CONCAT(" ", middle_name), "")) as full_name, phone, class_id FROM members WHERE id = ?');
  $stmt->bind_param('i', $member_id);
  $stmt->execute();
  $m = $stmt->get_result()->fetch_assoc();
  if ($m) {
    $crn = $m['crn'];
    $full_name = $m['full_name'];
    $phone = $m['phone'];
    // Get class name
    $class_name = '';
    if (!empty($m['class_id'])) {
      $res = $conn->query('SELECT name FROM bible_classes WHERE id = '.intval($m['class_id']));
      if ($row = $res->fetch_assoc()) $class_name = $row['name'];
    }
  }
}
?>
<div class="font-weight-bold text-primary" style="font-size: 1.2rem;">Welcome, <?php echo htmlspecialchars($full_name); ?></div>
<div class="small text-muted">CRN: <b><?php echo htmlspecialchars($crn); ?></b> | Class: <b><?php echo htmlspecialchars($class_name); ?></b></div>
<div class="small text-muted">Phone: <b><?php echo htmlspecialchars($phone); ?></b></div>
      </div>
    </div>
  </div>
  <!-- Tabs -->
  <ul class="nav nav-pills nav-justified mb-3" id="payTab" role="tablist">
    <li class="nav-item">
      <a class="nav-link active" id="single-tab" data-toggle="pill" href="#singlePanel" role="tab" aria-controls="singlePanel" aria-selected="true"><i class="fas fa-money-bill-wave"></i> Single Payment</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="bulk-tab" data-toggle="pill" href="#bulkPanel" role="tab" aria-controls="bulkPanel" aria-selected="false"><i class="fas fa-layer-group"></i> Bulk Payment</a>
    </li>
  </ul>
  <div class="tab-content" id="payTabContent">
    <!-- Single Payment -->
    <div class="tab-pane fade show active" id="singlePanel" role="tabpanel" aria-labelledby="single-tab">
      <form id="singlePaymentForm" autocomplete="off">
        <div class="form-row">
          <div class="form-group col-md-4">
            <label for="single_payment_type">Payment Type <span class="text-danger">*</span></label>
            <select class="form-control form-control-lg" id="single_payment_type" name="payment_type_id" required>
              <option value="">-- Select Type --</option>
              <?php $types = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name");
                if ($types && $types->num_rows > 0): while($t = $types->fetch_assoc()): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
              <?php endwhile; endif; ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label for="single_amount">Amount (₵) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="1" class="form-control form-control-lg" id="single_amount" name="amount" placeholder="e.g. 100.00" required>
          </div>
          <div class="form-group col-md-3">
            <label for="single_mode">Mode <span class="text-danger">*</span></label>
            <select class="form-control form-control-lg" id="single_mode" name="mode" readonly required>
             
              <option value="Cash">Hubtel</option>
             
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
                $selected = ($i == 0) ? 'selected' : '';
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
            <button type="submit" class="btn btn-success btn-lg px-4 shadow-sm" id="submitSinglePaymentBtn"><i class="fas fa-credit-card mr-1"></i> Pay Now</button>
          </div>
        </div>
        <div id="single-payment-feedback" class="mt-2"></div>
        <script>
        // Auto-populate description field
        function updateDescriptionField() {
          var paymentType = $('#single_payment_type option:selected').text();
          var periodText = $('#single_payment_period option:selected').text();
          if (paymentType && paymentType !== '-- Select Type --' && periodText && periodText !== '-- Select Period --') {
            $('#single_description').val('Payment for ' + periodText + ' ' + paymentType);
          } else {
            $('#single_description').val('');
          }
        }
        $('#single_payment_type, #single_payment_period').on('change', updateDescriptionField);
        $(document).ready(updateDescriptionField);
        </script>
      </form>
    </div>
    <!-- Bulk Payment -->
    <div class="tab-pane fade" id="bulkPanel" role="tabpanel" aria-labelledby="bulk-tab">
      <div class="card border-primary shadow-sm mb-3">
        <div class="card-header bg-primary text-white py-3 d-flex justify-content-between align-items-center">
          <span style="font-size:1.25rem"><i class="fas fa-layer-group mr-2"></i>Bulk Payment Entry</span>
          <span class="badge badge-light text-primary px-3 py-2" style="font-size:1rem;">Professional Teller Mode</span>
        </div>
        <div class="card-body bg-light">
          <div class="row mb-3">
            <div class="form-group" hidden>
              <label for="payment_method">Payment Method</label>
              <select class="form-control" id="payment_method" name="payment_method" required readonly>
                <option value="hubtel" selected>Hubtel</option>
              </select>
            </div>
          </div>
          <form id="bulkPaymentEntryForm" autocomplete="off" onsubmit="return false;">
            <div class="form-row align-items-end mb-2">
              <div class="form-group col-md-4 mb-2">
                <label for="bulk_payment_type_id" class="font-weight-bold">Payment Type <span class="text-danger">*</span></label>
                <select class="form-control form-control-lg border-primary" id="bulk_payment_type_id" name="bulk_payment_type_id">
                  <option value="">-- Select Type --</option>
                  <?php $types2 = $conn->query("SELECT id, name FROM payment_types WHERE active=1 ORDER BY name");
                  if ($types2 && $types2->num_rows > 0): while($t = $types2->fetch_assoc()): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                  <?php endwhile; endif; ?>
                </select>
              </div>
              <div class="form-group col-md-2 mb-2">
                <label for="bulk_amount" class="font-weight-bold">Amount (₵) <span class="text-danger">*</span></label>
                <input type="number" step="0.01" min="1" class="form-control form-control-lg border-primary" id="bulk_amount" name="bulk_amount" placeholder="e.g. 100.00">
              </div>
              <div class="form-group col-md-2 mb-2" hidden>
                <label for="bulk_payment_date" class="font-weight-bold">Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control form-control-lg border-primary" id="bulk_payment_date" name="bulk_payment_date" value="<?= date('Y-m-d') ?>" readonly>
              </div>
              <div class="form-group col-md-4 mb-2">
                <label for="bulk_payment_period" class="font-weight-bold">Period <span class="text-danger">*</span></label>
                <select class="form-control form-control-lg border-primary" id="bulk_payment_period" name="bulk_payment_period">
                  <option value="">-- Select Period --</option>
                  <?php
                  for ($i = 0; $i < 12; $i++) {
                    $date = date('Y-m-01', strtotime("-$i months"));
                    $display = date('F Y', strtotime($date));
                    $selected = ($i == 0) ? 'selected' : '';
                    echo "<option value=\"$date\" $selected>$display</option>";
                  }
                  ?>
                </select>
              </div>
              <!-- <div class="form-group col-md-3 mb-2">
                <label for="bulk_description" class="font-weight-bold">Description</label>
                <input type="text" class="form-control form-control-lg border-primary" id="bulk_description" name="bulk_description" placeholder="Optional">
              </div> -->
              
              <div class="form-group col-md-1 mb-2 text-right">
                <button type="button" class="btn btn-success btn-lg px-3 shadow-sm mt-4 w-100" id="addToBulkBtn" style="min-width:44px;">
                  <i class="fas fa-plus-circle"></i>
                </button>
              </div>
            </div>
          </form>
          <hr class="my-3">
          <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm mt-2 bg-white" id="bulkPaymentsTable">
              <thead class="thead-light">
                <tr style="font-size:1.07rem;">
                  <th>#</th>
                  <th>Type</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Payment Period</th>
                  <th>Description</th>
                  <th></th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div id="bulkPaymentsFooter" class="d-flex justify-content-between align-items-center mt-3">
            <span class="font-weight-bold" style="font-size:1.15rem;">Total: <span id="bulkPaymentsTotal">₵0.00</span></span>
            <button type="button" class="btn btn-primary btn-lg px-4" id="submitBulkPaymentsBtn" disabled><i class="fas fa-check mr-1"></i> Submit All Payments</button>
          </div>
          <div id="bulk-payment-feedback" class="mt-3"></div>
        </div>
      </div>
      <style>
        #bulkPaymentsTable th, #bulkPaymentsTable td { vertical-align: middle; }
        #bulkPaymentsTable tbody tr:hover { background: #e3f2fd; }
        #bulkPaymentEntryForm .form-control-lg { font-size: 1.15rem; }
        #bulkPaymentEntryForm label { margin-bottom: 0.25rem; }
        @media (max-width: 900px) {
          .container { max-width: 99vw !important; }
        }
      </style>
    </div>
    
  </div>
</div>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/animate.min.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/custom.css">
<style>
.card-hover:hover {
  background: #f4f8ff;
  box-shadow: 0 2px 8px rgba(0,0,0,0.07);
}
.btn-outline-primary:focus, .btn-outline-primary.active {
  background: #e3f2fd;
  color: #1565c0;
  border-color: #1565c0;
}
#bulkCartList .remove-bulk-item {
  color: #e53935;
  cursor: pointer;
}
</style>

<script>
// --- Single Payment ---
$('#singlePaymentForm').on('submit', function(e){
  e.preventDefault();
  let typeId = $('#single_payment_type').val();
  let typeName = $('#single_payment_type option:selected').text();
  let amount = parseFloat($('#single_amount').val());
  let paymentMethod = $('#payment_method').val();
  if (!typeId || !amount || amount < 1) {
    $('#single-payment-feedback').html('<div class="alert alert-danger">Please select a payment type and enter a valid amount.</div>');
    return;
  }
  $('#confirmSummary').html(`<div>Type: <b>${typeName}</b><br>Amount: <b>₵${amount.toLocaleString(undefined,{minimumFractionDigits:2})}</b></div>`);
  $('#paymentConfirmModal').modal('show');
  $('#confirmPayBtn').off('click').on('click', function(){
    $('#paymentConfirmModal').modal('hide');
    let endpoint = paymentMethod === 'paystack' ? '<?php echo BASE_URL; ?>/views/ajax_paystack_checkout.php' : '<?php echo BASE_URL; ?>/views/ajax_hubtel_checkout.php';
    let feedbackMsg = paymentMethod === 'paystack' ? 'Contacting Paystack...' : 'Contacting Hubtel...';
    $('#single-payment-feedback').html('<div class="alert alert-info">'+feedbackMsg+'</div>');
    let periodText = $('#single_payment_period option:selected').text();
    let periodValue = $('#single_payment_period').val();
    let payload = {
      amount: amount,
      description: 'Payment for ' + periodText + ' ' + typeName,
      payment_type_id: typeId,
      payment_type_name: typeName,
      payment_period: periodValue,
      payment_period_description: periodText,
      customerName: <?php echo json_encode($full_name); ?>,
      customerPhone: <?php echo json_encode($phone); ?>,
      member_id: <?php echo json_encode($member_id); ?>,
      church_id: <?php echo json_encode($church_id); ?>
    };

    if (paymentMethod === 'paystack') {
      payload.customerEmail = <?php echo json_encode($_SESSION['email'] ?? ''); ?>;
    }
    $.post(
      endpoint,
      payload,
      function(resp) {
        if (resp.success && resp.checkoutUrl) {
          window.location.href = resp.checkoutUrl;
        } else {
          let debugMsg = resp.debug ? `<pre class='small bg-light p-2 border rounded mt-2'>${JSON.stringify(resp.debug, null, 2)}</pre>` : '';
          $('#single-payment-feedback').html('<div class="alert alert-danger">'+(resp.error || 'Could not initiate payment. Please try again.')+debugMsg+'</div>');
        }
      },
      'json'
    ).fail(function(xhr){
      $('#single-payment-feedback').html('<div class="alert alert-danger">Failed to contact '+(paymentMethod==='paystack'?'Paystack':'Hubtel')+'. Try again later.</div>');
    });
  });
});
// --- Bulk Payment Logic ---
let bulkCart = [];
function updateBulkCartUI() {
  console.log('updateBulkCartUI called, bulkCart:', bulkCart);
  let $tbody = $('#bulkPaymentsTable tbody');
  $tbody.empty();
  let total = 0;
  bulkCart.forEach(function(item, idx){
    total += item.amount;
    $tbody.append(`
      <tr data-idx="${idx}">
        <td>${idx+1}</td>
        <td>
          <select class="form-control form-control-sm bulk-type-input" data-idx="${idx}">
            ${$('#bulk_payment_type_id').html().replace('selected', '')}
          </select>
        </td>
        <td><input type="number" step="0.01" min="1" class="form-control form-control-sm bulk-amount-input" data-idx="${idx}" value="${item.amount}"></td>
        <td><input type="date" class="form-control form-control-sm" value="${item.date}" readonly></td>
        <td>
          <select class="form-control form-control-sm bulk-period-input" data-idx="${idx}">
            ${$('#bulk_payment_period').html().replace('selected', '')}
          </select>
        </td>
        <td><input type="text" class="form-control form-control-sm bulk-desc-input" data-idx="${idx}" value="${item.desc || ''}"></td>
        <td><span class="remove-bulk-item text-danger ml-2" style="cursor:pointer;" data-idx="${idx}">&times;</span></td>
      </tr>
    `);
    // Set current value for type and period
    $tbody.find(`.bulk-type-input[data-idx="${idx}"]`).val(item.typeId);
    $tbody.find(`.bulk-period-input[data-idx="${idx}"]`).val(item.period);
  });
  // Attach change handlers
  // Track if user has manually edited description for each row
  if (!window.bulkDescManualEdit) window.bulkDescManualEdit = {};

  $tbody.find('.bulk-type-input').off('change').on('change', function(){
    let idx = $(this).data('idx');
    let typeId = $(this).val();
    let typeName = $(this).find('option:selected').text();
    bulkCart[idx].typeId = typeId;
    bulkCart[idx].typeName = typeName;
    // Only auto-update desc if not manually edited
    if (!window.bulkDescManualEdit[idx]) {
      let periodText = $tbody.find(`.bulk-period-input[data-idx="${idx}"] option:selected`).text();
      if (typeName && typeName !== '-- Select Type --' && periodText && periodText !== '-- Select Period --') {
        let desc = 'Payment for ' + periodText + ' ' + typeName;
        bulkCart[idx].desc = desc;
        $tbody.find(`.bulk-desc-input[data-idx="${idx}"]`).val(desc);
      }
    }
    updateBulkCartUI();
  });
  $tbody.find('.bulk-amount-input').off('input').on('input', function(){
    let idx = $(this).data('idx');
    let amount = parseFloat($(this).val());
    if (!isNaN(amount)) bulkCart[idx].amount = amount;
    updateBulkCartUI();
  });
  $tbody.find('.bulk-period-input').off('change').on('change', function(){
    let idx = $(this).data('idx');
    let period = $(this).val();
    let periodText = $(this).find('option:selected').text();
    bulkCart[idx].period = period;
    bulkCart[idx].periodText = periodText;
    // Only auto-update desc if not manually edited
    if (!window.bulkDescManualEdit[idx]) {
      let typeName = $tbody.find(`.bulk-type-input[data-idx="${idx}"] option:selected`).text();
      if (typeName && typeName !== '-- Select Type --' && periodText && periodText !== '-- Select Period --') {
        let desc = 'Payment for ' + periodText + ' ' + typeName;
        bulkCart[idx].desc = desc;
        $tbody.find(`.bulk-desc-input[data-idx="${idx}"]`).val(desc);
      }
    }
    updateBulkCartUI();
  });
  $tbody.find('.bulk-desc-input').off('input').on('input', function(){
    let idx = $(this).data('idx');
    bulkCart[idx].desc = $(this).val();
    window.bulkDescManualEdit[idx] = true;
  });
  $('#bulkPaymentsTotal').text('₵'+total.toLocaleString(undefined,{minimumFractionDigits:2}));
  $('#submitBulkPaymentsBtn').prop('disabled', bulkCart.length===0);
}

$('#submitBulkPaymentsBtn').off('click').on('click', function(e){
  e.preventDefault();
  // Ensure only the bulk modal is shown and summary is populated ONCE
  $('#paymentConfirmModal').modal('hide'); // Hide single payment modal
  $('#bulkConfirmTable tbody').empty(); // Clear previous summary rows
  $('#bulkConfirmTotal').text('');
  if (bulkCart.length === 0) {
    $('#bulk-payment-feedback').html('<div class="alert alert-danger">Add at least one payment to your cart.</div>');
    return;
  }
  // Populate the bulk confirmation modal table (only once)
  let $tbody = $('#bulkConfirmTable tbody');
  let total = 0;
  bulkCart.forEach(function(item, idx){
    total += item.amount;
    $tbody.append(`
      <tr>
        <td>${idx+1}</td>
        <td>${item.typeName}</td>
        <td>₵${item.amount.toLocaleString(undefined,{minimumFractionDigits:2})}</td>
        <td>${item.date}</td>
        <td>${item.periodText || ''}</td>
        <td>${item.desc || ''}</td>
      </tr>
    `);
  });
  $('#bulkConfirmTotal').text('₵'+total.toLocaleString(undefined,{minimumFractionDigits:2}));
  $('#bulkPaymentConfirmModal').modal('show');
});

$(document).on('click', '#confirmBulkPaymentBtn', function(){
  console.log('Bulk payment: Confirm & Submit clicked');
  $('#bulkPaymentConfirmModal').modal('hide');
  let paymentMethod = $('#payment_method').val(); // Fix: use correct selector
  let endpoint = paymentMethod === 'paystack' ? '<?php echo BASE_URL; ?>/views/ajax_paystack_checkout.php' : '<?php echo BASE_URL; ?>/views/ajax_hubtel_checkout.php';
  let feedbackMsg = paymentMethod === 'paystack' ? 'Contacting Paystack...' : 'Contacting Hubtel...';
  $('#bulk-payment-feedback').html('<div class="alert alert-info">'+feedbackMsg+'</div>');
  // Calculate total and description
  let total = 0;
  let descArr = [];
  bulkCart.forEach(function(item){
    total += item.amount;
    descArr.push(item.desc || item.typeName);
  });
  let description = 'Bulk Payment ['+descArr.join(', ')+']';
  let payload = {
    amount: total,
    description: description,
    customerName: <?php echo json_encode($full_name); ?>,
    customerPhone: <?php echo json_encode($phone); ?>,
    member_id: <?php echo json_encode($member_id); ?>,
    church_id: <?php echo json_encode($church_id); ?>,
    bulk_items: bulkCart.map(item => ({
      typeId: item.typeId,
      typeName: item.typeName,
      amount: item.amount,
      date: item.date,
      period: item.period,
      periodText: item.periodText,
      desc: item.desc
    }))
  };
  // Paystack requires email; if missing, prompt
  if (paymentMethod === 'paystack') {
    payload.customerEmail = <?php echo json_encode($_SESSION['email'] ?? ''); ?>;
    if (!payload.customerEmail) {
      // Show modal to prompt for email
      $('#paystackBulkEmailInput').val('');
      $('#paystackBulkEmailError').text('');
      $('#paystackEmailPromptModal').modal('show');
      $('#paystackBulkEmailSubmitBtn').off('click').on('click', function(){
        var email = $('#paystackBulkEmailInput').val().trim();
        if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
          $('#paystackBulkEmailError').text('Please enter a valid email address.');
          return;
        }
        $('#paystackEmailPromptModal').modal('hide');
        setTimeout(function() {
          payload.customerEmail = email;
          console.log('Email entered, modal closed, submitting bulk paystack:', {endpoint, payload});
          submitBulkPaystack(endpoint, payload);
        }, 400); // Wait for modal to fully hide
      });
      return; // Wait for user to submit email
    }
  }
  // Debug: Log payload and endpoint
  console.log('Submitting bulk payment:', {endpoint, payload});
  submitBulkPaystack(endpoint, payload);
});

function submitBulkPaystack(endpoint, payload) {
  // Debug: Log payload and endpoint
  console.log('submitBulkPaystack() called:', {endpoint, payload});
  $.post(
    endpoint,
    payload,
    function(resp) {
      console.log((endpoint.includes('paystack') ? 'Paystack' : 'Hubtel')+' AJAX response:', resp);
      if (resp.success && resp.checkoutUrl) {
        window.location.href = resp.checkoutUrl;
      } else {
        $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+(resp.error || 'Could not initiate payment. Please try again.')+(resp.debug ? `<pre class='small bg-light p-2 border rounded mt-2'>${JSON.stringify(resp.debug, null, 2)}</pre>` : '')+'</div>');
      }
    },
    'json'
  ).fail(function(xhr){
    $('#bulk-payment-feedback').html('<div class="alert alert-danger">Failed to contact '+(endpoint.includes('paystack')?'Paystack':'Hubtel')+'. Try again later.</div>');
  });
}


$('#addToBulkBtn').on('click', function(){
  console.log('Add to Bulk button clicked');
  let typeId = $('#bulk_payment_type_id').val();
  let typeName = $('#bulk_payment_type_id option:selected').text();
  let amount = parseFloat($('#bulk_amount').val());
  let date = $('#bulk_payment_date').val();
  if (!date) {
    // Always set to today if empty
    let now = new Date();
    date = now.toISOString().slice(0, 10);
    $('#bulk_payment_date').val(date);
  }
  let period = $('#bulk_payment_period').val();
  let periodText = $('#bulk_payment_period option:selected').text();
  // Auto-populate description if not manually entered
  let desc = $('#bulk_description').val();
  if (!desc && typeName && periodText && typeName !== '-- Select Type --' && periodText !== '-- Select Period --') {
    desc = 'Payment for ' + periodText + ' ' + typeName;
    $('#bulk_description').val(desc);
  }
  console.log('typeId:', typeId, '| typeName:', typeName, '| amount:', amount, '| date:', date, '| period:', period, '| desc:', desc);
  if (!typeId) {
    console.log('No payment type selected');
  }
  if (!amount || amount < 1) {
    console.log('Invalid amount:', amount);
  }
  if (!date) {
    console.log('No date selected');
  }
  if (!period) {
    console.log('No period selected');
  }
  if (!typeId || !amount || amount < 1 || !date || !period) {
    $('#bulk-payment-feedback').html('<div class="alert alert-danger">Please select a payment type, enter a valid amount, select a date, and select a period.</div>');
    return;
  }
  bulkCart.push({typeId, typeName, amount, date, period, periodText, desc});
  console.log('Added to bulkCart:', bulkCart);
  updateBulkCartUI();
  // Reset form fields and set date to today
  $('#bulk_payment_type_id').val('');
  $('#bulk_amount').val('');
  let today = new Date().toISOString().slice(0, 10);
  $('#bulk_payment_date').val(today);
  $('#bulk_payment_period').val('');
  $('#bulk_description').val('');
  $('#bulk-payment-feedback').empty();
});

$(document).on('click', '.remove-bulk-item', function(){
  const idx = $(this).data('idx');
  bulkCart.splice(idx, 1);
  updateBulkCartUI();
});

// Disable form submit on Enter
$('#bulkPaymentEntryForm').on('submit', function(e){ e.preventDefault(); });

// Initial UI update
updateBulkCartUI();
$(document).on('click', '.add-bulk-btn', function(){
  let typeId = $(this).data('type-id');
  let typeName = $(this).data('type-name');
  let amount = parseFloat($(this).closest('.card').find('.bulk-amount-input').val());
  if (!amount || amount < 1) {
    alert('Enter a valid amount for '+typeName);
    return;
  }
  if (bulkCart.some(item => item.typeId == typeId)) {
    alert('This payment type is already in your cart.');
    return;
  }
  bulkCart.push({typeId, typeName, amount});
  updateBulkCartUI();
  $(this).closest('.card').find('.bulk-amount-input').val('');
});
$(document).on('click', '.remove-bulk-item', function(){
  let idx = $(this).data('idx');
  bulkCart.splice(idx, 1);
  updateBulkCartUI();
});
$('#bulkPaymentForm').on('submit', function(e){
  e.preventDefault();
  if (bulkCart.length === 0) {
    $('#bulk-payment-feedback').html('<div class="alert alert-danger">Add at least one payment to your cart.</div>');
    return;
  }
  let summaryHtml = '<ul>';
  let total = 0;
  bulkCart.forEach(function(item){
    summaryHtml += `<li>${item.typeName}: <b>₵${item.amount.toLocaleString(undefined,{minimumFractionDigits:2})}</b></li>`;
    total += item.amount;
  });
  summaryHtml += `</ul><div class="font-weight-bold mt-2">Total: ₵${total.toLocaleString(undefined,{minimumFractionDigits:2})}</div>`;
  $('#confirmSummary').html(summaryHtml);
  $('#paymentConfirmModal').modal('show');
  $(document).on('click', '#confirmBulkPaymentBtn', function(){
  console.log('Bulk payment: Confirm & Submit clicked');
  $('#paymentConfirmModal').modal('hide');
  $('#bulk-payment-feedback').html('<div class="alert alert-info">Contacting Hubtel...</div>');
  // Calculate total and description
  let total = 0;
  let descArr = [];
  bulkCart.forEach(function(item){
    total += item.amount;
    descArr.push(item.desc || item.typeName);
  });
  let description = 'Bulk Payment ['+descArr.join(', ')+']';
  $.post(
    '<?php echo BASE_URL; ?>/views/ajax_hubtel_checkout.php',
    {
      amount: total,
      description: description,
      customerName: <?php echo json_encode($full_name); ?>,
      customerPhone: <?php echo json_encode($phone); ?>,
      member_id: <?php echo json_encode($member_id); ?>,
      church_id: <?php echo json_encode($church_id); ?>,
      bulk_items: JSON.stringify(bulkCart.map(function(item) {
        return {
          typeId: item.typeId,
          typeName: item.typeName,
          amount: item.amount,
          date: item.date,
          desc: item.desc,
          payment_type_id: item.typeId,
          payment_period: item.date || null,
          payment_period_description: $('#bulk_payment_period option:selected').text() || null,
          church_id: <?php echo json_encode($church_id); ?>,
          recorded_by: <?php echo json_encode($_SESSION['user_id'] ?? null); ?>
        };
      }))
    },
    function(resp) {
      console.log('Hubtel AJAX response:', resp);
      if (resp.success && resp.checkoutUrl) {
        window.location.href = resp.checkoutUrl;
      } else {
        $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+(resp.error || 'Could not initiate payment. Please try again.')+(resp.debug ? `<pre class='small bg-light p-2 border rounded mt-2'>${JSON.stringify(resp.debug, null, 2)}</pre>` : '')+'</div>');
      }
    },
    'json'
  ).fail(function(xhr){
    $('#bulk-payment-feedback').html('<div class="alert alert-danger">Failed to contact Hubtel. Try again later.</div>');
  });
});
});
</script>
<?php
// Ensure Paystack email prompt modal is available
include __DIR__.'/bulk_paystack_email_prompt.php';
$page_content = ob_get_clean();
include __DIR__.'/../includes/layout.php';
?>

<!-- Confirmation Modal -->

<div class="modal fade" id="paymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="paymentConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="paymentConfirmModalLabel"><i class="fas fa-question-circle mr-2"></i>Confirm Payment</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="confirmSummary"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="confirmPayBtn"><i class="fas fa-credit-card mr-1"></i>Proceed to Pay</button>
      </div>
    </div>
  </div>
</div>

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
           
            
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-success" id="confirmBulkPaymentBtn"><i class="fas fa-check-circle mr-1"></i>Confirm & Submit</button>
            </div>
          </div>
        </div>
      </div>
    </div>