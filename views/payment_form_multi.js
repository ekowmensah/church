// payment_form_multi.js: Robust, modern rewrite for bulk payment UI
$(function(){
    // --- State ---
    let payments = [];
    let member = null;
    let allowBulkSubmit = false;

    // --- Utility: Render payments table ---
    function renderPayments() {
        const $tbody = $('#bulkPaymentsTable tbody');
        $tbody.empty();
        let total = 0;
        payments.forEach((p, idx) => {
            total += parseFloat(p.amount) || 0;
            $tbody.append(`
                <tr>
                    <td>${idx+1}</td>
                    <td><select class="form-control form-control-sm bulk-type-input" data-idx="${idx}">${$('#bulk_payment_type_id').html()}</select></td>
                    <td><input type="number" min="0" step="0.01" class="form-control form-control-sm bulk-amount-input" data-idx="${idx}" value="${p.amount}" style="width:100px;text-align:right"></td>
                    <td>
                        <select class="form-control form-control-sm bulk-mode-input" data-idx="${idx}" style="width:110px">
                            <option value="">-- Select --</option>
                            <option value="Cash"${p.mode==='Cash'?' selected':''}>Cash</option>
                            <option value="Cheque"${p.mode==='Cheque'?' selected':''}>Cheque</option>
                        </select>
                    </td>
                    <td><input type="date" class="form-control form-control-sm bulk-date-input" data-idx="${idx}" value="${p.date}" style="width:135px"></td>
                    <td>
                        <select class="form-control form-control-sm bulk-period-input" data-idx="${idx}" style="width:120px">
                            ${$('#bulk_payment_period').html()}
                        </select>
                    </td>
                    <td><input type="text" class="form-control form-control-sm bulk-desc-input" data-idx="${idx}" value="${p.desc||''}" data-autodesc="${p._autodesc||''}" style="width:140px"></td>
                    <td><button type="button" class="btn btn-link text-danger btn-sm remove-payment-row" data-idx="${idx}"><i class="fa fa-trash"></i></button></td>
                </tr>`);
            // Set type and period selection after rendering and trigger change for autofill
            $tbody.find(`.bulk-type-input[data-idx="${idx}"]`).val(p.type_id).trigger('change');
            $tbody.find(`.bulk-period-input[data-idx="${idx}"]`).val(p.period).trigger('change');
            $tbody.find(`.bulk-date-input[data-idx="${idx}"]`).trigger('change');
        });
        $('#bulkPaymentsTotal').text('₵'+total.toFixed(2));
        if (payments.length === 0) {
            $('#bulkPaymentsTable, #bulkPaymentsFooter').hide();
            $('#submitBulkPaymentsBtn').prop('disabled', true);
        } else {
            $('#bulkPaymentsTable, #bulkPaymentsFooter').show();
            $('#submitBulkPaymentsBtn').prop('disabled', false);
        }
    }

    // Utility: Generate auto-description for bulk payment row
    function getBulkAutoDescription(typeText, periodText) {
        if (typeText && typeText !== '-- Select --' && periodText && periodText !== '-- Select Period --') {
            return 'Payment for ' + periodText + ' ' + typeText;
        }
        return '';
    }

    // --- Add payment to bulk list ---
    function addToBulk() {
        const typeId = $('#bulk_payment_type_id').val();
        const typeText = $('#bulk_payment_type_id option:selected').text();
        const amount = $('#bulk_amount').val();
        const mode = $('#bulk_mode').val();
        const date = $('#bulk_payment_date').val();
        const period = $('#bulk_payment_period').val();
        const periodText = $('#bulk_payment_period option:selected').text();
        const desc = $('#bulk_description').val();
        if (!typeId || !amount || !mode || !date || !period) {
            alert('Please fill all required fields.');
            return;
        }
        payments.push({ type_id: typeId, type_text: typeText, amount, mode, date, period, period_text: periodText, desc });
        renderPayments();
        // Clear fields
        $('#bulk_payment_type_id').val('');
        $('#bulk_amount').val('');
        $('#bulk_mode').val('');
        $('#bulk_payment_period').val('');
        $('#bulk_description').val('');
    }

    // --- Remove payment row ---
    $(document).on('click', '.remove-payment-row', function(){
        const idx = $(this).data('idx');
        payments.splice(idx, 1);
        renderPayments();
    });

    // --- Inline edits ---
    $(document).on('input change blur', '.bulk-amount-input', function(){
        const idx = $(this).data('idx');
        const val = parseFloat($(this).val());
        if (!isNaN(val) && val >= 0) {
            payments[idx].amount = val.toFixed(2);
            // Update total only
            let total = 0;
            payments.forEach(p => { total += parseFloat(p.amount) || 0; });
            $('#bulkPaymentsTotal').text('₵'+total.toFixed(2));
        }
    });
    $(document).on('change', '.bulk-mode-input', function(){
        const idx = $(this).data('idx');
        payments[idx].mode = $(this).val();
    });
    $(document).on('change blur', '.bulk-date-input', function(){
        const idx = $(this).data('idx');
        payments[idx].date = $(this).val();
    });
    $(document).on('change', '.bulk-period-input', function(){
        const idx = $(this).data('idx');
        payments[idx].period = $(this).val();
        payments[idx].period_text = $(this).find('option:selected').text();
    });
    $(document).on('input change blur', '.bulk-desc-input', function(){
        const idx = $(this).data('idx');
        payments[idx].desc = $(this).val();
    });

    // Auto-populate desc on type/period change for each row
    $(document).on('change', '.bulk-type-input, .bulk-period-input', function(){
        const idx = $(this).data('idx');
        const p = payments[idx];
        // Only auto-update if desc is empty or matches previous auto-desc
        const $desc = $(`.bulk-desc-input[data-idx="${idx}"]`);
        const prevAuto = p._autodesc || '';
        const currentDesc = $desc.val();
        const typeText = $(`.bulk-type-input[data-idx="${idx}"] option:selected`).text();
        const periodText = $(`.bulk-period-input[data-idx="${idx}"] option:selected`).text();
        const autoDesc = getBulkAutoDescription(typeText, periodText);
        if (!currentDesc || currentDesc === prevAuto) {
            $desc.val(autoDesc);
            payments[idx].desc = autoDesc;
        }
        // Track the auto-desc for this row
        payments[idx]._autodesc = autoDesc;
    });
    // When user manually edits desc, stop auto-replacing
    $(document).on('input', '.bulk-desc-input', function(){
        const idx = $(this).data('idx');
        payments[idx].desc = $(this).val();
    });

    // Ensure payment type change updates payment object
    $(document).on('change', '.bulk-type-input', function() {
        const idx = $(this).data('idx');
        payments[idx].type_id = $(this).val();
        payments[idx].type_text = $(this).find('option:selected').text();
    });

    // --- Enable Add to Bulk Button ---
    function enableAddToBulkBtn() {
        const $btn = $('#addToBulkBtn');
        $btn.prop('disabled', false).show();
    }
    $('a[data-toggle="tab"][href="#bulkPanel"], #bulk-tab').on('shown.bs.tab click', enableAddToBulkBtn);
    $(document).off('click.addToBulk').on('click.addToBulk', '#addToBulkBtn', function(e){
        e.preventDefault();
        addToBulk();
    });

    // --- Modal confirmation for bulk submit ---
    $('#submitBulkPaymentsBtn').off('click').on('click', function(e) {
        e.preventDefault();
        if (payments.length === 0) {
            $('#bulk-payment-feedback').html('<div class="alert alert-danger">No payments to submit.</div>');
            return;
        }
        let total = 0;
        let html = '';
        payments.forEach((p, i) => {
            total += parseFloat(p.amount) || 0;
            html += `<tr><td>${i+1}</td><td>${p.type_text}</td><td>₵${parseFloat(p.amount).toLocaleString(undefined,{minimumFractionDigits:2})}</td><td>${p.mode}</td><td>${p.date}</td><td>${p.desc||''}</td></tr>`;
        });
        $('#bulkConfirmTable tbody').html(html);
        $('#bulkConfirmTotal').text('₵' + total.toLocaleString(undefined,{minimumFractionDigits:2}));
        $('#bulkPaymentConfirmModal').modal('show');
    });
    $('#confirmBulkPaymentBtn').off('click').on('click', function(){
        if ($(this).prop('disabled')) return;
        $(this).prop('disabled', true).text('Processing...');
        allowBulkSubmit = true;
        $('#bulkPaymentEntryForm').trigger('submit');
        $('#bulkPaymentConfirmModal').modal('hide');
        setTimeout(()=>{
            $('#confirmBulkPaymentBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i>Confirm & Submit');
        }, 2000);
    });

    // --- AJAX submit for bulk payments ---
    $('#bulkPaymentEntryForm').on('submit', function(e){
        if (!allowBulkSubmit) {
            e.preventDefault();
            return false;
        }
        allowBulkSubmit = false;
        $('#submitBulkPaymentsBtn').prop('disabled', true).text('Processing...');

        // Always use arrays for member_ids/sundayschool_ids to match backend validation
        let isSRN = member && (member.person_type === 'sundayschool' || member.sundayschool_id);
        let postData = {
            member_ids: [],
            sundayschool_ids: [],
            amounts: {},
            church_id: member?.church_id || '',
            payment_date: payments[0]?.date || ''
        };
        // --- Build descriptions, modes, periods, and period descriptions objects for backend ---
        let descriptions = {};
        let modes = {};
        let periods = {};
        let period_descriptions = {};
        if (isSRN) {
            let sid = member.sundayschool_id || member.id;
            postData.sundayschool_ids = [sid];
            postData.amounts['ss_' + sid] = {};
            descriptions['ss_' + sid] = {};
            modes['ss_' + sid] = {};
            periods['ss_' + sid] = {};
            period_descriptions['ss_' + sid] = {};
            payments.forEach(function(p) {
                postData.amounts['ss_' + sid][p.type_id] = p.amount;
                descriptions['ss_' + sid][p.type_id] = p.desc || '';
                modes['ss_' + sid][p.type_id] = p.mode || 'Cash';
                periods['ss_' + sid][p.type_id] = p.period || '';
                period_descriptions['ss_' + sid][p.type_id] = p.period_text || '';
            });
        } else {
            postData.member_ids = [member.id];
            postData.amounts[member.id] = {};
            descriptions[member.id] = {};
            modes[member.id] = {};
            periods[member.id] = {};
            period_descriptions[member.id] = {};
            payments.forEach(function(p) {
                postData.amounts[member.id][p.type_id] = p.amount;
                descriptions[member.id][p.type_id] = p.desc || '';
                modes[member.id][p.type_id] = p.mode || 'Cash';
                periods[member.id][p.type_id] = p.period || '';
                period_descriptions[member.id][p.type_id] = p.period_text || '';
            });
        }
        postData.descriptions = descriptions;
        postData.modes = modes;
        postData.periods = periods;
        postData.period_descriptions = period_descriptions;


        $.ajax({
            url: 'ajax_bulk_payment.php',
            type: 'POST',
            data: JSON.stringify(postData),
            contentType: 'application/json',
            dataType: 'json',
            success: function(resp){
                let typeMap = {};
                $('#bulk_payment_type_id option').each(function(){
                    if ($(this).val()) typeMap[$(this).val()] = $(this).text();
                });
                if (resp.success) {
                    $('#bulk-payment-feedback').html('<div class="alert alert-success">Payments recorded successfully!</div>');
                    window.location.href = 'payment_list.php';
                } else {
                    let msg = resp.msg || 'Error saving payments.';
                    if (resp.failed && Array.isArray(resp.failed)) {
                        msg += '\n\nFailed payments:';
                        resp.failed.forEach(function(f){
                            let typeName = typeMap[f.type_id] || ('Type ID ' + f.type_id);
                            let reason = f.reason.replace(/type ID (\d+)/i, typeName);
                            msg += `\n- ${typeName}: ${reason}`;
                        });
                    }
                    alert(msg);
                    $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg.replace(/\n/g,'<br>')+'</div>');
                }
            },
            error: function(xhr, status, err){
                let msg = 'Network/server error.';
                if (xhr && xhr.responseText) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        msg = resp.msg || msg;
                    } catch(e) {}
                }
                alert(msg);
                $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg+'</div>');
            },
            complete: function(){
                $('#submitBulkPaymentsBtn').prop('disabled', false).text('Submit All Payments');
            }
        });
    });

    // --- Expose setBulkMember for PHP integration ---
    window.setBulkMember = function(m, type) {
        member = m;
        // Patch: ensure correct person_type and sundayschool_id for Sunday School students
        if (type === 'sundayschool' || m.srn) {
            member.person_type = 'sundayschool';
            member.sundayschool_id = m.id;
        } else {
            member.person_type = 'member';
            member.sundayschool_id = null;
        }
        payments = [];
        renderPayments();
        $('#bulk-payment-panel').show();
        enableAddToBulkBtn();
    };
});

    // Submit all payments
    // Payment confirmation modal logic
    if (!$('#paymentConfirmModal').length) {
        $('body').append(`
        <div class="modal fade" id="paymentConfirmModal" tabindex="-1" role="dialog" aria-labelledby="paymentConfirmModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="paymentConfirmModalLabel">Confirm Payment</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                  <span aria-hidden="true">&times;</span>
                </button>
              </div>
              <div class="modal-body">
                Are you sure you want to submit this payment? This action cannot be undone.
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmSubmitPaymentBtn">Yes, Submit Payment</button>
              </div>
            </div>
          </div>
        </div>`);
    }

    var submitHandler = function() {
        var data = {
            member_id: member.id,
            payments: payments
        };
        $('#submitBulkPaymentsBtn').prop('disabled', true).text('Processing...');
        $.ajax({
            url: 'ajax_bulk_payments_single_member.php',
            type: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            dataType: 'json',
            success: function(resp){
                // Build a map of type_id to name from the select options
                let typeMap = {};
                $('#bulk_payment_type_id option').each(function(){
                    if ($(this).val()) typeMap[$(this).val()] = $(this).text();
                });
                if (resp.success) {
                    $('#bulk-payment-feedback').html('<div class="alert alert-success">Payments recorded successfully!</div>');
setTimeout(function(){
    window.location.href = 'payment_list.php';
}, 1200);
                } else {
                    let msg = resp.msg || 'Error saving payments.';
                    if (resp.failed && Array.isArray(resp.failed)) {
                        msg += '\n\nFailed payments:';
                        resp.failed.forEach(function(f){
                            let typeName = typeMap[f.type_id] || ('Type ID ' + f.type_id);
                            let reason = f.reason.replace(/type ID (\d+)/i, typeName);
                            msg += `\n- ${typeName}: ${reason}`;
                        });
                    }
                    alert(msg);
                    $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg.replace(/\n/g,'<br>')+'</div>');
                }
            },
            error: function(xhr, status, err){
                let msg = 'Network/server error.';
                if (xhr && xhr.responseText) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        msg = resp.msg || msg;
                    } catch(e) {}
                }
                alert(msg);
                $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg+'</div>');
            },
            complete: function(){
                $('#submitBulkPaymentsBtn').prop('disabled', false).text('Submit All Payments');
            }
        });
        $('#paymentConfirmModal').modal('hide');
    };

    // --- Bulk Payment Confirmation Modal Logic ---
    allowBulkSubmit = false; // Only assign, do not redeclare
    $('#submitBulkPaymentsBtn').off('click').on('click', function(e) {
        console.log('[DEBUG] Submit All Payments button clicked');
        e.preventDefault();
        var $rows = $('#bulkPaymentsTable tbody tr');
        console.log('[DEBUG] Number of bulk payment rows:', $rows.length);
        if ($rows.length === 0) {
            $('#bulk-payment-feedback').html('<div class="alert alert-danger">No payments to submit.</div>');
            return;
        }
        var total = 0;
        var html = '';
        $rows.each(function(i, row){
            var $tds = $(row).find('td');
            var type = $tds.eq(1).text();
            var amount = parseFloat($tds.eq(2).text().replace(/[^\d.]/g, '')) || 0;
            var mode = $tds.eq(3).text();
            var date = $tds.eq(4).text();
            var desc = $tds.eq(5).text();
            total += amount;
            html += `<tr><td>${i+1}</td><td>${type}</td><td>₵${amount.toLocaleString(undefined,{minimumFractionDigits:2})}</td><td>${mode}</td><td>${date}</td><td>${desc}</td></tr>`;
        });
        $('#bulkConfirmTable tbody').html(html);
        $('#bulkConfirmTotal').text('₵' + total.toLocaleString(undefined,{minimumFractionDigits:2}));
        console.log('[DEBUG] Showing bulk payment confirmation modal');
        $('#bulkPaymentConfirmModal').modal('show');
    });
    $('#confirmBulkPaymentBtn').off('click').on('click', function(){
        if ($(this).prop('disabled')) return;
        $(this).prop('disabled', true).text('Processing...');
        allowBulkSubmit = true;
        $('#bulkPaymentEntryForm').trigger('submit');
        $('#bulkPaymentConfirmModal').modal('hide');
        setTimeout(()=>{
            $('#confirmBulkPaymentBtn').prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i>Confirm & Submit');
        }, 2000);
    });
    // Prevent default form submit, only allow after confirm, and run AJAX here
    $('#bulkPaymentEntryForm').on('submit', function(e){
        if (!allowBulkSubmit) {
            e.preventDefault();
            return false;
        }
        allowBulkSubmit = false;
        // AJAX logic (was submitHandler)
        var data = {};
        if (member && (member.person_type === 'sundayschool' || member.sundayschool_id)) {
            data.sundayschool_id = member.sundayschool_id || member.id;
        } else {
            data.member_id = member.id;
        }
        data.payments = payments;
        $('#submitBulkPaymentsBtn').prop('disabled', true).text('Processing...');
        $.ajax({
            url: 'ajax_bulk_payments_single_member.php',
            type: 'POST',
            data: JSON.stringify(data),
            contentType: 'application/json',
            dataType: 'json',
            success: function(resp){
                let typeMap = {};
                $('#bulk_payment_type_id option').each(function(){
                    if ($(this).val()) typeMap[$(this).val()] = $(this).text();
                });
                if (resp.success) {
                    $('#bulk-payment-feedback').html('<div class="alert alert-success">Payments recorded successfully!</div>');
setTimeout(function(){
    window.location.href = 'payment_list.php';
}, 1200);
                } else {
                    let msg = resp.msg || 'Error saving payments.';
                    if (resp.failed && Array.isArray(resp.failed)) {
                        msg += '\n\nFailed payments:';
                        resp.failed.forEach(function(f){
                            let typeName = typeMap[f.type_id] || ('Type ID ' + f.type_id);
                            let reason = f.reason.replace(/type ID (\d+)/i, typeName);
                            msg += `\n- ${typeName}: ${reason}`;
                        });
                    }
                    alert(msg);
                    $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg.replace(/\n/g,'<br>')+'</div>');
                }
            },
            error: function(xhr, status, err){
                let msg = 'Network/server error.';
                if (xhr && xhr.responseText) {
                    try {
                        let resp = JSON.parse(xhr.responseText);
                        msg = resp.msg || msg;
                    } catch(e) {}
                }
                alert(msg);
                $('#bulk-payment-feedback').html('<div class="alert alert-danger">'+msg+'</div>');
            },
            complete: function(){
                $('#submitBulkPaymentsBtn').prop('disabled', false).text('Submit All Payments');
            }
        });
    });

    // When member is found, store for bulk
    window.setBulkMember = function(m, type) {
        member = m;
        // Patch: ensure correct person_type and sundayschool_id for Sunday School students
        if (type === 'sundayschool' || m.srn) {
            member.person_type = 'sundayschool';
            member.sundayschool_id = m.id;
        } else {
            member.person_type = 'member';
            member.sundayschool_id = null;
        }
        payments = [];
        renderPayments();
        // Show bulk panel and enable Add to Bulk button
        $('#bulk-payment-panel').show();
        enableAddToBulkBtn();
    };