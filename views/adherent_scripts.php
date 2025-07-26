<script>
$(document).ready(function() {
    // Handle Mark as Adherent Modal
    $('#markAdherentModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var memberId = button.data('member-id');
        var memberName = button.data('member-name');
        
        $('#adherentMemberId').val(memberId);
        $('#adherentMemberName').text(memberName);
        $('#adherentReason').val('');
        $('#adherentDate').val('<?= date('Y-m-d') ?>');
    });

    // Handle Mark as Adherent Form Submission
    $('#markAdherentForm').on('submit', function(e) {
        e.preventDefault();
        
        var btn = $('#markAdherentBtn');
        var originalText = btn.html();
        
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-1"></i>Processing...');
        
        $.ajax({
            url: BASE_URL + '/views/ajax_mark_adherent.php',
            type: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#markAdherentModal').modal('hide');
                    location.reload(); // Refresh page to show updated status
                } else {
                    alert('Error: ' + (response.message || 'Failed to mark member as adherent'));
                }
            },
            error: function(xhr) {
                var errorMsg = 'Failed to mark member as adherent';
                try {
                    var response = JSON.parse(xhr.responseText);
                    errorMsg = response.message || errorMsg;
                } catch(e) {
                    errorMsg = xhr.responseText || errorMsg;
                }
                alert('Error: ' + errorMsg);
            },
            complete: function() {
                btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Handle Adherent History Modal
    $('#adherentHistoryModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var memberId = button.data('member-id');
        var memberName = button.data('member-name');
        
        $('#historyMemberName').text(memberName);
        
        // Load adherent history
        $.ajax({
            url: BASE_URL + '/views/ajax_get_adherent_history.php',
            type: 'GET',
            data: { member_id: memberId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    var content = '';
                    if (response.history && response.history.length > 0) {
                        content = '<div class="table-responsive">';
                        content += '<table class="table table-striped table-hover">';
                        content += '<thead class="thead-dark">';
                        content += '<tr>';
                        content += '<th><i class="fas fa-calendar mr-1"></i>Date Became Adherent</th>';
                        content += '<th><i class="fas fa-comment mr-1"></i>Reason</th>';
                        content += '<th><i class="fas fa-user mr-1"></i>Marked By</th>';
                        content += '<th><i class="fas fa-clock mr-1"></i>Date Recorded</th>';
                        content += '</tr>';
                        content += '</thead>';
                        content += '<tbody>';
                        
                        response.history.forEach(function(record) {
                            content += '<tr>';
                            content += '<td><span class="badge badge-primary">' + record.date_became_adherent + '</span></td>';
                            content += '<td>' + record.reason + '</td>';
                            content += '<td><i class="fas fa-user-circle mr-1"></i>' + record.marked_by_name + '</td>';
                            content += '<td><small class="text-muted">' + record.created_at + '</small></td>';
                            content += '</tr>';
                        });
                        
                        content += '</tbody>';
                        content += '</table>';
                        content += '</div>';
                    } else {
                        content = '<div class="text-center py-4">';
                        content += '<i class="fas fa-info-circle fa-3x text-muted mb-3"></i>';
                        content += '<p class="text-muted">No adherent history found for this member.</p>';
                        content += '</div>';
                    }
                    $('#adherentHistoryContent').html(content);
                } else {
                    $('#adherentHistoryContent').html(
                        '<div class="alert alert-danger">' +
                        '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                        'Error loading adherent history: ' + (response.message || 'Unknown error') +
                        '</div>'
                    );
                }
            },
            error: function(xhr) {
                var errorMsg = 'Failed to load adherent history. Please try again.';
                var debugInfo = '';
                
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.debug_error) {
                        errorMsg = response.message || errorMsg;
                        debugInfo = '<br><small class="text-muted">Debug: ' + response.debug_error + '</small>';
                    }
                } catch(e) {
                    // If response is not JSON, show the raw response
                    if (xhr.responseText) {
                        debugInfo = '<br><small class="text-muted">Response: ' + xhr.responseText.substring(0, 200) + '</small>';
                    }
                }
                
                $('#adherentHistoryContent').html(
                    '<div class="alert alert-danger">' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    errorMsg + debugInfo +
                    '</div>'
                );
            }
        });
    });
});
</script>
