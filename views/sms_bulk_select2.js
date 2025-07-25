// Bulk SMS Select2 integration and selection fix
$(function(){
  // Load Select2 if not already loaded
  if (typeof $.fn.select2 === 'undefined') {
    var s2 = document.createElement('link');
    s2.rel = 'stylesheet';
    s2.href = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
    document.head.appendChild(s2);
    var s2js = document.createElement('script');
    s2js.src = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
    document.body.appendChild(s2js);
    s2js.onload = function() { initBulkSmsSelect2(); };
  } else {
    initBulkSmsSelect2();
  }
  function initBulkSmsSelect2() {
    $('#class_ids').select2({
      width: '100%',
      placeholder: 'Select Bible Class(es)',
      dropdownParent: $('#classSelectGroup').length ? $('#classSelectGroup') : undefined
    });
    $('#organization_ids').select2({
      width: '100%',
      placeholder: 'Select Organization(s)',
      dropdownParent: $('#organizationSelectGroup').length ? $('#organizationSelectGroup') : undefined
    });
    $('#church_ids').select2({
      width: '100%',
      placeholder: 'Select Church(es)',
      dropdownParent: $('#churchSelectGroup').length ? $('#churchSelectGroup') : undefined
    });
    // Fix for dynamic show/hide
    $('#recipient_type').on('change', function(){
      setTimeout(function(){
        $('#class_ids, #organization_ids, #church_ids').select2('close');
      }, 200);
    });
  }
});
