(function ($) {
    'use strict';

    var config = window.paymentFormConfig || {};
    var payer = null;
    var payerType = null;
    var payments = [];

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function money(value) {
        return 'GH\u20b5' + Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function chequeAllocationTotalCents() {
        return payments.reduce(function (total, payment) {
            return payment.mode === 'Cheque'
                ? total + Math.round(Number(payment.amount) * 100)
                : total;
        }, 0);
    }

    function paymentModeOrder() {
        var modes = [];
        payments.forEach(function (payment) {
            if (modes.indexOf(payment.mode) === -1) modes.push(payment.mode);
        });
        return modes;
    }

    function chequeFaceValueCents() {
        var value = $.trim($('#bulk_cheque_amount').val());
        var amount = Number(value);
        return value !== '' && isFinite(amount) && amount > 0
            ? Math.round(amount * 100)
            : null;
    }

    function formatRegistrationNumber(value) {
        var normalized = String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 10);
        var segments = [];
        if (normalized.length > 0) segments.push(normalized.substring(0, 3));
        if (normalized.length > 3) segments.push(normalized.substring(3, 8));
        if (normalized.length > 8) segments.push(normalized.substring(8, 10));
        return segments.join('-');
    }

    function setFeedback(message, level) {
        $('#bulk-payment-feedback').html(
            '<div class="alert alert-' + (level || 'danger') + '">' + escapeHtml(message) + '</div>'
        );
    }

    function clearFieldErrors() {
        $('.payment-field-error').remove();
        $('#bulkPaymentEntryForm .is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
        $('#cheque_entry_confirmed').removeClass('is-invalid').removeAttr('aria-invalid');
    }

    function showFieldError(message, selector) {
        clearFieldErrors();
        setFeedback(message);
        if (!selector) return;

        var field = $(selector).first();
        if (!field.length) return;
        field.addClass('is-invalid').attr('aria-invalid', 'true');
        $('<div class="invalid-feedback d-block payment-field-error" role="alert"></div>')
            .text(message)
            .insertAfter(field);
        field.trigger('focus');
    }

    function resetLineForm() {
        $('#bulk_payment_type_id').val('');
        $('#bulk_amount').val('');
        $('#bulk_description').val('');
    }

    function resetBatchForm() {
        payments = [];
        $('#bulk_bank_name, #bulk_cheque_number, #bulk_cheque_amount').val('');
        $('input[name="payment_method"][value="Cash"]').prop('checked', true).trigger('change');
        $('#payment-method-options label').removeClass('active');
        $('input[name="payment_method"][value="Cash"]').closest('label').addClass('active');
        resetLineForm();
        renderPayments();
    }

    function syncSharedChequeEvidence() {
        var bank = $.trim($('#bulk_bank_name').val());
        var cheque = $.trim($('#bulk_cheque_number').val());
        var chequeAmountValue = $.trim($('#bulk_cheque_amount').val());
        var chequeAmount = chequeAmountValue === '' ? null : Number(chequeAmountValue);
        payments.forEach(function (payment) {
            if (payment.mode !== 'Cheque') return;
            payment.bank_name = bank;
            payment.cheque_number = cheque;
            payment.cheque_amount = chequeAmount;
        });
        renderPayments();
    }

    function renderChequeReconciliation() {
        var hasChequeLines = payments.some(function (payment) { return payment.mode === 'Cheque'; });
        var isSelectingCheque = $('#bulk_mode').val() === 'Cheque';
        var panel = $('#cheque-reconciliation');
        if (!hasChequeLines && !isSelectingCheque) {
            panel.addClass('d-none');
            return true;
        }

        var faceValueCents = chequeFaceValueCents();
        var allocatedCents = chequeAllocationTotalCents();
        var isBalanced = hasChequeLines && faceValueCents !== null && allocatedCents === faceValueCents;
        var differenceCents = faceValueCents === null ? null : faceValueCents - allocatedCents;
        var message;

        $('#chequeReconciliationFaceValue').text(
            faceValueCents === null ? 'Not entered' : money(faceValueCents / 100)
        );
        $('#chequeReconciliationAllocated').text(money(allocatedCents / 100));
        $('#chequeReconciliationDifference').text(
            differenceCents === null
                ? 'Enter cheque amount'
                : differenceCents < 0
                    ? 'Over by ' + money(Math.abs(differenceCents) / 100)
                    : money(differenceCents / 100)
        );

        if (faceValueCents === null) {
            message = 'Enter the cheque face value to reconcile its allocations.';
        } else if (isBalanced) {
            message = 'Balanced. Cheque allocations match the cheque face value.';
        } else if (differenceCents < 0) {
            message = 'Cheque allocations exceed the face value. Reduce an allocation or correct the cheque amount.';
        } else {
            message = 'Add ' + money(differenceCents / 100) + ' more in cheque allocations before review.';
        }

        panel.removeClass('d-none alert-info alert-warning alert-danger alert-success')
            .addClass(isBalanced ? 'alert-success' : (differenceCents !== null && differenceCents < 0 ? 'alert-danger' : 'alert-warning'));
        $('#chequeReconciliationMessage').text(message);
        return !hasChequeLines || isBalanced;
    }

    function renderPayments() {
        var body = $('#bulkPaymentsTable').empty();
        var total = 0;
        var cashTotal = 0;
        var chequeTotal = 0;
        payments.forEach(function (payment, index) {
            total += payment.amount;
            if (payment.mode === 'Cheque') {
                chequeTotal += payment.amount;
            } else {
                cashTotal += payment.amount;
            }
        });
        paymentModeOrder().forEach(function (mode) {
            var modePayments = payments.map(function (payment, index) {
                return { payment: payment, index: index };
            }).filter(function (entry) {
                return entry.payment.mode === mode;
            });
            var modeTotal = modePayments.reduce(function (sum, entry) {
                return sum + entry.payment.amount;
            }, 0);
            var group = $('<section class="payment-mode-group"></section>');
            group.append(
                $('<div class="payment-mode-group-header"></div>')
                    .append($('<span></span>').text(mode + ' payments'))
                    .append($('<span></span>').text(modePayments.length + ' lines · ' + money(modeTotal)))
            );
            var groupLines = $('<div class="payment-mode-group-lines"></div>');
            modePayments.forEach(function (entry) {
                var payment = entry.payment;
                var index = entry.index;
                var details = payment.mode === 'Cheque'
                    ? '<span><i class="fas fa-university mr-1" aria-hidden="true"></i>' + escapeHtml(payment.bank_name) + '</span>' +
                    '<span><i class="fas fa-hashtag mr-1" aria-hidden="true"></i>' + escapeHtml(payment.cheque_number) + '</span>' +
                    '<span><i class="fas fa-coins mr-1" aria-hidden="true"></i>Cheque value ' + escapeHtml(payment.cheque_amount == null ? 'Not entered' : money(payment.cheque_amount)) + '</span>'
                    : '<span><i class="fas fa-money-bill-wave mr-1" aria-hidden="true"></i>Cash payment</span>';
                groupLines.append(
                    '<article class="payment-line-card">' +
                    '<div class="payment-line-card-head">' +
                    '<span class="payment-line-index">' + (index + 1) + '</span>' +
                    '<div class="payment-line-title">' +
                    '<strong>' + escapeHtml(payment.type_text) + '</strong>' +
                    '<span class="badge badge-' + (payment.mode === 'Cheque' ? 'primary' : 'success') + '">' + escapeHtml(payment.mode) + '</span>' +
                    '</div>' +
                    '<div class="payment-line-amount">' + money(payment.amount) + '</div>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger remove-payment" data-index="' + index + '" aria-label="Remove payment line ' + (index + 1) + '"><i class="fas fa-times" aria-hidden="true"></i></button>' +
                    '</div>' +
                    '<div class="payment-line-card-body">' +
                    '<div class="payment-line-meta">' + details + '</div>' +
                    '<div class="payment-line-meta"><span><i class="far fa-calendar-alt mr-1" aria-hidden="true"></i>' + escapeHtml(payment.period_text) + '</span></div>' +
                    '<div class="payment-line-description">' + escapeHtml(payment.desc || '') + '</div>' +
                    '</div>' +
                    '</article>'
                );
            });
            group.append(groupLines);
            body.append(group);
        });
        if (payments.length === 0) {
            body.append('<div class="payment-lines-empty"><i class="fas fa-receipt mr-1" aria-hidden="true"></i>No payment lines added yet.</div>');
        }
        $('#bulkPaymentsCount').text(payments.length + (payments.length === 1 ? ' line' : ' lines'));
        $('#bulkPaymentsCashTotal').text(money(cashTotal));
        $('#bulkPaymentsChequeTotal').text(money(chequeTotal));
        $('#bulkPaymentsTotal').text(money(total));
        var chequeBalanced = renderChequeReconciliation();
        $('#submitBulkPaymentsBtn').prop('disabled', payments.length === 0 || !payer || !chequeBalanced);
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
        resetBatchForm();
    };

    $(function () {
        window.addEventListener('beforeunload', function (event) {
            if (payments.length === 0) return;
            event.preventDefault();
            event.returnValue = '';
        });

        // The layout creates a stacking context for the content wrapper, while
        // Bootstrap appends its backdrop directly to body. Keeping the modal at
        // body level guarantees it remains above the backdrop and clickable.
        $('#bulkPaymentConfirmModal').appendTo(document.body);

        // Match the member-login CRN behavior. Both current CRNs and SRNs use
        // the FMC-K0101-KM / FMC-S2101-KM 3-5-2 registration-number pattern.
        $('#crn').on('input', function () {
            this.value = formatRegistrationNumber(this.value);
        }).on('keydown', function (event) {
            if (event.key !== 'Backspace') return;
            var input = this;
            var cursorPosition = input.selectionStart;
            if (cursorPosition > 0 && input.value.charAt(cursorPosition - 1) === '-') {
                window.setTimeout(function () {
                    input.setSelectionRange(cursorPosition - 1, cursorPosition - 1);
                }, 0);
            }
        });

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
                    $('#bulk_payment_type_id').trigger('focus');
                })
                .fail(function () {
                    $('#crn-feedback').removeClass('text-muted').addClass('text-danger').text('The payer lookup failed. Please try again.');
                })
                .always(function () {
                    $('#findMemberBtn').prop('disabled', false).html('<i class="fas fa-search mr-1"></i>Find payer');
                });
        });

        $('input[name="payment_method"]').on('change', function () {
            clearFieldErrors();
            var mode = $('input[name="payment_method"]:checked').val() || 'Cash';
            $('#bulk_mode').val(mode);
            $('#bulk_cheque_fields').toggleClass('d-none', mode !== 'Cheque');
            renderPayments();
        });

        // Bank and cheque number describe the cheque itself, not an individual
        // allocation. Updating either field therefore updates every cheque
        // allocation already staged in this batch.
        $('#bulk_bank_name, #bulk_cheque_number').on('change blur', syncSharedChequeEvidence);
        $('#bulk_cheque_amount').on('input change blur', syncSharedChequeEvidence);
        $('#bulkPaymentEntryForm').on('input change', '.form-control', clearFieldErrors);
        $('#cheque_entry_confirmed').on('change', function () {
            clearFieldErrors();
            if ($(this).prop('checked')) $('#bulk-payment-feedback').empty();
        });

        $('#bulk_payment_type_id, #bulk_payment_period').on('change', function () {
            var typeText = $('#bulk_payment_type_id option:selected').text();
            var periodText = $('#bulk_payment_period option:selected').text();
            var description = $('#bulk_payment_type_id').val()
                ? 'Payment for ' + periodText + ' ' + typeText
                : '';
            $('#bulk_description').val(description);
        });

        $('#addToBulkBtn').on('click', function () {
            if (!payer) return showFieldError('Find and select a payer first.', '#crn');
            var typeId = Number($('#bulk_payment_type_id').val());
            var amount = Number($('#bulk_amount').val());
            var mode = $('#bulk_mode').val();
            var period = $('#bulk_payment_period').val();
            var bank = $.trim($('#bulk_bank_name').val());
            var cheque = $.trim($('#bulk_cheque_number').val());
            var chequeAmount = Number($('#bulk_cheque_amount').val());
            if (!typeId) return showFieldError('Select a payment type.', '#bulk_payment_type_id');
            if (!(amount > 0)) return showFieldError('Enter an amount greater than zero.', '#bulk_amount');
            if (!period) return showFieldError('Choose a reporting period.', '#bulk_payment_period');
            if (mode === 'Cheque' && !bank) return showFieldError('Enter the cheque bank name.', '#bulk_bank_name');
            if (mode === 'Cheque' && !cheque) return showFieldError('Enter the cheque number.', '#bulk_cheque_number');
            if (mode === 'Cheque' && !(chequeAmount > 0)) return showFieldError('Enter a cheque amount greater than zero.', '#bulk_cheque_amount');
            if (mode === 'Cheque'
                && chequeAllocationTotalCents() + Math.round(amount * 100) > Math.round(chequeAmount * 100)) {
                return showFieldError('This allocation would exceed the cheque amount of ' + money(chequeAmount) + '.', '#bulk_amount');
            }
            if (payments.length >= 30) return showFieldError('A manual batch can contain at most 30 payment lines.', '#bulk_payment_type_id');
            payments.push({
                type_id: typeId,
                type_text: $('#bulk_payment_type_id option:selected').text(),
                amount: amount,
                mode: mode,
                period: period,
                period_text: $('#bulk_payment_period option:selected').text(),
                desc: $.trim($('#bulk_description').val()),
                bank_name: mode === 'Cheque' ? bank : '',
                cheque_number: mode === 'Cheque' ? cheque : '',
                cheque_amount: mode === 'Cheque' ? chequeAmount : null
            });
            $('#bulk-payment-feedback').empty();
            clearFieldErrors();
            renderPayments();
            resetLineForm();
            $('#bulk_payment_type_id').trigger('focus');
        });

        $('#bulkPaymentsTable').on('click', '.remove-payment', function () {
            payments.splice(Number($(this).data('index')), 1);
            renderPayments();
        });

        $('#submitBulkPaymentsBtn').on('click', function () {
            if (!payer || payments.length === 0) return;
            var hasCheque = payments.some(function (payment) { return payment.mode === 'Cheque'; });
            if (hasCheque) {
                var chequeAmountCents = Math.round(Number($('#bulk_cheque_amount').val()) * 100);
                var chequeAllocationCents = chequeAllocationTotalCents();
                if (chequeAllocationCents !== chequeAmountCents) {
                    return showFieldError('Cheque allocations total ' + money(chequeAllocationCents / 100)
                        + ' and must equal the cheque amount of ' + money(chequeAmountCents / 100) + '.', '#bulk_cheque_amount');
                }
            }
            var total = 0;
            var body = $('#bulkConfirmTable').empty();
            payments.forEach(function (payment, index) {
                total += payment.amount;
            });
            paymentModeOrder().forEach(function (mode) {
                var modePayments = payments.map(function (payment, index) {
                    return { payment: payment, index: index };
                }).filter(function (entry) {
                    return entry.payment.mode === mode;
                });
                var modeTotal = modePayments.reduce(function (sum, entry) {
                    return sum + entry.payment.amount;
                }, 0);
                var group = $('<section class="payment-confirm-group"></section>');
                group.append(
                    $('<div class="payment-confirm-group-header"></div>')
                        .append($('<span></span>').text(mode + ' payments'))
                        .append($('<span></span>').text(modePayments.length + ' lines · ' + money(modeTotal)))
                );
                var groupLines = $('<div class="payment-confirm-group-lines"></div>');
                modePayments.forEach(function (entry) {
                    var payment = entry.payment;
                    var details = payment.mode === 'Cheque'
                        ? payment.bank_name + ' / ' + payment.cheque_number + ' / cheque value ' + money(payment.cheque_amount)
                        : '-';
                    groupLines.append(
                        '<div class="payment-confirm-line">' +
                        '<span class="payment-line-index">' + (entry.index + 1) + '</span>' +
                        '<div><strong>' + escapeHtml(payment.type_text) + '</strong>' +
                        '<small>' + escapeHtml(payment.mode) + ' &middot; ' + escapeHtml(details) + ' &middot; ' + escapeHtml(payment.period_text) + '</small></div>' +
                        '<strong class="confirm-amount">' + money(payment.amount) + '</strong>' +
                        '</div>'
                    );
                });
                group.append(groupLines);
                body.append(group);
            });
            $('#bulkConfirmTotal').text(money(total));
            $('#chequeConfirmationPanel').toggleClass('d-none', !hasCheque);
            $('#cheque_entry_confirmed').prop('checked', false);
            $('#bulkPaymentConfirmModal').modal('show');
        });

        $('#confirmBulkPaymentBtn').on('click', function () {
            var hasCheque = payments.some(function (payment) { return payment.mode === 'Cheque'; });
            if (hasCheque && !$('#cheque_entry_confirmed').prop('checked')) {
                clearFieldErrors();
                setFeedback('Complete the cheque recorder checklist before submission.');
                $('#cheque_entry_confirmed').addClass('is-invalid').attr('aria-invalid', 'true').trigger('focus');
                return;
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
                resetBatchForm();
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
