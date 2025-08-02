// payment_bulk_member_multi.js: Modern, modular bulk payment UI logic
console.log('DEBUG: payment_bulk_member_multi.js loaded');
$(function() {
  // --- Persistent data model for all member/payment data ---
  window.bulkMembers = {};

  // --- Select2 setup for Church ---
  $('#church_id').select2({
    placeholder: 'Select a church',
    ajax: {
      url: 'ajax_get_churches.php',
      dataType: 'json',
      delay: 200,
      processResults: function(data) {
        if (!data || !Array.isArray(data.results)) {
          showToast('Failed to load churches.', 'danger');
          console.error('Church Select2: Unexpected response:', data);
          return { results: [] };
        }
        return { results: data.results };
      },
      error: function(xhr, status, error) {
        showToast('Error loading churches: ' + error, 'danger');
        console.error('Church Select2 AJAX error:', status, error, xhr);
      },
      cache: true
    },
    width: '100%'
  });

  // --- Member Select2 setup ---
  $('#member_search').prop('disabled', true);
  $('#church_id').on('change', function() {
    if ($(this).val()) {
      $('#member_search').prop('disabled', false);
    } else {
      $('#member_search').val(null).trigger('change');
      $('#member_search').prop('disabled', true);
    }
  });

  $('#member_search').select2({
    placeholder: 'Search member by name/CRN',
    minimumInputLength: 2,
    ajax: {
      url: 'ajax_members_by_church.php',
      dataType: 'json',
      delay: 200,
      data: function(params) {
        var churchId = $('#church_id').val();
        if (!churchId) {
          showToast('Please select a church first.', 'warning');
          return false;
        }
        return {
          q: params.term,
          church_id: churchId
        };
      },
      processResults: function(data) {
        return {
          results: (data.results || []).map(m => ({
            id: m.id,
            text: m.text,
            crn: m.crn,
            class: m.class,
            last_name: m.last_name,
            first_name: m.first_name,
            middle_name: m.middle_name,
            type: m.type
          }))
        };
      },
      cache: true
    },
    width: '100%'
  });

  // --- Add member to model ---
  function addMemberToBulk(data) {
    var memberId = data.id;
    var type = data.type || 'member';
    // Only one type allowed at a time
    if (type === 'sundayschool') {
      for (const id in bulkMembers) {
        if (bulkMembers[id].type !== 'sundayschool') delete bulkMembers[id];
      }
    } else {
      for (const id in bulkMembers) {
        if (bulkMembers[id].type === 'sundayschool') delete bulkMembers[id];
      }
    }
    if (bulkMembers[memberId]) {
      showToast('Member already added.', 'warning');
      return;
    }
    var memberName = data.text || [data.last_name, data.first_name, data.middle_name].filter(Boolean).join(' ');
    if (!memberName) memberName = 'Unknown';
    bulkMembers[memberId] = {
      id: memberId,
      name: memberName,
      type: type,
      payments: []
    };
    renderBulkTable();
    $('#submitBulkBtn').removeClass('d-none');
    $('#member_search').val(null).trigger('change');
  }

  // --- DataTable setup for bulk table ---
  var bulkTable = $('#bulkTable').DataTable({
    columns: [
      { data: null, render: function(data, type, row, meta) { return meta.row + 1; } }, // Row number
      { data: 'name' },
      { data: 'payments', orderable: false },
      { data: 'actions', orderable: false }
    ],
    ordering: false,
    paging: false,
    searching: false,
    info: false,
    autoWidth: false,
    destroy: true
  });

  // --- Render payment types cell for a member ---
  function renderPaymentTypesCell(member) {
    var html = '<div class="payment-types-group" data-member-id="' + member.id + '">';
    html += '<select class="form-control form-control-sm payment-type-select" style="width:40%; display:inline-block;"></select>';
    html += '<input type="number" min="0.01" step="0.01" class="form-control form-control-sm payment-amount-input" placeholder="₵0.00" style="width:40%; display:inline-block; margin-left:4px;">';
    html += '<button type="button" class="btn btn-sm btn-secondary add-type-btn ml-2">Add</button>';
    html += '<div class="member-payment-types mt-2">';
    member.payments.forEach(function(pt, i) {
      html += '<div class="d-flex align-items-center mb-2">'
        + '<span class="badge badge-info mr-2">' + pt.typeText + ': ₵' + parseFloat(pt.amount).toFixed(2) + '</span>'
        + '<input type="text" class="form-control form-control-sm payment-desc-input ml-2" placeholder="Description (optional)" style="max-width:200px;" data-pt-idx="' + i + '" value="' + (pt.description ? pt.description.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '') + '">' 
        + '<span class="remove-type ml-2" style="cursor:pointer;" data-pt-idx="' + i + '">&times;</span>'
        + '</div>';
    });
    html += '</div>';
    html += '<div class="member-subtotal mt-1 small text-right"><span class="badge badge-secondary">Subtotal: ₵' + member.payments.reduce(function(sum, pt) { return sum + parseFloat(pt.amount || 0) }, 0).toFixed(2) + '</span></div>';
    html += '</div>';
    return html;
  }

  // --- Render the table from the model ---
  function renderBulkTable() {
    bulkTable.clear();
    Object.values(bulkMembers).forEach(function(member) {
      bulkTable.row.add({
        id: member.id,
        name: member.name,
        payments: renderPaymentTypesCell(member),
        actions: '<button class="btn btn-sm btn-danger remove-member-btn" data-member-id="' + member.id + '">Remove</button>'
      });
    });
    bulkTable.draw(false);
    // Re-initialize Select2 for payment-type-select in table
    $('#bulkTable tbody .payment-type-select').each(function() {
      if (!$(this).data('select2')) {
        $(this).select2({
          placeholder: 'Select payment type',
          ajax: {
            url: 'ajax_payment_types.php',
            dataType: 'json',
            delay: 150,
            processResults: function(data) {
              if (!data || !data.results) {
                console.error('No payment types loaded:', data);
                return { results: [] };
              }
              return { results: data.results };
            },
            cache: true,
            error: function(xhr, status, error) {
              console.error('Payment type load failed:', status, error);
            }
          },
          width: 'resolve',
          dropdownParent: $(this).closest('tr')
        });
      }
    });
    attachDynamicEvents();
    renumberRows();
    updateBulkTotals();
  }

  // --- Attach dynamic events for payment type actions ---
  function attachDynamicEvents() {
    // Add payment type
    $('#bulkTable tbody .add-type-btn').off('click').on('click', function() {
      var $group = $(this).closest('.payment-types-group');
      var memberId = $group.data('member-id');
      var typeData = $group.find('.payment-type-select').select2('data')[0];
      var amount = $group.find('.payment-amount-input').val();
      if (!typeData || !amount || parseFloat(amount) <= 0) {
        showToast('Select a payment type and enter a valid amount.', 'warning');
        return;
      }
      // Prevent duplicate payment types for a member
      if (bulkMembers[memberId].payments.some(pt => pt.typeId == typeData.id)) {
        showToast('This payment type is already added for this member.', 'warning');
        return;
      }
      // Auto-populate description
      var paymentDate = $('#payment_date').val();
      var autoDesc = getDefaultDescription(typeData.text, paymentDate);
      bulkMembers[memberId].payments.push({
        typeId: typeData.id,
        typeText: typeData.text,
        amount: parseFloat(amount),
        description: autoDesc,
        manualDesc: false
      });
      renderBulkTable();
    });
    // Remove payment type
    $('#bulkTable tbody .remove-type').off('click').on('click', function() {
      var $group = $(this).closest('.payment-types-group');
      var memberId = $group.data('member-id');
      var idx = $(this).data('pt-idx');
      bulkMembers[memberId].payments.splice(idx, 1);
      renderBulkTable();
    });
    // Description input per payment type
    $('#bulkTable tbody .payment-desc-input').off('input').on('input', function() {
      var $group = $(this).closest('.payment-types-group');
      var memberId = $group.data('member-id');
      var idx = $(this).data('pt-idx');
      var val = $(this).val();
      if (bulkMembers[memberId] && bulkMembers[memberId].payments[idx]) {
        bulkMembers[memberId].payments[idx].description = val;
        bulkMembers[memberId].payments[idx].manualDesc = true;
      }
    });
    // Remove member
    $('#bulkTable tbody .remove-member-btn').off('click').on('click', function() {
      var memberId = $(this).data('member-id');
      delete bulkMembers[memberId];
      renderBulkTable();
      if (Object.keys(bulkMembers).length === 0) {
        $('#submitBulkBtn').addClass('d-none');
      }
    });
  }

  function renumberRows() {
    $('#bulkTable tbody tr').each(function(idx) {
      $(this).find('td').eq(0).text(idx + 1);
    });
  }
  function updateBulkTotals() {
    var total = 0;
    Object.values(bulkMembers).forEach(function(member) {
      if (member.payments && Array.isArray(member.payments)) {
        member.payments.forEach(function(pt) {
          total += parseFloat(pt.amount) || 0;
        });
      }
    });
    $('#bulkTotals').text('Total: ₵' + total.toFixed(2));
  }

  // --- Auto-populate description for payment type ---
  function getDefaultDescription(typeText, dateStr) {
    if (!typeText) return '';
    let date = dateStr ? new Date(dateStr) : new Date();
    let month = date.toLocaleString('default', { month: 'long' });
    let year = date.getFullYear();
    return `Payment for ${month} ${typeText}`;
  }

  // --- Patch add-type-btn to auto-populate description unless user has typed ---
  $('#bulkTable tbody').on('click', '.add-type-btn', function() {
    var $group = $(this).closest('.payment-types-group');
    var memberId = $group.data('member-id');
    var typeData = $group.find('.payment-type-select').select2('data')[0];
    var amount = $group.find('.payment-amount-input').val();
    if (!typeData || !amount || parseFloat(amount) <= 0) {
      showToast('Select a payment type and enter a valid amount.', 'warning');
      return;
    }
    // Prevent duplicate payment types for a member
    if (bulkMembers[memberId].payments.some(pt => pt.typeId == typeData.id)) {
      showToast('This payment type is already added for this member.', 'warning');
      return;
    }
    // Auto-populate description
    var paymentDate = $('#payment_date').val();
    var autoDesc = getDefaultDescription(typeData.text, paymentDate);
    bulkMembers[memberId].payments.push({
      typeId: typeData.id,
      typeText: typeData.text,
      amount: parseFloat(amount),
      description: autoDesc,
      manualDesc: false
    });
    renderBulkTable();
  });

  // --- Track manual edits to description ---
  $('#bulkTable tbody').on('input', '.payment-desc-input', function() {
    var $group = $(this).closest('.payment-types-group');
    var memberId = $group.data('member-id');
    var idx = $(this).data('pt-idx');
    var val = $(this).val();
    if (bulkMembers[memberId] && bulkMembers[memberId].payments[idx]) {
      bulkMembers[memberId].payments[idx].description = val;
      bulkMembers[memberId].payments[idx].manualDesc = true;
    }
  });

  // --- Auto-update description if payment type or date changes and not manually edited ---
  $('#payment_date').on('change', function() {
    Object.values(bulkMembers).forEach(function(member) {
      member.payments.forEach(function(pt, i) {
        if (!pt.manualDesc) {
          pt.description = getDefaultDescription(pt.typeText, $('#payment_date').val());
        }
      });
    });
    renderBulkTable();
  });

  // --- Add member via Select2 ---
  $('#member_search').on('select2:select', function(e) {
    var data = e.params.data;
    if (!data.type) {
      showToast('WARNING: Selected member is missing type property!','danger');
      console.warn('Selected member is missing type property:', data);
    }
    addMemberToBulk(data);
  });

  // --- Handler for direct ID search (manual CRN/SRN entry) ---
  $('#direct_id_search_btn').on('click', function() {
    var idVal = $('#direct_id_search').val().trim();
    var churchId = $('#church_id').val();
    if (!idVal) {
      showToast('Please enter a CRN or SRN.','warning');
      return;
    }
    if (!churchId) {
      showToast('Please select a church first.','warning');
      return;
    }
    // Fetch person by ID (could be CRN or SRN)
    $.get('ajax_get_person_by_id.php', {id: idVal}, function(resp) {
      if (resp.success && resp.data && resp.data.id) {
        if (!resp.type) {
          showToast('WARNING: Direct ID search result is missing type property!','danger');
          console.warn('Direct ID search result is missing type property:', resp);
        }
        var type = resp.type || 'member';
        var memberId = resp.data.id;
        // If adding a Sunday School child, clear all regular members
        if (type === 'sundayschool') {
          for (const id in bulkMembers) {
            if (bulkMembers[id].type !== 'sundayschool') {
              delete bulkMembers[id];
            }
          }
        }
        // If adding a regular member, clear all Sunday School children
        if (type === 'member') {
          for (const id in bulkMembers) {
            if (bulkMembers[id].type === 'sundayschool') {
              delete bulkMembers[id];
            }
          }
        }
        if (bulkMembers[memberId]) {
          showToast('Member already added.','warning');
          return;
        }
        // Build member object in model
        var memberName = '';
        if (resp.data.last_name) {
          memberName = (resp.data.last_name || '') + ' ' + (resp.data.first_name || '') + ' ' + (resp.data.middle_name || '');
          if (type === 'member' && resp.data.crn) memberName += ' (' + resp.data.crn + ')';
          if (type === 'sundayschool' && resp.data.srn) memberName += ' (' + resp.data.srn + ')';
        }
        if (!memberName) memberName = 'Unknown';
        bulkMembers[memberId] = {
          id: memberId,
          name: memberName,
          type: type, // 'member' or 'sundayschool'
          payments: []
        };
        renderBulkTable();
        $('#submitBulkBtn').removeClass('d-none');
        $('#direct_id_search').val('');
      } else {
        showToast(resp.msg || 'ID not found.','danger');
      }
    }, 'json').fail(function(xhr) {
      showToast('Error searching for ID.','danger');
    });
  });

  // --- Submit bulk payment ---
  $('#submitBulkBtn').click(function() {
    var churchId = $('#church_id').val();
    var paymentDate = $('#payment_date').val();
    var memberIds = [];
    var sundayschoolIds = [];
    var amounts = {};
    var descriptions = {};
    var valid = true;
    var hasSRN = false, hasCRN = false;
    Object.values(bulkMembers).forEach(function(m) {
      if (!m.type) {
        showToast('ERROR: bulkMembers entry missing type property! Payment cannot proceed.','danger');
        valid = false;
        return;
      }
      if (m.type === 'sundayschool') {
        hasSRN = true;
        if (!sundayschoolIds.includes(m.id)) sundayschoolIds.push(m.id);
      } else if (m.type === 'member') {
        hasCRN = true;
        if (!memberIds.includes(m.id)) memberIds.push(m.id);
      }
      var key = (m.type === 'sundayschool') ? ('ss_' + m.id) : String(m.id);
      amounts[key] = {};
      descriptions[key] = {};
      (m.payments || []).forEach(function(pt) {
        amounts[key][pt.typeId] = pt.amount;
        descriptions[key][pt.typeId] = pt.description || '';
      });
    });
    if (!valid) {
      showToast('Bulk payment payload invalid. Please check your entries.','danger');
      return;
    }
    if (hasSRN && hasCRN) {
      showToast('Cannot mix Members (CRN) and Sunday School (SRN) in one bulk payment.','danger');
      return;
    }
    if (hasSRN && sundayschoolIds.length === 0) {
      showToast('No Sunday School child selected for bulk payment.','danger');
      return;
    }
    if (hasCRN && memberIds.length === 0) {
      showToast('No Member selected for bulk payment.','danger');
      return;
    }
    // Render confirmation summary
    var summaryHtml = '<div class="mb-2"><strong>Church:</strong> ' + $('#church_id option:selected').text() + '</div>';
    summaryHtml += '<div class="mb-2"><strong>Payment Date:</strong> ' + paymentDate + '</div>';
    summaryHtml += '<div class="mb-2"><strong>Members and Payments:</strong></div>';
    summaryHtml += '<ul class="list-group">';
    Object.keys(amounts).forEach(function(key) {
      var memberId = key.startsWith('ss_') ? key.slice(3) : key;
      var memberName = (bulkMembers[memberId] && bulkMembers[memberId].name) ? bulkMembers[memberId].name : 'Unknown';
      summaryHtml += '<li class="list-group-item"><strong>' + memberName + '</strong><ul class="mb-0">';
      var memberAmounts = amounts[key];
      var subtotal = 0;
      Object.keys(memberAmounts).forEach(function(typeId) {
        var amt = memberAmounts[typeId];
        if (typeof amt !== 'number' || isNaN(amt)) amt = 0;
        subtotal += amt;
        var desc = (descriptions[key] && descriptions[key][typeId]) ? descriptions[key][typeId] : '';
        summaryHtml += '<li>' + typeId + ': ₵' + amt.toFixed(2) + (desc ? ' <span class="text-muted small">[' + $('<div>').text(desc).html() + ']</span>' : '') + '</li>';
      });
      summaryHtml += '<li><em>Subtotal: ₵' + subtotal.toFixed(2) + '</em></li>';
      summaryHtml += '</ul></li>';
    });
    summaryHtml += '</ul>';
    var total = 0;
    Object.values(amounts).forEach(function(memberAmounts) {
      Object.values(memberAmounts).forEach(function(amt) {
        total += amt;
      });
    });
    summaryHtml += '<div class="mt-3 font-weight-bold text-right">Total: ₵' + total.toFixed(2) + '</div>';
    $('#confirmSummary').html(summaryHtml);
    $('#confirmModal').modal('show');
    // On confirm
    $('#confirmSubmitBtn').off('click').on('click', function() {
      $(this).prop('disabled', true).text('Processing...');
      // Debug: log the payload before sending
      $.ajax({
        url: 'ajax_bulk_payment.php',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
          church_id: churchId,
          payment_date: paymentDate,
          member_ids: memberIds,
          sundayschool_ids: sundayschoolIds,
          amounts: amounts,
          descriptions: descriptions
        }),
        dataType: 'json',
        success: function(resp) {
          $('#confirmSubmitBtn').prop('disabled', false).text('Confirm & Submit');
          if (resp.success) {
            $('#confirmModal').modal('hide');
            showToast('Bulk payment successful!', 'success');
            setTimeout(function() { location.reload(); }, 1500);
          } else {
            showToast(resp.msg || 'Error processing payment.', 'danger');
            if (resp.msg) console.error('[AJAX ERROR] Server message:', resp.msg);
          }
        },
        error: function(xhr, status, err) {
          $('#confirmSubmitBtn').prop('disabled', false).text('Confirm & Submit');
          let msg = 'Network/server error.';
          if (xhr && xhr.responseText) {
            try {
              let resp = JSON.parse(xhr.responseText);
              msg = resp.msg || msg;
              console.error('[AJAX ERROR] Parsed response:', resp);
            } catch (e) {
              console.error('[AJAX ERROR] Could not parse responseText:', xhr.responseText);
            }
          } else {
            console.error('[AJAX ERROR] status:', status, 'err:', err, 'xhr:', xhr);
          }
          showToast(msg, 'danger');
        }
      });
    });
  });

  // --- Toast helper ---
  function showToast(msg, type) {
    var $toast = $('#bulkToast');
    $toast.removeClass('bg-info bg-success bg-danger bg-warning');
    if (type === 'success') $toast.find('.toast-header').addClass('bg-success').removeClass('bg-info bg-danger bg-warning');
    else if (type === 'danger') $toast.find('.toast-header').addClass('bg-danger').removeClass('bg-info bg-success bg-warning');
    else if (type === 'warning') $toast.find('.toast-header').addClass('bg-warning').removeClass('bg-info bg-success bg-danger');
    else $toast.find('.toast-header').addClass('bg-info').removeClass('bg-success bg-danger bg-warning');
    $toast.find('.toast-body').text(msg);
    $toast.toast('show');
  }
});