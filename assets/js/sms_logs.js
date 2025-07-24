$(document).on('click', '.resend-sms-btn', function() {
    var btn = $(this);
    var logId = btn.data('log-id');
    btn.prop('disabled', true).text('Resending...');
    $.post('resend_sms.php', { log_id: logId }, function(resp) {
        if (resp && resp.success) {
            btn.closest('td').html('<span class="badge badge-success">Sent</span>');
            showToast('SMS resent successfully.', 'success');
        } else {
            btn.prop('disabled', false).text('Resend');
            showToast(resp && resp.msg ? resp.msg : 'Failed to resend SMS.', 'danger');
        }
    }, 'json').fail(function() {
        btn.prop('disabled', false).text('Resend');
        showToast('Network error. Please try again.', 'danger');
    });
});
function showToast(msg, type) {
    var toast = $('<div class="toast" style="position:fixed;top:20px;right:20px;z-index:9999;min-width:200px;">'
        +'<div class="toast-header bg-'+(type==='success'?'success':'danger')+' text-white">'+(type==='success'?'Success':'Error')+'</div>'
        +'<div class="toast-body">'+msg+'</div></div>');
    $('body').append(toast);
    toast.toast({ delay: 2500 });
    toast.toast('show');
    setTimeout(function(){ toast.remove(); }, 2700);
}
