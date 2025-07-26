<script>
$(document).ready(function() {
    // Handle collapsible filter section
    $('#filterCollapse').on('show.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });

    $('#filterCollapse').on('hide.bs.collapse', function () {
        $('#filterToggleIcon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });

    // AJAX fetch total payments for each member
    $('.total-payments').each(function() {
        var span = $(this);
        var memberId = span.data('member-id');
        var baseUrl = (typeof BASE_URL !== 'undefined') ? BASE_URL : '';
        
        $.get(baseUrl + '/views/ajax_get_member_total_payments.php?member_id=' + memberId, function(data) {
            if (data && typeof data.total !== 'undefined') {
                var total = parseFloat(data.total);
                span.html('<i class="fas fa-coins mr-1"></i>₵' + total.toFixed(2));
            } else {
                span.html('<i class="fas fa-coins mr-1"></i>₵0.00');
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('Failed to load total payments for member ' + memberId + ':', error);
            span.html('<i class="fas fa-exclamation-triangle mr-1 text-warning"></i>Error');
        });
    });
    
    // Show missing requirements in modal
    $('#statusInfoModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var memberName = button.data('member-name');
        var missingRequirements = button.data('missing');
        var modal = $(this);
        modal.find('.modal-title').text('Missing Requirements for ' + memberName);
        modal.find('#statusInfoModalMessage').text('Member: ' + missingRequirements);
    });
    
    // Export PDF functionality
    $('#export-pdf').on('click', function() {
        // Create a printable version of the table
        var printWindow = window.open('', '_blank');
        var tableHtml = $('#membersTable').clone();
        
        // Remove action columns and buttons from the cloned table
        tableHtml.find('th:last-child, td:last-child').remove();
        tableHtml.find('.btn, .dropdown').remove();
        
        var htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Member List - ${new Date().toLocaleDateString()}</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .member-photo { width: 40px; height: 40px; border-radius: 50%; }
                    @media print { body { margin: 0; } }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Church Members List</h2>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                </div>
                ${tableHtml[0].outerHTML}
            </body>
            </html>
        `;
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
        printWindow.focus();
        
        // Auto-trigger print dialog
        setTimeout(function() {
            printWindow.print();
        }, 250);
    });
    
    // Print table functionality
    $('#print-table').on('click', function() {
        // Create a printable version
        var printWindow = window.open('', '_blank');
        var tableHtml = $('#membersTable').clone();
        
        // Remove action columns and buttons from the cloned table
        tableHtml.find('th:last-child, td:last-child').remove();
        tableHtml.find('.btn, .dropdown').remove();
        
        var htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Member List - Print</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 6px; text-align: left; font-size: 12px; }
                    th { background-color: #f2f2f2; font-weight: bold; }
                    .header { text-align: center; margin-bottom: 20px; }
                    .member-photo { width: 30px; height: 30px; border-radius: 50%; }
                    @media print { 
                        body { margin: 0; }
                        table { font-size: 10px; }
                        th, td { padding: 4px; }
                    }
                </style>
            </head>
            <body>
                <div class="header">
                    <h2>Church Members List</h2>
                    <p>Printed on: ${new Date().toLocaleDateString()}</p>
                </div>
                ${tableHtml[0].outerHTML}
            </body>
            </html>
        `;
        
        printWindow.document.write(htmlContent);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    });
});

$('.resend-token-btn').on('click', function() {
    var btn = $(this);
    var memberId = btn.data('member-id');
    btn.prop('disabled', true).text('Sending...');
    
    $.post(BASE_URL + '/views/ajax_resend_token.php', {member_id: memberId}, function(response) {
        if (response.success) {
            btn.removeClass('btn-warning').addClass('btn-success').text('Sent!');
        } else {
            btn.text('Failed');
        }
    });
});

var BASE_URL = "<?= addslashes(BASE_URL) ?>";
</script>
