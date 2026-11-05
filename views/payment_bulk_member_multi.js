(function ($) {
  'use strict';

  var $workspace = $('#bulkPaymentWorkspace');
  if (!$workspace.length) return;

  var config = {
    canSubmit: $workspace.data('can-submit') === 1 || $workspace.data('can-submit') === '1',
    membersUrl: String($workspace.data('members-url') || ''),
    paymentTypesUrl: String($workspace.data('payment-types-url') || ''),
    submitUrl: String($workspace.data('submit-url') || '')
  };
  var entries = [];
  var selectedChurch = String($('#church_id').val() || '');
  var moneyFormatter = new Intl.NumberFormat('en-GH', {
    style: 'currency', currency: 'GHS', minimumFractionDigits: 2
  });

  function escapeHtml(value) {
    return $('<div>').text(value == null ? '' : String(value)).html();
  }

  function entryKey(type, id) {
    return String(type) + ':' + String(id);
  }

  function findEntry(key) {
    return entries.find(function (entry) { return entry.key === String(key); });
  }

  function formatMoney(amount) {
    return moneyFormatter.format(Number(amount || 0)).replace('GHS', 'GH₵');
  }

  function monthValue(dateValue) {
    var date = dateValue ? new Date(dateValue + 'T12:00:00') : new Date();
    if (isNaN(date.getTime())) date = new Date();
    return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-01';
  }

  function monthLabel(value) {
    var date = new Date(String(value).slice(0, 7) + '-01T12:00:00');
    if (isNaN(date.getTime())) return '';
    return date.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
  }

  function periodOptions(selected) {
    var base = new Date(String(selected || monthValue('')).slice(0, 7) + '-01T12:00:00');
    if (isNaN(base.getTime())) base = new Date();
    var options = [];
    for (var offset = 12; offset >= -35; offset--) {
      var date = new Date(base.getFullYear(), base.getMonth() + offset, 1, 12, 0, 0);
      var value = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-01';
      options.push('<option value="' + value + '"' + (value === selected ? ' selected' : '') + '>'
        + escapeHtml(monthLabel(value)) + '</option>');
    }
    return options.join('');
  }

  function defaultDescription(typeText, period) {
    return String(typeText || 'Payment') + ' for ' + monthLabel(period);
  }

  function totals() {
    var lineCount = 0;
    var amount = 0;
    entries.forEach(function (entry) {
      entry.payments.forEach(function (line) {
        lineCount++;
        amount += Number(line.amount || 0);
      });
    });
    return { lineCount: lineCount, amount: amount };
  }

  function batchIsReady() {
    return entries.length > 0 && entries.every(function (entry) {
      return entry.payments.length > 0 && entry.payments.every(function (line) {
        return Number(line.amount) > 0 && Number(line.typeId) > 0 && line.period;
      });
    });
  }

  function paymentLinesHtml(entry) {
    var lines = entry.payments.map(function (line, lineIndex) {
      return '<div class="payment-line" data-line-index="' + lineIndex + '">'
        + '<div class="payment-line-heading"><strong>' + escapeHtml(line.typeText) + '</strong>'
        + '<span>' + escapeHtml(formatMoney(line.amount)) + '</span></div>'
        + '<div class="payment-line-fields">'
        + '<div><label>Reporting period</label><select class="form-control form-control-sm payment-period-select" data-line-index="' + lineIndex + '">'
        + periodOptions(line.period) + '</select></div>'
        + '<div><label>Description</label><input class="form-control form-control-sm payment-desc-input" data-line-index="' + lineIndex + '" maxlength="255" value="' + escapeHtml(line.description) + '"></div>'
        + '<button type="button" class="btn btn-sm btn-outline-danger remove-payment-line" data-line-index="' + lineIndex + '" title="Remove payment line" aria-label="Remove payment line"><i class="fas fa-times"></i></button>'
        + '</div></div>';
    }).join('');

    return '<div class="payment-types-group" data-entry-key="' + escapeHtml(entry.key) + '">'
      + '<div class="payment-line-composer">'
      + '<div class="payment-type-field"><label>Payment type</label><select class="form-control payment-type-select" style="width:100%"></select></div>'
      + '<div class="payment-amount-field"><label>Amount</label><div class="input-group input-group-sm"><div class="input-group-prepend"><span class="input-group-text">GH₵</span></div><input type="number" class="form-control payment-amount-input" min="0.01" step="0.01" placeholder="0.00"></div></div>'
      + '<button type="button" class="btn btn-sm btn-primary add-payment-line"><i class="fas fa-plus mr-1"></i>Add line</button>'
      + '</div><div class="payment-line-list">' + (lines || '<span class="no-lines-message">No payment line added yet.</span>') + '</div></div>';
  }

  function rowHtml(entry, index) {
    var subtotal = entry.payments.reduce(function (sum, line) { return sum + Number(line.amount || 0); }, 0);
    var identity = entry.type === 'sundayschool' ? 'Sunday School / SRN' : 'Member / CRN';
    return '<tr data-entry-key="' + escapeHtml(entry.key) + '">'
      + '<td data-label="Sequence" class="sequence-cell"><span>' + (index + 1) + '</span><small>of ' + entries.length + '</small></td>'
      + '<td data-label="Beneficiary"><div class="beneficiary-name">' + escapeHtml(entry.name) + '</div><span class="beneficiary-type">' + identity + '</span></td>'
      + '<td data-label="Payment lines">' + paymentLinesHtml(entry) + '</td>'
      + '<td data-label="Subtotal" class="subtotal-cell">' + escapeHtml(formatMoney(subtotal)) + '</td>'
      + '<td data-label="Actions"><div class="sequence-actions">'
      + '<button type="button" class="btn btn-sm btn-outline-secondary move-entry-up"' + (index === 0 ? ' disabled' : '') + ' title="Move up" aria-label="Move beneficiary up"><i class="fas fa-arrow-up"></i></button>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary move-entry-down"' + (index === entries.length - 1 ? ' disabled' : '') + ' title="Move down" aria-label="Move beneficiary down"><i class="fas fa-arrow-down"></i></button>'
      + '<button type="button" class="btn btn-sm btn-outline-danger remove-entry" title="Remove beneficiary" aria-label="Remove beneficiary"><i class="fas fa-trash-alt"></i></button>'
      + '</div></td></tr>';
  }

  function initializePaymentTypePickers() {
    $('#bulkTable .payment-type-select').each(function () {
      var $select = $(this);
      $select.select2({
        width: '100%',
        placeholder: 'Search payment type',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
          url: config.paymentTypesUrl,
          dataType: 'json',
          delay: 250,
          data: function (params) { return { q: params.term || '' }; },
          processResults: function (data) { return { results: Array.isArray(data.results) ? data.results : [] }; }
        }
      });
    });
  }

  function render() {
    var $tbody = $('#bulkTable tbody');
    if (!entries.length) {
      $tbody.html('<tr class="bulk-empty-row"><td colspan="5"><div class="bulk-empty-state"><i class="fas fa-users"></i><strong>No beneficiaries added</strong><span>Select a church, then search and add people in the required order.</span></div></td></tr>');
    } else {
      $tbody.html(entries.map(rowHtml).join(''));
      initializePaymentTypePickers();
    }

    var summary = totals();
    $('#beneficiaryCount').text(entries.length);
    $('#paymentLineCount').text(summary.lineCount);
    $('#bulkTotals').text(formatMoney(summary.amount));
    var ready = batchIsReady();
    $('#submitBulkBtn').prop('disabled', !config.canSubmit || !ready);
    $('#bulkReadiness').text(ready
      ? entries.length + ' beneficiaries ready in the displayed sequence.'
      : (entries.length ? 'Every beneficiary needs at least one valid payment line.' : 'Add a beneficiary and at least one payment line.'));
  }

  function showToast(message, type) {
    var $toast = $('#bulkToast');
    var $header = $toast.find('.toast-header');
    $header.removeClass('bg-info bg-success bg-danger bg-warning')
      .addClass(type === 'success' ? 'bg-success' : type === 'danger' ? 'bg-danger' : type === 'warning' ? 'bg-warning' : 'bg-info');
    $toast.find('.toast-body').text(message);
    $toast.toast('show');
  }

  function resetSearch() {
    $('#member_search').val(null).trigger('change');
  }

  function addBeneficiary(data) {
    var churchId = String($('#church_id').val() || '');
    if (!churchId) {
      showToast('Select a church before adding beneficiaries.', 'warning');
      resetSearch();
      return;
    }
    var type = data.type === 'sundayschool' ? 'sundayschool' : 'member';
    var key = entryKey(type, data.id);
    if (findEntry(key)) {
      showToast('This beneficiary is already in the batch.', 'warning');
      resetSearch();
      return;
    }
    if (entries.length && entries[0].type !== type) {
      showToast('Members and Sunday School beneficiaries must be submitted in separate batches.', 'warning');
      resetSearch();
      return;
    }
    entries.push({ key: key, id: Number(data.id), type: type, name: String(data.text || 'Unknown beneficiary'), payments: [] });
    render();
    resetSearch();
  }

  function buildPayload() {
    var memberIds = [];
    var sundaySchoolIds = [];
    var amounts = {};
    var descriptions = {};
    var modes = {};
    var periods = {};
    var periodDescriptions = {};
    var sequence = [];

    entries.forEach(function (entry, index) {
      var mapKey = entry.type === 'sundayschool' ? 'ss_' + entry.id : String(entry.id);
      if (entry.type === 'sundayschool') sundaySchoolIds.push(entry.id);
      else memberIds.push(entry.id);
      sequence.push({ position: index + 1, type: entry.type, id: entry.id });
      amounts[mapKey] = {};
      descriptions[mapKey] = {};
      modes[mapKey] = {};
      periods[mapKey] = {};
      periodDescriptions[mapKey] = {};
      entry.payments.forEach(function (line) {
        amounts[mapKey][line.typeId] = Number(line.amount);
        descriptions[mapKey][line.typeId] = line.description || '';
        modes[mapKey][line.typeId] = 'Cash';
        periods[mapKey][line.typeId] = line.period;
        periodDescriptions[mapKey][line.typeId] = monthLabel(line.period);
      });
    });

    return {
      csrf_token: $('#bulkPaymentForm input[name="csrf_token"]').val(),
      church_id: Number($('#church_id').val()),
      payment_date: $('#payment_date').val(),
      member_ids: memberIds,
      sundayschool_ids: sundaySchoolIds,
      beneficiary_sequence: sequence,
      amounts: amounts,
      descriptions: descriptions,
      modes: modes,
      periods: periods,
      period_descriptions: periodDescriptions
    };
  }

  function confirmationHtml() {
    var total = totals().amount;
    var rows = entries.map(function (entry, index) {
      var lines = entry.payments.map(function (line) {
        return '<div class="confirm-line"><span><strong>' + escapeHtml(line.typeText) + '</strong><small>'
          + escapeHtml(monthLabel(line.period)) + ' · ' + escapeHtml(line.description) + '</small></span><strong>'
          + escapeHtml(formatMoney(line.amount)) + '</strong></div>';
      }).join('');
      return '<div class="confirm-beneficiary"><div class="confirm-sequence">' + (index + 1) + '</div><div class="confirm-beneficiary-body"><div class="confirm-beneficiary-name">'
        + escapeHtml(entry.name) + '</div>' + lines + '</div></div>';
    }).join('');

    return '<div class="confirm-batch-meta"><div><span>Church</span><strong>' + escapeHtml($('#church_id option:selected').text())
      + '</strong></div><div><span>Receipt date</span><strong>' + escapeHtml($('#payment_date').val())
      + '</strong></div><div><span>Beneficiaries</span><strong>' + entries.length + '</strong></div><div><span>Batch total</span><strong>'
      + escapeHtml(formatMoney(total)) + '</strong></div></div><div class="confirm-order-note"><i class="fas fa-sort-numeric-down mr-2"></i>This is the exact posting order.</div>' + rows;
  }

  $('#member_search').select2({
    width: '100%',
    placeholder: 'Search by name, CRN or SRN',
    allowClear: true,
    minimumInputLength: 1,
    ajax: {
      url: config.membersUrl,
      dataType: 'json',
      delay: 300,
      data: function (params) { return { q: params.term || '', church_id: $('#church_id').val() || 0 }; },
      processResults: function (data) { return { results: Array.isArray(data.results) ? data.results : [] }; }
    },
    templateResult: function (item) {
      if (item.loading) return item.text;
      var badge = item.type === 'sundayschool' ? 'SRN' : 'CRN';
      return $('<div class="beneficiary-search-result"><strong></strong><span></span></div>')
        .find('strong').text(item.text || '').end().find('span').text(badge + (item.class ? ' · ' + item.class : '')).end();
    }
  }).on('select2:select', function (event) {
    addBeneficiary(event.params.data || {});
  });

  $('#church_id').on('change', function () {
    var nextChurch = String($(this).val() || '');
    if (entries.length && nextChurch !== selectedChurch) {
      if (!window.confirm('Changing the church will clear the current bulk-payment batch. Continue?')) {
        $(this).val(selectedChurch);
        return;
      }
      entries = [];
      render();
    }
    selectedChurch = nextChurch;
    resetSearch();
  });

  $('#bulkTable tbody').on('click', '.add-payment-line', function () {
    var $group = $(this).closest('.payment-types-group');
    var entry = findEntry($group.attr('data-entry-key'));
    var selectedType = $group.find('.payment-type-select').select2('data')[0];
    var amount = Number($group.find('.payment-amount-input').val());
    if (!entry || !selectedType || !selectedType.id || !amount || amount <= 0) {
      showToast('Select a payment type and enter an amount greater than zero.', 'warning');
      return;
    }
    if (entry.payments.some(function (line) { return String(line.typeId) === String(selectedType.id); })) {
      showToast('That payment type is already assigned to this beneficiary.', 'warning');
      return;
    }
    var period = monthValue($('#payment_date').val());
    entry.payments.push({
      typeId: Number(selectedType.id),
      typeText: String(selectedType.text || 'Payment'),
      amount: amount,
      period: period,
      description: defaultDescription(selectedType.text, period),
      manualDescription: false
    });
    render();
  });

  $('#bulkTable tbody').on('change', '.payment-period-select', function () {
    var $row = $(this).closest('tr');
    var entry = findEntry($row.attr('data-entry-key'));
    var index = Number($(this).data('line-index'));
    if (!entry || !entry.payments[index]) return;
    entry.payments[index].period = String($(this).val() || '');
    if (!entry.payments[index].manualDescription) {
      entry.payments[index].description = defaultDescription(entry.payments[index].typeText, entry.payments[index].period);
      $row.find('.payment-desc-input[data-line-index="' + index + '"]').val(entry.payments[index].description);
    }
  });

  $('#bulkTable tbody').on('input', '.payment-desc-input', function () {
    var entry = findEntry($(this).closest('tr').attr('data-entry-key'));
    var index = Number($(this).data('line-index'));
    if (!entry || !entry.payments[index]) return;
    entry.payments[index].description = String($(this).val() || '');
    entry.payments[index].manualDescription = true;
  });

  $('#bulkTable tbody').on('click', '.remove-payment-line', function () {
    var entry = findEntry($(this).closest('tr').attr('data-entry-key'));
    var index = Number($(this).data('line-index'));
    if (!entry || !entry.payments[index]) return;
    entry.payments.splice(index, 1);
    render();
  });

  $('#bulkTable tbody').on('click', '.remove-entry', function () {
    var key = String($(this).closest('tr').attr('data-entry-key'));
    entries = entries.filter(function (entry) { return entry.key !== key; });
    render();
  });

  $('#bulkTable tbody').on('click', '.move-entry-up, .move-entry-down', function () {
    var key = String($(this).closest('tr').attr('data-entry-key'));
    var index = entries.findIndex(function (entry) { return entry.key === key; });
    var target = $(this).hasClass('move-entry-up') ? index - 1 : index + 1;
    if (index < 0 || target < 0 || target >= entries.length) return;
    var moved = entries.splice(index, 1)[0];
    entries.splice(target, 0, moved);
    render();
  });

  $('#submitBulkBtn').on('click', function () {
    if (!batchIsReady()) {
      showToast('Every beneficiary needs at least one valid payment line.', 'warning');
      return;
    }
    $('#confirmSummary').html(confirmationHtml());
    $('#confirmModal').modal('show');
  });

  $(document).on('click', '#confirmSubmitBtn', function () {
    var $button = $(this);
    if ($button.prop('disabled')) return;
    $button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm mr-2" role="status" aria-hidden="true"></span>Posting in sequence…');
    $.ajax({
      url: config.submitUrl,
      method: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify(buildPayload())
    }).done(function (response) {
      if (!response || !response.success) {
        showToast((response && (response.msg || response.error)) || 'The batch could not be completed.', 'danger');
        return;
      }
      $('#confirmModal').modal('hide');
      entries = [];
      render();
      showToast(response.msg || 'Bulk payment completed successfully.', 'success');
    }).fail(function (xhr) {
      var response = xhr.responseJSON || {};
      showToast(response.msg || response.error || 'The server could not process the batch.', 'danger');
    }).always(function () {
      $button.prop('disabled', false).html('<i class="fas fa-check-circle mr-2"></i>Confirm and submit');
    });
  });

  render();
})(jQuery);
