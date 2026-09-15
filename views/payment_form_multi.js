(function ($) {
    'use strict';

    var config = window.paymentFormConfig || {};
    var payer = null;
    var payerType = null;
    var payments = [];
    var lastAutoDescription = '';
    var descriptionWasEdited = false;

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function money(value) {
        return 'GH\u20b5' + Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function setFeedback(message, level) {
        $('#bulk-payment-feedback').html(
            '<div class="alert alert-' + (level || 'danger') + '">' + escapeHtml(message) + '</div>'
        );
    }

    function resetLineForm() {
        $('#bulk_payment_type_id').val('');
        $('#bulk_amount').val('');
        $('#bulk_description').val('');
        lastAutoDescription = '';
        descriptionWasEdited = false;
        $('#bulk_bank_name, #bulk_cheque_number').val('');
        $('input[name="payment_method"][value="Cash"]').prop('checked', true).trigger('change');
        $('#payment-method-options label').removeClass('active');
        $('input[name="payment_method"][value="Cash"]').closest('label').addClass('active');
    }

    function renderPayments() {
        var body = $('#bulkPaymentsTable tbody').empty();
        var total = 0;
        payments.forEach(function (payment, index) {
            total += payment.amount;
            var details = payment.mode === 'Cheque'
                ? escapeHtml(payment.bank_name) + '<br><small class="text-muted">No. ' + escapeHtml(payment.cheque_number) + '</small>'
                : '<span class="text-muted">&mdash;</span>';
            body.append(
                '<tr>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td>' + escapeHtml(payment.type_text) + '</td>' +
                    '<td class="text-right">' + money(payment.amount) + '</td>' +
                    '<td><span class="badge badge-' + (payment.mode === 'Cheque' ? 'primary' : 'success') + '">' + payment.mode + '</span></td>' +
                    '<td>' + details + '</td>' +
                    '<td>' + escapeHtml(payment.period_text) + '</td>' +
                    '<td>' + escapeHtml(payment.desc || '') + '</td>' +
                    '<td><button type="button" class="btn btn-sm btn-outline-danger remove-payment" data-index="' + index + '" aria-label="Remove payment"><i class="fas fa-times"></i></button></td>' +
                '</tr>'
            );
        });
        $('#bulkPaymentsTotal').text(money(total));
        $('#submitBulkPaymentsBtn').prop('disabled', payments.length === 0 || !payer);
    }

    function payerSummary(data, type) {
        var isMember = type === 'member';
        var reg = isMember ? data.crn : data.srn;
        var phone = isMember ? data.phone : data.contact;
        var className = isMember ? data.class_name : (data.class_name || data.class_id);
        var photo = isMember && data.photo
            ? '<img class="payer-photo mr-3" src="../uploads/members/' + encodeURIComponent(String(data.photo).replace(/^.*[\\/]/, '')) + '" alt="Member photo">'
            : '<div class="payer-photo bg-light d-flex align-items-center justify-content-center mr-3"><i class="fas fa-user fa-2x text-muted"></i></div>';
        return '<div class="card border-success"><div class="card-body d-flex align-items-center">' + photo +
            '<div class="flex-grow-1"><div class="d-flex align-items-center mb-2"><strong class="text-success mr-2">Payer found</strong>' +
            '<span class="badge badge-' + (isMember ? 'primary' : 'warning') + '">' + (isMember ? 'Member' : 'Sunday School') + '</span></div>' +
            '<div class="payment-summary-grid">' +
            '<div><div class="payment-summary-label">' + (isMember ? 'CRN' : 'SRN') + '</div><div class="payment-summary-value">' + escapeHtml(reg || '-') + '</div></div>' +
            '<div><div class="payment-summary-label">Name</div><div class="payment-summary-value">' + escapeHtml((data.first_name || '') + ' ' + (data.last_name || '')) + '</div></div>' +
            '<div><div class="payment-summary-label">Phone</div><div class="payment-summary-value">' + escapeHtml(phone || '-') + '</div></div>' +
            '<div><div class="payment-summary-label">Class</div><div class="payment-summary-value">' + escapeHtml(className || '-') + '</div></div>' +
            '</div></div></div></div>';
    }

    window.setBulkMember = function (data, type) {
        payer = data;
        payerType = type;
        payments = [];
        renderPayments();
    };

    $(function () {
        // The layout creates a stacking context for the content wrapper, while
        // Bootstrap appends its backdrop directly to body. Keeping the modal at
        // body level guarantees it remains above the backdrop and clickable.
        $('#bulkPaymentConfirmModal').appendTo(document.body);

        $('#searchMemberForm').on('submit', function (event) {
            event.preventDefault();
            var registrationNumber = $.trim($('#crn').val());
            if (!registrationNumber) {
                $('#crn-feedback').removeClass('text-success').addClass('text-danger').text('Enter a CRN or SRN.');
                return;
            }
            $('#findMemberBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Searching');
            $('#crn-feedback').removeClass('text-danger').addClass('text-muted').text('Searching...');
            $.getJSON('ajax_get_person_by_id.php', { id: registrationNumber })
                .done(function (response) {
                    if (!response.success) {
                        payer = null;
                        payerType = null;
                        payments = [];
                        renderPayments();
                        $('#member-summary, #payment-panels').addClass('d-none');
                        $('#crn-feedback').removeClass('text-muted').addClass('text-danger').text(response.msg || response.error || 'Payer not found.');
                        return;
                    }
                    window.setBulkMember(response.data, response.type);
                    $('#member-summary').html(payerSummary(response.data, response.type)).removeClass('d-none');
                    $('#payment-panels').removeClass('d-none');
                    $('#crn-feedback').removeClass('text-danger text-muted').addClass('text-success').text('Payer selected.');
                })
                .fail(function () {
                    $('#crn-feedback').removeClass('text-muted').addClass('text-danger').text('The payer lookup failed. Please try again.');
                })
                .always(function () {
                    $('#findMemberBtn').prop('disabled', false).html('<i class="fas fa-search mr-1"></i>Find payer');
                });
        });

        $('input[name="payment_method"]').on('change', function () {
            var mode = $('input[name="payment_method"]:checked').val() || 'Cash';
            $('#bulk_mode').val(mode);
            $('#bulk_cheque_fields').toggleClass('d-none', mode !== 'Cheque');
        });

        $('#bulk_description').on('input', function () {
            var current = $.trim($(this).val());
            descriptionWasEdited = current !== '' && current !== lastAutoDescription;
            if (current === '') descriptionWasEdited = false;
        });

        $('#bulk_payment_type_id, #bulk_payment_period').on('change', function () {
            var typeText = $('#bulk_payment_type_id option:selected').text();
            var periodText = $('#bulk_payment_period option:selected').text();
            if (!$('#bulk_payment_type_id').val() || descriptionWasEdited) return;
            lastAutoDescription = 'Payment for ' + periodText + ' ' + typeText;
            $('#bulk_description').val(lastAutoDescription);
        });

        $('#addToBulkBtn').on('click', function () {
            if (!payer) return setFeedback('Find and select a payer first.');
            var typeId = Number($('#bulk_payment_type_id').val());
            var amount = Number($('#bulk_amount').val());
            var mode = $('#bulk_mode').val();
            var period = $('#bulk_payment_period').val();
            var bank = $.trim($('#bulk_bank_name').val());
            var cheque = $.trim($('#bulk_cheque_number').val());
            if (!typeId || !(amount > 0) || !period) return setFeedback('Select a payment type, enter a valid amount, and choose the reporting period.');
            if (mode === 'Cheque' && (!bank || !cheque)) return setFeedback('Bank name and cheque number are required for cheque payments.');
            if (payments.length >= 30) return setFeedback('A manual batch can contain at most 30 payment lines.');
            payments.push({
                type_id: typeId,
                type_text: $('#bulk_payment_type_id option:selected').text(),
                amount: amount,
                mode: mode,
                period: period,
                period_text: $('#bulk_payment_period option:selected').text(),
                desc: $.trim($('#bulk_description').val()),
                bank_name: mode === 'Cheque' ? bank : '',
                cheque_number: mode === 'Cheque' ? cheque : ''
            });
            $('#bulk-payment-feedback').empty();
            renderPayments();
            resetLineForm();
        });

        $('#bulkPaymentsTable').on('click', '.remove-payment', function () {
            payments.splice(Number($(this).data('index')), 1);
            renderPayments();
        });

        $('#submitBulkPaymentsBtn').on('click', function () {
            if (!payer || payments.length === 0) return;
            var hasCheque = payments.some(function (payment) { return payment.mode === 'Cheque'; });
            var total = 0;
            var body = $('#bulkConfirmTable tbody').empty();
            payments.forEach(function (payment, index) {
                total += payment.amount;
                var details = payment.mode === 'Cheque' ? payment.bank_name + ' / ' + payment.cheque_number : '-';
                body.append('<tr><td>' + (index + 1) + '</td><td>' + escapeHtml(payment.type_text) + '</td><td>' + money(payment.amount) + '</td><td>' + payment.mode + '</td><td>' + escapeHtml(details) + '</td><td>' + escapeHtml(payment.period_text) + '</td></tr>');
            });
            $('#bulkConfirmTotal').text(money(total));
            $('#chequeConfirmationPanel').toggleClass('d-none', !hasCheque);
            $('#cheque_entry_confirmed').prop('checked', false);
            $('#bulkPaymentConfirmModal').modal('show');
        });

        $('#confirmBulkPaymentBtn').on('click', function () {
            var hasCheque = payments.some(function (payment) { return payment.mode === 'Cheque'; });
            if (hasCheque && !$('#cheque_entry_confirmed').prop('checked')) {
                return setFeedback('Complete the cheque recorder checklist before submission.');
            }
            var request = {
                member_id: payerType === 'member' ? Number(payer.id) : 0,
                sundayschool_id: payerType === 'sundayschool' ? Number(payer.id) : 0,
                payments: payments,
                cheque_entry_confirmed: hasCheque && $('#cheque_entry_confirmed').prop('checked'),
                csrf_token: config.csrfToken
            };
            var button = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Saving');
            $.ajax({
                url: config.submitUrl,
                method: 'POST',
                data: JSON.stringify(request),
                contentType: 'application/json',
                dataType: 'json'
            }).done(function (response) {
                if (!response.success) return setFeedback(response.msg || 'The payment batch could not be saved.');
                $('#bulkPaymentConfirmModal').modal('hide');
                setFeedback(response.msg + ' Batch: ' + response.batch_reference, 'success');
                payments = [];
                renderPayments();
                window.setTimeout(function () { window.location.href = config.listUrl; }, 1400);
            }).fail(function (xhr) {
                var response = xhr.responseJSON || {};
                setFeedback(response.msg || 'The payment batch could not be saved.');
            }).always(function () {
                button.prop('disabled', false).html('<i class="fas fa-check-circle mr-1"></i>Confirm and submit');
            });
        });
    });
})(jQuery);
